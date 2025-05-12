<?php
// src/Services/EbayInventoryService.php
namespace Four\ScrEbaySync\Services;

use Four\ScrEbaySync\Entity\ScrItem;
use Four\ScrEbaySync\Entity\EbayItem;
use Four\ScrEbaySync\Api\eBay\Inventory;
use Four\ScrEbaySync\Services\EbayListing\DescriptionFormatter;
use Four\ScrEbaySync\Services\EbayListing\ImageService;
use Four\ScrEbaySync\Services\EbayListing\ItemConverter;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

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
        } catch (\Exception $e) {
            $this->logger->error("Exception creating eBay listing for item {$scrItem->getId()}: " . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
            return null;
        }
    }
    
    /**
     * Update an existing eBay listing
     *
     * @param ScrItem $scrItem The item to update
     * @param EbayItem $ebayItem The corresponding eBay item
     * @return bool Success status
     */
    public function updateListing(ScrItem $scrItem, EbayItem $ebayItem): bool
    {
        $this->logger->info("Updating eBay listing {$ebayItem->getEbayItemId()} for item {$scrItem->getId()}");
        
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
                return false;
            }
            
            // Convert to inventory item format
            $inventoryItemData = $itemConverter->createInventoryItem();
            
            // Get the offer ID
            $offerResponse = $this->inventoryApi->getOffers($scrItem->getId());
            
            if (!isset($offerResponse['offers']) || empty($offerResponse['offers'])) {
                $this->logger->error("No offers found for item {$scrItem->getId()}");
                return false;
            }
            
            $offerId = $offerResponse['offers'][0]['offerId'];
            
            // Update offer data
            $offerData = $itemConverter->createOffer();
            
            // Send to eBay
            $response = $this->inventoryApi->updateListing(
                $scrItem->getId(),
                $offerId, 
                $inventoryItemData,
                $offerData
            );
            
            if (isset($response['listingId'])) {
                // Update the eBay item in our database
                $ebayItem->setQuantity($itemConverter->getQuantity());
                $ebayItem->setPrice((string)$itemConverter->getPrice());
                $ebayItem->setUpdated(new \DateTime());
                $this->entityManager->persist($ebayItem);
                $this->entityManager->flush();
                
                $this->logger->info("Successfully updated eBay listing {$response['listingId']} for item {$scrItem->getId()}");
                return true;
            } else {
                $this->logger->error("Failed to update eBay listing for item {$scrItem->getId()}: No listing ID in response", ['response' => $response]);
                return false;
            }
        } catch (\Exception $e) {
            $this->logger->error("Exception updating eBay listing for item {$scrItem->getId()}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update inventory quantity for an existing listing
     *
     * @param ScrItem $scrItem The item to update
     * @param EbayItem $ebayItem The corresponding eBay item
     * @return bool Success status
     */
    public function updateQuantity(ScrItem $scrItem, EbayItem $ebayItem): bool
    {
        $quantity = min($scrItem->getQuantity(), 3); // Max 3
        
        $this->logger->info("Updating quantity to {$quantity} for eBay listing {$ebayItem->getEbayItemId()}");
        
        try {
            // Update the quantity on eBay
            $response = $this->inventoryApi->updateQuantity($scrItem->getId(), $quantity);
            
            // Update the eBay item in our database
            $ebayItem->setQuantity($quantity);
            $ebayItem->setUpdated(new \DateTime());
            $this->entityManager->persist($ebayItem);
            $this->entityManager->flush();
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Exception updating quantity for item {$scrItem->getId()}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * End an eBay listing
     *
     * @param EbayItem $ebayItem The eBay item to end
     * @return bool Success status
     */
    public function endListing(EbayItem $ebayItem): bool
    {
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
            $ebayItem->setDeleted(new \DateTime());
            $this->entityManager->persist($ebayItem);
            $this->entityManager->flush();
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Exception ending listing {$ebayItem->getEbayItemId()}: " . $e->getMessage());
            return false;
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
        $now = new \DateTime();
        
        // Check if the item already exists
        $ebayItemRepo = $this->entityManager->getRepository(EbayItem::class);
        $ebayItem = $ebayItemRepo->findOneBy(['item_id' => $scrItem->getId()]);
        
        if (!$ebayItem) {
            // Create new eBay item
            $ebayItem = new EbayItem();
            $ebayItem->setItemId($scrItem->getId());
            $ebayItem->setCreated($now);
        }
        
        // Update properties
        $ebayItem->setEbayItemId($ebayItemId);
        $ebayItem->setQuantity($quantity);
        $ebayItem->setPrice((string)$price);
        $ebayItem->setUpdated($now);
        
        // Save to database
        $this->entityManager->persist($ebayItem);
        $this->entityManager->flush();
        
        return $ebayItem;
    }
}