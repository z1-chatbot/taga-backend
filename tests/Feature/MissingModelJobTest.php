<?php

namespace Tests\Feature;

use App\Jobs\SendCartReminderEmail;
use App\Jobs\SendOrderFollowUpEmail;
use App\Jobs\SendOrderStatusEmail;
use App\Jobs\SendWelcomeEmail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Facades\Mail;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * A queued email about a record that has since been deleted.
 *
 * SerializesModels stores only an id and reloads the record when the job runs,
 * so deleting the subject between dispatch and delivery makes restoration throw
 * ModelNotFoundException. Laravel's default is to route that to failed_jobs,
 * where it sits looking like a mail fault to investigate — when in truth there
 * is nobody left to email. Four such rows are exactly what a deleted staff
 * account left behind on the live queue.
 *
 * `$deleteWhenMissingModels` makes the job disappear quietly instead. This
 * asserts the behaviour rather than the flag: a property can be set and still
 * not do what it is supposed to.
 */
class MissingModelJobTest extends TestCase
{
    /** Every queued job that carries a model and should tolerate its removal. */
    public static function jobsCarryingModels(): array
    {
        return [
            'welcome' => [SendWelcomeEmail::class],
            'cart reminder' => [SendCartReminderEmail::class],
            'order status' => [SendOrderStatusEmail::class],
            'order follow-up' => [SendOrderFollowUpEmail::class],
        ];
    }

    /**
     * @dataProvider jobsCarryingModels
     */
    public function test_the_job_is_deleted_rather_than_failed(string $class): void
    {
        $shouldDelete = (new ReflectionClass($class))
            ->getDefaultProperties()['deleteWhenMissingModels'] ?? false;

        $this->assertTrue(
            $shouldDelete,
            "{$class} carries a model and must tolerate that model being deleted."
        );
    }

    public function test_a_deleted_customer_deletes_the_job_and_sends_nothing(): void
    {
        Mail::fake();

        $user = $this->makeUser();
        $job = new SendWelcomeEmail($user);

        // Serialise first, exactly as dispatching would, then delete the user —
        // so restoration has a real id pointing at a row that no longer exists.
        $payload = serialize(clone $job);
        $user->forceDelete();

        // The queue job Laravel would hand to the worker.
        $queueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('resolveQueuedJobClass')->andReturn(SendWelcomeEmail::class);
        $queueJob->shouldReceive('hasFailed')->andReturn(false);
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $queueJob->shouldReceive('uuid')->andReturn('test-uuid');
        $queueJob->shouldReceive('getConnectionName')->andReturn('database');

        // The assertion that matters: deleted, never failed.
        $queueJob->shouldReceive('delete')->once();
        $queueJob->shouldNotReceive('fail');

        app(CallQueuedHandler::class)->call($queueJob, ['command' => $payload]);

        // And nobody was emailed about an account that no longer exists.
        Mail::assertNothingSent();
    }

    public function test_a_live_customer_still_gets_their_email(): void
    {
        Mail::fake();

        // The other half: the tolerance must not have turned into silence for
        // records that are perfectly fine.
        $user = $this->makeUser();

        (new SendWelcomeEmail($user))->handle();

        Mail::assertSent(\App\Mail\WelcomeEmail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_a_deleted_order_does_not_fail_its_status_email(): void
    {
        Mail::fake();

        $address = [
            'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
            'phone' => '08000000000', 'address' => '1 Test Road',
            'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria',
        ];

        $order = Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-MM-'.uniqid(),
            'status' => Order::STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 1000,
            'total_amount' => 1000,
            'shipping_address' => $address,
            'billing_address' => $address,
        ]);

        $payload = serialize(new SendOrderStatusEmail($order, 'confirmed'));
        $order->forceDelete();

        $queueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('resolveQueuedJobClass')->andReturn(SendOrderStatusEmail::class);
        $queueJob->shouldReceive('hasFailed')->andReturn(false);
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $queueJob->shouldReceive('uuid')->andReturn('test-uuid');
        $queueJob->shouldReceive('getConnectionName')->andReturn('database');
        $queueJob->shouldReceive('delete')->once();
        $queueJob->shouldNotReceive('fail');

        app(CallQueuedHandler::class)->call($queueJob, ['command' => $payload]);

        Mail::assertNothingSent();
    }
}
