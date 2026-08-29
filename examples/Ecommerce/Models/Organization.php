<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Organization Model (Delegate)
 *
 * Represents a business/organization customer.
 * Delegated from Customer for Organization-specific attributes.
 *
 * @see https://schema.org/Organization
 */

namespace Examples\Ecommerce\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;

class Organization extends ActiveRow
{
    use Persistable;

    // =========================================
    // NAME
    // =========================================

    /**
     * Get legal name (official registered name)
     *
     * @return string|null
     */
    public function legal_name(): ?string
    {
        return $this['legal_name'];
    }

    /**
     * Get trading name (DBA / trading as)
     *
     * @return string|null
     */
    public function trading_name(): ?string
    {
        return $this['trading_name'];
    }

    /**
     * Get display name (trading name or legal name)
     *
     * @return string
     */
    public function display_name(): string
    {
        return $this['trading_name'] ?? $this['legal_name'] ?? 'Unknown Organization';
    }

    // =========================================
    // TAX/LEGAL IDENTIFIERS
    // =========================================

    /**
     * Get VAT ID (EU VAT number)
     *
     * @return string|null
     */
    public function vat_id(): ?string
    {
        return $this['vat_id'];
    }

    /**
     * Get Tax ID (EIN, tax number)
     *
     * @return string|null
     */
    public function tax_id(): ?string
    {
        return $this['tax_id'];
    }

    /**
     * Get D-U-N-S number
     *
     * @return string|null
     */
    public function duns_number(): ?string
    {
        return $this['duns_number'];
    }

    /**
     * Get LEI code (Legal Entity Identifier)
     *
     * @return string|null
     */
    public function lei_code(): ?string
    {
        return $this['lei_code'];
    }

    /**
     * Check if organization has a valid VAT ID
     *
     * @return bool
     */
    public function has_vat(): bool
    {
        return !empty($this['vat_id']);
    }

    /**
     * Get the country code from VAT ID (for EU VAT numbers)
     *
     * @return string|null Two-letter country code or null
     */
    public function vat_country(): ?string
    {
        $vat = $this['vat_id'] ?? '';
        if (strlen($vat) >= 2 && ctype_alpha(substr($vat, 0, 2))) {
            return strtoupper(substr($vat, 0, 2));
        }
        return null;
    }

    // =========================================
    // ORGANIZATION DETAILS
    // =========================================

    /**
     * Get founding date
     *
     * @return string|null
     */
    public function founding_date(): ?string
    {
        return $this['founding_date'];
    }

    /**
     * Get number of employees
     *
     * @return int|null
     */
    public function number_of_employees(): ?int
    {
        return $this['number_of_employees'] !== null
            ? (int) $this['number_of_employees']
            : null;
    }

    /**
     * Get company size category
     *
     * @return string
     */
    public function company_size(): string
    {
        $employees = $this->number_of_employees();

        if ($employees === null) {
            return 'Unknown';
        }

        if ($employees <= 10) {
            return 'Micro';
        } elseif ($employees <= 50) {
            return 'Small';
        } elseif ($employees <= 250) {
            return 'Medium';
        } else {
            return 'Large';
        }
    }

    // =========================================
    // CONTACT PERSON
    // =========================================

    /**
     * Get contact person name
     *
     * @return string|null
     */
    public function contact_name(): ?string
    {
        return $this['contact_name'];
    }

    /**
     * Get contact person email
     *
     * @return string|null
     */
    public function contact_email(): ?string
    {
        return $this['contact_email'];
    }

    /**
     * Get contact person phone
     *
     * @return string|null
     */
    public function contact_phone(): ?string
    {
        return $this['contact_phone'];
    }

    /**
     * Check if organization has a contact person defined
     *
     * @return bool
     */
    public function has_contact_person(): bool
    {
        return !empty($this['contact_name']) || !empty($this['contact_email']);
    }

    // =========================================
    // SCHEMA.ORG JSON-LD
    // =========================================

    /**
     * Generate Schema.org JSON-LD for this organization
     *
     * @param Customer $customer The parent Customer
     * @return array
     */
    public function to_schema_org(Customer $customer): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
        ];

        // Names
        if ($this['legal_name']) {
            $schema['legalName'] = $this['legal_name'];
        }

        $schema['name'] = $this->display_name();

        // Identifiers
        if ($this['vat_id']) {
            $schema['vatID'] = $this['vat_id'];
        }

        if ($this['tax_id']) {
            $schema['taxID'] = $this['tax_id'];
        }

        if ($this['duns_number']) {
            $schema['duns'] = $this['duns_number'];
        }

        if ($this['lei_code']) {
            $schema['leiCode'] = $this['lei_code'];
        }

        // Organization details
        if ($this['founding_date']) {
            $schema['foundingDate'] = $this['founding_date'];
        }

        if ($this['number_of_employees']) {
            $schema['numberOfEmployees'] = [
                '@type' => 'QuantitativeValue',
                'value' => (int) $this['number_of_employees'],
            ];
        }

        // Contact from Customer base
        if ($customer['email']) {
            $schema['email'] = $customer['email'];
        }

        if ($customer['telephone']) {
            $schema['telephone'] = $customer['telephone'];
        }

        // Contact person
        if ($this->has_contact_person()) {
            $schema['contactPoint'] = [
                '@type' => 'ContactPoint',
                'contactType' => 'customer service',
            ];

            if ($this['contact_name']) {
                $schema['contactPoint']['name'] = $this['contact_name'];
            }

            if ($this['contact_email']) {
                $schema['contactPoint']['email'] = $this['contact_email'];
            }

            if ($this['contact_phone']) {
                $schema['contactPoint']['telephone'] = $this['contact_phone'];
            }
        }

        return $schema;
    }
}
