<?php

namespace Tests\Feature;

use App\Mail\OrderNotificationEmail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Services\OrderNotificationService;
use App\Support\AppUrl;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * An order split across two pharmacies.
 *
 * The basket is one checkout but the fulfilment is not: OrderShipmentService
 * already breaks the order into a shipment per store, each with its own
 * tracking number and delivery estimate. The notifications have to be split the
 * same way, and were not.
 *
 * Every pharmacy received the same message carrying the whole order's subtotal
 * and total, so a shop supplying ₦3,000 of a ₦12,000 basket was told it had a
 * ₦12,000 order — and learnt in passing roughly what the other pharmacies were
 * selling into it.
 */
class MultiVendorOrderNotificationTest extends TestCase
{
    /** Two pharmacies, one order, different amounts each. */
    private function splitOrder(): array
    {
        $address = [
            'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
            'phone' => '08000000000', 'address' => '1 Test Road',
            'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria',
        ];

        $order = Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-MV-'.uniqid(),
            'status' => Order::STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 12000,
            'total_amount' => 12000,
            'shipping_address' => $address,
            'billing_address' => $address,
        ]);

        $stores = [];

        foreach ([['Alpha Pharmacy', 3000], ['Beta Pharmacy', 9000]] as [$name, $amount]) {
            $owner = $this->makeUser(['role' => 'store_owner']);

            $store = Store::create([
                'owner_id' => $owner->id,
                'name' => $name,
                'slug' => 'mv-'.uniqid(),
                'email' => 'shop'.uniqid().'@pharmacy.test',
                'state' => 'Lagos',
                'city' => 'Ikeja',
                'status' => 'active',
                'verification_status' => Store::VERIFICATION_APPROVED,
            ]);

            $product = Product::factory()->create(['store_id' => $store->id]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => $amount,
                'total' => $amount,
                'product_snapshot' => ['name' => $product->name, 'sku' => 'MV-'.$amount],
            ]);

            $stores[] = [$store, $owner, $amount];
        }

        return [$order->fresh(), $stores];
    }

    public function test_each_pharmacy_gets_its_own_message(): void
    {
        Mail::fake();

        [$order, $stores] = $this->splitOrder();

        (new OrderNotificationService())->notifyOrderPlaced($order);

        foreach ($stores as [, $owner]) {
            Mail::assertSent(
                OrderNotificationEmail::class,
                fn ($mail) => $mail->notificationType === 'new_order' && $mail->hasTo($owner->email)
            );
        }
    }

    public function test_a_pharmacy_is_told_its_own_total_not_the_orders(): void
    {
        Mail::fake();

        [$order, $stores] = $this->splitOrder();

        (new OrderNotificationService())->notifyOrderPlaced($order);

        foreach ($stores as [, $owner, $amount]) {
            Mail::assertSent(OrderNotificationEmail::class, function ($mail) use ($owner, $amount) {
                if (! $mail->hasTo($owner->email) || $mail->notificationType !== 'new_order') {
                    return false;
                }

                $html = $mail->render();

                // Their share is shown...
                $this->assertStringContainsString(number_format($amount, 2), $html);

                // ...and the order-wide total is not. 12,000 appears nowhere,
                // so neither pharmacy can infer the other's share by
                // subtraction.
                $this->assertStringNotContainsString('12,000.00', $html);

                return true;
            });
        }
    }

    public function test_the_vendor_link_goes_to_the_dashboard_not_the_storefront(): void
    {
        Mail::fake();

        [$order, $stores] = $this->splitOrder();

        (new OrderNotificationService())->notifyOrderPlaced($order);

        [, $owner] = $stores[0];

        Mail::assertSent(OrderNotificationEmail::class, function ($mail) use ($owner, $order) {
            if (! $mail->hasTo($owner->email) || $mail->notificationType !== 'new_order') {
                return false;
            }

            $html = $mail->render();

            // "View this order" always pointed at the storefront — the
            // customer's own order page, which a pharmacy cannot open. The one
            // link a vendor most needs was a dead end.
            $this->assertStringContainsString(AppUrl::admin('/orders/'.$order->id), $html);
            $this->assertStringNotContainsString(AppUrl::storefront('/orders/'.$order->id), $html);

            return true;
        });
    }

    public function test_the_customer_still_sees_the_whole_order(): void
    {
        Mail::fake();

        [$order] = $this->splitOrder();

        // Scoping the vendors' copies must not have scoped the customer's:
        // they bought one basket and are paying one total.
        $html = (new \App\Mail\OrderStatusEmail($order, 'confirmed'))->render();

        $this->assertStringContainsString('12,000.00', $html);
    }
}
