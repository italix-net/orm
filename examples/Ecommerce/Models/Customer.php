<?php
/**
 * Customer Model (Base Class for Agent)
 *
 * Represents a customer using the Schema.org Agent pattern.
 * Uses DelegatedTypes to delegate to Person or Organization.
 *
 * A customer can be either:
 * - Person: Individual customer (no VAT ID)
 * - Organization: Business customer (has VAT ID)
 *
 * @see https://schema.org/Person
 * @see https://schema.org/Organization
 */

namespace Examples\Ecommerce\Models;

use Italix\Orm\ActiveRow\ActiveRow;
use Italix\Orm\ActiveRow\Traits\Persistable;
use Italix\Orm\ActiveRow\Traits\DelegatedTypes;
use Italix\Orm\ActiveRow\Traits\HasTimestamps;

use function Italix\Orm\Operators\eq;

class Customer extends ActiveRow
{
    use Persistable, DelegatedTypes, HasTimestamps;

    // =========================================
    // DELEGATED TYPES CONFIGURATION
    // =========================================

    /**
     * Map of type names to delegate classes
     */
    protected function get_delegated_types(): array
    {
        return [
            'Person'       => Person::class,
            'Organization' => Organization::class,
        ];
    }

    /**
     * Column that stores the type name
     */
    protected function get_type_column(): string
    {
        return 'type';
    }

    /**
     * Column that stores the hierarchy path
     */
    protected function get_type_path_column(): ?string
    {
        return 'type_path';
    }

    /**
     * Foreign key column in delegate tables
     */
    protected function get_delegate_foreign_key(): string
    {
        return 'customer_id';
    }

    // =========================================
    // FACTORY METHODS
    // =========================================

    /**
     * Create a Person customer
     *
     * @param array $customer_data Base customer data (email, phone, etc.)
     * @param array $person_data Person-specific data (given_name, family_name, etc.)
     * @return static
     */
    public static function create_person(array $customer_data, array $person_data = []): static
    {
        $customer_data['uuid'] = $customer_data['uuid'] ?? self::generate_uuid();
        $customer_data['is_person'] = true;
        $customer_data['is_organization'] = false;
        $customer_data['type_path'] = 'Customer/Person';
        $customer_data['customer_since'] = $customer_data['customer_since'] ?? date('Y-m-d H:i:s');

        return static::create_with_delegate('Person', $customer_data, $person_data);
    }

    /**
     * Create an Organization customer
     *
     * @param array $customer_data Base customer data (email, phone, etc.)
     * @param array $org_data Organization-specific data (legal_name, vat_id, etc.)
     * @return static
     */
    public static function create_organization(array $customer_data, array $org_data = []): static
    {
        $customer_data['uuid'] = $customer_data['uuid'] ?? self::generate_uuid();
        $customer_data['is_person'] = false;
        $customer_data['is_organization'] = true;
        $customer_data['type_path'] = 'Customer/Organization';
        $customer_data['customer_since'] = $customer_data['customer_since'] ?? date('Y-m-d H:i:s');

        return static::create_with_delegate('Organization', $customer_data, $org_data);
    }

    /**
     * Create a customer, auto-detecting type based on VAT ID presence
     *
     * @param array $customer_data Base customer data
     * @param array $delegate_data Delegate-specific data (Person or Organization fields)
     * @return static
     */
    public static function create_auto(array $customer_data, array $delegate_data = []): static
    {
        // If VAT ID is present, create Organization; otherwise Person
        $hasVat = !empty($delegate_data['vat_id']);

        if ($hasVat) {
            return static::create_organization($customer_data, $delegate_data);
        } else {
            return static::create_person($customer_data, $delegate_data);
        }
    }

    // =========================================
    // QUERY HELPERS
    // =========================================

    /**
     * Find customer by email
     *
     * @param string $email
     * @return static|null
     */
    public static function find_by_email(string $email): ?static
    {
        $table = static::get_table();
        $customer = static::find_one([
            'where' => eq($table->email, $email),
        ]);

        if ($customer) {
            // Load delegate
            return static::find_with_delegate($customer['id']);
        }

        return null;
    }

    /**
     * Find customer by customer number
     *
     * @param string $customerNumber
     * @return static|null
     */
    public static function find_by_customer_number(string $customerNumber): ?static
    {
        $table = static::get_table();
        $customer = static::find_one([
            'where' => eq($table->customer_number, $customerNumber),
        ]);

        if ($customer) {
            return static::find_with_delegate($customer['id']);
        }

        return null;
    }

    /**
     * Find all Person customers
     *
     * @return array<static>
     */
    public static function find_persons(): array
    {
        $table = static::get_table();
        return static::find_with_delegates([
            'where' => eq($table->is_person, true),
        ]);
    }

