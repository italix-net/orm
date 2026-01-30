<?php
/**
 * Movie - Delegate class for Movie-type Things
 *
 * Represents movie-specific attributes and behaviors.
 */

namespace Examples\DelegatedTypes\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Examples\DelegatedTypes\Traits\CreativeWorkBehavior;

class Movie extends ActiveRow
{
    use Persistable, CreativeWorkBehavior;

    /**
     * Cache for parent Thing
     * @var Thing|null
     */
    protected ?Thing $thing_cache = null;

    /**
     * Get the thing_id (foreign key)
     *
     * @return int
     */
    public function thing_id(): int
    {
        return (int) $this['thing_id'];
    }

    /**
     * Get the parent Thing
     *
     * @return Thing
     */
    public function thing(): Thing
    {
        if ($this->thing_cache === null) {
            $this->thing_cache = Thing::find($this['thing_id']);
        }
        return $this->thing_cache;
    }

    /**
     * Set the cached Thing (for eager loading)
     *
     * @param Thing $thing
     * @return static
     */
    public function set_thing(Thing $thing): static
    {
        $this->thing_cache = $thing;
        return $this;
    }

    // =========================================
    // MOVIE-SPECIFIC METHODS
    // =========================================

    /**
     * Get directors
     *
     * @return array<Thing>
     */
    public function directors(): array
    {
        return $this->contributors_by_role('director');
    }

    /**
     * Get actors/cast
     *
     * @return array<Thing>
     */
    public function cast(): array
    {
        return $this->contributors_by_role('actor');
    }

    /**
     * Get producers
     *
     * @return array<Thing>
     */
    public function producers(): array
    {
        return $this->contributors_by_role('producer');
    }

    /**
     * Add a director
     *
     * @param Thing $person
     * @param int $position
     * @return Contribution
     */
    public function add_director(Thing $person, int $position = 0): Contribution
    {
        return $this->add_contributor($person, 'director', $position);
    }

    /**
     * Add an actor
     *
     * @param Thing $person
     * @param int $position
     * @return Contribution
     */
    public function add_actor(Thing $person, int $position = 0): Contribution
    {
        return $this->add_contributor($person, 'actor', $position);
    }

    /**
     * Get duration in minutes
     *
     * @return int|null
     */
    public function duration(): ?int
    {
        return $this['duration'] ? (int) $this['duration'] : null;
    }

    /**
     * Get formatted duration (e.g., "2h 15m")
     *
     * @return string|null
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
     * Get content rating
     *
     * @return string|null
     */
    public function content_rating(): ?string
    {
        return $this['content_rating'];
    }

    /**
     * Get release date
     *
     * @return \DateTime|null
     */
    public function date_released(): ?\DateTime
    {
        if (empty($this['date_released'])) {
            return null;
        }
        return new \DateTime($this['date_released']);
    }

    /**
     * Get release year
     *
     * @return int|null
     */
    public function year_released(): ?int
    {
        $date = $this->date_released();
        return $date ? (int) $date->format('Y') : null;
    }

    // =========================================
    // FORMATTING
    // =========================================

    /**
     * Get director names as string
     *
     * @param string $separator
     * @return string
     */
    public function directors_string(string $separator = ', '): string
    {
        $directors = $this->directors();
        $names = array_map(function ($director) {
            $delegate = $director->delegate();
            if ($delegate && method_exists($delegate, 'display_name')) {
                return $delegate->display_name();
            }
            return $director['name'];
        }, $directors);

        return implode($separator, $names);
    }

    /**
     * Get a formatted movie title with year
     *
     * @return string
     */
    public function title_with_year(): string
    {
        $thing = $this->thing();
        $year = $this->year_released();

        if ($year) {
            return $thing['name'] . ' (' . $year . ')';
        }
        return $thing['name'];
    }
}
