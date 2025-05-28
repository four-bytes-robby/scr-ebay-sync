<?php declare(strict_types=1);

namespace Four\ScrEbaySync\Services;

use DateTime;
use Exception;
use Four\ScrEbaySync\Entity\ScrItem;
use Four\ScrEbaySync\Entity\EbayItem;
use Four\ScrEbaySync\Api\eBay\Inventory;
use Four\ScrEbaySync\Repository\ScrItemRepository;
use Four\ScrEbaySync\Services\EbayListing\DescriptionFormatter;
use Four\ScrEbaySync\Services\EbayListing\ImageService;
use Four\ScrEbaySync\Services\EbayListing\ItemConverter;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\ClientException;
use Monolog\Logger;
use RuntimeException;

/**
 * Service for managing eBay inventory operations
 */
class EbayInventoryService
{
    private Inventory $inventoryApi;
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    
    /**
     * @param Inventory $inventoryApi The eBay Inventory API client
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        Inventory $inventoryApi,
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->inventoryApi = $inventoryApi;
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('ebay_inventory_service');
    }
    
    /**
     * Create a new listing on eBay
     *
     * @param ScrItem $scrItem The item to list
     * @return string|null The eBay listing ID or null if failed
     */
    public function createListing(ScrItem $scrItem): ?string
    {
        $this->logger->info("Creating new eBay listing for item {$scrItem->getId()}");
        
        try {
            // Initialize supporting services
            $itemConverter = new ItemConverter($scrItem, $this->entityManager, $this->logger);
            $imageService = new ImageService($scrItem, $this->logger);
            $descriptionFormatter = new DescriptionFormatter($scrItem, $this->logger);
            $descriptionFormatter->setImageService($imageService);
            
            // Check if item has images
            $imageUrls = $imageService->getImageUrls();
            if (empty($imageUrls)) {
                $this->logger->warning("Skipping item {$scrItem->getId()} - no images found");
                return null;
            }
            
            // Get main image for logging
            $mainImage = $imageService->getMainImageUrl();
            $imageCount = $imageService->getImageCount();
            $this->logger->debug("Item image info: main image = {$mainImage}, total images = {$imageCount}");
            
            // Convert to inventory item format
            $inventoryItemData = $itemConverter->createInventoryItem();
            $offerData = $itemConverter->createOffer();
            
            // Log inventory item and offer data at debug level
            $this->logger->debug("Inventory item data: " . json_encode($inventoryItemData, JSON_PRETTY_PRINT));
            $this->logger->debug("Offer data: " . json_encode($offerData, JSON_PRETTY_PRINT));

            try {
                // Send to eBay
                $response = $this->inventoryApi->createListing(
                    $scrItem->getId(),
                    $inventoryItemData,
                    $offerData
                );

                $this->logger->debug("eBay create listing response: " . json_encode($response, JSON_PRETTY_PRINT));

                if (isset($response['listingId'])) {
                    // Save the eBay item to our database
                    $this->saveEbayItem($scrItem, $response['listingId'], $itemConverter->getQuantity(), $itemConverter->getPrice());

                    $this->logger->info("Successfully created eBay listing {$response['listingId']} for item {$scrItem->getId()}");
                    return $response['listingId'];
                } else {
                    $this->logger->error("Failed to create eBay listing for item {$scrItem->getId()}: No listing ID in response", ['response' => $response]);
                    return null;
                }
            } catch (Exception $e) {
                // Could not create offer, try to get existing offers for item
                $this->logger->warning("Could not create offer, try to get existing offers for item {$scrItem->getId()}");
                $existingOffers = $this->inventoryApi->getOffers($scrItem->getId());
                if (!empty($existingOffers['offers'])) {
                    $offer = $existingOffers['offers'][0];
                    $listingId = $offer['listingId'] ?? null;

                    if ($listingId) {
                        // Save/update the eBay item in database
                        $this->saveEbayItem($scrItem, $listingId, $itemConverter->getQuantity(), $itemConverter->getPrice());
                        $this->logger->info("Found existing active listing {$listingId} for item {$scrItem->getId()}");
                        return $listingId;
                    } else {
                        // Check if we already have this item in our DB with a valid listing ID
                        $existingEbayItem = $scrItem->getEbayItem();
                        if ($existingEbayItem && $existingEbayItem->getEbayItemId() && $existingEbayItem->getQuantity() > 0) {
                            $this->logger->info("Found existing DB listing {$existingEbayItem->getEbayItemId()} for item {$scrItem->getId()}, skipping republish");
                            return $existingEbayItem->getEbayItemId();
                        }

                        $this->logger->warning("Offer exists but no listing ID found for item {$scrItem->getId()}, attempting update");
                        return $this->updateListing($scrItem);
                    }
                }
            }

        } catch (Exception $e) {
            $this->logger->error("Exception creating eBay listing for item {$scrItem->getId()}: " . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
            return null;
        }
    }
    
    /**
     * Update an existing eBay listing
     *
     * @param ScrItem $scrItem The item to update
     * @return string|null The listing ID or null if failed
     */
    public function updateListing(ScrItem $scrItem): ?string
    {
        $this->logger->info("Updating eBay listing for item {$scrItem->getId()}");
        
        try {
            // Create the item converter
            $itemConverter = new ItemConverter($scrItem, $this->entityManager, $this->logger);
            
            // Initialize supporting services
            $imageService = new ImageService($scrItem, $this->logger);
            $descriptionFormatter = new DescriptionFormatter($scrItem, $this->logger);
            $descriptionFormatter->setImageService($imageService);
            
            // Check if item has images
            $imageUrls = $imageService->getImageUrls();
            if (empty($imageUrls)) {
                $this->logger->warning("Skipping update for item {$scrItem->getId()} - no images found");
                return null;
            }
            
            // Convert to inventory item format
            $inventoryItemData = $itemConverter->createInventoryItem();
            
            // Get the offer ID
            $offerResponse = $this->inventoryApi->getOffers($scrItem->getId());
            
            if (empty($offerResponse['offers'])) {
                $this->logger->error("No offers found for item {$scrItem->getId()}");
                return null;
            }
            
            $offer = $offerResponse['offers'][0];
            $offerId = $offer['offerId'];
            
            // Update offer data
            $offerData = $itemConverter->createOffer();
            
            // If offer not published yet, publish it instead of updating
//            if (!isset($offer['listingId']) ||
// empty($offer['listingId'])) {
//                $this->logger->info("Publishing unpublished offer {$offerId} for item {$scrItem->getId()}");
//                $response = $this->inventoryApi->publishOffer($offerId);
//            } else {
            $this->logger->info("Update item listing for item {$scrItem->getId()}");
                // Update existing published listing
                $response = $this->inventoryApi->updateListing(
                    $scrItem->getId(),
                    $offerId, 
                    $inventoryItemData,
                    $offerData
                );
        //}

            
            if (isset($response['listingId'])) {
                // WICHTIG: Nur bei erfolgreichem eBay-Update das updated Feld aktualisieren
                $this->saveEbayItem($scrItem, $response['listingId'], $itemConverter->getQuantity(), $scrItem->getPrice());
                
                $this->logger->info("Successfully updated eBay listing {$response['listingId']} for item {$scrItem->getId()}");
                return $response['listingId'];
            } else {
                $this->logger->error("Failed to update eBay listing for item {$scrItem->getId()}: No listing ID in response", ['response' => $response]);
                return null;
            }
        } catch (Exception $e) {
            $this->logger->error("Exception updating eBay listing for item {$scrItem->getId()}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update inventory quantity for an existing listing
     * Wenn quantity 0 ist, wird das Item gelöscht/beendet statt aktualisiert
     *
     * @param ScrItem $scrItem The item to update
     * @return bool Success status
     */
    public function updateQuantity(ScrItem $scrItem): bool
    {
        $ebayItem = $scrItem->getEbayItem();
        $newQuantity = min($scrItem->getQuantity(), 3); // Max 3

        $this->logger->info("Updating quantity to {$newQuantity} for eBay item {$scrItem->getId()}");
        
        try {
            // Wenn quantity 0 ist, Item löschen/beenden
            if ($newQuantity <= 0) {
                $this->logger->info("Quantity is 0, ending listing for item {$scrItem->getId()}");
                return $this->endListing($scrItem);
            }
            
            // Korrekte Inventory Item Struktur für Bestandsaktualierung
            $inventoryItemData = [
                'availability' => [
                    'shipToLocationAvailability' => [
                        'quantity' => $newQuantity
                    ]
                ]
            ];
            
            // Verwende die korrekte eBay Inventory API Methode
            $response = $this->inventoryApi->createOrUpdateInventoryItem($scrItem->getId(), $inventoryItemData);
            
            // KRITISCH: NUR bei erfolgreichem eBay API-Call das updated Feld aktualisieren
            // Das verhindert, dass Items als "bereits synchronisiert" markiert werden, obwohl der API-Call fehlgeschlagen ist
            if ($response) {
                $ebayItem->setQuantity($newQuantity);
                $ebayItem->setUpdated(new DateTime());
                $this->entityManager->persist($ebayItem);
                $this->entityManager->flush();
                
                $this->logger->info("Successfully updated quantity to {$newQuantity} for item {$scrItem->getId()}");
                return true;
            } else {
                $this->logger->error("eBay API call failed for quantity update of item {$scrItem->getId()}");
                return false;
            }
            
        } catch (Exception $e) {
            $this->logger->error("Exception updating quantity for item {$scrItem->getId()}: " . $e->getMessage());
            
            // WICHTIG: Bei Exception NICHT das updated Feld setzen!
            // Das Item sollte beim nächsten Sync-Lauf erneut versucht werden
            
            // Falls der Fehler wegen quantity=0 kommt, versuche das Item zu beenden
            if (strpos($e->getMessage(), 'invalid quantity') !== false || 
                strpos($e->getMessage(), 'mehr als 0 betragen') !== false) {
                $this->logger->info("Quantity error detected, attempting to end listing for item {$scrItem->getId()}");
                return $this->endListing($scrItem);
            }
            
            return false;
        }
    }

    /**
     * End an eBay listing
     *
     * @param ScrItem $item The eBay item to end
     * @return bool Success status
     */
    public function endListing(ScrItem $item): bool
    {
        $ebayItem = $item->getEbayItem();
        $this->logger->info("Ending eBay listing {$ebayItem->getEbayItemId()}");
        
        try {
            // Get the offer ID
            $offerResponse = $this->inventoryApi->getOffers($ebayItem->getItemId());
            
            if (!isset($offerResponse['offers']) || empty($offerResponse['offers'])) {
                $this->logger->error("No offers found for item {$ebayItem->getItemId()}");
                return false;
            }
            
            $offerId = $offerResponse['offers'][0]['offerId'];
            
            // End the listing
            $this->inventoryApi->endListing($offerId);
            
            // Update the eBay item in our database
            $ebayItem->setQuantity(-1);
            $ebayItem->setDeleted(new DateTime());
            $this->entityManager->persist($ebayItem);
            $this->entityManager->flush();
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Exception ending listing {$ebayItem->getEbayItemId()}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Pull all inventory data from eBay and sync to database
     *
     * @param int $limit Maximum number of items to process (0 = no limit)
     * @return array Results with counts and changes
     */
    public function pullAllInventory(int $limit = 0): array
    {
        $this->logger->info("Starting full inventory pull from eBay" . ($limit > 0 ? " (limit: {$limit})" : ""));
        
        $results = [
            'processed' => 0,
            'updated' => 0,
            'created' => 0,
            'errors' => 0,
            'changes' => [],
            'error_items' => []
        ];
        
        try {
            // Get all inventory items from eBay
            $offset = 0;
            $batchSize = 100; // eBay API limit
            
            do {
                $inventoryResponse = $this->inventoryApi->getAllInventoryItems($batchSize, $offset);
                
                if (!isset($inventoryResponse['inventoryItems']) || empty($inventoryResponse['inventoryItems'])) {
                    break;
                }
                
                foreach ($inventoryResponse['inventoryItems'] as $inventoryItem) {
                    if ($limit > 0 && $results['processed'] >= $limit) {
                        break 2; // Break out of both loops
                    }
                    
                    $sku = $inventoryItem['sku'] ?? null;
                    if (!$sku) {
                        continue;
                    }
                    
                    try {
                        $change = $this->syncInventoryItem($sku, $inventoryItem);
                        if ($change) {
                            $results['changes'][] = $change;
                            if ($change['action'] === 'updated') {
                                $results['updated']++;
                            } elseif ($change['action'] === 'created') {
                                $results['created']++;
                            }
                        }
                        $results['processed']++;
                        
                    } catch (Exception $e) {
                        $results['errors']++;
                        $results['error_items'][] = [
                            'sku' => $sku,
                            'error' => $e->getMessage()
                        ];
                        $this->logger->error("Failed to sync inventory item {$sku}: " . $e->getMessage());
                    }
                }
                
                $offset += $batchSize;
                $this->logger->info("Processed batch, total items: {$results['processed']}");
                
            } while (isset($inventoryResponse['next']) && $inventoryResponse['next']);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to pull inventory from eBay: " . $e->getMessage());
            $results['error_items'][] = [
                'sku' => 'GLOBAL',
                'error' => $e->getMessage()
            ];
        }
        
        $this->logger->info("Inventory pull completed", $results);
        return $results;
    }
    
    /**
     * Sync a single inventory item from eBay data
     *
     * @param string $sku The item SKU
     * @param array $inventoryData The eBay inventory data
     * @return array|null Change information or null if no changes
     */
    private function syncInventoryItem(string $sku, array $inventoryData): ?array
    {
        // Find corresponding SCR item
        $scrItem = $this->entityManager->getRepository(ScrItem::class)->find($sku);
        if (!$scrItem) {
            $this->logger->warning("SKU {$sku} not found in SCR database, skipping");
            return null;
        }
        
        // Get or create EbayItem
        $ebayItem = $scrItem->getEbayItem();
        $isNew = false;
        
        if (!$ebayItem) {
            $ebayItem = new EbayItem();
            $ebayItem->setItemId($sku);
            $ebayItem->setScrItem($scrItem);
            $ebayItem->setCreated(new DateTime());
            $isNew = true;
        }
        
        // Extract data from eBay inventory
        $ebayQuantity = $inventoryData['availability']['shipToLocationAvailability']['quantity'] ?? 0;
        $ebayPrice = null;
        
        // Try to get price from offers if available
        if (isset($inventoryData['offers']) && !empty($inventoryData['offers'])) {
            $ebayPrice = $inventoryData['offers'][0]['pricingSummary']['price']['value'] ?? null;
        }
        
        // If no price in inventory data, try to get from current offer
        if (!$ebayPrice) {
            try {
                $offerResponse = $this->inventoryApi->getOffers($sku);
                if (isset($offerResponse['offers'][0]['pricingSummary']['price']['value'])) {
                    $ebayPrice = $offerResponse['offers'][0]['pricingSummary']['price']['value'];
                }
            } catch (Exception $e) {
                $this->logger->debug("Could not get offer price for {$sku}: " . $e->getMessage());
            }
        }
        
        // Check for changes
        $oldQuantity = $ebayItem->getQuantity();
        $oldPrice = (float)$ebayItem->getPrice();
        $newPrice = $ebayPrice ? (float)$ebayPrice : $oldPrice;
        
        $hasChanges = $ebayQuantity != $oldQuantity || abs($newPrice - $oldPrice) > 0.01;
        
        if ($hasChanges || $isNew) {
            $ebayItem->setQuantity($ebayQuantity);
            if ($ebayPrice) {
                $ebayItem->setPrice((string)$newPrice);
            }
            $ebayItem->setUpdated(new DateTime());
            $ebayItem->setDeleted(null); // Mark as active
            
            $this->entityManager->persist($ebayItem);
            $this->entityManager->flush();
            
            return [
                'sku' => $sku,
                'action' => $isNew ? 'created' : 'updated',
                'changes' => [
                    'quantity' => ['old' => $oldQuantity, 'new' => $ebayQuantity],
                    'price' => ['old' => $oldPrice, 'new' => $newPrice]
                ]
            ];
        }
        
        return null;
    }
    
    /**
     * Migrate old eBay listings to inventory format using bulk migration
     *
     * @param array $listingIds Array of eBay listing IDs to migrate
     * @return array Migration results
     */
    public function bulkMigrateListings(array $listingIds): array
    {
        $this->logger->info("Starting bulk migration of " . count($listingIds) . " listings to inventory format");
        
        $results = [
            'total' => count($listingIds),
            'migrated' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        if (empty($listingIds)) {
            return $results;
        }
        
        try {
            // Call eBay bulk migrate API
            $response = $this->inventoryApi->bulkMigrateListing($listingIds);
            
            $this->logger->debug("Bulk migrate response: " . json_encode($response, JSON_PRETTY_PRINT));
            
            if (isset($response['responses'])) {
                foreach ($response['responses'] as $migrationResponse) {
                    $listingId = $migrationResponse['listingId'] ?? 'unknown';
                    
                    if (isset($migrationResponse['statusCode']) && $migrationResponse['statusCode'] == 200) {
                        $results['migrated']++;
                        $this->logger->info("Successfully migrated listing {$listingId}");
                        
                        // Update database if we have the inventory location
                        if (isset($migrationResponse['inventoryItemLocation'])) {
                            $this->updateMigratedListing($listingId, $migrationResponse['inventoryItemLocation']);
                        }
                    } else {
                        $results['failed']++;
                        $error = $migrationResponse['errors'][0]['message'] ?? 'Unknown error';
                        $results['errors'][] = [
                            'listingId' => $listingId,
                            'error' => $error
                        ];
                        $this->logger->error("Failed to migrate listing {$listingId}: {$error}");
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->logger->error("Bulk migration failed: " . $e->getMessage());
            $results['errors'][] = [
                'listingId' => 'BULK_OPERATION',
                'error' => $e->getMessage()
            ];
        }
        
        $this->logger->info("Bulk migration completed", $results);
        return $results;
    }
    
    /**
     * Find all old format listings that need migration
     *
     * @return array Array of listing IDs that need migration
     */
    public function findListingsToMigrate(): array
    {
        $this->logger->info("Finding old format listings that need migration");
        try {
            // Get all active listings from database

            // Get the repository
            /** @var ScrItemRepository $scrItemRepo */
            $scrItemRepo = $this->entityManager->getRepository(ScrItem::class);

            // Find eBay items that need updates
            $scrItemsToMigrate = $scrItemRepo->findItemsWithEbayListings(5000);
            $listingIdsToMigrate = array_map(function (ScrItem $item) { return $item->getEbayItemId(); }, $scrItemsToMigrate);

            $this->logger->info("Found " . count($listingIdsToMigrate) . " listings that need migration");

            return $listingIdsToMigrate;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to find listings to migrate: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update database after successful listing migration
     *
     * @param string $listingId The migrated listing ID
     * @param array $inventoryLocation The new inventory location data
     */
    private function updateMigratedListing(string $listingId, array $inventoryLocation): void
    {
        try {
            // Find the EbayItem by listing ID
            $ebayItem = $this->entityManager->getRepository(EbayItem::class)
                ->findOneBy(['ebay_item_id' => $listingId]);
            
            if ($ebayItem) {
                // Update with new inventory format data
                $ebayItem->setUpdated(new DateTime());
                // Store migration info in a comment or separate field if needed
                
                $this->entityManager->persist($ebayItem);
                $this->entityManager->flush();
                
                $this->logger->info("Updated database for migrated listing {$listingId}");
            }
            
        } catch (Exception $e) {
            $this->logger->warning("Failed to update database for migrated listing {$listingId}: " . $e->getMessage());
        }
    }
    
    /**
     * Get inventory item data from eBay API
     *
     * @param string $itemId The SKU/item ID to get inventory for
     * @return array|null The inventory data or null if not found
     */
    public function getInventoryItem(string $itemId): ?array
    {
        $this->logger->info("Getting inventory data from eBay for item {$itemId}");
        
        try {
            $response = $this->inventoryApi->getInventoryItem($itemId);
            
            if ($response && isset($response['sku'])) {
                $this->logger->debug("eBay inventory response: " . json_encode($response, JSON_PRETTY_PRINT));
                return $response;
            } else {
                $this->logger->warning("No inventory data found for item {$itemId}");
                return null;
            }
        } catch (Exception $e) {
            if ($e instanceof RuntimeException) {
                $clientException = $e->getPrevious();
                if ($clientException instanceof ClientException) {
                    if ($clientException->getResponse()) {
                        if ($clientException->getResponse()->getStatusCode() == 404) {
                            $this->logger->warning("No inventory data found for item {$itemId}");
                            return null;
                        }
                    }
                }
            }
            $this->logger->error("Exception getting inventory data for item {$itemId}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Save a new eBay item to the database
     *
     * @param ScrItem $scrItem The SCR item
     * @param string $ebayItemId The eBay listing ID
     * @param int $quantity The quantity
     * @param float $price The price
     * @return EbayItem The created eBay item
     */
    private function saveEbayItem(ScrItem $scrItem, string $ebayItemId, int $quantity, float $price): EbayItem
    {
        $now = new DateTime();
        
        // Check if the item already exists
        $ebayItemRepo = $this->entityManager->getRepository(EbayItem::class);
        $ebayItem = $ebayItemRepo->findOneBy(['item_id' => $scrItem->getId()]);
        
        if (!$ebayItem) {
            // Create new eBay item
            $ebayItem = new EbayItem();
            $ebayItem->setScrItem($scrItem);
            $ebayItem->setItemId($scrItem->getId());
            $ebayItem->setCreated($now);
        }
        
        // Update properties
        $ebayItem->setEbayItemId($ebayItemId);
        $ebayItem->setQuantity($quantity);
        $ebayItem->setPrice((string)$price);
        // Only update 'updated' timestamp when actually syncing TO eBay
        $ebayItem->setUpdated($now);

        // Save to database
        $this->entityManager->persist($ebayItem);
        $this->entityManager->flush();
        
        return $ebayItem;
    }
}