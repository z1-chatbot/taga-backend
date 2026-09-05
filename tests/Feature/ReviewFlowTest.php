<?php

namespace Tests\Feature;

use App\Mail\AdminAlertEmail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A shopper reviews something they bought, and everyone sees it once approved.
 *
 * The whole point of the moderation step is that an unapproved review counts for
 * nothing anywhere: not in the list, not in the average, not in the ranking. So
 * most of what is asserted here is about what a *pending* or *rejected* review
 * must not be able to do — that is the half a walkthrough misses, because the
 * happy path looks the same either way.
 */
class ReviewFlowTest extends TestCase
{
    private function admin(): array
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));
    }

    /** A shopper who has paid for $product AND received it. */
    private function buyerOf(Product $product): User
    {
        $buyer = $this->makeUser(['role' => 'customer']);

        $order = Order::factory()->create([
            'user_id' => $buyer->id,
            'payment_status' => Order::PAYMENT_PAID,
            'status' => Order::STATUS_DELIVERED,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'total' => $product->price,
        ]);

        return $buyer;
    }

    private function product(): Product
    {
        return Product::factory()->create(['is_active' => true, 'stock_quantity' => 10]);
    }

    private function leaveReview(Product $product, User $buyer, int $rating = 5)
    {
        return $this->postJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => $rating,
            'title' => 'Worked well',
            'comment' => 'Arrived quickly and did the job.',
        ], $this->tokenFor($buyer));
    }

    /** The public list, as an anonymous visitor sees it. */
    private function publicReviews(Product $product): array
    {
        return $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()
            ->json('data.data');
    }

    // ---- the happy path ----------------------------------------------------

    public function test_a_buyer_can_leave_a_review(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $this->leaveReview($product, $buyer)->assertCreated();

        $this->assertDatabaseHas('reviews', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'is_verified_purchase' => true,
            'is_approved' => false,
        ]);
    }

    public function test_can_review_reports_eligibility(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);
        $stranger = $this->makeUser(['role' => 'customer']);

        $this->getJson("/api/v1/products/{$product->id}/can-review", $this->tokenFor($buyer))
            ->assertOk()
            ->assertJsonPath('data.can_review', true)
            ->assertJsonPath('data.has_purchased', true);

        $this->getJson("/api/v1/products/{$product->id}/can-review", $this->tokenFor($stranger))
            ->assertOk()
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.has_purchased', false);

        $this->leaveReview($product, $buyer)->assertCreated();

        $this->getJson("/api/v1/products/{$product->id}/can-review", $this->tokenFor($buyer))
            ->assertOk()
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.has_reviewed', true);
    }

    public function test_an_approved_review_is_visible_to_everyone(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $id = $this->leaveReview($product, $buyer)->assertCreated()->json('data.id');

        $this->assertCount(0, $this->publicReviews($product), 'pending reviews must not be public');

        $this->putJson("/api/v1/admin/reviews/{$id}/approve", [], $this->admin())->assertOk();

        $visible = $this->publicReviews($product);

        $this->assertCount(1, $visible);
        $this->assertSame('Worked well', $visible[0]['title']);
    }

    public function test_approval_updates_the_products_rating(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $id = $this->leaveReview($product, $buyer, 4)->assertCreated()->json('data.id');

        $this->assertSame(0, (int) $product->fresh()->rating_count);

        $this->putJson("/api/v1/admin/reviews/{$id}/approve", [], $this->admin())->assertOk();

        $fresh = $product->fresh();
        $this->assertSame(1, (int) $fresh->rating_count);
        $this->assertEquals(4, round((float) $fresh->average_rating));
    }

    // ---- who may review ----------------------------------------------------

    public function test_someone_who_never_bought_it_is_refused(): void
    {
        $product = $this->product();
        $stranger = $this->makeUser(['role' => 'customer']);

        $this->leaveReview($product, $stranger)->assertStatus(403);

        $this->assertDatabaseMissing('reviews', ['user_id' => $stranger->id]);
    }

    /** An unpaid order is not a purchase. */
    public function test_an_unpaid_order_does_not_earn_a_review(): void
    {
        $product = $this->product();
        $shopper = $this->makeUser(['role' => 'customer']);

        $order = Order::factory()->create([
            'user_id' => $shopper->id,
            'payment_status' => Order::PAYMENT_PENDING,
            'status' => Order::STATUS_DELIVERED,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'total' => $product->price,
        ]);

        $this->leaveReview($product, $shopper)->assertStatus(403);
    }

    public function test_a_second_review_of_the_same_product_is_refused(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $this->leaveReview($product, $buyer)->assertCreated();
        $this->leaveReview($product, $buyer)->assertStatus(422);

        $this->assertSame(1, Review::where('user_id', $buyer->id)->count());
    }

    public function test_a_signed_out_visitor_cannot_review(): void
    {
        $product = $this->product();

        $this->postJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => 5,
            'comment' => 'Great.',
        ])->assertStatus(401);
    }

    // ---- taking one back down ----------------------------------------------

    /**
     * Rejecting hides the review. It must also take its stars back out of the
     * average — otherwise a review nobody can read goes on counting, and the
     * moderation step only pretends to work.
     */
    public function test_rejecting_removes_it_from_the_rating(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $id = $this->leaveReview($product, $buyer, 5)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/reviews/{$id}/approve", [], $this->admin())->assertOk();

        $this->assertSame(1, (int) $product->fresh()->rating_count);

        $this->putJson("/api/v1/admin/reviews/{$id}/reject", [], $this->admin())->assertOk();

        $this->assertCount(0, $this->publicReviews($product));
        $this->assertSame(0, (int) $product->fresh()->rating_count, 'a rejected review must not still count');
        $this->assertEquals(0, (float) $product->fresh()->average_rating);
    }

    /** The bulk button has to do exactly what the single one does. */
    public function test_bulk_rejecting_removes_them_from_the_rating(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $id = $this->leaveReview($product, $buyer, 5)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/reviews/{$id}/approve", [], $this->admin())->assertOk();

        $this->postJson('/api/v1/admin/reviews/bulk-reject', ['review_ids' => [$id]], $this->admin())
            ->assertOk();

        $this->assertCount(0, $this->publicReviews($product));
        $this->assertSame(0, (int) $product->fresh()->rating_count);
    }

    public function test_bulk_deleting_removes_them_from_the_rating(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $id = $this->leaveReview($product, $buyer, 5)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/reviews/{$id}/approve", [], $this->admin())->assertOk();

        $this->postJson('/api/v1/admin/reviews/bulk-delete', ['review_ids' => [$id]], $this->admin())
            ->assertOk();

        $this->assertSame(0, (int) $product->fresh()->rating_count);
    }

    /**
     * Editing sends a review back for re-approval, so its old stars have to come
     * out of the average until it is approved again.
     */
    public function test_editing_a_review_takes_it_back_out_of_the_rating(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $id = $this->leaveReview($product, $buyer, 5)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/reviews/{$id}/approve", [], $this->admin())->assertOk();

        $this->putJson("/api/v1/reviews/{$id}", [
            'rating' => 1,
            'title' => 'Changed my mind',
            'comment' => 'Not as described after all.',
        ], $this->tokenFor($buyer))->assertOk();

        $this->assertCount(0, $this->publicReviews($product), 'an edited review is pending again');
        $this->assertSame(0, (int) $product->fresh()->rating_count);
    }

    public function test_deleting_your_own_review_updates_the_rating(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $id = $this->leaveReview($product, $buyer, 5)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/reviews/{$id}/approve", [], $this->admin())->assertOk();

        $this->deleteJson("/api/v1/reviews/{$id}", [], $this->tokenFor($buyer))->assertOk();

        $this->assertSame(0, (int) $product->fresh()->rating_count);
    }

    // ---- what the catalogue reports ----------------------------------------

    /**
     * The listing carries its own average, computed separately from
     * Product::updateRating(). A pending review must not reach it either, or a
     * shopper sees five stars on a product whose only review nobody has cleared.
     */
    public function test_a_pending_review_does_not_show_in_the_catalogue_average(): void
    {
        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $this->leaveReview($product, $buyer, 5)->assertCreated();

        $listed = collect($this->getJson('/api/v1/products?per_page=100')->assertOk()->json('data.data'))
            ->firstWhere('id', $product->id);

        $this->assertNotNull($listed, 'the product should be listed');
        $this->assertEquals(0, (float) ($listed['average_rating'] ?? 0));
        $this->assertSame(0, (int) ($listed['reviews_count'] ?? 0));
    }

    // ---- delivered, not merely paid for ------------------------------------

    /**
     * Payment clears when the card is charged, which is before a pharmacist has
     * dispensed anything. Reviewing a medicine you have not yet held rates the
     * one thing a review is for.
     */
    public function test_a_paid_but_undelivered_order_does_not_earn_a_review(): void
    {
        $product = $this->product();
        $shopper = $this->makeUser(['role' => 'customer']);

        $order = Order::factory()->create([
            'user_id' => $shopper->id,
            'payment_status' => Order::PAYMENT_PAID,
            'status' => Order::STATUS_SHIPPED,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'total' => $product->price,
        ]);

        $this->getJson("/api/v1/products/{$product->id}/can-review", $this->tokenFor($shopper))
            ->assertOk()
            ->assertJsonPath('data.has_purchased', false);

        $this->leaveReview($product, $shopper)->assertStatus(403);
    }

    /** And it becomes reviewable the moment it arrives. */
    public function test_it_becomes_reviewable_once_delivered(): void
    {
        $product = $this->product();
        $shopper = $this->makeUser(['role' => 'customer']);

        $order = Order::factory()->create([
            'user_id' => $shopper->id,
            'payment_status' => Order::PAYMENT_PAID,
            'status' => Order::STATUS_SHIPPED,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'total' => $product->price,
        ]);

        $order->forceFill(['status' => Order::STATUS_DELIVERED])->save();

        $this->leaveReview($product, $shopper)->assertCreated();
    }

    // ---- somebody is told --------------------------------------------------

    /**
     * A review held for approval that nobody is told about only goes up if an
     * administrator happens to open the queue - while the customer has been
     * told it will appear once checked.
     */
    public function test_every_administrator_is_told_about_a_new_review(): void
    {
        Mail::fake();

        $one = $this->makeUser(['role' => 'admin']);
        $two = $this->makeUser(['role' => 'admin']);

        $product = $this->product();
        $buyer = $this->buyerOf($product);

        $this->leaveReview($product, $buyer)->assertCreated();

        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($one->email));
        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($two->email));
    }

    /**
     * And the pharmacy being talked about. They cannot approve or remove it, so
     * this is a heads-up rather than a task - but hearing about a one-star
     * review of your dispensing from us beats discovering it.
     */
    public function test_the_pharmacy_is_told_about_a_review_of_its_medicine(): void
    {
        Mail::fake();
        $this->makeUser(['role' => 'admin']);

        $owner = $this->makeUser(['role' => 'store_owner']);

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Reviewed Pharmacy',
            'slug' => 'reviewed-'.uniqid(),
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
            'pharmacy_license_expiry' => now()->addYear(),
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'is_active' => true,
            'stock_quantity' => 10,
        ]);

        $buyer = $this->buyerOf($product);

        $this->leaveReview($product, $buyer)->assertCreated();

        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($owner->email));
    }

    /**
     * A product Taga sells itself has no pharmacy behind it. That is not a
     * failure, and it must not stop the platform being told.
     */
    public function test_a_product_with_no_pharmacy_still_alerts_the_platform(): void
    {
        Mail::fake();
        $admin = $this->makeUser(['role' => 'admin']);

        $product = Product::factory()->create([
            'store_id' => null,
            'is_active' => true,
            'stock_quantity' => 10,
        ]);

        $buyer = $this->buyerOf($product);

        $this->leaveReview($product, $buyer)->assertCreated();

        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    /**
     * Best-effort. The review is saved and the shopper thanked before any of
     * this runs, so a mail outage must not turn that into an error they would
     * answer by writing the review again.
     */
    public function test_a_mail_failure_does_not_lose_the_review(): void
    {
        $this->makeUser(['role' => 'admin']);

        $product = $this->product();
        $buyer = $this->buyerOf($product);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->leaveReview($product, $buyer)->assertCreated();

        $this->assertDatabaseHas('reviews', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);
    }
}
