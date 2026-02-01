<?php
/**
 * Person Model (Delegate)
 *
 * Represents an individual customer (natural person).
 * Delegated from Customer for Person-specific attributes.
 *
 * @see https://schema.org/Person
 */

namespace Examples\Ecommerce\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

class Person extends ActiveRow
{
    use Persistable;

    // =========================================
    // NAME FORMATTING
    // =========================================

    /**
     * Get given (first) name
     *
     * @return string|null
     */
    public function given_name(): ?string
    {
        return $this['given_name'];
    }

    /**
     * Get family (last) name
     *
     * @return string|null
     */
    public function family_name(): ?string
    {
        return $this['family_name'];
    }

    /**
     * Get additional (middle) name
     *
     * @return string|null
     */
    public function additional_name(): ?string
    {
        return $this['additional_name'];
    }

    /**
     * Get honorific prefix (Mr., Mrs., Dr., etc.)
     *
     * @return string|null
     */
    public function honorific_prefix(): ?string
    {
        return $this['honorific_prefix'];
    }

    /**
     * Get honorific suffix (Jr., III, PhD, etc.)
     *
     * @return string|null
     */
    public function honorific_suffix(): ?string
    {
        return $this['honorific_suffix'];
    }

    /**
     * Get full display name
     *
     * @return string
     */
    public function display_name(): string
    {
        $parts = array_filter([
            $this['honorific_prefix'],
            $this['given_name'],
            $this['additional_name'],
            $this['family_name'],
            $this['honorific_suffix'],
        ]);

        return implode(' ', $parts) ?: 'Unknown Person';
    }

    /**
     * Get formal name (family name, given name)
     *
     * @return string
     */
    public function formal_name(): string
    {
        $family = $this['family_name'] ?? '';
        $given = $this['given_name'] ?? '';

        if ($family && $given) {
            return "{$family}, {$given}";
        }

        return $this->display_name();
    }

    /**
     * Get initials
     *
     * @return string
     */
    public function initials(): string
    {
        $initials = '';

        if ($this['given_name']) {
            $initials .= strtoupper(substr($this['given_name'], 0, 1));
        }

        if ($this['additional_name']) {
            $initials .= strtoupper(substr($this['additional_name'], 0, 1));
        }

        if ($this['family_name']) {
            $initials .= strtoupper(substr($this['family_name'], 0, 1));
        }

        return $initials;
    }

    // =========================================
    // PERSONAL INFO
    // =========================================

    /**
     * Get tax ID (fiscal code, SSN, etc.)
     *
     * @return string|null
     */
    public function tax_id(): ?string
    {
        return $this['tax_id'];
    }

    /**
     * Get birth date
     *
     * @return string|null
     */
    public function birth_date(): ?string
    {
        return $this['birth_date'];
    }

    /**
     * Get gender
     *
     * @return string|null
     */
    public function gender(): ?string
    {
        return $this['gender'];
    }

    /**
     * Calculate age (if birth date is set)
     *
     * @return int|null
     */
    public function age(): ?int
    {
        if (!$this['birth_date']) {
            return null;
        }

        try {
            $birth = new \DateTime($this['birth_date']);
            $now = new \DateTime();
            return (int) $now->diff($birth)->y;
        } catch (\Exception $e) {
            return null;
        }
    }

    // =========================================
    // SCHEMA.ORG JSON-LD
    // =========================================

    /**
     * Generate Schema.org JSON-LD for this person
     *
     * @param Customer $customer The parent Customer
     * @return array
     */
    public function to_schema_org(Customer $customer): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
        ];

        if ($this['given_name']) {
            $schema['givenName'] = $this['given_name'];
        }

        if ($this['family_name']) {
            $schema['familyName'] = $this['family_name'];
        }

        if ($this['additional_name']) {
            $schema['additionalName'] = $this['additional_name'];
        }

        if ($this['honorific_prefix']) {
            $schema['honorificPrefix'] = $this['honorific_prefix'];
        }

        if ($this['honorific_suffix']) {
            $schema['honorificSuffix'] = $this['honorific_suffix'];
        }

        // Full name
        $schema['name'] = $this->display_name();

        // From Customer base
        if ($customer['email']) {
            $schema['email'] = $customer['email'];
        }

        if ($customer['telephone']) {
            $schema['telephone'] = $customer['telephone'];
        }

        if ($this['tax_id']) {
            $schema['taxID'] = $this['tax_id'];
        }

        if ($this['birth_date']) {
            $schema['birthDate'] = $this['birth_date'];
        }

        if ($this['gender']) {
            $schema['gender'] = $this['gender'];
        }

        return $schema;
    }
}
