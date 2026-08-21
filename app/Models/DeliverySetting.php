<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DeliverySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'group'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Scopes
    public function scopeByGroup($query, $group)
    {
        return $query->where('group', $group);
    }

    public function scopeByKey($query, $key)
    {
        return $query->where('key', $key);
    }

    // Methods
    public function getTypedValue()
    {
        switch ($this->type) {
            case 'number':
                return is_numeric($this->value) ? (float) $this->value : 0;
            case 'boolean':
                return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($this->value, true);
            default:
                // Handle boolean-like strings stored with type='string'
                if (in_array(strtolower($this->value), ['true', 'false', '1', '0'], true)) {
                    return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
                }
                return $this->value;
        }
    }

    // Static methods
    public static function getValue($key, $default = null)
    {
        return Cache::remember("delivery_setting_{$key}", 3600, function () use ($key, $default) {
            $setting = self::byKey($key)->first();
            return $setting ? $setting->getTypedValue() : $default;
        });
    }

    public static function setValue($key, $value, $type = null)
    {
        // Auto-detect type if not explicitly provided
        if ($type === null) {
            if (is_bool($value) || in_array(strtolower((string) $value), ['true', 'false'], true)) {
                $type = 'boolean';
            } elseif (is_numeric($value)) {
                $type = 'number';
            } else {
                $type = 'string';
            }
        }

        $setting = self::byKey($key)->first();

        if ($setting) {
            $setting->update(['value' => $value, 'type' => $type]);
        } else {
            $setting = self::create([
                'key' => $key,
                'value' => $value,
                'type' => $type
            ]);
        }

        // The group and all-settings caches are built from the same rows, so a
        // saved change stayed invisible to anything reading through those for
        // up to an hour.
        Cache::forget("delivery_setting_{$key}");
        Cache::forget("delivery_settings_group_{$setting->group}");
        Cache::forget('delivery_settings_all');

        return $setting;
    }

    public static function getByGroup($group)
    {
        return Cache::remember("delivery_settings_group_{$group}", 3600, function () use ($group) {
            return self::byGroup($group)->get()->mapWithKeys(function ($setting) {
                return [$setting->key => $setting->getTypedValue()];
            });
        });
    }

    public static function getAllSettings()
    {
        return Cache::remember('delivery_settings_all', 3600, function () {
            return self::all()->mapWithKeys(function ($setting) {
                return [$setting->key => $setting->getTypedValue()];
            });
        });
    }

    /**
     * Drops only what this model caches.
     *
     * This used to call Cache::flush(), which emptied the whole application
     * cache — sessions, rate limiters and everything else — to invalidate a
     * handful of settings rows.
     */
    public static function clearCache()
    {
        foreach (self::all() as $setting) {
            Cache::forget("delivery_setting_{$setting->key}");
            Cache::forget("delivery_settings_group_{$setting->group}");
        }

        Cache::forget('delivery_settings_all');
    }

    // Setting key constants
    const AGENT_COMMISSION_PERCENTAGE = 'agent_commission_percentage';
    const LOGISTICS_COMPANY_COMMISSION_PERCENTAGE = 'logistics_company_commission_percentage';
    const MINIMUM_PAYOUT_AMOUNT = 'minimum_payout_amount';
    const EARNINGS_HOLD_PERIOD_HOURS = 'earnings_hold_period_hours';
    const FREE_SHIPPING_THRESHOLD = 'free_shipping_threshold';
    const COD_FEE_PERCENTAGE = 'cod_fee_percentage';
    const SMS_NOTIFICATIONS_ENABLED = 'sms_notifications_enabled';
    const EMAIL_NOTIFICATIONS_ENABLED = 'email_notifications_enabled';
}
