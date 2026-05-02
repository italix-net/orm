<?php
/**
 * MusicEvent - Second-level delegate under Event
 */

namespace Examples\Events\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

class MusicEvent extends ActiveRow
{
    use Persistable;

    protected ?Event $event_cache = null;

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
    // MUSIC EVENT SPECIFIC
    // =========================================

    public function music_genre(): ?string
    {
        return $this['music_genre'];
    }

    /**
     * Get a display label like "Jazz Night (Jazz)"
     */
    public function label(): string
    {
        $name = $this->thing()['name'];
        $genre = $this['music_genre'];
        return $genre ? $name . ' (' . $genre . ')' : $name;
    }
}
