<?php
/**
 * Event - First-level delegate for event-type Things
 *
 * Holds common event properties. Event subtypes (DanceEvent, MusicEvent,
 * CourseInstance) are second-level delegates loaded via sub_delegate().
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Examples\Events\Traits\EventBehavior;

use function Italix\Orm\Operators\eq;

class Event extends ActiveRow
{
    use Persistable, EventBehavior;

    protected ?Thing $thing_cache = null;
    protected ?Thing $location_cache = null;

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
    // SUB-DELEGATION
    // =========================================

    /**
     * Get the sub-delegate (DanceEvent, MusicEvent, CourseInstance)
     */
    public function sub_delegate(): ?ActiveRow
    {
        $thing = $this->thing();
        $type = $thing['type'];

        $sub_types = [
            'DanceEvent'     => DanceEvent::class,
            'MusicEvent'     => MusicEvent::class,
            'CourseInstance'  => CourseInstance::class,
        ];

        if (!isset($sub_types[$type])) {
            return null;
        }

        $class = $sub_types[$type];
        $table = $class::get_table();
        return $class::find_one([
            'where' => eq($table->event_id, $this['id']),
        ]);
    }

    // =========================================
    // DATE/TIME METHODS
    // =========================================

    public function start_date(): ?\DateTime
    {
        if (empty($this['start_date'])) {
            return null;
        }
        return new \DateTime($this['start_date']);
    }

    public function end_date(): ?\DateTime
    {
        if (empty($this['end_date'])) {
            return null;
        }
        return new \DateTime($this['end_date']);
    }

    public function duration(): ?int
    {
        return $this['duration'] ? (int) $this['duration'] : null;
    }

    /**
     * Get formatted duration (e.g., "2h 30m")
     */
    public function formatted_duration(): ?string
    {
        $minutes = $this->duration();
        if ($minutes === null) {
            return null;
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $mins);
        }
        return sprintf('%dm', $mins);
    }

    /**
     * Get formatted date range (e.g., "15 Jun 2026, 20:00 - 23:00")
     */
    public function formatted_date_range(): ?string
    {
        $start = $this->start_date();
        if ($start === null) {
            return null;
        }

        $end = $this->end_date();
        if ($end === null) {
            return $start->format('j M Y, H:i');
        }

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('j M Y, H:i') . ' - ' . $end->format('H:i');
        }

        return $start->format('j M Y, H:i') . ' - ' . $end->format('j M Y, H:i');
    }

    // =========================================
    // STATUS & ATTENDANCE
    // =========================================

    public function event_status(): ?string
    {
        return $this['event_status'];
    }

    public function is_scheduled(): bool
    {
        return ($this['event_status'] ?? 'EventScheduled') === 'EventScheduled';
    }

    public function is_cancelled(): bool
    {
        return $this['event_status'] === 'EventCancelled';
    }

    public function event_attendance_mode(): ?string
    {
        return $this['event_attendance_mode'];
    }

    public function is_free(): bool
    {
        return (bool) ($this['is_free'] ?? false);
    }

    public function max_attendees(): ?int
    {
        return $this['max_attendees'] ? (int) $this['max_attendees'] : null;
    }

    public function in_language(): ?string
    {
        return $this['in_language'];
    }

    // =========================================
    // LOCATION
    // =========================================

    /**
     * Get the event location (a Place Thing)
     */
    public function location(): ?Thing
    {
        if (empty($this['location_id'])) {
            return null;
        }
        if ($this->location_cache === null) {
            $this->location_cache = Thing::find_with_delegate($this['location_id']);
        }
        return $this->location_cache;
    }

    /**
     * Set the event location
     */
    public function set_location(Thing $place): static
    {
        $this['location_id'] = $place['id'];
        $this->location_cache = $place;
        return $this;
    }

    /**
     * Get a one-line location string
     */
    public function location_name(): ?string
    {
        $location = $this->location();
        return $location ? $location['name'] : null;
    }
}
