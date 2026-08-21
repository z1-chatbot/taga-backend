<?php

namespace App\Services;

use App\Models\Order;
use App\Models\DeliveryTrackingEvent;
use App\Models\DeliverySetting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class DeliveryNotificationService
{
    public function sendTrackingUpdate(DeliveryTrackingEvent $event)
    {
        $order = $event->order;
        $smsEnabled = DeliverySetting::getValue('sms_notifications_enabled', true);
        $emailEnabled = DeliverySetting::getValue('email_notifications_enabled', true);

        if ($emailEnabled && $order->user && $order->user->email) {
            $this->sendEmailNotification($order, $event);
        }

        if ($smsEnabled && $order->user && $order->user->phone) {
            $this->sendSMSNotification($order, $event);
        }
    }

    protected function sendEmailNotification(Order $order, DeliveryTrackingEvent $event)
    {
        try {
            // TODO: Implement email notification
            Log::info("Email notification sent for order {$order->order_number}: {$event->description}");
        } catch (\Exception $e) {
            Log::error("Failed to send email notification: " . $e->getMessage());
        }
    }

    protected function sendSMSNotification(Order $order, DeliveryTrackingEvent $event)
    {
        try {
            // TODO: Implement SMS notification (Termii, Twilio, etc.)
            Log::info("SMS notification sent for order {$order->order_number}: {$event->description}");
        } catch (\Exception $e) {
            Log::error("Failed to send SMS notification: " . $e->getMessage());
        }
    }
}
