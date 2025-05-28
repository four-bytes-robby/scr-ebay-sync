<?php
// src/Services/EbayOrder/TransactionProcessor.php
namespace Four\ScrEbaySync\Services\EbayOrder;

use Four\ScrEbaySync\Entity\ScrInvoice;
use Four\ScrEbaySync\Entity\EbayTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for processing eBay transactions
 */
class TransactionProcessor
{
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    
    /**
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('ebay_transaction_processor');
    }
    
    /**
     * Create eBay transactions for the order
     *
     * @param array $order The order data
     * @param ScrInvoice $invoice The invoice entity
     * @return array Created transactions
     */
    public function createEbayTransactions(array $order, ScrInvoice $invoice): array
    {
        $transactions = [];
        
        foreach ($order['lineItems'] as $lineItem) {
            // Get SKU from line item
            $sku = $lineItem['sku'] ?? null;
            
            if (!$sku) {
                continue;
            }
            
            // Create transaction
            $transaction = new EbayTransaction();
            
            // Fee calculation - if available in the API response
            $feeValue = 0;
            if (isset($lineItem['marketplaceFee']['value'])) {
                $feeValue = (float)$lineItem['marketplaceFee']['value'];
            }
            
            // Set transaction data
            $transaction->setEbayTransactionId($lineItem['lineItemId']);
            $transaction->setEbayCreated(new \DateTime($order['creationDate']));
            $transaction->setEbayOrderId($order['orderId']);
            $transaction->setEbayOrderLineItemId($lineItem['lineItemId']);
            $transaction->setEbayItemId($lineItem['legacyItemId'] ?? '');
            $transaction->setEbayBuyerId($order['buyer']['username']);
            $transaction->setEbayFinalValueFee($feeValue);
            $transaction->setEbayTracking('');
            $transaction->setInvoiceId($invoice->getId());
            $transaction->setQuantity($lineItem['quantity']);
            $transaction->setItemId($sku);
            
            // Statuses
            $transaction->setPaid($invoice->getPaydat() !== null ? 1 : 0);
            $transaction->setShipped(0);
            $transaction->setCanceled(0);
            
            // Created/updated timestamps are handled by entity lifecycle callbacks
            // No need to set them manually
            
            // Save transaction
            $this->entityManager->persist($transaction);
            $transactions[] = $transaction;
        }
        
        $this->entityManager->flush();
        
        return $transactions;
    }
}