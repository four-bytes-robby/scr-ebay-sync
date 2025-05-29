<?php
// src/Services/EbayOrder/OrderImportService.php
namespace Four\ScrEbaySync\Services\EbayOrder;

use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Four\ScrEbaySync\Entity\ScrInvoice;
use Four\ScrEbaySync\Entity\ScrCustomer;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for importing eBay orders
 */
class OrderImportService
{
    private Fulfillment $fulfillmentApi;
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    private CustomerProcessor $customerProcessor;
    private InvoiceProcessor $invoiceProcessor;
    private OrderItemProcessor $itemProcessor;
    private TransactionProcessor $transactionProcessor;
    
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
        $this->logger = $logger ?? new Logger('ebay_order_import');
        
        // Initialize sub-processors
        $this->customerProcessor = new CustomerProcessor($entityManager, $logger);
        $this->invoiceProcessor = new InvoiceProcessor($entityManager, $logger);
        $this->itemProcessor = new OrderItemProcessor($entityManager, $logger);
        $this->transactionProcessor = new TransactionProcessor($entityManager, $logger);
    }
    
    /**
     * Import orders from eBay
     *
     * @param \DateTime $fromDate Import orders from this date
     * @return int Number of imported orders
     */
    public function importOrders(\DateTime $fromDate): int
    {
        $this->logger->info("Importing eBay orders from {$fromDate->format('Y-m-d H:i:s')}");
        
        try {
            // Get orders from eBay
            $orders = $this->fulfillmentApi->getOrdersByCreationDate($fromDate);
            
            if (!isset($orders['orders']) || empty($orders['orders'])) {
                $this->logger->info("No new orders found");
                return 0;
            }
            
            $this->logger->info("Found {$orders['total']} orders to process");
            
            // Filter to only open and paid orders
            $openOrders = array_filter($orders['orders'], function($order) {
                return $order['orderFulfillmentStatus'] === 'NOT_STARTED' ||
                      ($order['orderPaymentStatus'] === 'PAID' && !isset($order['cancelStatus']));
            });

            // Get already imported order IDs
            $importedOrderIds = $this->getImportedOrderIds($fromDate);
            
            // Process each order
            $importCount = 0;
            foreach ($openOrders as $order) {
                // Skip if already imported
                if (in_array($order['orderId'], $importedOrderIds)) {
                    $this->logger->debug("Skipping already imported order {$order['orderId']}");
                    continue;
                }
                
                // Import the order
                if ($this->importBuyerOrder($order)) {
                    $importCount++;
                }
            }
            
            $this->logger->info("Successfully imported {$importCount} new orders");
            return $importCount;
        } catch (\Exception $e) {
            $this->logger->error("Exception importing orders: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Import a single buyer order
     *
     * @param array $order The order data from eBay
     * @return bool Success status
     */
    private function importBuyerOrder(array $order): bool
    {
        $this->logger->debug("Importing order {$order['orderId']}");
        
        try {
            // Get detailed order info if needed
            if (!isset($order['lineItems']) || !isset($order['buyer']) || !isset($order['fulfillmentStartInstructions'])) {
                $order = $this->fulfillmentApi->getOrder($order['orderId']);
            }
            
            // Skip orders without line items
            if (empty($order['lineItems'])) {
                $this->logger->warning("Order {$order['orderId']} has no line items, skipping");
                return false;
            }
            
            // Start database transaction
            $this->entityManager->beginTransaction();

            // Create or update customer
            $customer = $this->customerProcessor->createOrUpdateCustomer($order);

            // Create invoice
            $invoice = $this->invoiceProcessor->createInvoice($order, $customer);

            // Create invoice positions
            $this->itemProcessor->createInvoicePositions($order, $invoice);
            
            // Create eBay transactions
            $this->transactionProcessor->createEbayTransactions($order, $invoice);
            
            // Update item quantities
            $this->itemProcessor->updateItemQuantities($order);
            
            // Commit transaction
            $this->entityManager->commit();
            
            $this->logger->info("Successfully imported order {$order['orderId']} as invoice {$invoice->getId()}");
            return true;
        } catch (\Exception $e) {
            // Rollback transaction
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            
            $this->logger->error("Exception importing order {$order['orderId']}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get already imported order IDs
     *
     * @param \DateTime $fromDate From date
     * @return array Order IDs
     */
    private function getImportedOrderIds(\DateTime $fromDate): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $result = $qb->select('i.source_id')
            ->from(ScrInvoice::class, 'i')
            ->where('i.source = :source')
            ->andWhere('i.receivedat >= :fromDate')
            ->setParameter('source', 'ebay')
            ->setParameter('fromDate', $fromDate)
            ->getQuery()
            ->getArrayResult();
            
        return array_column($result, 'source_id');
    }
}