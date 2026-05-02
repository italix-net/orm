<?php
/**
 * Course - Delegate class for Course-type Things
 *
 * Represents a course series (e.g., "Introduction to Tango").
 * Individual sessions are CourseInstance events.
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

use function Italix\Orm\Operators\eq;

class Course extends ActiveRow
{
    use Persistable;

    protected ?Thing $thing_cache = null;

    public function thing_id(): int
    {
        return (int) $this['thing_id'];
    }

    public function thing(): Thing
    {
        if ($this->thing_cache === null) {
            $this->thing_cache = Thing::find($this['thing_id']);
        }
        return $this->thing_cache;
    }

    public function set_thing(Thing $thing): static
    {
        $this->thing_cache = $thing;
        return $this;
    }

    // =========================================
    // COURSE-SPECIFIC METHODS
    // =========================================

    public function course_code(): ?string
    {
        return $this['course_code'];
    }

    public function course_level(): ?string
    {
        return $this['course_level'];
    }

    public function total_sessions(): ?int
    {
        return $this['total_sessions'] ? (int) $this['total_sessions'] : null;
    }

    /**
     * Get all CourseInstance events for this course
     *
     * @return array<CourseInstance>
     */
    public function instances(): array
    {
        $table = CourseInstance::get_table();
        return CourseInstance::find_all([
            'where' => eq($table->course_id, $this->thing_id()),
            'order_by' => 'session_number',
        ]);
    }

    /**
     * Get a formatted label like "TANGO-101 (Beginner)"
     */
    public function label(): string
    {
        $code = $this['course_code'] ?? '';
        $level = $this['course_level'] ?? '';

        if ($code && $level) {
            return $code . ' (' . $level . ')';
        }
        return $code ?: $this->thing()['name'];
    }
}
