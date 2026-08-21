<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailAutomationSetting;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EmailAutomationController extends Controller
{
    /**
     * Get all email automation settings
     */
    public function index(): JsonResponse
    {
        try {
            $settings = EmailAutomationSetting::orderBy('name')->get()
                ->map(function (EmailAutomationSetting $setting) {
                    // The screen renders a badge instead of a switch for these.
                    $setting->always_on = EmailAutomationSetting::isAlwaysOn($setting->key);

                    return $setting;
                });

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch email automation settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single automation setting
     */
    public function show(string $key): JsonResponse
    {
        try {
            $setting = EmailAutomationSetting::where('key', $key)->first();

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Setting not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $setting
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch setting',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update automation setting
     */
    public function update(Request $request, string $key): JsonResponse
    {
        try {
            $request->validate([
                'is_enabled' => 'sometimes|boolean',
                'config' => 'sometimes|array',
            ]);

            $setting = EmailAutomationSetting::where('key', $key)->first();

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Setting not found'
                ], 404);
            }

            // The config of an always-on email may still be edited; its
            // enabled flag may not.
            if (EmailAutomationSetting::isAlwaysOn($key) && $request->has('is_enabled')) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email is part of the transaction and cannot be switched off.',
                    'code' => 'always_on',
                ], 422);
            }

            $setting->update($request->only(['is_enabled', 'config']));

            return response()->json([
                'success' => true,
                'message' => 'Setting updated successfully',
                'data' => $setting
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
                'message' => 'Failed to update setting',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle automation setting
     */
    public function toggle(string $key): JsonResponse
    {
        try {
            $setting = EmailAutomationSetting::where('key', $key)->first();

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Setting not found'
                ], 404);
            }

            if (EmailAutomationSetting::isAlwaysOn($key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email is part of the transaction and cannot be switched off. '
                        .'A customer who pays and hears nothing is a support problem, not a preference.',
                    'code' => 'always_on',
                ], 422);
            }

            $setting->update(['is_enabled' => !$setting->is_enabled]);

            return response()->json([
                'success' => true,
                'message' => 'Setting toggled successfully',
                'data' => $setting
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle setting',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get email logs with filters
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $query = EmailLog::with('user')->orderBy('created_at', 'desc');

            // Filter by type
            if ($request->has('type') && $request->type) {
                $query->where('type', $request->type);
            }

            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Filter by date range
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // Filter by user
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            $logs = $query->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $logs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch email logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get email statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_sent' => EmailLog::where('status', EmailLog::STATUS_SENT)->count(),
                'total_failed' => EmailLog::where('status', EmailLog::STATUS_FAILED)->count(),
                'total_pending' => EmailLog::where('status', EmailLog::STATUS_PENDING)->count(),
                'by_type' => EmailLog::selectRaw('type, COUNT(*) as count')
                    ->groupBy('type')
                    ->get()
                    ->pluck('count', 'type'),
                'recent_24h' => EmailLog::where('created_at', '>=', now()->subDay())->count(),
                'recent_7d' => EmailLog::where('created_at', '>=', now()->subDays(7))->count(),
                'success_rate' => $this->calculateSuccessRate(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate email success rate
     */
    private function calculateSuccessRate(): float
    {
        $total = EmailLog::count();
        if ($total === 0) {
            return 0;
        }

        $sent = EmailLog::where('status', EmailLog::STATUS_SENT)->count();
        return round(($sent / $total) * 100, 2);
    }

    /**
     * Test email configuration
     */
    public function testEmail(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'type' => 'nullable|string',
            ]);

            // Send through the same mailbox the real automation uses, not the
            // default mailer. Each mailbox is its own SMTP account, so a test
            // from the default proves only that the default works — which was
            // how a broken shop mailbox went unnoticed while noreply was fine.
            $mailbox = EmailAutomationSetting::mailboxFor($request->input('type'));
            $from = config("mail.mailers.{$mailbox}.from.address");

            \Mail::mailer($mailbox)->raw(
                "This is a test email from Taga, sent through the {$mailbox} mailbox ({$from}) — "
                ."the same one the real message uses. If you received this, that mailbox is working.",
                function ($message) use ($request, $mailbox) {
                    $message->to($request->email)
                        ->subject("Test email from the {$mailbox} mailbox - Taga");
                }
            );

            return response()->json([
                'success' => true,
                'message' => "Test email sent to {$request->email} from the {$mailbox} mailbox ({$from})",
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
                'message' => 'Failed to send test email',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
