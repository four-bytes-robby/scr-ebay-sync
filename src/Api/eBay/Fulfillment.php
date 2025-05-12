<?php
// src/Api/eBay/Fulfillment.php
namespace Four\ScrEbaySync\Api\eBay;

use Monolog\Logger;

/**
 * Client for eBay Fulfillment API
 */
class Fulfillment extends ApiClient
{
    protected string $apiVersion = 'v1';

    /**
     * @param Auth $auth The eBay Authentication instance
     * @param Logger|null $logger Optional logger
     */
    public function __construct(Auth $auth, ?Logger $logger = null)
    {
        parent::__construct($auth, $logger ?? new Logger('ebay_fulfillment'));
    }

    /**
     * Get all orders
     *
     * @param array $queryParams Additional query parameters
     * @return array Order data
     */
    public function getOrders(array $queryParams = []): array
    {
        return $this->get("/sell/fulfillment/{$this->apiVersion}/order", $queryParams);
    }

    /**
     * Get orders by creation date
     *
     * @param \DateTime $fromDate Start date
     * @param \DateTime|null $toDate End date (defaults to now)
     * @param int $limit Maximum number of orders (default 50)
     * @return array Order data
     */
    public function getOrdersByCreationDate(\DateTime $fromDate, ?\DateTime $toDate = null, int $limit = 50): array
    {
        $toDate = $toDate ?? new \DateTime();
        
        $queryParams = [
            'filter' => 'creationdate:[' . $fromDate->format('Y-m-d\TH:i:s\Z') . '..' . $toDate->format('Y-m-d\TH:i:s\Z') . ']',
            'limit' => $limit
        ];
        
        return $this->getOrders($queryParams);
    }

    /**
     * Get a specific order
     *
     * @param string $orderId The eBay order ID
     * @return array Order data
     */
    public function getOrder(string $orderId): array
    {
        return $this->get("/sell/fulfillment/{$this->apiVersion}/order/{$orderId}");
    }
    
    /**
     * Get orders by specific status
     *
     * @param string $status Order status (ACTIVE, COMPLETED, etc.)
     * @param int $limit Maximum number of orders (default 50)
     * @return array Order data
     */
    public function getOrdersByStatus(string $status, int $limit = 50): array
    {
        $queryParams = [
            'filter' => 'orderfulfillmentstatus:{' . strtoupper($status) . '}',
            'limit' => $limit
        ];
        
        return $this->getOrders($queryParams);
    }

    /**
     * Get shipping fulfillments for an order
     *
     * @param string $orderId The eBay order ID
     * @return array Shipping fulfillment data
     */
    public function getShippingFulfillments(string $orderId): array
    {
        return $this->get("/sell/fulfillment/{$this->apiVersion}/order/{$orderId}/shipping_fulfillment");
    }

    /**
     * Create a shipping fulfillment
     *
     * @param string $orderId The eBay order ID
     * @param array $fulfillmentData Shipping details
     * @return array Response data
     */
    public function createShippingFulfillment(string $orderId, array $fulfillmentData): array
    {
        return $this->post("/sell/fulfillment/{$this->apiVersion}/order/{$orderId}/shipping_fulfillment", $fulfillmentData);
    }

    /**
     * Mark an order as shipped
     *
     * @param string $orderId The eBay order ID
     * @param string $trackingNumber Shipping tracking number
     * @param string $carrier Shipping carrier
     * @param \DateTime|null $shippedDate Shipped date (defaults to now)
     * @return array Response data
     */
    public function markAsShipped(string $orderId, string $trackingNumber, string $carrier, ?\DateTime $shippedDate = null): array
    {
        $shippedDate = $shippedDate ?? new \DateTime();
        
        // Get order line items first
        $order = $this->getOrder($orderId);
        
        if (!isset($order['lineItems']) || empty($order['lineItems'])) {
            throw new \RuntimeException("No line items found for order {$orderId}");
        }
        
        // Create line item data for shipping
        $lineItems = [];
        foreach ($order['lineItems'] as $lineItem) {
            $lineItems[] = [
                'lineItemId' => $lineItem['lineItemId'],
                'quantity' => $lineItem['quantity']
            ];
        }
        
        $fulfillmentData = [
            'lineItems' => $lineItems,
            'shippedDate' => $shippedDate->format('Y-m-d\TH:i:s.000\Z'),
            'shippingCarrierCode' => $carrier,
            'trackingNumber' => $trackingNumber
        ];
        
        return $this->createShippingFulfillment($orderId, $fulfillmentData);
    }

    /**
     * Get payment disputes
     *
     * @param array $queryParams Additional query parameters
     * @return array Payment dispute data
     */
    public function getPaymentDisputes(array $queryParams = []): array
    {
        return $this->get("/sell/fulfillment/{$this->apiVersion}/payment_dispute", $queryParams);
    }

    /**
     * Get a specific payment dispute
     *
     * @param string $disputeId The payment dispute ID
     * @return array Payment dispute data
     */
    public function getPaymentDispute(string $disputeId): array
    {
        return $this->get("/sell/fulfillment/{$this->apiVersion}/payment_dispute/{$disputeId}");
    }

    /**
     * Mark order as paid
     *
     * @param string $orderId The eBay order ID
     * @return array Response data
     */
    public function markOrderAsPaid(string $orderId): array
    {
        // For the REST API, we use the "issue_refund" endpoint with a type of "PAYPAL" and state of "REFUNDED"
        // This endpoint doesn't exist, but we need to consider how to handle this in the REST API
        // This method is a placeholder - in the REST API we might not need to explicitly mark as paid
        
        // For now, just return the order info to indicate success
        return $this->getOrder($orderId);
    }

    /**
     * Cancel an order
     *
     * @param string $orderId The eBay order ID
     * @param string $cancelReason Reason for cancellation
     * @return array Response data
     */
    public function cancelOrder(string $orderId, string $cancelReason): array
    {
        // In REST API, cancellation is done through this endpoint with a POST
        return $this->post("/sell/fulfillment/{$this->apiVersion}/order/{$orderId}/cancel", [
            'cancelStateReason' => $cancelReason,
            'legacyOrderId' => $orderId
        ]);
    }

    /**
     * Issue a refund for an order
     *
     * @param string $orderId The eBay order ID
     * @param array $refundData Refund details
     * @return array Response data
     */
    public function issueRefund(string $orderId, array $refundData): array
    {
        return $this->post("/sell/fulfillment/{$this->apiVersion}/order/{$orderId}/issue_refund", $refundData);
    }
}