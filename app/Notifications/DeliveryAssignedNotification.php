<?php

namespace App\Notifications;

use App\Support\AppUrl;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class DeliveryAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $order;
    public $accessToken;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
        // Generate unique access token for this delivery
        $this->accessToken = Str::random(64);
        
        // Store token in order for verification
        $order->update(['delivery_access_token' => $this->accessToken]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        /*
         * This used to be url("/delivery/order/{id}?token=..."), which resolves
         * against APP_URL — the API host — where no such page exists. The
         * matching endpoints live under /api/v1/delivery/order/{id} and are
         * JSON, so every rider who clicked the button in this email got a 404.
         *
         * Riders are sent to their portal instead. The token endpoints still
         * work and are still worth a no-login page in the agents app; until
         * that page exists, the portal's own sign-in is the working route.
         */
        $portalUrl = AppUrl::agentPortal();
        $deliveryUrl = "{$portalUrl}/deliveries";


        return (new MailMessage)
            ->subject('New Delivery Assignment - Order #' . $this->order->order_number)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('You have been assigned a new delivery.')
            ->line('**Order Number:** ' . $this->order->order_number)
            ->line('**Customer:** ' . $this->order->customer_name)
            ->line('**Delivery Address:** ' . $this->getFormattedAddress())
            ->line('**Customer Phone:** ' . ($this->order->shipping_address['phone'] ?? 'N/A'))
            ->line('**Order Total:** ₦' . number_format($this->order->total_amount, 2))
            ->action('View Delivery Details', $deliveryUrl)
            ->line('Click the button above to view full order details and update delivery status.')
            ->line('Thank you for your service!');
    }

    /**
     * Get formatted shipping address
     */
    private function getFormattedAddress(): string
    {
        $address = $this->order->shipping_address;
        return sprintf(
            '%s, %s, %s, %s',
            $address['address'] ?? '',
            $address['city'] ?? '',
            $address['state'] ?? '',
            $address['country'] ?? 'Nigeria'
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'customer_name' => $this->order->customer_name,
            'delivery_address' => $this->getFormattedAddress(),
        ];
    }
}
