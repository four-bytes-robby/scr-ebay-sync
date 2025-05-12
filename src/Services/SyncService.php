<?php
// src/Services/SyncService.php
namespace Four\ScrEbaySync\Services;

use Four\ScrEbaySync\Api\eBay\Inventory;
use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Doctrine\ORM\EntityManagerInterface;
use Four\ScrEbaySync\Entity\EbayItem;
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
        $this->logger->info("Starting eBay sync process");
        
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
            $fromDate = new \DateTime();
            $fromDate->modify("-10 days");
            $results['importedOrders'] = $this->orderService->importOrders($fromDate);
        }
        
        if ($updateOrderStatus) {
            $results['updatedOrderStatus'] = $this->orderService->updateOrderStatus();
        }
        
        $this->logger->info("eBay sync process completed", $results);
        
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
        $ebayItemRepo = $this->entityManager->getRepository(EbayItem::class);
        
        // Find items eligible for listing (items with quantity > 0 that aren't listed on eBay)
        $items = $scrItemRepo->findEligibleItemsForEbay();
        
        if (!$items) {
            $this->logger->info("No new items found to list on eBay");
            return 0;
        }
        
        $this->logger->info(sprintf("Found %d items to list on eBay", count($items)));
        
        $addCount = 0;
        foreach ($items as $item) {
            try {
                // Check if already in eBay items
                $ebayItem = $ebayItemRepo->findOneBy(['item_id' => $item->getId()]);
                if ($ebayItem && !$ebayItem->getDeleted()) {
                    $this->logger->debug("Item {$item->getId()} is already listed on eBay, skipping");
                    continue; // Skip if already listed
                }
                
                // Create eBay listing
                $listingId = $this->inventoryService->createListing($item);
                
                if ($listingId) {
                    $addCount++;
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    "Error adding item %d to eBay: %s", 
                    $item->getId(), 
                    $e->getMessage()
                ));
            }
        }
        
        $this->logger->info("Added {$addCount} new items to eBay");
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
        $ebayItemRepo = $this->entityManager->getRepository(EbayItem::class);
        $scrItemRepo = $this->entityManager->getRepository(ScrItem::class);
        
        // Find eBay items that need updates
        $ebayItems = $ebayItemRepo->findItemsNeedingUpdate();
        
        if (!$ebayItems) {
            $this->logger->info("No items found needing updates on eBay");
            return 0;
        }
        
        $this->logger->info(sprintf("Found %d items to update on eBay", count($ebayItems)));
        
        $updateCount = 0;
        foreach ($ebayItems as $ebayItem) {
            try {
                // Get the corresponding SCR item
                $scrItem = $scrItemRepo->find($ebayItem->getItemId());
                
                if (!$scrItem) {
                    $this->logger->warning("SCR item {$ebayItem->getItemId()} not found, cannot update eBay listing");
                    continue;
                }
                
                // Update the listing
                $success = $this->inventoryService->updateListing($scrItem, $ebayItem);
                
                if ($success) {
                    $updateCount++;
                    $this->logger->info("Successfully updated eBay listing {$ebayItem->getEbayItemId()} for item {$scrItem->getId()}");
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    "Error updating eBay item %s: %s", 
                    $ebayItem->getEbayItemId(), 
                    $e->getMessage()
                ));
            }
        }
        
        $this->logger->info("Updated {$updateCount} items on eBay");
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
        $ebayItemRepo = $this->entityManager->getRepository(EbayItem::class);
        $scrItemRepo = $this->entityManager->getRepository(ScrItem::class);
        
        // Find eBay items that need quantity updates
        $ebayItems = $ebayItemRepo->findItemsNeedingQuantityUpdate();
        
        if (!$ebayItems) {
            $this->logger->info("No items found needing quantity updates on eBay");
            return 0;
        }
        
        $this->logger->info(sprintf("Found %d items to update quantities on eBay", count($ebayItems)));
        
        $updateCount = 0;
        foreach ($ebayItems as $ebayItem) {
            try {
                // Get the corresponding SCR item
                $scrItem = $scrItemRepo->find($ebayItem->getItemId());
                
                if (!$scrItem) {
                    $this->logger->warning("SCR item {$ebayItem->getItemId()} not found, cannot update quantity");
                    continue;
                }
                
                // Update the quantity
                $success = $this->inventoryService->updateQuantity($scrItem, $ebayItem);
                
                if ($success) {
                    $updateCount++;
                    $this->logger->info("Successfully updated quantity for eBay listing {$ebayItem->getEbayItemId()}");
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    "Error updating quantity for eBay item %s: %s", 
                    $ebayItem->getEbayItemId(), 
                    $e->getMessage()
                ));
            }
        }
        
        $this->logger->info("Updated quantities for {$updateCount} items on eBay");
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
        $ebayItemRepo = $this->entityManager->getRepository(EbayItem::class);
        $scrItemRepo = $this->entityManager->getRepository(ScrItem::class);
        
        // Find eBay items that need to be ended
        $ebayItems = $ebayItemRepo->findActiveListingsForUnavailableItems();
        
        if (!$ebayItems) {
            $this->logger->info("No listings found to end on eBay");
            return 0;
        }
        
        $this->logger->info(sprintf("Found %d listings to end on eBay", count($ebayItems)));
        
        $endCount = 0;
        foreach ($ebayItems as $ebayItem) {
            try {
                // End the listing
                $success = $this->inventoryService->endListing($ebayItem);
                
                if ($success) {
                    $endCount++;
                    $this->logger->info("Successfully ended eBay listing {$ebayItem->getEbayItemId()}");
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    "Error ending eBay listing %s: %s", 
                    $ebayItem->getEbayItemId(), 
                    $e->getMessage()
                ));
            }
        }
        
        $this->logger->info("Ended {$endCount} listings on eBay");
        return $endCount;
    }

    /**
     * Import orders from eBay
     *
     * @param \DateTime $fromDate Import orders from this date
     * @return int Number of imported orders
     */
    public function importOrders(\DateTime $fromDate): int
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