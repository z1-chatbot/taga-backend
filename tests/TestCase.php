<?php

namespace Tests;

use App\Models\User;
use App\Support\ApiToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case.
 *
 * Uses DatabaseTransactions, not RefreshDatabase. This project's migrations
 * were squashed into database/schema/mysql-schema.sql and the individual
 * migration files removed, so migrate:fresh would drop every table and have
 * nothing to rebuild them from. Wrapping each test in a transaction gives the
 * same isolation and leaves the schema intact.
 *
 * Run `php artisan test:prepare-db` once to build the taga_test database.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // A guard, not a convenience: if the test database were ever pointed at
        // `taga`, these tests would delete real data. Fail loudly instead.
        $database = config('database.connections.mysql.database');

        if ($database !== 'taga_test') {
            $this->fail("Tests must run against 'taga_test', got '{$database}'. Check phpunit.xml.");
        }
    }

    /** Authorization header for a genuinely-issued customer token. */
    protected function tokenFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.ApiToken::issue(ApiToken::TYPE_USER, $user->id, $user->email),
            'Accept' => 'application/json',
        ];
    }

    protected function guestHeaders(string $guestId = 'test-guest'): array
    {
        return [
            'X-Guest-ID' => $guestId,
            'Accept' => 'application/json',
        ];
    }

    protected function makeUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /**
     * A delivery route the platform actually serves.
     *
     * Checkout refuses a home delivery to a state no shipping zone covers, so a
     * test that places an order has to say where it can go. Without this every
     * checkout in the suite was quietly priced at ₦0 shipping, which was exactly
     * the production bug.
     */
    protected function serviceableZone(
        string $destination = 'Lagos',
        ?string $origin = 'Lagos',
        float $fee = 1500
    ): \App\Models\ShippingZone {
        return \App\Models\ShippingZone::create([
            'name' => "{$origin} to {$destination}",
            'origin_state' => $origin,
            'state' => $destination,
            'type' => $origin === $destination ? 'intrastate' : 'interstate',
            'shipping_fee' => $fee,
            'estimated_delivery_days' => 2,
            'is_active' => true,
        ]);
    }
}
