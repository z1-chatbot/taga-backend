<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $address = [
            'firstName' => $this->faker->firstName(),
            'lastName' => $this->faker->lastName(),
            'email' => $this->faker->safeEmail(),
            'address' => $this->faker->streetAddress(),
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'country' => 'Nigeria',
            'phone' => '080'.$this->faker->numerify('########'),
        ];

        $subtotal = $this->faker->randomFloat(2, 500, 50000);

        return [
            'user_id' => null,
            'session_id' => null,
            'order_number' => 'TG-'.date('Ym').$this->faker->unique()->numerify('#####'),
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PENDING,
            'payment_method' => 'cash_on_delivery',
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $subtotal,
            'shipping_address' => $address,
            'billing_address' => $address,
            'requires_prescription' => false,
            'prescription_status' => 'not_required',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['payment_status' => Order::PAYMENT_PAID]);
    }

    /**
     * Paid, but holding for a pharmacist — the state the pay-now flow creates.
     */
    public function awaitingPrescriptionReview(): static
    {
        return $this->state(fn () => [
            'payment_status' => Order::PAYMENT_PAID,
            'requires_prescription' => true,
            'prescription_status' => 'pending',
        ]);
    }
}
