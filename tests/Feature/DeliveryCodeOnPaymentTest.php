<?php

namespace Tests\Feature;

use App\Mail\OrderStatusEmail;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The delivery code has to exist before the email that carries it is sent.
 *
 * The confirmation template prints the code behind `@if ($order->delivery_code)`,
 * so a missing code does not fail — it silently drops the block, which looks
 * exactly like the code having been taken out of the template. It had been
 * missing on the busiest path of all: returning from Paystack never generated
 * one, despite a comment on the send saying the message "contains delivery
 * code".
 */
class DeliveryCodeOnPaymentTest extends TestCase
{
    private function order(): Order
    {
        $user = User::factory()->create();

        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'TEST-DC-'.uniqid(),
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PENDING,
            'subtotal' => 1000,
            'total_amount' => 1000,
            'shipping_address' => $address = [
                'firstName' => 'Ada',
                'lastName' => 'Obi',
                'email' => 'ada@example.test',
                'phone' => '08000000000',
                'address' => '1 Test Road',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'Nigeria',
            ],
            'billing_address' => $address,
        ]);
    }

    public function test_ensure_delivery_code_mints_one_and_then_leaves_it_alone(): void
    {
        $order = $this->order();

        $this->assertNull($order->delivery_code);

        $first = $order->ensureDeliveryCode();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $first);

        // Idempotence is the whole point: every paid path calls this without
        // checking first, and re-minting would invalidate a code the customer
        // has already been emailed and is holding at their door.
        $this->assertSame($first, $order->ensureDeliveryCode());
        $this->assertSame($first, $order->fresh()->delivery_code);
    }

    public function test_returning_from_paystack_generates_a_code_before_the_email(): void
    {
        Mail::fake();

        $order = $this->order();

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'reference' => 'TEST-REF-'.uniqid(),
            'amount' => 1000,
            'status' => PaymentTransaction::STATUS_PENDING,
            'gateway' => PaymentTransaction::GATEWAY_PAYSTACK,
        ]);

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 123456,
                    'status' => 'success',
                    'fees' => 1500,
                    'authorization' => ['authorization_code' => 'AUTH_test'],
                    'customer' => ['customer_code' => 'CUS_test'],
                ],
            ]),
        ]);

        app(PaystackService::class)->verifyPayment($transaction->reference);

        $order->refresh();

        $this->assertNotNull(
            $order->delivery_code,
            'Returning from Paystack must mint a delivery code — this is the path that never did.'
        );

        // And the code must be on the order by the time the message is built,
        // not merely present shortly afterwards.
        Mail::assertSent(OrderStatusEmail::class, function (OrderStatusEmail $mail) use ($order) {
            return $mail->order->id === $order->id
                && $mail->statusType === 'confirmed'
                && $mail->order->delivery_code === $order->delivery_code;
        });
    }

    public function test_the_confirmation_email_actually_prints_the_code(): void
    {
        // Guards the template as well as the data. Both halves have to hold for
        // the customer to end up with a number they can read to the rider.
        $order = $this->order();
        $code = $order->ensureDeliveryCode();

        $html = (new OrderStatusEmail($order->fresh(), 'confirmed'))->render();

        $this->assertStringContainsString($code, $html);
        $this->assertStringContainsString('Delivery code', $html);
    }

    public function test_a_webhook_confirmation_also_carries_the_code(): void
    {
        Mail::fake();

        $order = $this->order();

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'reference' => 'TEST-WH-'.uniqid(),
            'amount' => 1000,
            'status' => PaymentTransaction::STATUS_PENDING,
            'gateway' => PaymentTransaction::GATEWAY_PAYSTACK,
        ]);

        $transaction->markAsSuccessful(['id' => 999]);

        $this->assertNotNull($order->fresh()->delivery_code);
    }
}
