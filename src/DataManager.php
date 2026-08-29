<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix ORM - Main ORM Class
 * 
 * @package Italix\Orm
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\Orm;

use Italix\Orm\Dialects\Driver;
use Italix\Orm\Dialects\DialectInterface;
use Italix\Orm\Cache\QueryCache;
use Italix\Orm\Hooks\HookRegistry;
use Italix\Orm\Profiling\QueryLog;
use Italix\Orm\QueryBuilder\QueryBuilder;
use Italix\Orm\Scopes\ScopeRegistry;
use Italix\Orm\Relations\RelationalQueryBuilder;
use Italix\Orm\Relations\TableQuery;
use Italix\Orm\Schema\Table;
use Italix\Orm\Sql;
use PDO;

/**
 * Main ORM class providing database operations.
 */
class DataManager
{
    /** @var Driver Database driver */
    protected Driver $driver;

    /** @var QueryBuilder Query builder instance */
    protected QueryBuilder $query_builder;

    /** @var RelationalQueryBuilder|null Relational query builder */
    protected ?RelationalQueryBuilder $relational_builder = null;

    /** @var QueryCache|null Where answered queries are kept, when there is one */
    protected ?QueryCache $query_cache = null;

    /** @var QueryLog|null Where statements report what they cost */
    protected ?QueryLog $query_log = null;

    /** @var HookRegistry Lifecycle hooks for $this->insert()/update()/delete() writes */
    protected HookRegistry $hooks;

    /** @var ScopeRegistry Global scopes applied to every read, until without_scopes() */
    protected ScopeRegistry $scopes;

    /** @var Driver[] Read-only copies of the database, if any were given */
    protected array $replicas = [];

    /**
     * Whether this manager has written since the last reset.
     *
     * Once it has, reads go back to the primary — see {@see use_replicas()}.
     */
    protected bool $wrote_flag = false;

    /**
     * How many transactions are open, counting nested ones.
     *
     * 0 means none; 1 means a real database transaction; anything higher means
     * that transaction plus that many savepoints inside it.
     */
    protected int $transaction_depth = 0;

    /**
     * True when the outermost open transaction was not opened by this object.
     *
     * Everything nested inside it is a savepoint, and neither commit() nor
     * rollback() may touch the enclosing transaction: it belongs to whoever
     * began it.
     */
    protected bool $foreign_transaction_flag = false;

    /** @var array<int, callable> Pending on_commit() callbacks, oldest first. See commit()/rollback(). */
    protected array $commit_hooks = [];

    /** @var array<int, callable> Pending on_rollback() callbacks, oldest first. See commit()/rollback(). */
    protected array $rollback_hooks = [];

    /**
     * @var array<int, array{commit_n: int, rollback_n: int}>
     * One entry per currently-open transaction_depth level, pushed by
     * begin_transaction() and popped by whichever of commit()/rollback()
     * closes it: how many entries $commit_hooks/$rollback_hooks held the
     * instant that level opened. A rollback at that level uses the counts
     * to know exactly which *tail* of each queue was registered since —
     * the unit of work that just got undone — without disturbing whatever
     * an enclosing level already had queued.
     */
    protected array $hook_marks = [];

    /**
     * Create a new DataManager instance
     */
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
        $this->query_builder = new QueryBuilder($driver->get_dialect_name());
        $this->query_builder->set_connection($driver->get_connection());

        $this->hooks = new HookRegistry();
        $this->query_builder->set_hooks($this->hooks);

