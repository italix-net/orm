<?php
/**
 * EventBehavior Trait
 *
 * Shared behavior for all event types (Event, DanceEvent, MusicEvent, CourseInstance).
 * Provides methods for working with participants (organizers, performers, etc.)
 */

namespace Examples\Events\Traits;

use Examples\Events\Models\Participation;
use Examples\Events\Models\Thing;

use function Italix\Orm\Operators\eq;
use function Italix\Orm\Operators\and_;

trait EventBehavior
{
    abstract public function thing_id(): int;

    abstract public function thing(): Thing;

    // =========================================
    // PARTICIPANT ACCESS
    // =========================================

    /**
     * Get all participants by role
     *
     * @param string $role
     * @return array<Thing>
     */
    public function participants_by_role(string $role): array
    {
        $table = Participation::get_table();
        $participations = Participation::find_all([
            'where' => and_(
                eq($table->thing_id, $this->thing_id()),
                eq($table->role, $role)
            ),
            'order_by' => 'position',
        ]);

        return array_map(fn($p) => $p->agent(), $participations);
    }

    /**
     * Get organizers
     *
     * @return array<Thing>
     */
    public function organizers(): array
    {
        return $this->participants_by_role('organizer');
    }

    /**
     * Get performers
     *
     * @return array<Thing>
     */
    public function performers(): array
    {
        return $this->participants_by_role('performer');
    }

    /**
     * Get instructors
     *
     * @return array<Thing>
     */
    public function instructors(): array
    {
        return $this->participants_by_role('instructor');
    }

    // =========================================
    // PARTICIPANT MANAGEMENT
    // =========================================

    /**
     * Add a participant with a specific role
     *
     * @param Thing $agent Person or Organization
     * @param string $role Role name
     * @param int $position Position in ordering
     * @param string|null $billing Billing info (headline, support, etc.)
     * @return Participation
     */
    public function add_participant(Thing $agent, string $role, int $position = 0, ?string $billing = null): Participation
    {
        $data = [
            'thing_id' => $this->thing_id(),
            'agent_id' => $agent['id'],
            'role'     => $role,
            'position' => $position,
        ];

        if ($billing !== null) {
            $data['billing'] = $billing;
        }

        return Participation::create($data);
    }

    /**
     * Add an organizer
     *
     * @param Thing $agent
     * @param int $position
     * @return Participation
     */
    public function add_organizer(Thing $agent, int $position = 0): Participation
    {
        return $this->add_participant($agent, 'organizer', $position);
    }

    /**
     * Add a performer
     *
     * @param Thing $agent
     * @param int $position
     * @param string|null $billing
     * @return Participation
     */
    public function add_performer(Thing $agent, int $position = 0, ?string $billing = null): Participation
    {
        return $this->add_participant($agent, 'performer', $position, $billing);
    }

    /**
     * Add an instructor
     *
     * @param Thing $agent
     * @param int $position
     * @return Participation
     */
    public function add_instructor(Thing $agent, int $position = 0): Participation
    {
        return $this->add_participant($agent, 'instructor', $position);
    }

    /**
     * Remove all participants with a specific role
     *
     * @param string $role
     * @return void
     */
    public function clear_participants(string $role): void
    {
        $table = Participation::get_table();
        $participations = Participation::find_all([
            'where' => and_(
                eq($table->thing_id, $this->thing_id()),
                eq($table->role, $role)
            ),
        ]);

        foreach ($participations as $participation) {
            $participation->delete();
        }
    }

    // =========================================
    // FORMATTING
    // =========================================

    /**
     * Get participant names as a formatted string
     *
     * @param string $role
     * @param string $separator
     * @return string
     */
    public function participants_string(string $role, string $separator = ', '): string
    {
        $participants = $this->participants_by_role($role);
        $names = array_map(function ($agent) {
            $delegate = $agent->delegate();
            if ($delegate && method_exists($delegate, 'display_name')) {
                return $delegate->display_name();
            }
            return $agent['name'];
        }, $participants);

        return implode($separator, $names);
    }

    /**
     * Get organizer names as string
     *
     * @param string $separator
     * @return string
     */
    public function organizers_string(string $separator = ', '): string
    {
        return $this->participants_string('organizer', $separator);
    }

    /**
     * Get performer names as string
     *
     * @param string $separator
     * @return string
     */
    public function performers_string(string $separator = ', '): string
    {
        return $this->participants_string('performer', $separator);
    }
}
