<?php

namespace App\Services;

use App\Models\Order;
use App\Mail\OrderNotificationEmail;
use App\Models\EmailLog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class OrderNotificationService
{
    /**
     * Notify the trade side when an order is placed and paid for.
     *
     * The customer is deliberately not told from here. They used to be: this
     * sent them an "order_placed" message and then, in the very next statement
     * of every caller, an OrderStatusEmail('confirmed') went out as well — two
     * emails about one event, arriving in the same second, saying the same
     * thing in two different templates.
     *
     * The 'confirmed' one is the survivor. It carries the delivery code, which
     * is the one thing in a post-payment email a customer has to keep, and it
     * reads as a receipt rather than an announcement. Anything that belongs in
     * the customer's copy belongs in emails/order-status.blade.php now, not
     * here.
     */
    public function notifyOrderPlaced(Order $order)
    {
        Log::info("OrderNotificationService: Sending order placed notifications for order #{$order->order_number}");

        // Notify store owner(s)
        $this->sendToStoreOwners($order, 'new_order', [
            'order_number' => $order->order_number,
            'items' => $order->items
        ]);
        
        // Notify admin
        $this->sendToAdmin($order, 'new_order', [
            'order_number' => $order->order_number
        ]);
    }
    
    /**
     * Notify all parties when order status changes
     */
    public function notifyStatusUpdate(Order $order, string $oldStatus, string $newStatus)
    {
        Log::info("OrderNotificationService: Status changed from {$oldStatus} to {$newStatus} for order #{$order->order_number}");
        
        // Always notify customer of status changes
        $this->sendToCustomer($order, 'status_update', [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'order_number' => $order->order_number
        ]);
        
        // Send specific notifications based on new status
        switch ($newStatus) {
            case 'processing':
                // Store started processing
                Log::info("Order #{$order->order_number} is now being processed");
                break;
                
            case 'ready_for_pickup':
                // Notify admin that order is ready for delivery assignment
                $this->sendToAdmin($order, 'ready_for_pickup', [
                    'order_number' => $order->order_number,
                    'store_names' => $this->getStoreNames($order)
                ]);
                break;
                
            // 'assigned' is not a status this application ever sets — the
            // column's value is 'assigned_to_agent'. This arm never ran, so
            // neither the rider nor the pharmacy was told an assignment had
            // happened.
            case 'assigned_to_agent':
            case 'assigned':
                // Delivery agent assigned
                if ($order->deliveryAgent) {
                    $this->sendToAgent($order, 'delivery_assigned', [
                        'order_number' => $order->order_number,
                        'pickup_address' => $this->getStoreAddresses($order),
                        'delivery_address' => $order->shipping_address
                    ]);
                    
                    $this->sendToStoreOwners($order, 'agent_assigned', [
                        'order_number' => $order->order_number,
                        'agent_name' => $order->deliveryAgent->name,
                        'agent_phone' => $order->deliveryAgent->phone
                    ]);
                }
                break;
                
            // 'shipped' is the admin console's word for the same moment the
            // rider calls 'picked_up' — the parcel has left the pharmacy. Both
            // reach the same people.
            case 'shipped':
            case 'picked_up':
                // Agent picked up from store
                $this->sendToStoreOwners($order, 'order_collected', [
                    'order_number' => $order->order_number,
                    'agent_name' => $order->deliveryAgent->name ?? 'Agent'
                ]);
                
                $this->sendToAdmin($order, 'order_picked_up', [
                    'order_number' => $order->order_number
                ]);
                break;
                
            case 'arrived_at_hub':
                // Interstate: order arrived at logistics hub, needs reassignment
                $this->sendToAdmin($order, 'arrived_at_hub', [
                    'order_number' => $order->order_number
                ]);
                Log::info("Order #{$order->order_number} arrived at logistics hub");
                break;
                
            case 'out_for_delivery':
                // Agent is out for final delivery to customer
                Log::info("Order #{$order->order_number} is out for delivery");
                break;
                
            case 'in_transit':
                // Interstate: logistics company is transporting between states
                $this->sendToAdmin($order, 'in_transit', [
                    'order_number' => $order->order_number
                ]);
                Log::info("Order #{$order->order_number} is in transit between states");
                break;
                
            case 'delivered':
                // Successfully delivered
                $this->sendToStoreOwners($order, 'order_delivered', [
                    'order_number' => $order->order_number
                ]);
                
                $this->sendToAdmin($order, 'order_delivered', [
                    'order_number' => $order->order_number
                ]);
                
                if ($order->deliveryAgent) {
                    $this->sendToAgent($order, 'delivery_completed', [
                        'order_number' => $order->order_number,
                        'earnings' => $order->delivery_fee ?? 0
                    ]);
                }
                break;
                
            case 'cancelled':
                // Order cancelled
                $this->sendToStoreOwners($order, 'order_cancelled', [
                    'order_number' => $order->order_number,
                    'reason' => $order->notes ?? 'No reason provided'
                ]);
                
                if ($order->deliveryAgent) {
                    $this->sendToAgent($order, 'delivery_cancelled', [
                        'order_number' => $order->order_number
                    ]);
                }
                break;
        }
    }
    
    /**
     * Notify about delivery issues
     */
    public function notifyDeliveryIssue(Order $order, string $issue)
    {
        $this->sendToAdmin($order, 'delivery_issue', [
            'order_number' => $order->order_number,
            'issue' => $issue
        ]);
        
        $this->sendToCustomer($order, 'delivery_issue', [
            'order_number' => $order->order_number,
            'issue' => $issue
        ]);
    }
    
    /**
     * Send email to customer
     */
    private function sendToCustomer(Order $order, string $template, array $data = [])
    {
        $recipientEmail = null;
        $recipientName = 'Customer';
        
        if ($order->user && $order->user->email) {
            $recipientEmail = $order->user->email;
            $recipientName = $order->user->name;
        } elseif (isset($order->shipping_address['email'])) {
            $recipientEmail = $order->shipping_address['email'];
            $recipientName = $order->shipping_address['name'] ?? 
                           ($order->shipping_address['firstName'] ?? '') . ' ' . ($order->shipping_address['lastName'] ?? '');
        }
        
        if ($recipientEmail) {
            try {
                $emailLog = EmailLog::logEmail(
                    $recipientEmail,
                    'order_' . $template,
                    'Order Notification',
                    null,
                    $order->user_id
                );
                
                Mail::to($recipientEmail)->send(
                    new OrderNotificationEmail($order, $template, $recipientName, $data)
                );
                
                $emailLog->markAsSent();
                Log::info("Sent {$template} email to customer: {$recipientEmail}");
            } catch (\Exception $e) {
                Log::error("Failed to send email to customer: " . $e->getMessage());
                if (isset($emailLog)) {
                    $emailLog->markAsFailed($e->getMessage());
                }
            }
        }
    }
    
    /**
     * Send email to store owner(s)
     */
    private function sendToStoreOwners(Order $order, string $template, array $data = [])
    {
        $stores = collect();
        
        foreach ($order->items as $item) {
            if ($item->product && $item->product->store) {
                $stores->push($item->product->store);
            }
        }
        
        $stores = $stores->unique('id');
        
        foreach ($stores as $store) {
            if ($store->owner && $store->owner->email) {
                try {
                    $emailLog = EmailLog::logEmail(
                        $store->owner->email,
                        'order_' . $template,
                        'Order Notification',
                        null,
                        $store->owner_id
                    );
                    
                    Mail::to($store->owner->email)->send(
                        new OrderNotificationEmail($order, $template, $store->owner->name, $data)
                    );
                    
                    $emailLog->markAsSent();
                    Log::info("Sent {$template} email to store owner: {$store->owner->email}");
                } catch (\Exception $e) {
                    Log::error("Failed to send email to store owner: " . $e->getMessage());
                    if (isset($emailLog)) {
                        $emailLog->markAsFailed($e->getMessage());
                    }
                }
            }
        }
    }
    
    /**
     * Send email to admin
     */
    /**
     * Tell every platform administrator.
     *
     * This used to resolve one address as
     * `config('mail.admin_email', env('ADMIN_EMAIL', 'admin@example.com'))`.
     * config/mail.php declared no such key, so it always fell through to the
     * default — and that default calls env() outside a config file, which
     * returns null once `config:cache` has run, because Laravel then never
     * loads the .env at all. Every order notification from a cached-config
     * deploy went to the literal admin@example.com.
     *
     * PlatformAdmins resolves real admin accounts first and treats the
     * configured address as a fallback for a platform that has none yet.
     */
    private function sendToAdmin(Order $order, string $template, array $data = [])
    {
        $recipients = \App\Support\PlatformAdmins::emails();

        if (empty($recipients)) {
            Log::warning("No platform administrator to notify about order {$order->order_number}", [
                'hint' => 'Create a user with role=admin, or set ADMIN_EMAIL.',
            ]);

            return;
        }

        foreach ($recipients as $adminEmail) {
            try {
                $emailLog = EmailLog::logEmail(
                    $adminEmail,
                    'order_' . $template,
                    'Order Notification',
                    null,
                    null
                );

                // A fresh mailable per recipient: Mailable::to() accumulates,
                // so reusing one instance across the loop would send the second
                // message to two people and the third to three.
                Mail::to($adminEmail)->send(
                    new OrderNotificationEmail($order, $template, 'Admin', $data)
                );

                $emailLog->markAsSent();
                Log::info("Sent {$template} email to admin: {$adminEmail}");
            } catch (\Exception $e) {
                Log::error("Failed to send email to admin: " . $e->getMessage());
                if (isset($emailLog)) {
                    $emailLog->markAsFailed($e->getMessage());
                }
            }
        }
    }
    
    /**
     * Send email to delivery agent
     */
    private function sendToAgent(Order $order, string $template, array $data = [])
    {
        if ($order->deliveryAgent && $order->deliveryAgent->email) {
            try {
                $emailLog = EmailLog::logEmail(
                    $order->deliveryAgent->email,
                    'order_' . $template,
                    'Order Notification',
                    null,
                    null
                );
                
                Mail::to($order->deliveryAgent->email)->send(
                    new OrderNotificationEmail($order, $template, $order->deliveryAgent->name, $data)
                );
                
                $emailLog->markAsSent();
                Log::info("Sent {$template} email to agent: {$order->deliveryAgent->email}");
            } catch (\Exception $e) {
                Log::error("Failed to send email to agent: " . $e->getMessage());
                if (isset($emailLog)) {
                    $emailLog->markAsFailed($e->getMessage());
                }
            }
        }
    }
    
    /**
     * Get store names from order
     */
    private function getStoreNames(Order $order): string
    {
        $stores = collect();
        
        foreach ($order->items as $item) {
            if ($item->product && $item->product->store) {
                $stores->push($item->product->store->name);
            }
        }
        
        return $stores->unique()->implode(', ');
    }
    
    /**
     * Get store addresses from order
     */
    private function getStoreAddresses(Order $order): array
    {
        $addresses = [];
        
        foreach ($order->items as $item) {
            if ($item->product && $item->product->store) {
                $store = $item->product->store;
                $addresses[] = [
                    'store_name' => $store->name,
                    'address' => $store->address,
                    'phone' => $store->phone
                ];
            }
        }
        
        return $addresses;
    }
}
