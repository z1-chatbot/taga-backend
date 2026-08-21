<?php

namespace Tests\Feature;

use App\Models\Order;
use Tests\TestCase;

/**
 * The order endpoints guests can reach used
 *
 *     if (auth()->check() && $order->user_id !== auth()->id()) { ... }
 *
 * on routes carrying no auth middleware, so auth()->check() was always false
 * and the guard never ran. The confirmation endpoint had no check at all and
 * returned the customer's name, phone and delivery address for any id.
 */
class OrderAccessTest extends TestCase
{
    private function orderFor(?int $userId, ?string $sessionId = null): Order
    {
        return Order::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'order_number' => 'TG-TEST'.uniqid(),
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'shipping_address' => ['firstName' => 'Ada', 'phone' => '08000000000', 'address' => '1 Private Street'],
            'billing_address' => ['firstName' => 'Ada', 'phone' => '08000000000', 'address' => '1 Private Street'],
        ]);
    }

    public function test_a_stranger_cannot_read_another_users_order_confirmation(): void
    {
        $owner = $this->makeUser();
        $order = $this->orderFor($owner->id);

        $this->getJson("/api/v1/orders/{$order->id}/confirmation")
            ->assertStatus(404);
    }

    public function test_a_signed_in_stranger_cannot_read_it_either(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $order = $this->orderFor($owner->id);

        $this->getJson("/api/v1/orders/{$order->id}/confirmation", $this->tokenFor($other))
            ->assertStatus(404);
    }

    public function test_the_owner_can_read_their_own_confirmation(): void
    {
        $owner = $this->makeUser();
        $order = $this->orderFor($owner->id);

        $this->getJson("/api/v1/orders/{$order->id}/confirmation", $this->tokenFor($owner))
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }

    public function test_a_guest_order_is_readable_only_with_its_own_guest_id(): void
    {
        $order = $this->orderFor(null, 'guest-abc-123');

        $this->getJson("/api/v1/orders/{$order->id}/confirmation", ['X-Guest-ID' => 'guest-abc-123'])
            ->assertOk();

        $this->getJson("/api/v1/orders/{$order->id}/confirmation", ['X-Guest-ID' => 'guest-someone-else'])
            ->assertStatus(404);

        $this->getJson("/api/v1/orders/{$order->id}/confirmation")
            ->assertStatus(404);
    }

    public function test_payment_status_is_not_readable_by_order_id_alone(): void
    {
        $owner = $this->makeUser();
        $order = $this->orderFor($owner->id);

        $this->getJson("/api/v1/payments/{$order->id}/status")
            ->assertStatus(404);

        $this->getJson("/api/v1/payments/{$order->id}/status", $this->tokenFor($owner))
            ->assertOk()
            ->assertJsonPath('data.order_id', $order->id);
    }

    public function test_a_stranger_cannot_initialise_payment_for_someone_elses_order(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $order = $this->orderFor($owner->id);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'email' => 'attacker@evil.test',
        ], $this->tokenFor($other))->assertStatus(404);
    }

    public function test_a_missing_order_is_indistinguishable_from_a_forbidden_one(): void
    {
        $owner = $this->makeUser();
        $order = $this->orderFor($owner->id);

        $forbidden = $this->getJson("/api/v1/orders/{$order->id}/confirmation");
        $missing = $this->getJson('/api/v1/orders/99999999/confirmation');

        $this->assertSame($forbidden->status(), $missing->status());
        $this->assertSame($forbidden->getContent(), $missing->getContent());
    }
}
