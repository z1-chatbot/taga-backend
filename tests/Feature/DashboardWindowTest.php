<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard landing page answers two different questions.
 *
 * "How did we trade" is revenue, orders placed, customers gained — events that
 * happened on a date, and the only things a date filter applies to. "Where
 * things stand" is stock levels, pending orders, unapproved reviews: the state
 * of the shop right now, which no window can sensibly filter.
 *
 * The insights page got a from/to picker first; this page kept a fixed today /
 * this month / this year and a chart hardcoded to thirty days. These cover the
 * window it now accepts, and the two figures that used to ignore it.
 */
class DashboardWindowTest extends TestCase
{
    private function admin(): array
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));
    }

    private function paidOrderOn(string $date, float $amount): Order
    {
        $order = Order::factory()->create([
            'payment_status' => Order::PAYMENT_PAID,
            'total_amount' => $amount,
        ]);

        $order->forceFill(['created_at' => Carbon::parse($date)->setTime(10, 0)])->save();

        return $order;
    }

    /** A line on an order. OrderItem has no factory in this repo. */
    private function sold(Order $order, Product $product, int $quantity): void
    {
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'total' => $product->price * $quantity,
        ]);
    }

    private function overview(string $query = ''): array
    {
        return $this->getJson('/api/v1/admin/dashboard/overview'.$query, $this->admin())
            ->assertOk()
            ->json('data');
    }

    public function test_the_window_reports_only_orders_inside_it(): void
    {
        $this->paidOrderOn('2026-06-10', 5000);   // inside
        $this->paidOrderOn('2026-06-20', 3000);   // inside
        $this->paidOrderOn('2026-07-05', 9000);   // after

        $window = $this->overview('?from=2026-06-01&to=2026-06-30')['window'];

        $this->assertEquals(8000.0, $window['revenue']);
        $this->assertEquals(2, $window['orders']);
    }

    public function test_the_last_day_of_the_window_counts(): void
    {
        // Same trap as the insights page: an order at 10am on the 30th sits
        // after midnight on the 30th, so treating `to` as the start of its day
        // drops a day of trade.
        $this->paidOrderOn('2026-06-30', 4000);

        $window = $this->overview('?from=2026-06-01&to=2026-06-30')['window'];

        $this->assertEquals(4000.0, $window['revenue']);
    }

    public function test_the_window_is_echoed_back(): void
    {
        $window = $this->overview('?from=2026-06-01&to=2026-06-30')['window'];

        $this->assertSame('2026-06-01', $window['from']);
        $this->assertSame('2026-06-30', $window['to']);
    }

    public function test_growth_compares_against_the_same_length_of_time_before(): void
    {
        // June is 30 days, so the window before it is 2 May to 1 June.
        $this->paidOrderOn('2026-05-20', 2000);   // previous window
        $this->paidOrderOn('2026-06-15', 3000);   // this window

        $window = $this->overview('?from=2026-06-01&to=2026-06-30')['window'];

        $this->assertEquals(3000.0, $window['revenue']);
        $this->assertEquals(50.0, $window['revenue_growth']);
    }

    public function test_nothing_to_compare_against_reads_as_no_movement(): void
    {
        // Not +100%. A first month of trade has no precedent, and reporting one
        // as infinite growth is worse than reporting none.
        $this->paidOrderOn('2026-06-15', 3000);

        $window = $this->overview('?from=2026-06-01&to=2026-06-30')['window'];

        $this->assertEquals(0.0, $window['revenue_growth']);
    }

    public function test_the_state_of_the_shop_ignores_the_window(): void
    {
        // Stock is what it is today. A product created outside the window is
        // still on the shelf, and filtering it out would be a lie.
        Product::factory()->create(['stock_quantity' => 0, 'is_active' => true]);

        $data = $this->overview('?from=2026-06-01&to=2026-06-30');

        $this->assertGreaterThan(0, $data['products']['out_of_stock']);
    }

    /**
     * Top sellers used to count every line ever sold.
     *
     * Beside a date filter that is actively misleading: the same five products
     * sit there whatever range is picked, because something that sold well two
     * years ago outranks this week's mover.
     */
    public function test_top_sellers_are_counted_within_the_window(): void
    {
        $old = Product::factory()->create(['name' => 'Long-ago bestseller']);
        $recent = Product::factory()->create(['name' => 'Selling now']);

        $this->sold($this->paidOrderOn('2026-01-05', 5000), $old, 500);
        $this->sold($this->paidOrderOn('2026-06-15', 5000), $recent, 3);

        $top = collect($this->overview('?from=2026-06-01&to=2026-06-30')['charts']['top_products']);

        $this->assertSame('Selling now', $top->first()['name']);

        $this->assertNull(
            $top->firstWhere('name', 'Long-ago bestseller'),
            'a sale from January must not be counted in a June window'
        );
    }

    public function test_a_window_with_no_sales_lists_no_top_sellers(): void
    {
        // Not five products with a null total. A "top sellers" list of things
        // that sold nothing is worse than an empty one.
        Product::factory()->count(3)->create();

        $top = $this->overview('?from=2026-06-01&to=2026-06-30')['charts']['top_products'];

        $this->assertSame([], $top);
    }

    public function test_the_sales_chart_follows_the_window(): void
    {
        $this->paidOrderOn('2026-06-15', 3000);
        $this->paidOrderOn('2026-07-15', 9000);

        $chart = collect($this->overview('?from=2026-06-01&to=2026-06-30')['charts']['sales']);

        $this->assertCount(1, $chart);
        $this->assertEquals(3000.0, $chart->first()['revenue']);
    }

    public function test_sending_no_window_still_works(): void
    {
        // Every caller that predates the filter sends nothing at all.
        $data = $this->overview();

        $this->assertArrayHasKey('window', $data);
        $this->assertSame(30, $data['window']['days']);
        $this->assertArrayHasKey('revenue', $data);
    }

    public function test_a_shop_owner_gets_a_window_over_their_own_figures(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // role_id as well as role: dashboard.view is granted through the role
        // record, and without it the request is refused before it reaches the
        // shop-scoped branch this test is about.
        $owner = $this->makeUser([
            'role' => 'store_owner',
            'role_id' => Role::where('name', 'store_owner')->value('id'),
        ]);

        // Store has no factory in this repo.
        \App\Models\Store::create([
            'owner_id' => $owner->id,
            'name' => 'Window Pharmacy',
            'slug' => 'window-'.uniqid(),
            'email' => 'window'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
        ]);

        $data = $this->getJson(
            '/api/v1/admin/dashboard/overview?from=2026-06-01&to=2026-06-30',
            $this->tokenFor($owner)
        )->assertOk()->json('data');

        $this->assertSame('2026-06-01', $data['window']['from']);
    }
}
