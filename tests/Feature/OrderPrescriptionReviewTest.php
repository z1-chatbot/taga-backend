<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

/**
 * Prescriptions read and decided from the order they belong to.
 *
 * They were reachable only from a queue of their own: a flat list of documents
 * with nothing beside them saying what had been bought. A pharmacist judging
 * whether a prescription covers a purchase has to see the purchase, and a
 * rejection cancels the order and sends it for refund — so that is not a
 * decision anyone should be making from a filename and a date.
 */
class OrderPrescriptionReviewTest extends TestCase
{
    /**
     * The roles and permissions this test's routes are gated by.
     *
     * taga_test carries schema but no seed data, so `store_owner` exists as a
     * string on a user and as nothing else — and `/admin/orders/{id}` is behind
     * `permission:orders.view_details`, which User::hasPermission() answers
     * through the Role relation rather than that string. Seeding here keeps the
     * test honest about what a real pharmacy account holds instead of routing
     * around the middleware.
     *
     * Idempotent (the seeder is all updateOrCreate) and rolled back with the
     * rest of the transaction.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function pharmacy(): array
    {
        // role_id as well as the role string: the /admin/orders route is gated
        // by `permission:orders.view_details`, and User::hasPermission() reads
        // permissions through the Role relation rather than the string column.
        $owner = $this->makeUser([
            'role' => 'store_owner',
            'role_id' => \App\Models\Role::where('name', 'store_owner')->value('id'),
        ]);

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Rx Test Pharmacy',
            'slug' => 'rx-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
            'can_sell_prescription' => true,
        ]);

        $owner->forceFill(['store_id' => $store->id])->save();

        return [$owner, $store];
    }

    /**
     * An order with two Rx lines covered by a single prescription.
     */
    private function orderWithPrescription(Store $store): array
    {
        $customer = $this->makeUser();

        $address = [
            'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
            'phone' => '08000000000', 'address' => '1 Test Road',
            'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria',
        ];

        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'TEST-RX-'.uniqid(),
            'status' => Order::STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 2000,
            'total_amount' => 2000,
            'shipping_address' => $address,
            'billing_address' => $address,
            'requires_prescription' => true,
            'prescription_status' => 'pending',
        ]);

        $prescription = Prescription::factory()->create([
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => Prescription::STATUS_PENDING,
        ]);

        foreach (['Amoxicillin 500mg', 'Metformin 850mg'] as $name) {
            $product = Product::factory()->create([
                'store_id' => $store->id,
                'name' => $name,
                'requires_prescription' => true,
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 2,
                'price' => 500,
                'total' => 1000,
                'product_snapshot' => ['name' => $name, 'sku' => 'SKU-'.uniqid()],
                'prescription_id' => $prescription->id,
                'required_prescription' => true,
            ]);
        }

        return [$order, $prescription, $customer];
    }

    public function test_the_order_carries_its_prescriptions(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        [, $store] = $this->pharmacy();
        [$order, $prescription] = $this->orderWithPrescription($store);

        $response = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor($admin))
            ->assertOk();

        $rows = $response->json('data.prescriptions');

        // One row, not two: a single document covering two lines is one
        // judgement, and reviewing it per line would be two chances to decide
        // differently and two audit entries for one decision.
        $this->assertCount(1, $rows);
        $this->assertSame($prescription->id, $rows[0]['id']);
        $this->assertCount(2, $rows[0]['items']);
        $this->assertEqualsCanonicalizing(
            ['Amoxicillin 500mg', 'Metformin 850mg'],
            array_column($rows[0]['items'], 'name')
        );
    }

    public function test_the_file_path_is_never_exposed(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        [, $store] = $this->pharmacy();
        [$order] = $this->orderWithPrescription($store);

        $response = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor($admin));

        // The document is on the private disk and is served only through the
        // download route, which re-checks who is asking.
        $this->assertArrayNotHasKey('file_path', $response->json('data.prescriptions.0'));
        $this->assertStringContainsString(
            '/prescriptions/',
            $response->json('data.prescriptions.0.download_url')
        );
    }

    public function test_the_selling_pharmacy_sees_it_and_may_decide_it(): void
    {
        [$owner, $store] = $this->pharmacy();
        [$order, $prescription] = $this->orderWithPrescription($store);

        $response = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor($owner))
            ->assertOk();

        $this->assertSame($prescription->id, $response->json('data.prescriptions.0.id'));
        $this->assertTrue($response->json('data.prescriptions.0.can_review'));
    }

    public function test_another_pharmacy_cannot_see_the_order_at_all(): void
    {
        [, $store] = $this->pharmacy();
        [$otherOwner] = $this->pharmacy();
        [$order] = $this->orderWithPrescription($store);

        // Same answer as an order that does not exist, so ids cannot be probed
        // for what other pharmacies are shipping.
        $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor($otherOwner))
            ->assertStatus(404);
    }

    public function test_a_pharmacy_can_approve_from_the_order(): void
    {
        [$owner, $store] = $this->pharmacy();
        [$order, $prescription] = $this->orderWithPrescription($store);

        $this->postJson(
            "/api/v1/prescriptions/{$prescription->id}/review",
            ['action' => 'approve'],
            $this->tokenFor($owner)
        )->assertOk();

        $this->assertSame(Prescription::STATUS_APPROVED, $prescription->fresh()->status);

        // And the order it gates is released, which is the whole point of
        // deciding it here rather than in a list of loose documents.
        $this->assertSame('approved', $order->fresh()->prescription_status);
    }

    public function test_rejecting_from_the_order_cancels_the_order(): void
    {
        [$owner, $store] = $this->pharmacy();
        [$order, $prescription] = $this->orderWithPrescription($store);

        $this->postJson(
            "/api/v1/prescriptions/{$prescription->id}/review",
            ['action' => 'reject', 'reason' => 'The prescription has expired.'],
            $this->tokenFor($owner)
        )->assertOk();

        $order->refresh();

        $this->assertSame(Prescription::STATUS_REJECTED, $prescription->fresh()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
    }

    public function test_a_settled_prescription_is_not_offered_to_a_store_again(): void
    {
        [$owner, $store] = $this->pharmacy();
        [$order, $prescription] = $this->orderWithPrescription($store);

        $prescription->approve($owner);

        $response = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor($owner))
            ->assertOk();

        // A store reviewer can never re-decide a settled prescription — the API
        // refuses it — so the dashboard must not offer a button that can only
        // fail.
        $this->assertFalse($response->json('data.prescriptions.0.can_review'));
        $this->assertTrue($response->json('data.prescriptions.0.is_reviewed'));
    }

    public function test_an_order_without_prescriptions_reports_an_empty_list(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        [, $store] = $this->pharmacy();

        $address = [
            'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
            'phone' => '08000000000', 'address' => '1 Test Road',
            'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria',
        ];

        $order = Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-NORX-'.uniqid(),
            'status' => Order::STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 500,
            'total_amount' => 500,
            'shipping_address' => $address,
            'billing_address' => $address,
        ]);

        $product = Product::factory()->create(['store_id' => $store->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 500,
            'total' => 500,
            'product_snapshot' => ['name' => $product->name, 'sku' => 'SKU-1'],
            'required_prescription' => false,
        ]);

        $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor($admin))
            ->assertOk()
            ->assertJsonPath('data.prescriptions', []);
    }
}
