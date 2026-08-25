<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

/**
 * What happens to a guest's prescription when they sign in.
 *
 * Before this, nothing did. The upload was keyed to a `session_id` that the
 * cart merge never looked at, so signing in orphaned it: the shopper's own
 * prescription list — which prefers `user_id` the moment there is one — came
 * back empty, and they were asked to send the same document again.
 */
class GuestPrescriptionAdoptionTest extends TestCase
{
    private function guestPrescription(string $guestId): Prescription
    {
        return Prescription::factory()->create([
            'user_id' => null,
            'session_id' => $guestId,
            'status' => Prescription::STATUS_PENDING,
        ]);
    }

    public function test_signing_in_moves_a_guest_prescription_onto_the_account(): void
    {
        $guestId = 'guest_adopt_1';
        $prescription = $this->guestPrescription($guestId);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/cart/merge', ['guest_id' => $guestId])
            ->assertOk();

        $prescription->refresh();

        $this->assertSame($user->id, $prescription->user_id);

        // The session id must not survive alongside the account: canView() has a
        // guest branch that trusts a matching session_id, so leaving it set
        // would let anyone holding that guest id read a signed-in customer's
        // medical record.
        $this->assertNull($prescription->session_id);
    }

    public function test_adoption_happens_even_when_the_guest_basket_is_empty(): void
    {
        // The case the old ordering missed entirely: uploading from
        // /prescriptions with nothing in the basket, then signing in. The merge
        // returned early on the empty cart before it ever reached the
        // prescriptions.
        $guestId = 'guest_adopt_2';
        $prescription = $this->guestPrescription($guestId);
        $user = User::factory()->create();

        $this->assertSame(0, Cart::where('session_id', $guestId)->count());

        $this->actingAs($user)
            ->postJson('/api/v1/cart/merge', ['guest_id' => $guestId])
            ->assertOk();

        $this->assertSame($user->id, $prescription->refresh()->user_id);
    }

    public function test_the_adopted_prescription_is_listed_for_the_account(): void
    {
        $guestId = 'guest_adopt_3';
        $prescription = $this->guestPrescription($guestId);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/cart/merge', ['guest_id' => $guestId]);

        // The shopper-visible half of the bug: this list is what the basket's
        // reuse picker reads, and it was empty after signing in.
        $response = $this->actingAs($user)->getJson('/api/v1/prescriptions')->assertOk();

        $this->assertSame(
            [$prescription->id],
            array_column($response->json('data.data'), 'id')
        );
    }

    public function test_another_guests_prescription_is_not_adopted(): void
    {
        $mine = $this->guestPrescription('guest_adopt_4');
        $theirs = $this->guestPrescription('someone_else');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/cart/merge', ['guest_id' => 'guest_adopt_4']);

        $this->assertSame($user->id, $mine->refresh()->user_id);
        $this->assertNull($theirs->refresh()->user_id);
        $this->assertSame('someone_else', $theirs->session_id);
    }

    public function test_a_prescription_survives_a_line_being_merged_into_an_existing_one(): void
    {
        $guestId = 'guest_adopt_5';
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 20]);
        $prescription = $this->guestPrescription($guestId);

        // The same product in both baskets, so the guest line is folded into the
        // account's line and then deleted. Its prescription used to go with it.
        Cart::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
        ]);

        Cart::create([
            'session_id' => $guestId,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'prescription_id' => $prescription->id,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/cart/merge', ['guest_id' => $guestId])
            ->assertOk();

        $line = Cart::where('user_id', $user->id)->where('product_id', $product->id)->first();

        $this->assertNotNull($line);
        $this->assertSame($prescription->id, $line->prescription_id);
        $this->assertSame(2, $line->quantity);
    }

    public function test_a_prescription_the_account_already_attached_is_not_overwritten(): void
    {
        $guestId = 'guest_adopt_6';
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 20]);

        $guestScript = $this->guestPrescription($guestId);
        $ownScript = Prescription::factory()->create([
            'user_id' => $user->id,
            'status' => Prescription::STATUS_APPROVED,
        ]);

        Cart::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'prescription_id' => $ownScript->id,
        ]);

        Cart::create([
            'session_id' => $guestId,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'prescription_id' => $guestScript->id,
        ]);

        $this->actingAs($user)->postJson('/api/v1/cart/merge', ['guest_id' => $guestId]);

        $line = Cart::where('user_id', $user->id)->where('product_id', $product->id)->first();

        // Only an empty slot is filled. An approved prescription the account
        // deliberately attached must not be replaced by a pending guest one.
        $this->assertSame($ownScript->id, $line->prescription_id);
    }
}
