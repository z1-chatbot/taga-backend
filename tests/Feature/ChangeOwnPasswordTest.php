<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /v1/change-password, for whoever is signed in.
 *
 * Two front ends reach this one route and they spelled the confirmation field
 * differently: the admin dashboard sends `new_password_confirmation`, which is
 * what Laravel's `confirmed` rule reads, and the storefront sends
 * `confirm_password`. Only the first was accepted, so no customer had ever
 * changed their password -- the form answered "The new password field
 * confirmation does not match" no matter what they typed into it.
 *
 * It was invisible because the route was registered twice at the same URI. The
 * AuthController handler validated `same:new_password` and would have accepted
 * the storefront's spelling, but it was registered first and the later
 * registration wins, so it never ran. Reading it explained a bug that could not
 * be reproduced against it. The duplicate is gone.
 */
class ChangeOwnPasswordTest extends TestCase
{
    private function customer(): array
    {
        $user = $this->makeUser([
            'email' => 'pw-'.Str::random(6).'@example.com',
            'role' => 'customer',
            'password' => Hash::make('the-old-one'),
            'is_active' => true,
        ]);

        return [$user, $this->tokenFor($user)];
    }

    /** The spelling the storefront has always sent. */
    public function test_the_storefront_spelling_is_accepted(): void
    {
        [$user, $headers] = $this->customer();

        $this->postJson('/api/v1/change-password', [
            'current_password' => 'the-old-one',
            'new_password' => 'a-brand-new-one',
            'confirm_password' => 'a-brand-new-one',
        ], $headers)->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-one', $user->fresh()->password));
    }

    /** And the one the admin dashboard sends, which must keep working. */
    public function test_the_admin_spelling_is_accepted(): void
    {
        [$user, $headers] = $this->customer();

        $this->postJson('/api/v1/change-password', [
            'current_password' => 'the-old-one',
            'new_password' => 'a-brand-new-one',
            'new_password_confirmation' => 'a-brand-new-one',
        ], $headers)->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-one', $user->fresh()->password));
    }

    /** Accepting both spellings must not mean accepting a mismatch. */
    public function test_a_confirmation_that_does_not_match_is_still_refused(): void
    {
        [$user, $headers] = $this->customer();

        $this->postJson('/api/v1/change-password', [
            'current_password' => 'the-old-one',
            'new_password' => 'a-brand-new-one',
            'confirm_password' => 'something-else-entirely',
        ], $headers)->assertStatus(422);

        $this->assertTrue(Hash::check('the-old-one', $user->fresh()->password));
    }

    public function test_the_wrong_current_password_is_refused(): void
    {
        [$user, $headers] = $this->customer();

        $this->postJson('/api/v1/change-password', [
            'current_password' => 'not-the-old-one',
            'new_password' => 'a-brand-new-one',
            'confirm_password' => 'a-brand-new-one',
        ], $headers)->assertStatus(401);

        $this->assertTrue(Hash::check('the-old-one', $user->fresh()->password));
    }

    /**
     * One route, one handler. A second registration at the same URI is how the
     * spelling mismatch above stayed hidden.
     */
    public function test_the_route_is_registered_once(): void
    {
        $matching = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'api/v1/change-password'
                && in_array('POST', $route->methods(), true));

        $this->assertCount(1, $matching, 'a shadowed duplicate silently loses and cannot be debugged');
    }
}
