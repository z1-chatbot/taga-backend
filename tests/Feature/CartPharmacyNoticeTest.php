<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Store;
use Tests\TestCase;

/**
 * The basket says how many pharmacies it draws on.
 *
 * A basket is one payment, but fulfilment follows the shops: each pharmacy
 * dispatches its own parcel, and they arrive separately, on different days,
 * each with its own code. Nothing anywhere said so before payment — the first a
 * customer knew was a confirmation email with two codes in it, or a rider at
 * the door with half their order.
 */
class CartPharmacyNoticeTest extends TestCase
{
    private function pharmacy(string $name): Store
    {
        return Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $name,
            'slug' => 'cn-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);
    }

    private function basketOf(array $storeNames): array
    {
        $user = $this->makeUser();

        foreach ($storeNames as $name) {
            $product = Product::factory()->create(['store_id' => $this->pharmacy($name)->id]);

            Cart::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => $product->price,
            ]);
        }

        return $this->getJson('/api/v1/cart', $this->tokenFor($user))
            ->assertOk()
            ->json('data.pharmacies');
    }

    public function test_a_basket_from_one_pharmacy_reports_one(): void
    {
        $pharmacies = $this->basketOf(['Mercy Pharmacy']);

        // The storefront shows nothing below two, so this is what keeps the
        // ordinary basket free of a notice about a thing that is not happening.
        $this->assertSame(1, $pharmacies['count']);
        $this->assertSame(['Mercy Pharmacy'], $pharmacies['names']);
    }

    public function test_a_basket_from_two_pharmacies_names_both(): void
    {
        $pharmacies = $this->basketOf(['Alpha Pharmacy', 'Beta Pharmacy']);

        $this->assertSame(2, $pharmacies['count']);
        $this->assertEqualsCanonicalizing(
            ['Alpha Pharmacy', 'Beta Pharmacy'],
            $pharmacies['names']
        );
    }

    public function test_two_items_from_one_pharmacy_are_still_one_delivery(): void
    {
        $user = $this->makeUser();
        $store = $this->pharmacy('Mercy Pharmacy');

        foreach (range(1, 3) as $ignored) {
            $product = Product::factory()->create(['store_id' => $store->id]);

            Cart::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => $product->price,
            ]);
        }

        $pharmacies = $this->getJson('/api/v1/cart', $this->tokenFor($user))
            ->assertOk()
            ->json('data.pharmacies');

        // Counted by pharmacy, not by line. Three boxes from one shop is one
        // parcel, and telling the customer to expect three would be worse than
        // saying nothing.
        $this->assertSame(1, $pharmacies['count']);
    }
}
