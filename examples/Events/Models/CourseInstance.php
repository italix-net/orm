<?php
/**
 * CourseInstance - Second-level delegate under Event
 *
 * Represents a single session of a Course.
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

class CourseInstance extends ActiveRow
{
    use Persistable;

    protected ?Event $event_cache = null;
    protected ?Thing $course_cache = null;

    public function event_id(): int
    {
        return (int) $this['event_id'];
    }

    public function event(): Event
    {
        if ($this->event_cache === null) {
            $this->event_cache = Event::find($this['event_id']);
        }
        return $this->event_cache;
    }

    public function set_event(Event $event): static
    {
        $this->event_cache = $event;
        return $this;
    }

    public function thing(): Thing
    {
        return $this->event()->thing();
    }

    // =========================================
    // COURSE INSTANCE SPECIFIC
    // =========================================

    /**
     * Get the parent Course (Thing)
     */
    public function course(): ?Thing
    {
        if (empty($this['course_id'])) {
            return null;
        }
        if ($this->course_cache === null) {
            $this->course_cache = Thing::find_with_delegate($this['course_id']);
        }
        return $this->course_cache;
    }

    /**
     * Set the course
     */
    public function set_course(Thing $course): static
    {
        $this['course_id'] = $course['id'];
        $this->course_cache = $course;
        return $this;
    }

    public function session_number(): ?int
    {
        return $this['session_number'] ? (int) $this['session_number'] : null;
    }

    /**
     * Get a display label like "Introduction to Tango - Session 3/10"
     */
    public function label(): string
    {
        $name = $this->thing()['name'];
        $num = $this->session_number();
        if ($num === null) {
            return $name;
        }

        $course = $this->course();
        if ($course) {
            $course_delegate = $course->delegate();
            $total = $course_delegate ? $course_delegate->total_sessions() : null;
            if ($total) {
                return $name . " - Session {$num}/{$total}";
            }
        }

        return $name . " - Session {$num}";
    }
}