    /**
     * Find all Organization customers
     *
     * @return array<static>
     */
    public static function find_organizations(): array
    {
        $table = static::get_table();
        return static::find_with_delegates([
            'where' => eq($table->is_organization, true),
        ]);
    }

    // =========================================
    // TYPE CHECKING
    // =========================================

    /**
     * Check if this customer is a Person
     */
    public function is_person(): bool
    {
        return (bool) ($this['is_person'] ?? false);
    }

    /**
     * Check if this customer is an Organization
     */
    public function is_organization(): bool
    {
        return (bool) ($this['is_organization'] ?? false);
    }

    // =========================================
    // DISPLAY HELPERS
    // =========================================

    /**
     * Get display name for this customer
     *
     * @return string
     */
    public function display_name(): string
    {
        $delegate = $this->delegate();

        if ($delegate && method_exists($delegate, 'display_name')) {
            return $delegate->display_name();
        }

        return $this['email'] ?? 'Unknown Customer';
    }

    /**
     * Get all orders for this customer
     *
     * @return array<Thing>
     */
    public function orders(): array
    {
        return Thing::find_customer_orders($this);
    }

    /**
     * Get count of orders for this customer
     *
     * @return int
     */
    public function order_count(): int
    {
        return count($this->orders());
    }

    /**
     * Get total amount spent by this customer
     *
     * @return float
     */
    public function total_spent(): float
    {
        $total = 0.0;
        foreach ($this->orders() as $order) {
            $delegate = $order->delegate();
            if ($delegate) {
                $total += (float) ($delegate['total_price'] ?? 0);
            }
        }
        return $total;
    }

    /**
     * Get the first order date
     *
     * @return string|null
     */
    public function first_order_date(): ?string
    {
        $orders = $this->orders();
        if (empty($orders)) {
            return null;
        }

        $dates = [];
        foreach ($orders as $order) {
            $delegate = $order->delegate();
            if ($delegate && $delegate['order_date']) {
                $dates[] = $delegate['order_date'];
            }
        }

        if (empty($dates)) {
            return null;
        }

        sort($dates);
        return $dates[0];
    }

    /**
     * Get the last order date
     *
     * @return string|null
     */
    public function last_order_date(): ?string
    {
        $orders = $this->orders();
        if (empty($orders)) {
            return null;
        }

        $dates = [];
        foreach ($orders as $order) {
            $delegate = $order->delegate();
            if ($delegate && $delegate['order_date']) {
                $dates[] = $delegate['order_date'];
            }
        }

        if (empty($dates)) {
            return null;
        }

        rsort($dates);
        return $dates[0];
    }

    /**
     * Get days since last order
     *
     * @return int|null
     */
    public function days_since_last_order(): ?int
    {
        $lastDate = $this->last_order_date();
        if (!$lastDate) {
            return null;
        }

        $last = new \DateTime($lastDate);
        $now = new \DateTime();
        return (int) $now->diff($last)->days;
    }

    /**
     * Get average order value
     *
     * @return float
     */
    public function average_order_value(): float
    {
        $count = $this->order_count();
        if ($count === 0) {
            return 0.0;
        }

        return $this->total_spent() / $count;
    }

    /**
     * Get average days between orders
     *
     * @return float|null
     */
    public function average_days_between_orders(): ?float
    {
        $orders = $this->orders();
        if (count($orders) < 2) {
            return null;
        }

        $dates = [];
        foreach ($orders as $order) {
            $delegate = $order->delegate();
            if ($delegate && $delegate['order_date']) {
                $dates[] = strtotime($delegate['order_date']);
            }
        }

        if (count($dates) < 2) {
            return null;
        }

        sort($dates);

        $totalDays = 0;
        for ($i = 1; $i < count($dates); $i++) {
            $totalDays += ($dates[$i] - $dates[$i - 1]) / 86400;
        }

        return $totalDays / (count($dates) - 1);
    }

    // =========================================
    // ADDRESSES
    // =========================================

    /**
     * Get all addresses for this customer
     *
     * @return array<PostalAddress>
     */
    public function addresses(): array
    {
        return PostalAddress::find_by_owner($this);
    }

    /**
     * Get billing addresses
     *
     * @return array<PostalAddress>
     */
    public function billing_addresses(): array
    {
        return array_filter($this->addresses(), fn($addr) => $addr['is_billing']);
    }

    /**
     * Get shipping addresses
     *
     * @return array<PostalAddress>
     */
    public function shipping_addresses(): array
    {
        return array_filter($this->addresses(), fn($addr) => $addr['is_shipping']);
    }

    // =========================================
    // UTILITIES
    // =========================================

    /**
     * Generate a UUID v4
     */
    protected static function generate_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
