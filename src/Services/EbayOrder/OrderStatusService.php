<?php
// src/Services/EbayOrder/OrderStatusService.php
namespace Four\ScrEbaySync\Services\EbayOrder;

use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Four\ScrEbaySync\Entity\ScrInvoice;
use Four\ScrEbaySync\Entity\EbayTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for updating eBay order statuses
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
        $this->logger->info("Updating eBay order statuses");
        
        try {
            // Update orders marked as paid
            $paidCount = $this->updatePaidOrders();
            
            // Update orders marked as shipped
            $shippedCount = $this->updateShippedOrders();
            
            // Update orders marked as canceled
            $canceledCount = $this->updateCanceledOrders();
            
            $this->logger->info("Updated order statuses: {$paidCount} paid, {$shippedCount} shipped, {$canceledCount} canceled");
            return $paidCount + $shippedCount + $canceledCount;
        } catch (\Exception $e) {
            $this->logger->error("Exception updating order statuses: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update orders marked as paid
     *
     * @return int Number of updated orders
     */
    private function updatePaidOrders(): int
    {
        // Get transactions that are not marked as paid but have invoices with payment dates
        $qb = $this->entityManager->createQueryBuilder();
        $transactions = $qb->select('t')
            ->from(EbayTransaction::class, 't')
            ->join(ScrInvoice::class, 'i', 'WITH', 't.invoice_id = i.id')
            ->where('t.paid = 0')
            ->andWhere('i.paydat IS NOT NULL')
            ->getQuery()
            ->getResult();
            
        $count = 0;
        
        foreach ($transactions as $transaction) {
            try {
                // Mark as paid on eBay
                $this->fulfillmentApi->markOrderAsPaid($transaction->getEbayOrderId());
                
                // Update transaction
                $transaction->setPaid(1);
                $transaction->setUpdated(new \DateTime());
                $this->entityManager->persist($transaction);
                
                $count++;
            } catch (\Exception $e) {
                $this->logger->error("Error marking order {$transaction->getEbayOrderId()} as paid: " . $e->getMessage());
            }
        }
        
        $this->entityManager->flush();
        
        return $count;
    }
    
    /**
     * Update orders marked as shipped
     *
     * @return int Number of updated orders
     */
    private function updateShippedOrders(): int
    {
        // Get transactions that are not marked as shipped but have invoices with dispatch dates
        $qb = $this->entityManager->createQueryBuilder();
        $transactions = $qb->select('t')
            ->from(EbayTransaction::class, 't')
            ->join(ScrInvoice::class, 'i', 'WITH', 't.invoice_id = i.id')
            ->where('t.shipped = 0 OR t.ebayTracking != i.tracking')
            ->andWhere('i.dispatchdat IS NOT NULL')
            ->getQuery()
            ->getResult();
            
        $count = 0;
        
        foreach ($transactions as $transaction) {
            try {
                $invoice = $this->entityManager->getRepository(ScrInvoice::class)
                    ->find($transaction->getInvoiceId());
                
                if (!$invoice) {
                    continue;
                }
                
                // Skip if no tracking number
                if (empty($invoice->getTracking())) {
                    continue;
                }
                
                // Determine carrier
                $carrier = $invoice->getShipper();
                
                if (empty($carrier)) {
                    // Auto-detect carrier from tracking number format
                    $trackingNumber = $invoice->getTracking();
                    $length = strlen($trackingNumber);
                    
                    switch ($length) {
                        case 14:
                            $carrier = 'Hermes';
                            break;
                        case 13:
                            $carrier = 'DeutschePost';
                            break;
                        case 18:
                            $carrier = 'UPS';
                            break;
                        case 20:
                            $carrier = 'DHL';
                            break;
                        default:
                            $carrier= 'Spring GDS';
                            break;
                    }
                }
                
                // Mark as shipped on eBay
                $this->fulfillmentApi->markAsShipped(
                    $transaction->getEbayOrderId(),
                    $invoice->getTracking(),
                    $carrier,
                    $invoice->getDispatchdat()
                );
                
                // Update transaction
                $transaction->setShipped(1);
                $transaction->setEbayTracking($invoice->getTracking());
                $transaction->setUpdated(new \DateTime());
                $this->entityManager->persist($transaction);
                
                $count++;
            } catch (\Exception $e) {
                $this->logger->error("Error marking order {$transaction->getEbayOrderId()} as shipped: " . $e->getMessage());
            }
        }
        
        $this->entityManager->flush();
        
        return $count;
    }
    
    /**
     * Update orders marked as canceled
     *
     * @return int Number of updated orders
     */
    private function updateCanceledOrders(): int
    {
        // Get transactions that are not marked as canceled but have closed invoices
        $qb = $this->entityManager->createQueryBuilder();
        $transactions = $qb->select('t')
            ->from(EbayTransaction::class, 't')
            ->join(ScrInvoice::class, 'i', 'WITH', 't.invoice_id = i.id')
            ->where('t.canceled = 0')
            ->andWhere('i.closed = 1')
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
                    }
                }
                
                // Update transaction status locally regardless of eBay status
                $transaction->setCanceled(1);
                $transaction->setUpdated(new \DateTime());
                $this->entityManager->persist($transaction);
                
                $count++;
            } catch (\Exception $e) {
                $this->logger->error("Error canceling order {$transaction->getEbayOrderId()}: " . $e->getMessage());
            }
        }
        
        $this->entityManager->flush();
        
        return $count;
    }
}