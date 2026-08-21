<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\SaleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Promotions open and close on time.
 *
 * All that survives of the old "sales automation": an admin schedules a sale
 * for next week and something has to make it live on the day. The rest of that
 * job invented its own discounts — 25% to 50% off everything on fixed calendar
 * dates — which is not a decision a cron should take on a marketplace selling
 * other people's medicine.
 */
class PromotionScheduleTest extends TestCase
{
    private function runTask(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => ($e->description ?? '') === 'open-and-close-promotions');

        $this->assertNotNull($event, 'the promotions task is not scheduled, so it will never run');

        // run() is the public entry point; $callback is protected. This
        // executes exactly what the scheduler executes.
        $event->run($this->app);
    }

    private function sale(array $attributes): SaleEvent
    {
        return SaleEvent::create($attributes + [
            'name' => 'Scheduled sale',
            'type' => 'flash_sale',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'applicable_to' => 'all',
        ]);
    }

    public function test_a_scheduled_sale_goes_live_when_its_time_comes(): void
    {
        $sale = $this->sale([
            'auto_activate' => true,
            'is_active' => false,
            'start_date' => now()->subMinute(),
            'end_date' => now()->addWeek(),
        ]);

        $this->runTask();

        $this->assertTrue((bool) $sale->fresh()->is_active);
    }

    public function test_a_sale_that_has_not_started_stays_off(): void
    {
        $sale = $this->sale([
            'auto_activate' => true,
            'is_active' => false,
            'start_date' => now()->addWeek(),
            'end_date' => now()->addWeeks(2),
        ]);

        $this->runTask();

        $this->assertFalse((bool) $sale->fresh()->is_active);
    }

    public function test_a_sale_not_marked_for_auto_activation_is_left_alone(): void
    {
        // Someone drafting a sale has not agreed to it going live.
        $sale = $this->sale([
            'auto_activate' => false,
            'is_active' => false,
            'start_date' => now()->subMinute(),
            'end_date' => now()->addWeek(),
        ]);

        $this->runTask();

        $this->assertFalse((bool) $sale->fresh()->is_active);
    }

    public function test_an_expired_sale_is_switched_off(): void
    {
        $sale = $this->sale([
            'auto_activate' => false,
            'is_active' => true,
            'start_date' => now()->subWeeks(2),
            'end_date' => now()->subDay(),
        ]);

        $this->runTask();

        $this->assertFalse((bool) $sale->fresh()->is_active);
    }

    public function test_an_expired_coupon_is_switched_off(): void
    {
        $coupon = Coupon::create([
            'code' => 'OLD'.strtoupper(substr(uniqid(), -5)),
            'name' => 'Expired coupon',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
            'valid_from' => now()->subWeeks(2),
            'valid_until' => now()->subDay(),
        ]);

        $this->runTask();

        $this->assertFalse((bool) $coupon->fresh()->is_active);
    }

    public function test_nothing_invents_a_discount_of_its_own(): void
    {
        // The seasonal sale generators are gone. Running the task on an empty
        // database must leave it empty.
        $this->runTask();

        $this->assertSame(0, SaleEvent::count());
    }
}
