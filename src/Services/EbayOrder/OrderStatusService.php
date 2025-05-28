<?php
// src/Services/EbayOrder/OrderStatusService.php
namespace Four\ScrEbaySync\Services\EbayOrder;

use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Four\ScrEbaySync\Entity\ScrInvoice;
use Four\ScrEbaySync\Entity\EbayTransaction;
use Four\ScrEbaySync\Services\EbayOrder\CarrierMapping;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for updating eBay order statuses
 * 
 * Handles synchronization of order status from SCR invoices to eBay:
 * - Payment status (based on SCR invoice paydat)
 * - Shipping status (based on SCR invoice dispatchdat + tracking)
 * - Cancellation status (based on SCR invoice closed flag)
 */
class OrderStatusService
{
    private Fulfillment $fulfillmentApi;
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    
    /**
     * @param Fulfillment $fulfillmentApi The eBay Fulfillment API client
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        Fulfillment $fulfillmentApi,
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->fulfillmentApi = $fulfillmentApi;
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('ebay_order_status');
    }
    
    /**
     * Update the status of orders
     *
     * @return int Number of updated orders
     */
    public function updateOrderStatus(): int
    {
        $this->logger->info("Starting eBay order status updates");
        
        try {
            // Update orders marked as shipped
            $shippedCount = $this->updateShippedOrders();
            
            // Update orders marked as canceled
            $canceledCount = $this->updateCanceledOrders();
            
            $totalUpdated = $shippedCount + $canceledCount;
            
            $this->logger->info("Completed eBay order status updates", [
                'shipped_orders' => $shippedCount,
                'canceled_orders' => $canceledCount,
                'total_updated' => $totalUpdated
            ]);
            
            return $totalUpdated;
        } catch (\Exception $e) {
            $this->logger->error("Exception updating order statuses: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update orders marked as shipped
     * 
     * Finds transactions that need shipping updates based on SCR invoice changes.
     * Uses invoice.updated > transaction.updated to detect changes.
     *
     * @return int Number of updated orders
     */
    private function updateShippedOrders(): int
    {
        // Find transactions where invoice was updated after transaction
        $qb = $this->entityManager->createQueryBuilder();
        $transactions = $qb->select('t')
            ->from(EbayTransaction::class, 't')
            ->join(ScrInvoice::class, 'i', 'WITH', 't.invoice_id = i.id')
            ->where('i.dispatchdat IS NOT NULL')
            ->andWhere('i.tracking IS NOT NULL')
            ->andWhere("i.tracking != ''")
            ->andWhere($qb->expr()->orX(
                // Never updated transaction
                't.updated IS NULL',
                // Invoice updated after last transaction update
                'i.updated > t.updated',
                // Tracking changed
                't.ebayTracking != i.tracking OR t.ebayTracking IS NULL'
            ))
            ->getQuery()
            ->getResult();
            
        $count = 0;
        
        foreach ($transactions as $transaction) {
            try {
                $invoice = $this->entityManager->getRepository(ScrInvoice::class)
                    ->find($transaction->getInvoiceId());
                
                if (!$invoice) {
                    $this->logger->warning("Invoice not found for transaction", [
                        'transaction_id' => $transaction->getId(),
                        'invoice_id' => $transaction->getInvoiceId()
                    ]);
                    continue;
                }
                
                $trackingNumber = trim($invoice->getTracking());
                if (empty($trackingNumber)) {
                    $this->logger->debug("No tracking number for invoice", [
                        'invoice_id' => $invoice->getId(),
                        'transaction_id' => $transaction->getId()
                    ]);
                    continue;
                }
                
                // Primary: Use shipper field from SCR invoice with advanced mapping
                $ebayCarrier = CarrierMapping::mapShipperToCarrier($invoice->getShipper());
                
                // Fallback: Auto-detect from tracking number if shipper mapping returned OTHER
                if ($ebayCarrier === CarrierMapping::EBAY_CARRIERS['OTHER'] && empty($invoice->getShipper())) {
                    $ebayCarrier = CarrierMapping::detectCarrierFromTracking($trackingNumber);
                    
                    $this->logger->debug("Used tracking number pattern detection", [
                        'tracking_number' => $trackingNumber,
                        'detected_carrier' => $ebayCarrier
                    ]);
                }
                
                $this->logger->info("Processing shipped order", [
                    'ebay_order_id' => $transaction->getEbayOrderId(),
                    'tracking_number' => $trackingNumber,
                    'scr_shipper' => $invoice->getShipper(),
                    'ebay_carrier' => $ebayCarrier,
                    'dispatch_date' => $invoice->getDispatchdat()->format('Y-m-d H:i:s')
                ]);
                
                // Mark as shipped on eBay - only if newer than 90 days
                if ($invoice->getDispatchdat() > new \DateTime('-90 days')) {
                    $this->fulfillmentApi->markAsShipped(
                        $transaction->getEbayOrderId(),
                        $trackingNumber,
                        $ebayCarrier,
                        $invoice->getDispatchdat()
                    );
                }
                
                // Update transaction with new timestamp
                $transaction->setShipped(1);
                $transaction->setEbayTracking($trackingNumber);
                $transaction->setUpdated(new \DateTime());
                $this->entityManager->persist($transaction);
                
                $count++;
                
                $this->logger->info("Successfully marked order as shipped", [
                    'ebay_order_id' => $transaction->getEbayOrderId(),
                    'tracking_number' => $trackingNumber,
                    'carrier' => $ebayCarrier
                ]);
                
            } catch (\Exception $e) {
                $this->logger->error("Error marking order as shipped", [
                    'ebay_order_id' => $transaction->getEbayOrderId(),
                    'error' => $e->getMessage(),
                    'tracking_number' => $invoice->getTracking() ?? 'N/A',
                    'shipper' => $invoice->getShipper() ?? 'N/A'
                ]);
                
                // Force update timestamp even on failure to prevent infinite retries
                $transaction->setUpdated(new \DateTime());
                $this->entityManager->persist($transaction);
            }
        }
        
        if ($count > 0) {
            $this->entityManager->flush();
        }
        
        return $count;
    }

    /**
     * Update orders marked as canceled
     * 
     * Finds transactions that need cancellation based on SCR invoice changes.
     * Uses invoice.updated > transaction.updated to detect changes.
     *
     * @return int Number of updated orders
     */
    private function updateCanceledOrders(): int
    {
        // Find transactions where invoice was updated after transaction
        $qb = $this->entityManager->createQueryBuilder();
        $transactions = $qb->select('t')
            ->from(EbayTransaction::class, 't')
            ->join(ScrInvoice::class, 'i', 'WITH', 't.invoice_id = i.id')
            ->where('t.canceled = 0')
            ->andWhere('i.closed = 1')
            ->andWhere($qb->expr()->orX(
                // Never updated transaction
                't.updated IS NULL',
                // Invoice updated after last transaction update
                'i.updated > t.updated'
            ))
            ->getQuery()
            ->getResult();
            
        $count = 0;
        
        foreach ($transactions as $transaction) {
            try {
                // Check if order is within the 30-day cancellation window
                $createdDate = $transaction->getEbayCreated();
                $now = new \DateTime();
                $daysDifference = $now->diff($createdDate)->days;
                
                if ($daysDifference <= 30) {
                    // Get order status from eBay
                    $order = $this->fulfillmentApi->getOrder($transaction->getEbayOrderId());
                    
                    // Check if not already canceled
                    if (!isset($order['cancelStatus']) || 
                        !in_array($order['cancelStatus'], [
                            'CANCEL_CLOSED_FOR_COMMITMENT',
                            'CANCEL_CLOSED_NO_REFUND',
                            'CANCEL_CLOSED_UNKNOWN_REFUND',
                            'CANCEL_CLOSED_WITH_REFUND',
                            'CANCEL_COMPLETE'
                        ])) {
                        // Cancel order on eBay
                        $this->fulfillmentApi->cancelOrder(
                            $transaction->getEbayOrderId(),
                            'OUT_OF_STOCK'
                        );
                        
                        $this->logger->info("Canceled order on eBay", [
                            'ebay_order_id' => $transaction->getEbayOrderId(),
                            'reason' => 'OUT_OF_STOCK',
                            'days_old' => $daysDifference
                        ]);
                    }
                } else {
                    $this->logger->debug("Order too old for eBay cancellation", [
                        'ebay_order_id' => $transaction->getEbayOrderId(),
                        'days_old' => $daysDifference,
                        'max_days' => 30
                    ]);
                }
                
                // Update transaction status locally with new timestamp
                $transaction->setCanceled(1);
                $transaction->setUpdated(new \DateTime());
                $this->entityManager->persist($transaction);
                
                $count++;
                
            } catch (\Exception $e) {
                $this->logger->error("Error canceling order", [
                    'ebay_order_id' => $transaction->getEbayOrderId(),
                    'error' => $e->getMessage()
                ]);
                
                // Update timestamp even on failure to prevent infinite retries
                $transaction->setUpdated(new \DateTime());
                $this->entityManager->persist($transaction);
            }
        }
        
        if ($count > 0) {
            $this->entityManager->flush();
        }
        
        return $count;
    }
}
