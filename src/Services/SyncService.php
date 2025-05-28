<?php
// src/Services/SyncService.php
namespace Four\ScrEbaySync\Services;

use Exception;
use DateTime;
use Four\ScrEbaySync\Api\eBay\Inventory;
use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Doctrine\ORM\EntityManagerInterface;
use Four\ScrEbaySync\Entity\ScrItem;
use Monolog\Logger;

/**
 * Main service for synchronizing with eBay
 */
class SyncService
{
    private EbayInventoryService $inventoryService;
    private EbayOrderService $orderService;
    private EntityManagerInterface $entityManager;
    private Logger $logger;

    /**
     * @param Inventory $inventoryApi The eBay Inventory API client
     * @param Fulfillment $fulfillmentApi The eBay Fulfillment API client
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        Inventory $inventoryApi,
        Fulfillment $fulfillmentApi,
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('sync_service');
        
        // Initialize services
        $this->inventoryService = new EbayInventoryService($inventoryApi, $entityManager, $this->logger);
        $this->orderService = new EbayOrderService($fulfillmentApi, $entityManager, $this->logger);
    }

    /**
     * Run the complete sync process
     * 
     * @param bool $addNewItems Whether to add new items
     * @param bool $updateItems Whether to update existing items
     * @param bool $updateQuantities Whether to update quantities
     * @param bool $endListings Whether to end listings for unavailable items
     * @param bool $importOrders Whether to import orders
     * @param bool $updateOrderStatus Whether to update order status
     * @return array Results with counts
     */
    public function runSync(
        bool $addNewItems = true,
        bool $updateItems = true,
        bool $updateQuantities = true,
        bool $endListings = true,
        bool $importOrders = true,
        bool $updateOrderStatus = true
    ): array {
        $startTime = microtime(true);
        $this->logger->info("==================== eBay SYNC STARTED ====================", [
            'enabled_tasks' => [
                'addNewItems' => $addNewItems,
                'updateItems' => $updateItems,
                'updateQuantities' => $updateQuantities,
                'endListings' => $endListings,
                'importOrders' => $importOrders,
                'updateOrderStatus' => $updateOrderStatus
            ]
        ]);
        
        $results = [
            'newItems' => 0,
            'updatedItems' => 0,
            'updatedQuantities' => 0,
            'endedListings' => 0,
            'importedOrders' => 0,
            'updatedOrderStatus' => 0
        ];
        
        // Process inventory changes
        if ($addNewItems) {
            $results['newItems'] = $this->addNewItems();
        }
        
        if ($updateItems) {
            $results['updatedItems'] = $this->updateItems();
        }
        
        if ($updateQuantities) {
            $results['updatedQuantities'] = $this->updateQuantities();
        }
        
        if ($endListings) {
            $results['endedListings'] = $this->endListings();
        }
        
        // Process orders
        if ($importOrders) {
            $fromDate = new DateTime();
            $fromDate->modify("-10 days");
            $results['importedOrders'] = $this->orderService->importOrders($fromDate);
        }
        
        if ($updateOrderStatus) {
            $results['updatedOrderStatus'] = $this->orderService->updateOrderStatus();
        }
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $this->logger->info("==================== eBay SYNC COMPLETED ====================", array_merge($results, [
            'duration_seconds' => $duration,
            'total_operations' => array_sum($results)
        ]));
        
        return $results;
    }

    /**
     * Add new items to eBay
     *
     * @return int Number of added items
     */
    public function addNewItems(): int
    {
        $this->logger->info("Finding items to list on eBay");
        
        // Get the repositories
        $scrItemRepo = $this->entityManager->getRepository(ScrItem::class);

        // Find items eligible for listing (items with quantity > 0 that aren't listed on eBay)
        $items = $scrItemRepo->findNewItems();
        
        if (!$items) {
            $this->logger->info("No new items found to list on eBay");
            return 0;
        }
        
        $this->logger->info(sprintf("Found %d items to list on eBay", count($items)));
        
        $addCount = 0;
        foreach ($items as $item) {
            try {
                // Create eBay listing
                $listingId = $this->inventoryService->createListing($item);
                
                if ($listingId) {
                    $addCount += 1;
                }
            } catch (Exception $e) {
                $this->logger->error(sprintf(
                    "Error adding item %d to eBay: %s", 
                    $item->getId(), 
                    $e->getMessage()
                ));
            }
        }
        
        $this->logger->info("Added $addCount new items to eBay");
        return $addCount;
    }

