<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SocialAuthController extends Controller
{
    /**
     * Handle Google OAuth login/registration
     */
    public function googleAuth(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'google_id' => 'required|string',
            'email' => 'required|email',
            'name' => 'required|string',
            'avatar' => 'nullable|string',
            'email_verified' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check if user exists with this Google ID
            $user = User::where('google_id', $request->google_id)->first();

            if (!$user) {
                // Check if user exists with this email
                $user = User::where('email', $request->email)->first();

                if ($user) {
                    // Link Google account to existing user
                    $user->update([
                        'google_id' => $request->google_id,
                        'avatar' => $request->avatar ?? $user->avatar,
                        'email_verified_at' => $request->email_verified ? now() : $user->email_verified_at
                    ]);

                    Log::info('Google account linked to existing user', [
                        'user_id' => $user->id,
                        'email' => $user->email
                    ]);
                } else {
                    // Create new user
                    $user = User::create([
                        'name' => $request->name,
                        'email' => $request->email,
                        'google_id' => $request->google_id,
                        'avatar' => $request->avatar,
                        'password' => Hash::make(Str::random(32)), // Random password for OAuth users
                        'email_verified_at' => $request->email_verified ? now() : null,
                        'role' => 'customer'
                    ]);

                    Log::info('New user created via Google OAuth', [
                        'user_id' => $user->id,
                        'email' => $user->email
                    ]);

                    // Send welcome email
                    try {
                        \Mail::to($user->email)->send(new \App\Mail\WelcomeEmail($user));
                    } catch (\Exception $e) {
                        Log::error('Failed to send welcome email to OAuth user: ' . $e->getMessage());
                    }
                }
            } else {
                // Update user info if needed
                $user->update([
                    'name' => $request->name,
                    'avatar' => $request->avatar ?? $user->avatar,
                    'email_verified_at' => $request->email_verified ? now() : $user->email_verified_at
                ]);

                Log::info('Existing Google user logged in', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
            }

            // Generate token (same method as AuthController for consistency)
            $token = \App\Support\ApiToken::issue(\App\Support\ApiToken::TYPE_USER, $user->id, $user->email);

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
                        'email_verified' => !is_null($user->email_verified_at)
                    ],
                    'token' => $token
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Google OAuth error: ' . $e->getMessage(), [
                'email' => $request->email,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Authentication failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Unlink Google account from user
     */
    public function unlinkGoogle(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user->google_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Google account linked'
                ], 400);
            }

            // Check if user has a password set (to prevent lockout)
            if (!$user->password || $user->password === Hash::make(Str::random(32))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please set a password before unlinking your Google account to prevent account lockout'
                ], 400);
            }

            $user->update([
                'google_id' => null
            ]);

            Log::info('Google account unlinked', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Google account unlinked successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error unlinking Google account: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to unlink Google account'
            ], 500);
        }
    }
}
