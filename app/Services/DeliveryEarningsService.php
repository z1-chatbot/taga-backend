<?php

namespace App\Services;

use App\Models\AgentEarning;
use App\Models\DeliveryAgent;
use App\Models\DeliverySetting;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ShippingRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a delivery is turned into money owed.
 *
 * Before this existed the same calculation was written out four times — in the
 * rider portal, the logistics portal, the admin console and on the Order model —
 * and each copy disagreed with the others about who gets paid and how much. Two
 * further admin routes marked an order delivered and credited nobody at all.
 * None of them checked whether the order had already been paid for, so a rider
 * confirming a delivery that an admin then also confirmed produced two credits
 * for one journey.
 */
class DeliveryEarningsService
{
    /**
     * Credit whoever carried this order, once.
     *
     * Safe to call from every path that can mark an order delivered — the first
     * call wins and later ones return the earning already on file.
     */
    public function creditForDelivery(Order $order): ?AgentEarning
    {
        return DB::transaction(function () use ($order) {
            // Lock the order row so two portals confirming the same delivery at
            // the same moment cannot both find the ledger empty.
            Order::whereKey($order->id)->lockForUpdate()->first();

            $existing = AgentEarning::where('order_id', $order->id)->first();

            if ($existing) {
                Log::info('Delivery earning already recorded, not crediting again', [
                    'order_id' => $order->id,
                    'earning_id' => $existing->id,
                ]);

                return $existing;
            }

            [$company, $agent] = $this->partiesFor($order);

            if (! $company && ! $agent) {
                return null;
            }

            $breakdown = $this->quote($order, $company, $agent);

            if ($breakdown['courier'] <= 0) {
                Log::warning('Delivery completed with nothing owed to the courier', [
                    'order_id' => $order->id,
                    'customer_fee' => $breakdown['customer_fee'],
                ]);

                return null;
            }

            $holdHours = (int) DeliverySetting::getValue('earnings_hold_period_hours', 0);
            $immediate = $holdHours <= 0;

            $earning = AgentEarning::create([
                'delivery_agent_id' => $agent?->id,
                'logistics_company_id' => $company?->id,
                'order_id' => $order->id,
                'delivery_fee' => $breakdown['customer_fee'],
                'agreed_rate' => $breakdown['agreed_rate'],
                'agent_commission' => $breakdown['courier'],
                'platform_commission' => $breakdown['platform'],
                'commission_percentage' => $breakdown['courier_percentage'],
                'status' => $immediate ? 'available' : 'pending',
                'available_at' => $immediate ? now() : now()->addHours($holdHours),
            ]);

            // Exactly one balance moves. Both the rider portal and the logistics
            // portal can request a payout against their own balance, so crediting
            // both for one delivery paid the same journey twice.
            $payee = $this->payeeFor($company, $agent);

            $payee->increment($immediate ? 'available_balance' : 'pending_balance', $breakdown['courier']);
            $payee->increment('total_earned', $breakdown['courier']);

            Log::info('Delivery earning credited', [
                'order_id' => $order->id,
                'payee' => $payee instanceof LogisticsCompany ? 'company' : 'agent',
                'payee_id' => $payee->id,
                'amount' => $breakdown['courier'],
                'platform' => $breakdown['platform'],
                'basis' => $breakdown['basis'],
                'available_immediately' => $immediate,
            ]);

            return $earning;
        });
    }

    /**
     * What this delivery is worth, without writing anything.
     *
     * The assignment email uses this too, so the figure a rider is shown when
     * they accept a job is the figure they are actually paid.
     *
     * @return array{customer_fee: float, courier: float, platform: float, agreed_rate: float|null, courier_percentage: float, basis: string}
     */
    public function quote(Order $order, ?LogisticsCompany $company = null, ?DeliveryAgent $agent = null): array
    {
        if (! $company && ! $agent) {
            [$company, $agent] = $this->partiesFor($order);
        }

        $customerFee = round((float) ($order->shipping_amount ?? 0), 2);

        $agreedRate = $this->agreedRateFor($order, $company?->id ?? $agent?->logistics_company_id);

        if ($agreedRate !== null && $agreedRate > 0) {
            // A rate on file is a contract with the courier. It is what they are
            // owed whether that is more or less than the customer happened to pay.
            $courier = round($agreedRate, 2);
            $basis = 'agreed_rate';
        } else {
            // No rate on file. Fall back to the share configured in delivery
            // settings — the admin console has always offered these fields, and
            // until now nothing read them, so a courier with no rate on file
            // silently took the entire shipping fee and the platform earned zero.
            $courier = round(($customerFee * $this->commissionPercentageFor($company, $agent)) / 100, 2);
            $basis = 'commission_percentage';
        }

        $courier = max(0.0, $courier);
        $platform = round(max(0, $customerFee - $courier), 2);

        return [
            'customer_fee' => $customerFee,
            'courier' => $courier,
            'platform' => $platform,
            'agreed_rate' => $agreedRate !== null && $agreedRate > 0 ? round($agreedRate, 2) : null,
            'courier_percentage' => $customerFee > 0 ? round(($courier / $customerFee) * 100, 2) : 100.0,
            'basis' => $basis,
        ];
    }

    /**
     * The share of the customer's fee a courier keeps when no rate is on file.
     *
     * With the commission system switched off the courier keeps all of it, which
     * is what the toggle in the admin console has always claimed to do.
     */
    private function commissionPercentageFor(?LogisticsCompany $company, ?DeliveryAgent $agent): float
    {
        if (! DeliverySetting::getValue('enable_commission_system', false)) {
            return 100.0;
        }

        if ($company) {
            $percentage = $company->commission_percentage
                ?? DeliverySetting::getValue(DeliverySetting::LOGISTICS_COMPANY_COMMISSION_PERCENTAGE, 85);
        } else {
            $percentage = DeliverySetting::getValue(DeliverySetting::AGENT_COMMISSION_PERCENTAGE, 75);
        }

        return max(0.0, min(100.0, (float) $percentage));
    }

    /**
     * The rate agreed with this courier for the route the parcel travelled.
     */
    private function agreedRateFor(Order $order, $companyId): ?float
    {
        $destination = ($order->shipping_address ?? [])['state'] ?? null;
        $origin = $order->origin_state ?? $order->store?->state;

        if (! $origin) {
            $firstItem = $order->items()->with('product.store')->first();
            $origin = $firstItem?->product?->store?->state;
        }

        if (! $origin || ! $destination) {
            return null;
        }

        $rate = ShippingRate::findRate($origin, $destination, $companyId);

        return $rate ? (float) $rate->base_rate : null;
    }

    /**
     * @return array{0: ?LogisticsCompany, 1: ?DeliveryAgent}
     */
    private function partiesFor(Order $order): array
    {
        $agent = $order->delivery_agent_id ? DeliveryAgent::find($order->delivery_agent_id) : null;

        $companyId = $order->logistics_company_id ?: $agent?->logistics_company_id;
        $company = $companyId ? LogisticsCompany::find($companyId) : null;

        return [$company, $agent];
    }

    /**
     * A rider working under a company is paid by that company, not by us — so the
     * balance sits with the company. A rider with no company is paid directly.
     */
    private function payeeFor(?LogisticsCompany $company, ?DeliveryAgent $agent)
    {
        return $company ?: $agent;
    }
}
