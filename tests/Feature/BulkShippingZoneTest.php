<?php

namespace Tests\Feature;

use App\Models\ShippingZone;
use Tests\TestCase;

/**
 * Laying down a whole delivery map in one action.
 *
 * Checkout refuses any route no zone covers, so a pharmacy shipping nationwide
 * needs a zone for all 37 states before it can sell to the whole country.
 * Building those one form at a time is why this platform had none configured.
 */
class BulkShippingZoneTest extends TestCase
{
    private function adminHeaders(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    private function bulk(array $overrides = [])
    {
        return $this->postJson('/api/v1/admin/shipping-zones/bulk', array_merge([
            'origin_state' => 'Lagos',
            'intrastate_fee' => 1500,
            'interstate_fee' => 4500,
        ], $overrides), $this->adminHeaders());
    }

    public function test_one_action_covers_every_state(): void
    {
        $this->bulk()->assertStatus(201)->assertJsonPath('data.created', 37);

        $this->assertSame(37, ShippingZone::where('origin_state', 'Lagos')->count());
    }

    public function test_the_home_state_gets_the_intrastate_fee(): void
    {
        $this->bulk();

        $home = ShippingZone::where('origin_state', 'Lagos')->where('state', 'Lagos')->first();

        $this->assertSame('1500.00', $home->shipping_fee);
        $this->assertSame('intrastate', $home->type);
    }

    public function test_every_other_state_gets_the_interstate_fee(): void
    {
        $this->bulk();

        $away = ShippingZone::where('origin_state', 'Lagos')->where('state', 'Kano')->first();

        $this->assertSame('4500.00', $away->shipping_fee);
        $this->assertSame('interstate', $away->type);
    }

    public function test_the_zones_it_creates_are_the_ones_checkout_finds(): void
    {
        $this->bulk();

        // findByRoute is what prices a basket. A map the checkout cannot see
        // would be worse than no map at all.
        $zone = ShippingZone::findByRoute('Lagos', 'Kano');

        $this->assertNotNull($zone);
        $this->assertSame('4500.00', $zone->shipping_fee);
    }

    public function test_running_it_again_reprices_rather_than_duplicating(): void
    {
        $this->bulk();
        $this->bulk(['interstate_fee' => 5200])
            ->assertStatus(201)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 37);

        $this->assertSame(37, ShippingZone::where('origin_state', 'Lagos')->count(),
            'a second run must not double the map');

        $this->assertSame(
            '5200.00',
            ShippingZone::where('origin_state', 'Lagos')->where('state', 'Kano')->first()->shipping_fee
        );
    }

    public function test_a_shorter_list_of_states_is_respected(): void
    {
        $this->bulk(['destination_states' => ['Lagos', 'Ogun', 'Oyo']])
            ->assertStatus(201)
            ->assertJsonPath('data.created', 3);

        $this->assertSame(3, ShippingZone::where('origin_state', 'Lagos')->count());
        $this->assertNull(ShippingZone::findByRoute('Lagos', 'Kano'));
    }

    public function test_a_second_origin_does_not_disturb_the_first(): void
    {
        $this->bulk();
        $this->bulk(['origin_state' => 'Kano', 'intrastate_fee' => 900, 'interstate_fee' => 3800]);

        $this->assertSame('4500.00', ShippingZone::findByRoute('Lagos', 'Kano')->shipping_fee);
        $this->assertSame('900.00', ShippingZone::findByRoute('Kano', 'Kano')->shipping_fee);
    }

    public function test_it_is_closed_to_everyone_but_an_admin(): void
    {
        $this->postJson('/api/v1/admin/shipping-zones/bulk', [
            'origin_state' => 'Lagos',
            'intrastate_fee' => 1500,
            'interstate_fee' => 4500,
        ], $this->tokenFor($this->makeUser(['role' => 'store_owner'])))->assertStatus(403);

        $this->assertSame(0, ShippingZone::count());
    }

    public function test_a_map_makes_checkout_possible(): void
    {
        // The point of all this: before, every route was uncovered.
        $this->assertNull(ShippingZone::findByRoute('Lagos', 'Lagos'));

        $this->bulk();

        $this->assertNotNull(ShippingZone::findByRoute('Lagos', 'Lagos'));
    }
}
