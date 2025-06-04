<?php
// src/Services/EbayOrderService.php
namespace Four\ScrEbaySync\Services;

use DateTime;
use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Four\ScrEbaySync\Services\EbayOrder\OrderImportService;
use Four\ScrEbaySync\Services\EbayOrder\OrderStatusService;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Facade service for managing eBay orders
 */
class EbayOrderService
{
    private OrderImportService $importService;
    private OrderStatusService $statusService;
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
        $this->logger = $logger ?? new Logger('ebay_order_service');
        
        // Initialize services
        $this->importService = new OrderImportService($fulfillmentApi, $entityManager, $this->logger);
        $this->statusService = new OrderStatusService($fulfillmentApi, $entityManager, $this->logger);
    }
    
    /**
     * Import orders from eBay
     *
     * @param DateTime $fromDate Import orders from this date
     * @return int Number of imported orders
     */
    public function importOrders(DateTime $fromDate): int
    {
        return $this->importService->importOrders($fromDate);
    }
    
    /**
     * Update the status of orders
     *
     * @return int Number of updated orders
     */
    public function updateOrderStatus(): int
    {
        return $this->statusService->updateOrderStatus();
    }

    /**
     * Run the complete order sync process
     *
     * @param int $daysBack Number of days to look back for orders
     * @return array Results with counts
     * @throws \DateMalformedStringException
     */
    public function syncOrders(int $daysBack = 10): array
    {
        $this->logger->info("Starting eBay order sync process, looking back {$daysBack} days");
        
        $fromDate = new DateTime();
        $fromDate->modify("-{$daysBack} days");
        
        // Step 1: Import new orders
        $importCount = $this->importOrders($fromDate);
        
        // Step 2: Update order statuses
        $updateCount = $this->updateOrderStatus();
        
        $this->logger->info("eBay order sync completed: imported {$importCount} orders, updated {$updateCount} statuses");
        
        return [
            'imported' => $importCount,
            'updated' => $updateCount
        ];
    }
}