    /**
     * Update existing items on eBay
     *
     * @return int Number of updated items
     */
    public function updateItems(): int
    {
        $this->logger->info("Finding items to update on eBay");
        
        // Get the repositories
        $scrItemRepo = $this->entityManager->getRepository(ScrItem::class);
        
        // Find eBay items that need updates
        $updateItems = $scrItemRepo->findUpdatedItems();
        
        if (!$updateItems) {
            $this->logger->info("No items found needing updates on eBay");
            return 0;
        }
        
        $this->logger->info(sprintf("Found %d items to update on eBay", count($updateItems)));
        
        $updateCount = 0;
        foreach ($updateItems as $updateItem) {
            try {
                // Update the listing
                $listingId = $this->inventoryService->updateListing($updateItem);
                
                if ($listingId) {
                    $updateCount++;
                    // Log wird bereits im EbayInventoryService ausgegeben - hier entfernen
                }
            } catch (Exception $e) {
                $this->logger->error(sprintf(
                    "Error updating eBay item %s: %s",
                    $updateItem->getEbayItemId(),
                    $e->getMessage()
                ));
            }
        }
        
        $this->logger->info("Updated " . $updateCount . " items on eBay");
        return $updateCount;
    }

    /**
     * Update quantities of items on eBay
     *
     * @return int Number of updated quantities
     */
    public function updateQuantities(): int
    {
        $this->logger->info("Finding items to update quantities on eBay");
        
        // Get the repositories
        $scrItemRepo = $this->entityManager->getRepository(ScrItem::class);
        
        // Find eBay items that need quantity updates
        $quantityChangedItems = $scrItemRepo->findItemsWithChangedQuantities();
        
        if (!$quantityChangedItems) {
            $this->logger->info("No items found needing quantity updates on eBay");
            return 0;
        }
        
        $this->logger->info(sprintf("Found %d items to update quantities on eBay", count($quantityChangedItems)));
        
        $updateCount = 0;
        foreach ($quantityChangedItems as $item) {
            try {
                // Update the quantity
                $success = $this->inventoryService->updateQuantity($item);
                
                if ($success) {
                    $updateCount++;
                    // Log wird bereits im EbayInventoryService ausgegeben - hier entfernen
                }
            } catch (Exception $e) {
                $this->logger->error(sprintf(
                    "Error updating quantity for eBay item %s: %s", 
                    $item->getEbayItemId(),
                    $e->getMessage()
                ));
            }
        }
        
        $this->logger->info("Updated quantities for " . $updateCount . " items on eBay");
        return $updateCount;
    }

    /**
     * End listings for unavailable items
     *
     * @return int Number of ended listings
     */
    public function endListings(): int
    {
        $this->logger->info("Finding listings to end on eBay");
        
        // Get the repositories
        $scrItemRepo = $this->entityManager->getRepository(ScrItem::class);
        
        // Find eBay items that need to be ended
        $deleteItems = $scrItemRepo->findUnavailableItems();
        
        if (!$deleteItems) {
            $this->logger->info("No listings found to end on eBay");
            return 0;
        }
        
        $this->logger->info(sprintf("Found %d listings to end on eBay", count($deleteItems)));
        
        $endCount = 0;
        foreach ($deleteItems as $item) {
            try {
                // End the listing
                $success = $this->inventoryService->endListing($item);
                
                if ($success) {
                    $endCount++;
                    // Log wird bereits im EbayInventoryService ausgegeben - hier entfernen
                }
            } catch (Exception $e) {
                $this->logger->error(sprintf(
                    "Error ending eBay listing %s: %s", 
                    $item->getEbayItemId(),
                    $e->getMessage()
                ));
            }
        }
        
        $this->logger->info("Ended " . $endCount . " listings on eBay");
        return $endCount;
    }

    /**
     * Import orders from eBay
     *
     * @param DateTime $fromDate Import orders from this date
     * @return int Number of imported orders
     */
    public function importOrders(DateTime $fromDate): int
    {
        return $this->orderService->importOrders($fromDate);
    }

    /**
     * Update order status
     *
     * @return int Number of updated orders
     */
    public function updateOrderStatus(): int
    {
        return $this->orderService->updateOrderStatus();
    }
}