        $this->scopes = new ScopeRegistry();
        $this->query_builder->set_scopes($this->scopes);
    }

    /**
     * AND `$scope($table)` onto every read against `$table` from here on —
     * `$dm->select()->from(...)` and `$dm->query_table(...)` alike — until a
     * caller opts out with `->without_scopes()`.
     *
     *     $dm->add_global_scope($orders, 'tenant', fn (Table $t) => eq($t->tenant_id, $current_tenant_id));
     *     $dm->select()->from($orders)->execute();               // only this tenant's rows
     *     $dm->select()->from($orders)->without_scopes()->execute(); // every tenant's
     *
     * Registering the same `$name` again for the same table replaces the
     * previous scope rather than adding a second one.
     */
    public function add_global_scope(Table $table, string $name, callable $scope): self
    {
        $this->scopes->add($table, $name, $scope);

        return $this;
    }

    /**
     * Run `$hook` on `$event` for every future write against `$table` made
     * through `$this->insert()`/`update()`/`delete()` — and so through
     * `ActiveRow`'s `Persistable` too, which compiles to the same calls.
     *
     *     $dm->on($orders, 'before_insert', function (array $row): array {
     *         $row['reference'] = strtoupper(bin2hex(random_bytes(4)));
     *         return $row;
     *     });
     *
     * `before_insert`/`before_update` may return a replacement row/values
     * array to change what gets written; returning anything else (including
     * void) leaves it as-is. `before_delete` and every `after_*` hook run
     * for side effects only — see `HookRegistry` for the exact arguments
     * each event receives.
     */
    public function on(Table $table, string $event, callable $hook): self
    {
        $this->hooks->on($table, $event, $hook);

        return $this;
    }

    /**
     * Keep the answers to queries that ask to be kept.
     *
     *     $dm->use_query_cache(new QueryCache($cache, 300));
     *     $dm->select()->from($products)->cached()->execute();
     *
     * From here on every builder this manager makes carries the cache: reads can
     * ask for their answer to be reused, and **writes retire the answers about
     * the table they touch**. Without this, `cached()` raises rather than
     * quietly running uncached.
     */
    public function use_query_cache(?QueryCache $cache): self
    {
        $this->query_cache = $cache;
        $this->query_builder->set_query_cache($cache);

        return $this;
    }

    /** The query cache in use, or null. */
    public function query_cache(): ?QueryCache
    {
        return $this->query_cache;
    }

    /**
     * Record what every statement costs.
     *
     *     $log = new QueryLog(0.1);
     *     $dm->use_query_log($log);
     *     …
     *     $log->queries_n();       // 34, which is usually the answer
     *     $log->slow();            // the ones over 100 ms
     *     $log->repeated();        // the same statement, over and over: an N+1
     *
     * Covers the raw helpers and the query builder alike. A cached answer is not
     * recorded, because it never reached the server and counting it would hide
     * what the cache is there to show.
     */
    public function use_query_log(?QueryLog $log): self
    {
        $this->query_log = $log;
        $this->driver->set_query_log($log);
        $this->query_builder->set_query_log($log);

        return $this;
    }

    /** The query log in use, or null. */
    public function query_log(): ?QueryLog
    {
        return $this->query_log;
    }

    // =========================================
    // Read replicas
    // =========================================

    /**
     * Send reads to a copy of the database, and writes to this one.
     *
     *     $dm->use_replicas(Driver::mysql($replica_config));
     *
     * Replication is asynchronous: a replica is always a little behind, and how
     * far is not something the application can see. Everything below exists to
     * keep that from becoming a bug.
     *
     * **A read after a write goes to the primary.** Not the read that follows in
     * the same function — *every* read from then on, until
     * {@see resume_replica_reads()} is called. Saving a form and rendering the
     * page that shows it is the single most common thing an application does,
     * and a replica that has not caught up shows the value before the edit. The
     * user's conclusion is that the save did not work.
     *
     * **Reads inside a transaction go to the primary too**, because the rows the
     * transaction has written exist nowhere else yet.
     *
     * A long-lived worker should call `resume_replica_reads()` between jobs, or
     * every read after its first write is on the primary for the life of the
     * process.
     *
     * With more than one replica, each read picks one at random. Nothing here
     * checks whether a replica is healthy or how far behind it is — a driver
     * that cannot connect will raise, and that is a decision for the deployment,
     * not for an ORM.
     */
    public function use_replicas(Driver ...$replicas): self
    {
        $this->replicas = $replicas;

        return $this;
    }

    /** Are there replicas to read from? */
    public function has_replicas(): bool
    {
        return $this->replicas !== [];
    }

    /**
     * Would the next read go to a replica?
     *
     * False inside a transaction, false after a write, false with no replicas.
     */
    public function reads_replica(): bool
    {
        return $this->replicas !== [] && !$this->wrote_flag && $this->transaction_depth === 0;
    }

    /**
     * Send reads back to the replicas.
     *
     * For a worker between jobs, or a request that has finished the part of its
     * work that had to see its own writes. Calling it while a transaction is open
     * changes nothing: a transaction always reads from the primary.
     */
    public function resume_replica_reads(): self
    {
        $this->wrote_flag = false;

        return $this;
    }

    /**
     * Run something with reads pinned to the primary.
     *
     *     $total = $dm->on_primary(fn() => $dm->select()->from($ledger)->execute());
     *
     * For the reads that must be current whatever the replica lag — a balance
     * check before a payment, a uniqueness check before an insert. The pin is
     * lifted afterwards, including if the callback throws.
     *
     * @param callable():mixed $work
     * @return mixed
     */
    public function on_primary(callable $work)
    {
        $was_flagged      = $this->wrote_flag;
        $this->wrote_flag = true;

        try {
            return $work();
        } finally {
            $this->wrote_flag = $was_flagged;
        }
    }

    /** The driver a read should use right now. */
    protected function read_driver(): Driver
    {
        if (!$this->reads_replica()) {
            return $this->driver;
        }

        return $this->replicas[array_rand($this->replicas)];
    }

    /** Everything from here on reads from the primary. */
    protected function note_write(): void
    {
        $this->wrote_flag = true;
    }

    /**
     * Get the database driver
     */
    public function get_driver(): Driver
    {
        return $this->driver;
    }

    /**
     * Get the dialect
     */
    public function get_dialect(): DialectInterface
    {
        return $this->driver->get_dialect();
    }

    /**
     * Get the PDO connection
     */
    public function get_connection(): PDO
    {
        return $this->driver->get_connection();
    }

    /**
     * Start a SELECT query
     * 
     * @param array|null $columns Columns to select
     */
    public function select(?array $columns = null): QueryBuilder
    {
        // The connection is chosen here, when the read is asked for, rather than
        // once at construction: whether a replica may answer depends on what has
        // happened since — see use_replicas().
        return $this->query_builder->select($columns)
            ->set_connection($this->read_driver()->get_connection());
    }

    /**
     * Start an INSERT query
     */
    public function insert(Table $table): QueryBuilder
    {
        $this->note_write();

        return $this->query_builder->insert($table)
            ->set_connection($this->driver->get_connection());
    }

    /**
     * Start an UPDATE query
     */
    public function update(Table $table): QueryBuilder
    {
        $this->note_write();

        return $this->query_builder->update($table)
            ->set_connection($this->driver->get_connection());
    }

    /**
     * Start a DELETE query
     */
    public function delete(Table $table): QueryBuilder
    {
        $this->note_write();

        return $this->query_builder->delete($table)
            ->set_connection($this->driver->get_connection());
    }

    /**
     * Write many rows in as few statements as the chunk size allows.
     *
     *     $dm->insert_many($readings, $rows);          // 50,000 rows, 100 statements
     *
     * Measured on this project's MariaDB, 5,000 rows:
     *
     * | | |
     * |---|---|
     * | one `INSERT` at a time | **273 s** |
     * | one at a time, inside one transaction | **1.71 s** |
     * | `insert_many()`, chunks of 500 | **1.18 s** |
     *
     * Note where the time actually went. Batching is the smaller half — 1.5× —
     * and **the transaction is the other 160×**: without one, every row is its
     * own transaction and the database flushes to disk 5,000 times. This method
     * gives you both, which is the point of it existing rather than being advice
     * in a README.
     *
     * A failure half way therefore leaves no half import behind. Nesting is safe:
     * inside a caller's transaction this opens a savepoint, not a second
     * transaction.
     *
     * ## Why the rows must agree on their columns
     *
     * A multi-row `INSERT` has one column list. Rows that name different columns
     * are refused rather than reconciled — filling the gaps with `NULL` would
     * write something nobody asked for, and dropping the extras would lose data
     * silently. Group them yourself and call this twice; the message says which
     * row disagreed.
     *
     * ## The chunk size
     *
     * 500 rows is a size, not a limit discovered from the server. The limits
     * that bite are the statement size and the packet size, not a placeholder
     * count: measured, this MariaDB accepted 90,000 placeholders in one
     * statement and this SQLite 150,000. Raise it for narrow rows, lower it for
     * wide ones.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int rows written
     */
    public function insert_many(Table $table, array $rows, int $chunk_n = 500): int
    {
        if ($rows === []) {
            return 0;
        }

        if ($chunk_n < 1) {
            throw new \InvalidArgumentException('A chunk holds at least one row; ' . $chunk_n . ' asked for.');
        }

        $columns = array_keys(reset($rows));

        foreach ($rows as $index => $row) {
            if (array_keys($row) !== $columns) {
                throw new \InvalidArgumentException(
                    'Row ' . $index . ' names different columns than the first one, and a multi-row '
                    . 'INSERT has one column list. Group the rows by their columns and insert each '
                    . 'group, rather than having this decide what the missing ones should be.'
                );
            }
        }

        $written_n = 0;

        $this->transaction(function () use ($table, $rows, $chunk_n, &$written_n): void {
            foreach (array_chunk($rows, $chunk_n) as $chunk) {
                $this->query_builder->insert($table)->values($chunk)->execute();
                $written_n += count($chunk);
            }
        });

        return $written_n;
    }

    /**
     * Create tables from schemas
     * 
     * @param Table ...$tables
     */
    public function create_tables(Table ...$tables): void
    {
        foreach ($tables as $table) {
            $sql = $table->to_create_sql();
            $this->driver->execute($sql);
            
            // Create indexes
            foreach ($table->get_index_sql() as $index_sql) {
                try {
                    $this->driver->execute($index_sql);
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }
    }

    /**
     * Drop tables
     * 
     * @param Table ...$tables
     */
    public function drop_tables(Table ...$tables): void
    {
        foreach ($tables as $table) {
            $sql = $table->to_drop_sql();
            $this->driver->execute($sql);
        }
    }

    /**
     * Check if a table exists
     */
    public function table_exists(string $table_name): bool
    {
        $sql = $this->driver->get_dialect()->get_table_exists_sql($table_name);
        $result = $this->driver->query_one($sql, [$table_name]);
        return $result !== null;
    }

    /**
     * Execute a raw SQL query
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        // Raw SQL, so this cannot tell a write from a read — and guessing by
        // looking at the text is how a `WITH … INSERT` ends up on a replica.
        // Assume it wrote: the cost is reads on the primary, which is the safe
        // direction. Use query() for a read that may go to a replica.
        $this->note_write();

        return $this->driver->execute($sql, $params);
    }

    /**
     * Execute a query and fetch all results
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->read_driver()->query($sql, $params);
    }

    /**
     * Execute a query and read its rows one at a time.
     *
     *     foreach ($dm->cursor('SELECT * FROM big_table') as $row) { … }
     *
     * {@see query()} builds a PHP array of every row first, which is fine until
     * the result is large enough that the array is the problem. This yields
     * them instead — see {@see QueryBuilder::cursor()} for what that does and
     * does not save, and for what to do when even the driver's own buffer is
     * too much.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(string $sql, array $params = []): \Generator
    {
        $stmt = $this->read_driver()->execute($sql, $params);

        try {
            while (($row = $stmt->fetch()) !== false) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Execute a query and fetch one result
     */
    public function query_one(string $sql, array $params = []): ?array
    {
        return $this->read_driver()->query_one($sql, $params);
    }

    /**
     * Begin a transaction, or a savepoint inside the one already open.
     *
     * Nesting used to send a second `BEGIN` to a connection that was already in
     * a transaction. What happens then is not an error anybody sees: PDO throws
     * on some drivers, silently ignores it on others, and on the ones that
     * ignore it the *inner* commit ends the *outer* transaction — so work the
     * caller believed was still provisional is written, and the outer rollback
     * that follows has nothing left to undo.
     *
     * So the first call opens a real transaction and each one after it opens a
     * savepoint. Every dialect this package supports has them.
     */
    public function begin_transaction(): bool
    {
        // The connection can already be inside a transaction this object never
        // opened — a test harness that wraps each suite in one, a job that
        // brackets its work, any code sharing the PDO. The depth counter knows
        // nothing about it, so BEGIN went out anyway: PDO answers "There is
        // already an active transaction" and the caller reads it as a bug in
        // its own code.
        //
        // The right move is the one nesting already makes everywhere else: open
        // a savepoint inside theirs. Ending it releases or rolls back to that
        // savepoint and never touches the enclosing transaction, which stays
        // open and stays theirs to commit.
        // Neither branch below pushes a hook mark: on_commit()/on_rollback()
        // only ever queue while transaction_depth > 0, and every path back
        // to 0 fully drains both queues (a real commit clears them outright;
        // a rollback splices from a mark whose own fallback is the same
        // {0, 0} this level would have pushed). Opening a fresh outermost
        // level therefore always finds both queues already empty — pushing
        // a mark here would be provably redundant with pop_hook_mark()'s
        // own default, confirmed by mutation-testing it away and finding
        // no assertion cared.
        if ($this->transaction_depth === 0 && $this->driver->in_transaction()) {
            $this->driver->execute('SAVEPOINT ' . $this->savepoint_name(0));

            $this->foreign_transaction_flag = true;
            $this->transaction_depth        = 1;

            return true;
        }

        if ($this->transaction_depth === 0) {
            $started = $this->driver->begin_transaction();

            if ($started) {
                $this->transaction_depth = 1;
            }

            return $started;
        }

        $this->driver->execute('SAVEPOINT ' . $this->savepoint_name($this->transaction_depth));
        $this->transaction_depth++;
        $this->push_hook_mark();

        return true;
    }

    /**
     * Commit the innermost transaction.
     *
     * Releasing a savepoint is not a commit: it discards the marker and leaves
     * the work inside the enclosing transaction, still provisional. Only the
     * outermost commit writes anything, which is what nesting has to mean —
     * otherwise an inner block could make somebody else's work durable.
     */
    public function commit(): bool
    {
        if ($this->transaction_depth === 0) {
            throw new \RuntimeException(
                'commit() was called with no transaction open. Something committed twice, or a '
                . 'rollback already closed this one.'
            );
        }

        if ($this->transaction_depth === 1) {
            $this->transaction_depth = 0;

            // Never commit a transaction somebody else opened: their work is
            // theirs to make durable, and committing it here would end it early.
            // Releasing our savepoint leaves everything provisional, inside it.
            //
            // on_commit() hooks fire here regardless — this is the only point
            // *this* object ever reaches that looks like "our part is done",
            // even though a foreign transaction's own eventual commit is what
            // actually decides durability. There is no way to observe that
            // decision from here; see on_commit()'s own docblock.
            if ($this->foreign_transaction_flag) {
                $this->foreign_transaction_flag = false;
                $this->driver->execute('RELEASE SAVEPOINT ' . $this->savepoint_name(0));
                $this->pop_hook_mark();
                $this->resolve_commit_hooks();

                return true;
            }

            // No truthiness check on the result: this codebase runs PDO with
            // ERRMODE_EXCEPTION everywhere, so a failed commit() throws
            // rather than returning false — reaching the next line at all
            // already means it succeeded.
            $result = $this->driver->commit();
            $this->pop_hook_mark();
            $this->resolve_commit_hooks();

            return $result;
        }

        // A savepoint release, not a commit — see this method's own docblock.
        // Any on_commit()/on_rollback() hook queued since this level opened
        // simply stays queued, now indistinguishable from one the enclosing
        // level registered: exactly right, since this level's work is not
        // durable either way until something outside it eventually is.
        $this->transaction_depth--;
        $this->driver->execute('RELEASE SAVEPOINT ' . $this->savepoint_name($this->transaction_depth));
        $this->pop_hook_mark();

        return true;
    }

    /**
     * Roll back the innermost transaction.
     *
     * Inside a nested block this rolls back to the savepoint and **leaves the
     * enclosing transaction open and usable**. That is the property worth
     * having: on PostgreSQL a failed statement puts the whole transaction into
     * an aborted state where every further statement errors, and rolling back
     * to a savepoint is the only way out of it short of abandoning everything.
     */
    public function rollback(): bool
    {
        if ($this->transaction_depth === 0) {
            throw new \RuntimeException(
                'rollback() was called with no transaction open.'
            );
        }

        if ($this->transaction_depth === 1) {
            $this->transaction_depth = 0;

            // Same reasoning as commit(): rolling back the connection here would
            // throw away work belonging to whoever opened the enclosing
            // transaction. Rolling back to our own savepoint discards ours and
            // leaves theirs open and usable.
            if ($this->foreign_transaction_flag) {
                $this->foreign_transaction_flag = false;
                $this->driver->execute('ROLLBACK TO SAVEPOINT ' . $this->savepoint_name(0));
                $this->driver->execute('RELEASE SAVEPOINT ' . $this->savepoint_name(0));
                $this->resolve_rollback_hooks($this->pop_hook_mark());

                return true;
            }

            // Same reasoning as commit(): a failed rollback() throws under
            // ERRMODE_EXCEPTION rather than returning false.
            $result = $this->driver->rollback();
            $this->resolve_rollback_hooks($this->pop_hook_mark());

            return $result;
        }

        // A savepoint rollback: only the work registered *since this level
        // opened* is actually undone — on_rollback() hooks queued that
        // recently fire now, and on_commit() hooks queued that recently are
        // discarded, since the unit of work they were waiting on never
        // happened. Anything queued by an enclosing level is untouched,
        // still pending that level's own eventual commit or rollback.
        $this->transaction_depth--;
        $this->driver->execute('ROLLBACK TO SAVEPOINT ' . $this->savepoint_name($this->transaction_depth));
        $this->resolve_rollback_hooks($this->pop_hook_mark());

        return true;
    }

    /** Push, at the moment a transaction level opens, how many hooks existed already. */
    protected function push_hook_mark(): void
    {
        $this->hook_marks[] = [
            'commit_n'   => count($this->commit_hooks),
            'rollback_n' => count($this->rollback_hooks),
        ];
    }

    /** @return array{commit_n: int, rollback_n: int} */
    protected function pop_hook_mark(): array
    {
        return array_pop($this->hook_marks) ?? ['commit_n' => 0, 'rollback_n' => 0];
    }

    /**
     * The outermost transaction really committed (or, best-effort, a
     * foreign one released its part) — every pending on_commit() hook
     * fires, in registration order, and every pending on_rollback() hook
     * (never going to fire now — nothing rolled back) is discarded.
     */
    protected function resolve_commit_hooks(): void
    {
        $hooks                 = $this->commit_hooks;
        $this->commit_hooks    = [];
        $this->rollback_hooks  = [];

        foreach ($hooks as $hook) {
            $hook();
        }
    }

    /**
     * A transaction level — the outermost one, or a nested savepoint —
     * rolled back. Only the *tail* of each queue registered since that
     * level opened (from `$mark`) is resolved: its on_rollback() hooks
     * fire, in registration order, and its on_commit() hooks are discarded
     * — that specific unit of work is undone, whatever else in an
     * enclosing level eventually happens to it.
     *
     * @param array{commit_n: int, rollback_n: int} $mark
     */
    protected function resolve_rollback_hooks(array $mark): void
    {
        $hooks = array_splice($this->rollback_hooks, $mark['rollback_n']);
        array_splice($this->commit_hooks, $mark['commit_n']);

        foreach ($hooks as $hook) {
            $hook();
        }
    }

    /** True while the outermost open transaction was opened by somebody else. */
    public function in_foreign_transaction(): bool
    {
        return $this->foreign_transaction_flag;
    }

    /** How many transactions are open, counting nested ones. */
    public function transaction_depth(): int
    {
        return $this->transaction_depth;
    }

    /**
     * Run `$hook` once the transaction open right now actually commits —
     * the *outermost* one, or the enclosing level a nested savepoint is
     * part of, since only that makes anything durable (see `commit()`'s
     * own docblock: releasing a savepoint is not a commit).
     *
     *     $dm->transaction(function ($dm) use ($order) {
     *         $dm->insert($orders)->values([...])->execute();
     *         $dm->on_commit(fn () => $mailer->send_confirmation($order));
     *     });
     *
     * Exists for exactly the case above: an `after_insert` hook
     * (`DataManager::on()`) fires the instant its `INSERT` runs, whether or
     * not the transaction around it goes on to commit — the wrong moment
     * for a side effect the rest of the world will see, like sending an
     * email, that a later rollback cannot take back. `on_commit()` waits
     * for the fact that actually matters.
     *
     * Fires **immediately, synchronously**, right here, when no transaction
     * is open at all: outside `transaction()`/`begin_transaction()` every
     * statement commits on its own, so there is nothing to wait for.
     *
     * In a *foreign* transaction (`in_foreign_transaction()` — this object
     * did not open the outermost `BEGIN`) this fires when this object's own
     * part is done, which is only as durable as whatever opened the
     * enclosing transaction: if that later rolls back, this will already
     * have fired for work that did not survive. There is no way to observe
     * a transaction this object does not own; a caller that owns the
     * foreign transaction and needs this guarantee should hold its own
     * commit hook, not rely on this one.
     */
    public function on_commit(callable $hook): self
    {
        if ($this->transaction_depth === 0) {
            $hook();

            return $this;
        }

        $this->commit_hooks[] = $hook;

        return $this;
    }

    /**
     * Run `$hook` if the transaction level open right now rolls back —
     * whichever level that turns out to be, nested savepoint or outermost.
     * Unlike `on_commit()`, a nested rollback resolves this immediately: if
     * the specific savepoint this was registered under is undone, that
     * outcome is already decided regardless of what an enclosing
     * transaction goes on to do — see `resolve_rollback_hooks()`.
     *
     * A no-op — never firing — when called with no transaction open: there
     * is nothing that could roll back.
     */
    public function on_rollback(callable $hook): self
    {
        if ($this->transaction_depth === 0) {
            return $this;
        }

        $this->rollback_hooks[] = $hook;

        return $this;
    }

    /**
     * Execute a callback within a transaction, nesting safely.
     *
     *     $dm->transaction(function ($dm) use ($order) {
     *         $dm->insert(...);
     *
     *         // a helper that opens its own transaction — and does not have to
     *         // know whether it was called inside one
     *         $this->audit->record($dm, $order);
     *     });
     *
     * That composability is the point. A method that needs a transaction should
     * be able to ask for one without first asking whether it is already in one,
     * because the answer depends on its callers and it does not know them.
     *
     * `Throwable` rather than `Exception`: a `TypeError` inside the callback is
     * every bit as much a reason not to keep the work.
     *
     * @param  callable $callback
     * @return mixed Result of callback
     */
    public function transaction(callable $callback)
    {
        $this->begin_transaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            // The rollback may itself fail — a connection that has gone away,
            // or MySQL having implicitly committed on a DDL statement. Letting
            // that exception escape would replace the real cause with a
            // secondary one, and the real cause is what the caller needs.
            try {
                $this->rollback();
            } catch (\Throwable $ignored) {
                // Genuinely unknown state — the connection may be gone, so
                // there is no way to tell which hooks, if any, describe
                // what actually happened. Discarded rather than guessed at,
                // and reset alongside the depth counter so nothing queued
                // here leaks into whatever transaction this object is asked
                // to run next.
                $this->transaction_depth = 0;
                $this->commit_hooks      = [];
                $this->rollback_hooks    = [];
                $this->hook_marks        = [];
            }

            throw $e;
        }
    }

    /**
     * The identifier for the savepoint at a given depth.
     *
     * Generated, never taken from a caller: a savepoint name is an identifier
     * and cannot be a bound parameter, so anything user-supplied here would be
     * string-concatenated straight into SQL.
     */
    protected function savepoint_name(int $depth): string
    {
        return 'ix_sp_' . $depth;
    }

    /**
     * Get the last inserted ID
     */
    public function last_insert_id(?string $name = null): string
    {
        return $this->driver->last_insert_id($name);
    }

    /**
     * Create a custom SQL query with safe parameter binding
     *
     * This method provides a way to write custom SQL while maintaining
     * protection against SQL injection through parameterized queries.
     *
     * Usage:
     *   // Simple query with parameters
     *   $dm->sql('SELECT * FROM users WHERE id = ?', [$userId])->all();
     *
     *   // Multiple parameters
     *   $dm->sql('SELECT * FROM users WHERE status = ? AND age > ?', ['active', 18])->all();
     *
     *   // Fluent builder
     *   $dm->sql()
     *      ->append('SELECT * FROM ')
     *      ->identifier('users')
     *      ->append(' WHERE id = ')
     *      ->value($userId)
     *      ->all();
     *
     * @param string $query SQL query with ? placeholders (optional)
     * @param array $params Parameter bindings (optional)
     * @return Sql
     */
    public function sql(string $query = '', array $params = []): Sql
    {
        $sql = new Sql($query, $params);
        $sql->set_connection($this->driver->get_connection());
        $sql->set_dialect($this->driver->get_dialect_name());
        return $sql;
    }

    // ============================================
    // Relational Query Methods (Drizzle-style)
    // ============================================

    /**
     * Get the relational query builder
     *
     * @return RelationalQueryBuilder
     */
    protected function get_relational_builder(): RelationalQueryBuilder
    {
        if ($this->relational_builder === null) {
            $this->relational_builder = new RelationalQueryBuilder(
                $this->driver->get_connection(),
                $this->driver->get_dialect_name()
            );
        }
        return $this->relational_builder;
    }

    /**
     * Start a relational query for a table (Drizzle-style)
     *
     * This method provides Drizzle-style querying with eager loading support.
     *
     * Usage:
     *   // Find many with relations
     *   $users = $dm->query($users_table)
     *       ->with(['posts' => true, 'profile' => true])
     *       ->find_many();
     *
     *   // Find first with nested relations
     *   $user = $dm->query($users_table)
     *       ->with([
     *           'posts' => [
     *               'with' => ['comments' => true]
     *           ]
     *       ])
     *       ->where(eq($users_table->id, 1))
     *       ->find_first();
     *
     *   // Find by ID
     *   $user = $dm->query($users_table)
     *       ->with(['posts' => true])
     *       ->find(1);
     *
     * @param Table $table The table to query
     * @return TableQuery
     */
    public function query_table(Table $table): TableQuery
    {
        // TableQuery has no persistent template to inherit config from the
        // way QueryBuilder does (get_relational_builder()->query() hands
        // back a brand new instance every call) — so global scopes are
        // threaded on explicitly, here, rather than once at construction.
        return $this->get_relational_builder()->query($table)->set_scopes($this->scopes);
    }

    /**
     * Find many records with optional eager loading
     *
     * Shorthand for: $dm->query($table)->with($relations)->find_many()
     *
     * @param Table $table The table to query
     * @param array $options Options: 'with', 'where', 'order_by', 'limit', 'offset', 'columns'
     * @return array<array>
     */
    public function find_many(Table $table, array $options = []): array
    {
        $query = $this->query_table($table);

        if (isset($options['columns'])) {
            $query = $query->columns($options['columns']);
        }

        if (isset($options['where'])) {
            $query = $query->where($options['where']);
        }

        if (isset($options['order_by'])) {
            $order_by = is_array($options['order_by']) ? $options['order_by'] : [$options['order_by']];
            $query = $query->order_by(...$order_by);
        }

        if (isset($options['limit'])) {
            $query = $query->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $query = $query->offset($options['offset']);
        }

        if (isset($options['with'])) {
            $query = $query->with($options['with']);
        }

        return $query->find_many();
    }

    /**
     * Find the first matching record with optional eager loading
     *
     * Shorthand for: $dm->query($table)->with($relations)->find_first()
     *
     * @param Table $table The table to query
     * @param array $options Options: 'with', 'where', 'order_by', 'columns'
     * @return array|null
     */
    public function find_first(Table $table, array $options = []): ?array
    {
        $query = $this->query_table($table);

        if (isset($options['columns'])) {
            $query = $query->columns($options['columns']);
        }

        if (isset($options['where'])) {
            $query = $query->where($options['where']);
        }

        if (isset($options['order_by'])) {
            $order_by = is_array($options['order_by']) ? $options['order_by'] : [$options['order_by']];
            $query = $query->order_by(...$order_by);
        }

        if (isset($options['with'])) {
            $query = $query->with($options['with']);
        }

        return $query->find_first();
    }

    /**
     * Alias for find_first()
     *
     * @param Table $table The table to query
     * @param array $options Options: 'with', 'where', 'order_by', 'columns'
     * @return array|null
     */
    public function find_one(Table $table, array $options = []): ?array
    {
        return $this->find_first($table, $options);
    }
}
