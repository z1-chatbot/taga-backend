<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EmailLog;
use App\Mail\ResetPasswordEmail;
use App\Mail\VerifyEmail;
use App\Jobs\SendWelcomeEmail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8',
                'phone' => 'nullable|string|max:20',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            
            // Custom error message for email uniqueness
            if (isset($errors['email'])) {
                foreach ($errors['email'] as $error) {
                    if (str_contains($error, 'already been taken') || str_contains($error, 'unique')) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This email address is already registered. Please use a different email or login.',
                            'errors' => $errors
                        ], 422);
                    }
                }
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors
            ], 422);
        }

        try {
            // Create user (not verified yet)
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role' => 'customer',
                'is_active' => false, // Not active until email verified
            ]);

            // Generate verification token
            $token = $user->generateEmailVerificationToken();

            // Sending the verification email is deliberately outside the
            // success path. It used to run inline, so an SMTP failure threw
            // and returned a 500 — after the user row had already been
            // committed. That left an account that was inactive, unverified,
            // and unregisterable (the email was now taken): a permanent
            // lockout caused by a transient mail problem. The account is
            // created either way; a failed send is logged and recoverable with
            // "resend verification".
            $emailSent = true;

            try {
                Mail::to($user->email)->send(new VerifyEmail($user, $token));

                // Marked sent, not left pending. logEmail() creates the row as
                // pending and something has to resolve it; these four call sites
                // never did, so every verification email this platform has ever
                // delivered still counts as "pending" in the delivery history.
                // That is not cosmetic — it is the report you read when asking
                // which emails failed, and it was showing successes as failures.
                EmailLog::logEmail(
                    $user->email,
                    'verification',
                    'Verify Your Email - Taga',
                    null,
                    $user->id
                )->markAsSent();
            } catch (\Throwable $e) {
                $emailSent = false;

                Log::error('Verification email failed to send', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $emailSent
                    ? 'Registration successful! Please check your email to verify your account.'
                    : 'Account created, but we could not send the verification email. Please use "resend verification" to try again.',
                'data' => [
                    'email' => $user->email,
                    'requires_verification' => true,
                    'verification_email_sent' => $emailSent,
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Registration failed', ['error' => $e->getMessage()]);

            // The exception text can carry connection strings and mail
            // credentials, so it is logged rather than returned.
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        // An account created through Google has no password at all, so there is
        // nothing to compare and Hash::check() would error on the null. Say so
        // plainly rather than returning "incorrect credentials": the password
        // they are typing is not wrong, it does not exist, and a shopper told
        // their password is wrong will try to reset one they never had.
        if ($user && $user->signsInWithGoogle()) {
            return response()->json([
                'success' => false,
                'message' => 'This account signs in with Google. Use the "Continue with Google" button.',
                'requires_google' => true,
            ], 403);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if email is verified
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email address before logging in. Check your inbox for the verification link.',
                'requires_verification' => true,
                'email' => $user->email
            ], 403);
        }

        // Check if account is active
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        // Simple token generation without Sanctum for now
        $token = \App\Support\ApiToken::issue(\App\Support\ApiToken::TYPE_USER, $user->id, $user->email);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()
        ]);
    }

    /**
     * How long a password reset link stays usable.
     */
    private const RESET_TOKEN_MINUTES = 60;

    /**
     * Send a password reset link.
     *
     * This was previously a stub that returned the reset URL — token included —
     * in the HTTP response and never sent any email. Two unauthenticated
     * requests to a known address were enough to take over that account,
     * including the platform admin's. The token now goes only to the mailbox,
     * is stored hashed, and expires.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            // Deliberately not `exists:users,email`. That rule made this
            // endpoint an account-enumeration oracle: 422 meant "no such user",
            // 200 meant "this address is registered here" — which, on a
            // pharmacy, is a disclosure about a person's medical purchasing.
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $token = Str::random(64);

            // Hashed at rest: read access to the database should not hand over
            // working reset links for every pending request.
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            try {
                Mail::to($user->email)->send(
                    new ResetPasswordEmail($user, $token, self::RESET_TOKEN_MINUTES)
                );
            } catch (\Throwable $e) {
                // A mail outage must not tell the caller whether the address
                // exists, so this is logged and swallowed.
                Log::error('Password reset email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Identical response either way, and no token in it.
        return response()->json([
            'success' => true,
            'message' => 'If that email address has an account, a reset link is on its way.',
        ]);
    }

    /**
     * Reset a password using a token from the emailed link.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        $invalid = response()->json([
            'success' => false,
            'message' => 'This reset link is invalid or has expired. Please request a new one.',
        ], 400);

        if (! $record || ! Hash::check($request->token, $record->token)) {
            return $invalid;
        }

        // Carbon 3 returns a signed difference, so `now()->diffInMinutes($past)`
        // is negative and a naive `> 60` never fires — the expiry check has to
        // run in the other direction.
        if ($record->created_at === null
            || \Illuminate\Support\Carbon::parse($record->created_at)->diffInMinutes(now()) > self::RESET_TOKEN_MINUTES) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            return $invalid;
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $invalid;
        }

        $user->update([
            'password' => Hash::make($request->password),
            // Rotating this invalidates any outstanding "remember me" session.
            'remember_token' => Str::random(60),
        ]);

        // Single use.
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. You can now sign in.',
        ]);
    }

    public function getProfile(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $user
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|nullable|string|max:20',
            ]);

            if ($request->has('name')) {
                $user->name = $request->name;
            }
            if ($request->has('phone')) {
                $user->phone = $request->phone;
            }

            $user->save();

            return response()->json([
                'success' => true,
                'data' => $user
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage()
            ], 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8',
                'confirm_password' => 'required|string|same:new_password',
            ]);

            $user = $request->user();

            // Google accounts do not hold a password, and are not given one
            // here: the Profile page hides this form for them, so reaching this
            // point means the request did not come from that form.
            if ($user->signsInWithGoogle()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This account signs in with Google, so it has no password to change.',
                ], 400);
            }

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            // Update password
            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Password change failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify email address
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            // Check if already verified
            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Email already verified. You can now login.',
                    'already_verified' => true
                ]);
            }

            // Verify token
            $hashedToken = hash('sha256', $request->token);
            
            if ($user->email_verification_token !== $hashedToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification token.'
                ], 400);
            }

            // Check if token expired (24 hours)
            if ($user->email_verification_sent_at->addHours(24)->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Verification link has expired. Please request a new one.',
                    'expired' => true
                ], 400);
            }

            // Mark email as verified and activate account
            $user->markEmailAsVerified();
            $user->update(['is_active' => true]);

            // Log successful verification. This one records an event rather
            // than a send, so it is marked resolved immediately — left pending
            // it would sit in the delivery history forever as an email that
            // never went out, which is not what it is.
            EmailLog::logEmail(
                $user->email,
                'verification_success',
                'Email Verified Successfully',
                null,
                $user->id
            )->markAsSent();

            // Dispatch welcome email job
            SendWelcomeEmail::dispatch($user)->delay(now()->addSeconds(5));

            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully! You can now login to your account.',
                'data' => [
                    'email' => $user->email,
                    'verified' => true
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Email verification failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Email verification failed. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resend verification email
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            // Check if already verified
            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email is already verified. You can login now.'
                ], 400);
            }

            // Check rate limiting (don't allow resend within 2 minutes)
            if ($user->email_verification_sent_at && 
                $user->email_verification_sent_at->addMinutes(2)->isFuture()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please wait before requesting another verification email.'
                ], 429);
            }

            // Generate new token
            $token = $user->generateEmailVerificationToken();

            // Send verification email
            Mail::to($user->email)->send(new VerifyEmail($user, $token));

            // Marked sent — see the note on registration above.
            EmailLog::logEmail(
                $user->email,
                'verification_resend',
                'Verify Your Email - Taga',
                null,
                $user->id
            )->markAsSent();

            return response()->json([
                'success' => true,
                'message' => 'Verification email sent! Please check your inbox.',
            ]);

        } catch (\Exception $e) {
            \Log::error('Resend verification failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification email. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
