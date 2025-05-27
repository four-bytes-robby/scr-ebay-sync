<?php
declare(strict_types=1);

namespace Four\ScrEbaySync\Services\EbayWebhook;

use Four\ScrEbaySync\Services\EbayOrder\OrderImportService;
use Four\ScrEbaySync\Services\EbayOrder\OrderStatusService;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * eBay Webhook Service
 * 
 * Handles real-time eBay notifications via Push API instead of polling.
 * Provides near-instant order updates and reduces API call overhead.
 * 
 * @see https://developer.ebay.com/api-docs/commerce/notification/overview.html
 */
class WebhookService
{
    private OrderImportService $orderImportService;
    private OrderStatusService $orderStatusService;
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    private string $webhookSecret;
    
    public function __construct(
        OrderImportService $orderImportService,
        OrderStatusService $orderStatusService,
        EntityManagerInterface $entityManager,
        Logger $logger,
        string $webhookSecret
    ) {
        $this->orderImportService = $orderImportService;
        $this->orderStatusService = $orderStatusService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->webhookSecret = $webhookSecret;
    }
    
    /**
     * Process incoming eBay webhook notification
     * 
     * @param string $payload Raw webhook payload
     * @param string $signature eBay signature header
     * @return array Processing result
     */
    public function processWebhook(string $payload, string $signature): array
    {
        $this->logger->info('Processing eBay webhook notification', [
            'payload_size' => strlen($payload),
            'has_signature' => !empty($signature)
        ]);
        
        try {
            // Verify webhook signature
            if (!$this->verifySignature($payload, $signature)) {
                $this->logger->error('Invalid webhook signature');
                return ['status' => 'error', 'message' => 'Invalid signature'];
            }
            
            $notification = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Invalid JSON payload', ['error' => json_last_error_msg()]);
                return ['status' => 'error', 'message' => 'Invalid JSON'];
            }
            
            return $this->handleNotification($notification);
            
        } catch (\Exception $e) {
            $this->logger->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Handle specific notification types
     * 
     * @param array $notification Parsed notification data
     * @return array Processing result
     */
    private function handleNotification(array $notification): array
    {
        $topic = $notification['metadata']['topic'] ?? 'unknown';
        $this->logger->info('Handling notification', ['topic' => $topic]);
        
        switch ($topic) {
            case 'MARKETPLACE_ACCOUNT_DELETION':
                return $this->handleAccountDeletion($notification);
                
            case 'ITEM_SOLD':
            case 'FIXED_PRICE_TRANSACTION':
            case 'AUCTION_TRANSACTION':
                return $this->handleItemSold($notification);
                
            case 'ORDER_PAYMENT_RECEIVED':
                return $this->handlePaymentReceived($notification);
                
            case 'ORDER_SHIPPED':
                return $this->handleOrderShipped($notification);
                
            case 'ORDER_CANCELLED':
                return $this->handleOrderCancelled($notification);
                
            case 'RETURN_INITIATED':
                return $this->handleReturnInitiated($notification);
                
            case 'DISPUTE_OPENED':
                return $this->handleDisputeOpened($notification);
                
            default:
                $this->logger->warning('Unknown notification topic', ['topic' => $topic]);
                return ['status' => 'ignored', 'message' => 'Unknown topic'];
        }
    }
    
    /**
     * Handle new item sold notification
     * 
     * @param array $notification Notification data
     * @return array Processing result
     */
    private function handleItemSold(array $notification): array
    {
        try {
            $notificationData = $notification['notification'] ?? [];
            $orderId = $notificationData['orderId'] ?? null;
            $transactionId = $notificationData['transactionId'] ?? null;
            
            if (empty($orderId)) {
                return ['status' => 'error', 'message' => 'Missing order ID'];
            }
            
            $this->logger->info('Processing new sale', [
                'order_id' => $orderId,
                'transaction_id' => $transactionId
            ]);
            
            // Import the specific order immediately
            $importResult = $this->orderImportService->importSpecificOrder($orderId);
            
            return [
                'status' => 'success',
                'message' => 'Order imported',
                'order_id' => $orderId,
                'imported' => $importResult
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to handle item sold notification', [
                'error' => $e->getMessage()
            ]);
            
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Handle payment received notification
     * 
     * @param array $notification Notification data
     * @return array Processing result
     */
    private function handlePaymentReceived(array $notification): array
    {
        try {
            $orderId = $notification['notification']['orderId'] ?? null;
            
            if (empty($orderId)) {
                return ['status' => 'error', 'message' => 'Missing order ID'];
            }
            
            $this->logger->info('Processing payment received', ['order_id' => $orderId]);
            
            // Update payment status for this specific order
            $updated = $this->orderStatusService->updateSpecificOrderPayment($orderId);
            
            return [
                'status' => 'success',
                'message' => 'Payment status updated',
                'order_id' => $orderId,
                'updated' => $updated
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to handle payment notification', [
                'error' => $e->getMessage()
            ]);
            
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Handle order shipped notification
     * 
     * @param array $notification Notification data
     * @return array Processing result
     */
    private function handleOrderShipped(array $notification): array
    {
        // This would typically be triggered BY our system when we mark as shipped
        // But we can use it for validation/confirmation
        $orderId = $notification['notification']['orderId'] ?? null;
        
        $this->logger->info('Order shipped notification received', [
            'order_id' => $orderId,
            'note' => 'Confirmation of our own shipping update'
        ]);
        
        return [
            'status' => 'acknowledged',
            'message' => 'Shipping confirmation received',
            'order_id' => $orderId
        ];
    }
    
    /**
     * Handle order cancelled notification
     * 
     * @param array $notification Notification data
     * @return array Processing result
     */
    private function handleOrderCancelled(array $notification): array
    {
        try {
            $orderId = $notification['notification']['orderId'] ?? null;
            
            $this->logger->info('Processing order cancellation', ['order_id' => $orderId]);
            
            // Update cancellation status
            $updated = $this->orderStatusService->updateSpecificOrderCancellation($orderId);
            
            return [
                'status' => 'success',
                'message' => 'Cancellation processed',
                'order_id' => $orderId,
                'updated' => $updated
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to handle cancellation notification', [
                'error' => $e->getMessage()
            ]);
            
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Handle return initiated notification
     * 
     * @param array $notification Notification data
     * @return array Processing result
     */
    private function handleReturnInitiated(array $notification): array
    {
        $orderId = $notification['notification']['orderId'] ?? null;
        $returnId = $notification['notification']['returnId'] ?? null;
        
        $this->logger->warning('Return initiated', [
            'order_id' => $orderId,
            'return_id' => $returnId
        ]);
        
        // TODO: Implement return handling
        // Could create return records, update inventory, etc.
        
        return [
            'status' => 'logged',
            'message' => 'Return logged for manual processing',
            'order_id' => $orderId,
            'return_id' => $returnId
        ];
    }
    
    /**
     * Handle dispute opened notification
     * 
     * @param array $notification Notification data
     * @return array Processing result
     */
    private function handleDisputeOpened(array $notification): array
    {
        $orderId = $notification['notification']['orderId'] ?? null;
        $disputeId = $notification['notification']['disputeId'] ?? null;
        
        $this->logger->error('Dispute opened', [
            'order_id' => $orderId,
            'dispute_id' => $disputeId
        ]);
        
        // TODO: Implement dispute handling
        // Could send alerts, create tickets, etc.
        
        return [
            'status' => 'alerted',
            'message' => 'Dispute logged for immediate attention',
            'order_id' => $orderId,
            'dispute_id' => $disputeId
        ];
    }
    
    /**
     * Handle account deletion notification (GDPR compliance)
     * 
     * @param array $notification Notification data
     * @return array Processing result
     */
    private function handleAccountDeletion(array $notification): array
    {
        $userId = $notification['notification']['userId'] ?? null;
        
        $this->logger->critical('User account deletion request', [
            'user_id' => $userId,
            'compliance' => 'GDPR'
        ]);
        
        // TODO: Implement GDPR compliance
        // Must delete/anonymize user data within required timeframe
        
        return [
            'status' => 'compliance_required',
            'message' => 'GDPR deletion request logged',
            'user_id' => $userId
        ];
    }
    
    /**
     * Verify webhook signature for security
     * 
     * @param string $payload Raw payload
     * @param string $signature eBay signature
     * @return bool Valid signature
     */
    private function verifySignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret) || empty($signature)) {
            return false;
        }
        
        // eBay uses HMAC-SHA256 for webhook signatures
        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Get webhook subscription configuration
     * 
     * @return array Subscription config for eBay
     */
    public static function getSubscriptionConfig(): array
    {
        return [
            'topicIds' => [
                'MARKETPLACE_ACCOUNT_DELETION',
                'ITEM_SOLD',
                'FIXED_PRICE_TRANSACTION', 
                'AUCTION_TRANSACTION',
                'ORDER_PAYMENT_RECEIVED',
                'ORDER_SHIPPED',
                'ORDER_CANCELLED',
                'RETURN_INITIATED',
                'DISPUTE_OPENED'
            ],
            'deliveryConfig' => [
                'endpoint' => $_ENV['WEBHOOK_ENDPOINT'] ?? 'https://yourdomain.com/webhook/ebay',
                'verificationToken' => $_ENV['WEBHOOK_VERIFICATION_TOKEN'] ?? null
            ]
        ];
    }
}
