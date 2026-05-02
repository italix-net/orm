<?php
/**
 * Thing - Base class for all Schema.org entities
 *
 * Uses DelegatedTypes with two-level delegation:
 * - Thing → Event → DanceEvent/MusicEvent/CourseInstance
 * - Thing → Place → LocalBusiness
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\HasTimestamps;
use Italix\Orm\ActiveRow\Traits\DelegatedTypes;

class Thing extends ActiveRow
{
    use Persistable, HasTimestamps, DelegatedTypes;

    /**
     * Define the delegated types.
     *
     * For two-level delegates (DanceEvent, MusicEvent, CourseInstance, LocalBusiness),
     * the first-level delegate class is used. The sub-delegate is loaded through
     * the first-level delegate's sub_delegate() method.
     *
     * @return array<string, class-string<ActiveRow>>
     */
    protected function get_delegated_types(): array
    {
        return [
            'Person'         => Person::class,
            'Organization'   => Organization::class,
            'Place'          => Place::class,
            'LocalBusiness'  => Place::class,
            'Course'         => Course::class,
            'Event'          => Event::class,
            'DanceEvent'     => Event::class,
            'MusicEvent'     => Event::class,
            'CourseInstance'  => Event::class,
        ];
    }

    protected function before_save_uuid(): void
    {
        if (empty($this->data['uuid'])) {
            $this->data['uuid'] = $this->generate_uuid();
        }
    }

    protected function before_save_type_path(): void
    {
        if (empty($this->data['type_path']) && !empty($this->data['type'])) {
            $this->data['type_path'] = $this->get_default_type_path($this->data['type']);
        }
    }

    protected function before_save_flags(): void
    {
        $type = $this->data['type'] ?? '';
        $event_types = ['Event', 'DanceEvent', 'MusicEvent', 'CourseInstance'];
        $agent_types = ['Person', 'Organization'];

        $this->data['is_event'] = in_array($type, $event_types);
        $this->data['is_agent'] = in_array($type, $agent_types);
    }

    protected function get_default_type_path(string $type): string
    {
        $paths = [
            'Person'         => 'Thing/Person',
            'Organization'   => 'Thing/Organization',
            'Place'          => 'Thing/Place',
            'LocalBusiness'  => 'Thing/Place/LocalBusiness',
            'Course'         => 'Thing/Course',
            'Event'          => 'Thing/Event',
            'DanceEvent'     => 'Thing/Event/DanceEvent',
            'MusicEvent'     => 'Thing/Event/MusicEvent',
            'CourseInstance'  => 'Thing/Event/CourseInstance',
        ];

        return $paths[$type] ?? "Thing/{$type}";
    }

    protected function generate_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // =========================================
    // CONVENIENCE METHODS
    // =========================================

    public function is_event(): bool
    {
        return (bool) ($this['is_event'] ?? false);
    }

    public function is_agent(): bool
    {
        return (bool) ($this['is_agent'] ?? false);
    }

    public function uuid(): ?string
    {
        return $this['uuid'];
    }

    /**
     * Get the sub-delegate (second level) if any.
     * For DanceEvent: Thing->delegate() = Event, Event->sub_delegate() = DanceEvent
     *
     * @return ActiveRow|null
     */
    public function sub_delegate(): ?ActiveRow
    {
        $delegate = $this->delegate();
        if ($delegate && method_exists($delegate, 'sub_delegate')) {
            return $delegate->sub_delegate();
        }
        return null;
    }

    // =========================================
    // STATIC FACTORY METHODS
    // =========================================

    public static function create_person(array $thing_data, array $person_data = []): static
    {
        return static::create_with_delegate('Person', $thing_data, $person_data);
    }

    public static function create_organization(array $thing_data, array $org_data = []): static
    {
        return static::create_with_delegate('Organization', $thing_data, $org_data);
    }

    public static function create_place(array $thing_data, array $place_data = []): static
    {
        return static::create_with_delegate('Place', $thing_data, $place_data);
    }

    /**
     * Create a LocalBusiness (two-level: Thing + Place + LocalBusiness)
     */
    public static function create_local_business(array $thing_data, array $place_data = [], array $business_data = []): static
    {
        $thing = static::create_with_delegate('LocalBusiness', $thing_data, $place_data);
        $place = $thing->delegate();
        LocalBusiness::create(array_merge($business_data, [
            'place_id' => $place['id'],
        ]));
        return $thing;
    }

    public static function create_course(array $thing_data, array $course_data = []): static
    {
        return static::create_with_delegate('Course', $thing_data, $course_data);
    }

    public static function create_event(array $thing_data, array $event_data = []): static
    {
        return static::create_with_delegate('Event', $thing_data, $event_data);
    }

    /**
     * Create a DanceEvent (two-level: Thing + Event + DanceEvent)
     */
    public static function create_dance_event(array $thing_data, array $event_data = [], array $dance_data = []): static
    {
        $thing = static::create_with_delegate('DanceEvent', $thing_data, $event_data);
        $event = $thing->delegate();
        DanceEvent::create(array_merge($dance_data, [
            'event_id' => $event['id'],
        ]));
        return $thing;
    }

    /**
     * Create a MusicEvent (two-level: Thing + Event + MusicEvent)
     */
    public static function create_music_event(array $thing_data, array $event_data = [], array $music_data = []): static
    {
        $thing = static::create_with_delegate('MusicEvent', $thing_data, $event_data);
        $event = $thing->delegate();
        MusicEvent::create(array_merge($music_data, [
            'event_id' => $event['id'],
        ]));
        return $thing;
    }

    /**
     * Create a CourseInstance (two-level: Thing + Event + CourseInstance)
     */
    public static function create_course_instance(array $thing_data, array $event_data = [], array $instance_data = []): static
    {
        $thing = static::create_with_delegate('CourseInstance', $thing_data, $event_data);
        $event = $thing->delegate();
        CourseInstance::create(array_merge($instance_data, [
            'event_id' => $event['id'],
        ]));
        return $thing;
    }

    // =========================================
    // QUERY HELPERS
    // =========================================

    /**
     * Find all events (Event, DanceEvent, MusicEvent, CourseInstance)
     */
    public static function find_events(array $options = []): array
    {
        $table = static::get_table();
        $options['where'] = isset($options['where'])
            ? \Italix\Orm\Operators\and_($options['where'], \Italix\Orm\Operators\eq($table->is_event, 1))
            : \Italix\Orm\Operators\eq($table->is_event, 1);

        return static::find_with_delegates($options);
    }

    /**
     * Find all agents (persons and organizations)
     */
    public static function find_agents(array $options = []): array
    {
        $table = static::get_table();
        $options['where'] = isset($options['where'])
            ? \Italix\Orm\Operators\and_($options['where'], \Italix\Orm\Operators\eq($table->is_agent, 1))
            : \Italix\Orm\Operators\eq($table->is_agent, 1);

        return static::find_with_delegates($options);
    }

    /**
     * Find things by type
     */
    public static function find_by_type(string $type, array $options = []): array
    {
        $table = static::get_table();
        $options['where'] = isset($options['where'])
            ? \Italix\Orm\Operators\and_($options['where'], \Italix\Orm\Operators\eq($table->type, $type))
            : \Italix\Orm\Operators\eq($table->type, $type);

        return static::find_with_delegates($options);
    }
}
