<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Central access point for pharmacy business-policy settings.
 *
 * ---------------------------------------------------------------------------
 * WHAT LIVES HERE vs WHAT IS DELIBERATELY HARD-CODED
 * ---------------------------------------------------------------------------
 * These are *business policy* knobs that legitimately differ between operators,
 * so an admin can tune them without a deploy.
 *
 * The following are intentionally NOT configurable, because switching one off
 * would disable a safety or legal safeguard rather than change a business rule:
 *
 *   - Prescriptions are stored on the private disk and only ever served through
 *     an authorisation-checked endpoint.
 *   - Forbidden prescription access returns 404, never 403, so ids cannot be probed.
 *   - Stock that is already past its expiry date can never be sold.
 *   - Controlled substances are never granted implicitly; they always require an
 *     explicit, separate permission.
 *   - A lapsed pharmacy licence always revokes regulated-selling permission,
 *     regardless of the stored permission flags.
 *   - An order containing prescription items cannot be dispatched until every one
 *     of those prescriptions is approved.
 *
 * If a future requirement needs one of those relaxed, it should be a considered
 * code change with its own review — not a checkbox in an admin panel.
 */
class PharmacyPolicy
{
    public const CATEGORY = 'pharmacy';

    /**
     * Minimum remaining shelf life, in days, for stock to be sellable.
     *
     * 0 means "only block stock that has actually expired". Many pharmacies refuse
     * to dispatch anything with less than 30–90 days left, which is why this is a
     * setting rather than a constant. Note this only ever *tightens* the rule:
     * already-expired stock is blocked regardless.
     */
    public static function minShelfLifeDays(): int
    {
        return (int) SystemSetting::getValue(self::CATEGORY, 'min_shelf_life_days', 0);
    }

    /**
     * How long an uploaded prescription stays valid when the customer does not
     * supply an explicit expiry date. Jurisdictions differ; 6 months is a common
     * default for a repeatable script.
     */
    public static function prescriptionValidityDays(): int
    {
        return (int) SystemSetting::getValue(self::CATEGORY, 'prescription_validity_days', 180);
    }

    /**
     * Look-ahead window for "expiring soon" reporting and dashboard warnings.
     */
    public static function stockExpiryWarningDays(): int
    {
        return (int) SystemSetting::getValue(self::CATEGORY, 'stock_expiry_warning_days', 90);
    }

    /**
     * Whether approving a pharmacy's licence automatically grants it permission to
     * sell prescription medicines. Some operators prefer to grant that separately.
     *
     * Controlled substances are never covered by this — they always stay opt-in.
     */
    public static function grantPrescriptionOnApproval(): bool
    {
        return self::boolean('grant_prescription_on_approval', true);
    }

    /**
     * Whether a platform admin may overturn a store's prescription decision.
     *
     * Store reviewers can never re-decide an already-decided prescription (that
     * would quietly rewrite an audit trail). This setting governs only the admin
     * escalation path, which is always recorded with the reviewer and a reason.
     */
    public static function allowAdminPrescriptionOverride(): bool
    {
        return self::boolean('allow_admin_prescription_override', true);
    }

    /**
     * The earliest expiry date a product may carry and still be sellable today.
     */
    public static function earliestSellableExpiryDate(): \Illuminate\Support\Carbon
    {
        return now()->addDays(self::minShelfLifeDays())->startOfDay();
    }

    /**
     * Settings are stored as JSON, so a saved `false` can come back as bool, int or
     * string depending on how it was written.
     */
    private static function boolean(string $key, bool $default): bool
    {
        $value = SystemSetting::getValue(self::CATEGORY, $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * All policy values in one payload, for the admin settings screen.
     */
    public static function all(): array
    {
        return [
            'min_shelf_life_days' => self::minShelfLifeDays(),
            'prescription_validity_days' => self::prescriptionValidityDays(),
            'stock_expiry_warning_days' => self::stockExpiryWarningDays(),
            'grant_prescription_on_approval' => self::grantPrescriptionOnApproval(),
            'allow_admin_prescription_override' => self::allowAdminPrescriptionOverride(),
        ];
    }
}
