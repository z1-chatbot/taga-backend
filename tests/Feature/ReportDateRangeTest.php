<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Asking the analytics for a window rather than "the last N days".
 *
 * The insights page could only ever answer how trade is going. It could not
 * answer how it went — a campaign week, a month that has closed, the same
 * fortnight last year. `period` still works and still means the same thing, so
 * nothing that was already calling this had to change.
 */
class ReportDateRangeTest extends TestCase
{
    private function admin(): array
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));
    }

    /** A paid order on a given day, so a window either contains it or does not. */
    private function paidOrderOn(string $date, float $amount): Order
    {
        $order = Order::factory()->create([
            'payment_status' => Order::PAYMENT_PAID,
            'total_amount' => $amount,
        ]);

        // Written after creation: `created_at` is not fillable, and the factory
        // would otherwise stamp it with now().
        $order->forceFill(['created_at' => Carbon::parse($date)->setTime(10, 0)])->save();

        return $order;
    }

    public function test_a_from_and_to_pair_reports_only_that_window(): void
    {
        $this->paidOrderOn('2026-06-10', 5000);   // inside
        $this->paidOrderOn('2026-06-20', 3000);   // inside
        $this->paidOrderOn('2026-07-05', 9000);   // after

        $summary = $this->getJson(
            '/api/v1/admin/dashboard/sales-analytics?from=2026-06-01&to=2026-06-30',
            $this->admin()
        )->assertOk()->json('data.summary');

        $this->assertEquals(8000.0, $summary['total_revenue']);
        $this->assertEquals(2, $summary['total_orders']);
    }

    public function test_the_last_day_of_the_window_counts(): void
    {
        // An order at 10am on the 30th sits after midnight on the 30th. Treating
        // `to` as the start of its day silently drops a day of trade, which is
        // the kind of wrong that only shows up as a number that looks fine.
        $this->paidOrderOn('2026-06-30', 4000);

        $summary = $this->getJson(
            '/api/v1/admin/dashboard/sales-analytics?from=2026-06-01&to=2026-06-30',
            $this->admin()
        )->assertOk()->json('data.summary');

        $this->assertEquals(4000.0, $summary['total_revenue']);
    }

    public function test_the_window_is_echoed_back(): void
    {
        $data = $this->getJson(
            '/api/v1/admin/dashboard/sales-analytics?from=2026-06-01&to=2026-06-30',
            $this->admin()
        )->assertOk()->json('data');

        // The page labels its figures from this rather than from what it asked
        // for, so a window the server adjusted still reads honestly.
        $this->assertSame('2026-06-01', $data['from']);
        $this->assertSame('2026-06-30', $data['to']);
        // Both ends inclusive: the 1st to the 30th is thirty days of trade, and
        // the window it is compared against is the thirty before it.
        $this->assertEquals(30, $data['period']);
    }

    public function test_a_window_handed_over_backwards_is_read_the_right_way_round(): void
    {
        $this->paidOrderOn('2026-06-15', 2500);

        $summary = $this->getJson(
            '/api/v1/admin/dashboard/sales-analytics?from=2026-06-30&to=2026-06-01',
            $this->admin()
        )->assertOk()->json('data.summary');

        $this->assertEquals(2500.0, $summary['total_revenue']);
    }

    public function test_growth_compares_against_the_window_before_it(): void
    {
        // Two equal-length weeks. The earlier one is the comparison, and it is
        // the window immediately before — not "twice the period back", which is
        // the same thing only for windows that end today.
        $this->paidOrderOn('2026-06-08', 1000);
        $this->paidOrderOn('2026-06-16', 2000);

        $growth = $this->getJson(
            '/api/v1/admin/dashboard/sales-analytics?from=2026-06-15&to=2026-06-21',
            $this->admin()
        )->assertOk()->json('data.growth_indicators');

        $this->assertEquals(2000.0, $growth['current_period_revenue']);
        $this->assertEquals(1000.0, $growth['previous_period_revenue']);
        $this->assertEquals(100.0, $growth['revenue_growth']);
    }

    public function test_one_end_alone_is_a_legitimate_question(): void
    {
        $this->paidOrderOn('2026-06-15', 7000);

        // "Everything since the first of June" is a real thing to ask, and it
        // used to require working out how many days ago that was.
        $summary = $this->getJson(
            '/api/v1/admin/dashboard/sales-analytics?from=2026-06-01',
            $this->admin()
        )->assertOk()->json('data.summary');

        $this->assertEquals(7000.0, $summary['total_revenue']);
    }

    public function test_period_still_means_what_it_used_to(): void
    {
        $this->paidOrderOn(now()->subDays(3)->toDateString(), 6000);
        $this->paidOrderOn(now()->subDays(40)->toDateString(), 8000);

        $summary = $this->getJson('/api/v1/admin/dashboard/sales-analytics?period=30', $this->admin())
            ->assertOk()
            ->json('data.summary');

        $this->assertEquals(6000.0, $summary['total_revenue']);
    }

    public function test_customer_insights_takes_the_same_window(): void
    {
        $data = $this->getJson(
            '/api/v1/admin/dashboard/customer-insights?from=2026-06-01&to=2026-06-30',
            $this->admin()
        )->assertOk()->json('data');

        $this->assertSame('2026-06-01', $data['from']);
        $this->assertSame('2026-06-30', $data['to']);
    }
}
