<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use App\Support\ApiToken;
use App\Support\GoogleIdToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    /**
     * The one role Google sign-in serves.
     *
     * Google is a shopper convenience on the storefront. Pharmacies, platform
     * staff and administrators reach the dashboard with an email and password,
     * and that is deliberate: those accounts dispense medicine, move money and
     * approve licences, so who may hold them is a decision Taga makes rather
     * than one delegated to whoever controls a Google mailbox.
     */
    private const CUSTOMERS_ONLY = 'customer';

    /**
     * Sign in (or sign up) with Google.
     *
     * The request carries one field: `credential`, the ID token Google handed
     * the browser. Everything this method acts on -- the Google account id, the
     * email address, the display name -- is read from that token *after*
     * App\Support\GoogleIdToken has checked its signature and audience.
     *
     * It previously took `google_id` and `email` as plain request fields and
     * believed them, which meant anyone who could reach the endpoint could mint
     * a session for any account on the platform. See GoogleIdToken for the
     * full account of that.
     *
     * Signup and signin are one endpoint on purpose: the button says "continue
     * with Google", and a person who has forgotten whether they have an account
     * should not have to guess right to get in.
     */
    public function googleAuth(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'credential' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $claims = GoogleIdToken::verify($request->input('credential'));

        if ($claims === null) {
            Log::warning('Rejected an unverifiable Google credential', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not verify that Google sign-in. Please try again.',
            ], 401);
        }

        // Google's `email_verified` is a hard requirement, not a nicety. The
        // email is what links a Google account to an existing Taga account
        // below, so an unverified one would let someone claim another person's
        // account by putting their address on a Google profile.
        if (! filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'success' => false,
                'message' => 'Your Google account does not have a verified email address. '
                    .'Verify it with Google, or sign up with an email and password.',
            ], 403);
        }

        // Customers only. See CUSTOMERS_ONLY below for why this is checked
        // before resolveUser() rather than after it.
        if ($refusal = $this->refuseNonCustomer($claims)) {
            return $refusal;
        }

        try {
            $user = $this->resolveUser($claims);
        } catch (\Throwable $e) {
            Log::error('Google sign-in failed: '.$e->getMessage(), [
                'google_sub' => $claims['sub'],
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sign-in failed. Please try again.',
            ], 500);
        }

        // Mirrors the password login gate. Without it, Google was a way around
        // a deactivation: an account an administrator had switched off could
        // still sign in here.
        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Authentication successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'role' => $user->role,
                    'email_verified' => ! is_null($user->email_verified_at),
                ],
                'token' => ApiToken::issue(ApiToken::TYPE_USER, $user->id, $user->email),
            ],
        ]);
    }

    /**
     * Refuse a Google sign-in that would land on a non-customer account.
     *
     * Read-only, and deliberately run *before* resolveUser(). Doing it
     * afterwards would still be a refusal, but resolveUser() writes as it goes:
     * it stamps `google_id` onto an account matched by email, and can verify a
     * mailbox and reactivate the account while doing so. Refusing after that has
     * already happened means a pharmacy or admin account is quietly altered by a
     * request that was never allowed to succeed — a Google identity welded onto
     * a dashboard account by somebody who cannot sign in to it.
     *
     * Matching order mirrors resolveUser(): the `sub` claim first, because it is
     * the stronger identifier and survives a Google address change, then the
     * email. Both have to be checked. An account promoted to store_owner *after*
     * signing up with Google is found by the first; a pharmacy that never
     * touched Google is found by the second.
     *
     * Returns null when the sign-in may proceed — including when no account
     * exists yet, since a new one is created as a customer.
     */
    private function refuseNonCustomer(array $claims): ?JsonResponse
    {
        $account = User::where('google_id', $claims['sub'])->first()
            ?? User::where('email', $claims['email'])->first();

        if (! $account || $account->role === self::CUSTOMERS_ONLY) {
            return null;
        }

        Log::info('Refused a Google sign-in for a non-customer account', [
            'user_id' => $account->id,
            'role' => $account->role,
        ]);

        // Such an account always has a password: a Google account is refused at
        // the pharmacy application, so no pharmacy owner ever arrived here
        // without one. The message can simply point at it.
        $message = $account->role === 'store_owner'
            ? 'This email is registered to a pharmacy on Taga. Pharmacies sign in to the '
                .'dashboard with the email and password they registered with, not with Google.'
            : 'This email is registered to a Taga staff account. Sign in with your email and '
                .'password rather than with Google.';

        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }

    /**
     * Find, link, or create the account this verified Google identity belongs to.
     *
     * Three cases, in order of how strongly they identify the person:
     *
     *   1. `google_id` already on file -- the same Google account signing in
     *      again. Matched on the id rather than the email because Google account
     *      holders can change their address; the `sub` claim never changes.
     *   2. The email matches an account that has no Google link. Google has
     *      proved control of that mailbox, which is the same proof the
     *      email-verification link asks for, so the accounts are the same person
     *      and are linked rather than duplicated.
     *   3. Nobody. Create a customer.
     */
    private function resolveUser(array $claims): User
    {
        $googleId = $claims['sub'];
        $email = $claims['email'];
        $name = $claims['name'] ?? Str::before($email, '@');
        $avatar = $claims['picture'] ?? null;

        $user = User::where('google_id', $googleId)->first();

        if ($user) {
            $user->update([
                'name' => $name,
                'avatar' => $avatar ?? $user->avatar,
            ]);

            return $user;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'google_id' => $googleId,
                'avatar' => $avatar ?? $user->avatar,
            ]);

            // An account that registered with a password and never clicked the
            // verification link is inactive *because* the mailbox was unproven.
            // Google has now proved it, so the same thing happens as when the
            // link is clicked. An account that is inactive despite a verified
            // email was switched off deliberately, and is left alone for the
            // is_active gate above to refuse.
            if (is_null($user->email_verified_at)) {
                $user->markEmailAsVerified();
                $user->update(['is_active' => true]);
            }

            Log::info('Linked a Google account to an existing user', ['user_id' => $user->id]);

            return $user;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'google_id' => $googleId,
            'avatar' => $avatar,
            // No password, and null rather than a random one. A random hash is
            // indistinguishable from a chosen one, which left nothing able to
            // tell that this account signs in with Google -- so the Profile page
            // offered it a "change your password" form that could never work.
            // Every Hash::check() against this column is guarded; see
            // AuthController::login.
            'password' => null,
            'auth_provider' => User::AUTH_GOOGLE,
            'phone' => null,
            'role' => 'customer',
            // Unlike a password signup, there is nothing left to verify: the
            // account arrives with a mailbox Google has already confirmed.
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        Log::info('Created a new user from Google sign-in', ['user_id' => $user->id]);

        SendWelcomeEmail::dispatch($user)->delay(now()->addSeconds(5));

        return $user;
    }

    /**
     * Unlink the Google account from the signed-in user.
     *
     * Note: no route currently points here.
     */
    public function unlinkGoogle(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->google_id) {
            return response()->json([
                'success' => false,
                'message' => 'No Google account linked',
            ], 400);
        }

        // An account with no password has nothing to fall back on: unlinking
        // Google would be the last credential leaving, and the owner could not
        // sign in again by any route. Refused outright rather than asked for a
        // password they do not have.
        if ($user->signsInWithGoogle()) {
            return response()->json([
                'success' => false,
                'message' => 'Google is the only way into this account. Set a password first, '
                    .'then you can unlink Google.',
            ], 400);
        }

        // The lockout check used to be
        // `$user->password === Hash::make(Str::random(32))`, which compares a
        // stored hash against a brand-new hash of a random string and is
        // therefore never true -- so the guard never fired. Now that an account
        // without a password is refused above, asking the rest to prove they
        // know theirs is a real check.
        if (! $request->filled('password') || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Enter your Taga password to unlink Google. '
                    .'If you have never set one, use "forgot password" first.',
            ], 400);
        }

        $user->update(['google_id' => null]);

        Log::info('Google account unlinked', ['user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'message' => 'Google account unlinked successfully',
        ]);
    }
}
