<?php
// src/Api/eBay/Inventory.php
namespace Four\ScrEbaySync\Api\eBay;

use Monolog\Logger;
use RuntimeException;

class Inventory extends ApiClient
{
    protected string $apiVersion = 'v1';

    /**
     * @param Auth $auth The eBay Authentication instance
     * @param Logger|null $logger Optional logger
     */
    public function __construct(Auth $auth, ?Logger $logger = null)
    {
        parent::__construct($auth, $logger ?? new Logger('ebay_inventory'));
    }

    /**
     * Create or update an inventory item
     *
     * @param string $sku The SKU of the item
     * @param array $itemData The item data
     * @return array Response data
     */
    public function createOrUpdateInventoryItem(string $sku, array $itemData): array
    {
        return $this->put("/sell/inventory/$this->apiVersion/inventory_item/$sku", $itemData);
    }

    /**
     * Get an inventory item
     *
     * @param string $sku The SKU of the item
     * @return array Response data
     */
    public function getInventoryItem(string $sku): array
    {
        return $this->get("/sell/inventory/$this->apiVersion/inventory_item/$sku");
    }

    /**
     * Delete an inventory item
     *
     * @param string $sku The SKU of the item
     * @return array Response data
     */
    public function deleteInventoryItem(string $sku): array
    {
        return $this->delete("/sell/inventory/$this->apiVersion/inventory_item/$sku");
    }

    /**
     * Get inventory items
     *
     * @param int $limit Maximum number of items (default 100)
     * @param string|null $offset Pagination offset
     * @return array Response data
     */
    public function getInventoryItems(int $limit = 100, ?string $offset = null): array
    {
        $query = ['limit' => $limit];
        
        if ($offset) {
            $query['offset'] = $offset;
        }
        
        return $this->get("/sell/inventory/$this->apiVersion/inventory_item", $query);
    }

    /**
     * Update availability of an inventory item
     * DEPRECATED: Verwende stattdessen createOrUpdateInventoryItem mit shipToLocationAvailability
     * 
     * @param string $sku The SKU of the item
     * @param int $quantity The new quantity
     * @return array Response data
     * @deprecated Verwende createOrUpdateInventoryItem() mit availability.shipToLocationAvailability.quantity
     */
    public function updateInventoryItemAvailability(string $sku, int $quantity): array
    {
        $this->logger->warning("updateInventoryItemAvailability ist deprecated. Verwende createOrUpdateInventoryItem stattdessen.");
        
        $data = [
            'availability' => [
                'shipToLocationAvailability' => [
                    'quantity' => $quantity
                ]
            ]
        ];
        
        return $this->createOrUpdateInventoryItem($sku, $data);
    }

    /**
     * Create an offer for an inventory item
     *
     * @param array $offerData The offer data
     * @return array Response data with offer ID
     */
    public function createOffer(array $offerData): array
    {
        return $this->post("/sell/inventory/$this->apiVersion/offer", $offerData);
    }

    /**
     * Update an existing offer
     *
     * @param string $offerId The offer ID
     * @param array $offerData The updated offer data
     * @return array Response data
     */
    public function updateOffer(string $offerId, array $offerData): array
    {
        return $this->put("/sell/inventory/$this->apiVersion/offer/$offerId", $offerData);
    }

    /**
     * Get an existing offer
     *
     * @param string $offerId The offer ID
     * @return array Response data
     */
    public function getOffer(string $offerId): array
    {
        return $this->get("/sell/inventory/$this->apiVersion/offer/$offerId");
    }

    /**
     * Get all offers for a specific SKU
     *
     * @param string $sku The SKU to get offers for
     * @param int $limit Maximum number of offers (default 100)
     * @param string|null $offset Pagination offset
     * @return array Response data
     */
    public function getOffers(string $sku, int $limit = 100, ?string $offset = null): array
    {
        $query = [
            'sku' => $sku,
            'limit' => $limit
        ];
        
        if ($offset) {
            $query['offset'] = $offset;
        }
        
        return $this->get("/sell/inventory/$this->apiVersion/offer", $query);
    }

    /**
     * Delete an offer
     *
     * @param string $offerId The offer ID
     * @return array Response data
     */
    public function deleteOffer(string $offerId): array
    {
        return $this->delete("/sell/inventory/$this->apiVersion/offer/$offerId");
    }

    /**
     * Publish an offer to eBay (create/update a listing)
     *
     * @param string $offerId The offer ID
     * @return array Response data with listing ID
     */
    public function publishOffer(string $offerId): array
    {
        return $this->post("/sell/inventory/$this->apiVersion/offer/$offerId/publish");
    }

    /**
     * Withdraw an offer from eBay (end the listing)
     *
     * @param string $offerId The offer ID
     * @return array Response data
     */
    public function withdrawOffer(string $offerId): array
    {
        return $this->post("/sell/inventory/$this->apiVersion/offer/$offerId/withdraw");
    }

    /**
     * Create an eBay product listing from inventory item
     * 
     * This is a higher-level helper method that handles both the inventory item and creation of offers
     *
     * @param string $sku The SKU of the item
     * @param array $inventoryItemData The inventory item data
     * @param array $offerData The offer data
     * @return array Response with listing ID and other details
     */
    public function createListing(string $sku, array $inventoryItemData, array $offerData): array
    {
        // Step 1: Create or update the inventory item
        $this->createOrUpdateInventoryItem($sku, $inventoryItemData);
        
        // Step 2: Create and publish an offer for this inventory item
        return $this->createOffer($offerData);
    }


    /**
     * Create Offer and Publish
     * @param array $offerData
     * @return array
     */
    public function createAndPublishOffer(array $offerData): array
    {
        // Step 1: Create offer
        $offerResponse = $this->createOffer($offerData);

        if (!isset($offerResponse['offerId'])) {
            throw new RuntimeException('Failed to create offer: No offer ID returned');
        }

        // Step 2: Publish the offer
        return $this->publishOffer($offerResponse['offerId']);
    }


    /**
     * Update an existing listing
     *
     * @param string $sku The SKU of the item
     * @param string $offerId The offer ID
     * @param array $inventoryItemData Updated inventory item data
     * @param array $offerData Updated offer data
     * @return array Response data
     */
    public function updateListing(string $sku, string $offerId, array $inventoryItemData, array $offerData): array
    {
        // Step 1: Update the inventory item
        $this->createOrUpdateInventoryItem($sku, $inventoryItemData);
        
        // Step 2: Update the offer
        $this->updateOffer($offerId, $offerData);
        
        // Step 3: Republish the offer
        return $this->publishOffer($offerId);
    }

    /**
     * Update the quantity of an item in eBay inventory
     * DEPRECATED: Verwende createOrUpdateInventoryItem stattdessen
     *
     * @param string $sku The SKU of the item
     * @param int $quantity The new quantity
     * @return array Response data
     * @deprecated Verwende createOrUpdateInventoryItem() mit availability.shipToLocationAvailability.quantity
     */
    public function updateQuantity(string $sku, int $quantity): array
    {
        $this->logger->warning("updateQuantity ist deprecated. Verwende createOrUpdateInventoryItem stattdessen.");
        return $this->updateInventoryItemAvailability($sku, $quantity);
    }

    /**
     * End a listing on eBay
     *
     * @param string $offerId The offer ID
     * @return array Response data
     */
    public function endListing(string $offerId): array
    {
        return $this->withdrawOffer($offerId);
    }

    /**
     * Get all inventory items with pagination support
     *
     * @param int $limit Items per page (max 100)
     * @param int $offset Offset for pagination
     * @return array Response data with inventory items
     */
    public function getAllInventoryItems(int $limit = 100, int $offset = 0): array
    {
        $query = [
            'limit' => min($limit, 100), // eBay API limit
            'offset' => $offset
        ];
        
        return $this->get("/sell/inventory/$this->apiVersion/inventory_item", $query);
    }

    /**
     * Bulk migrate listings from old format to inventory format
     *
     * @param array $listingIds Array of listing IDs to migrate
     * @return array Migration results
     */
    public function bulkMigrateListing(array $listingIds): array
    {
        $data = [
            'requests' => array_map(function($listingId) {
                return ['listingId' => $listingId];
            }, $listingIds)
        ];
        
        return $this->post("/sell/inventory/$this->apiVersion/bulk_migrate_listing", $data);
    }

    /**
     * Get all active listings (for finding old format listings)
     *
     * @param int $limit Items per page (max 100)
     * @param int $offset Offset for pagination
     * @return array Active listings data
     */
    public function getAllActiveListings(int $limit = 100, int $offset = 0): array
    {
        // This would typically use the Trading API or Browse API
        // For now, we'll use a placeholder that gets offers and derives listings
        $query = [
            'limit' => min($limit, 100),
            'offset' => $offset
        ];
        
        // Get all offers first, then check which are published
        $offersResponse = $this->get("/sell/inventory/$this->apiVersion/inventory_item", $query);
        
        $activeListings = [];
        if (isset($offersResponse['offers'])) {
            foreach ($offersResponse['offers'] as $offer) {
                if (isset($offer['listingId']) && $offer['status'] === 'PUBLISHED') {
                    $activeListings[] = [
                        'listingId' => $offer['listingId'],
                        'offerId' => $offer['offerId'],
                        'sku' => $offer['sku'],
                        'format' => 'INVENTORY' // Since these come from inventory API
                    ];
                }
            }
        }
        
        return $activeListings;
    }

    /**
     * Get listings that need migration (legacy Trading API listings)
     * This is a placeholder - would typically require Trading API access
     *
     * @return array Array of legacy listing IDs
     */
    public function getLegacyListings(): array
    {
        // This would require Trading API GetMyeBaySelling call
        // For now, return empty array - this needs Trading API implementation
        $this->logger->warning('getLegacyListings requires Trading API - returning empty array');
        return [];
    }

    /**
     * Delete all location mappings
     *
     * @param string $listingId
     * @param string $sku
     * @return array
     */
    public function deleteSkuLocationMapping(string $listingId, string $sku): array
    {
        return $this->delete("/sell/inventory/$this->apiVersion/listing/$listingId/sku/$sku/locations");
    }

    /**
     * Get mappings for listing
     *
     * @param mixed $listingId
     * @param string $sku
     * @return array
     */
    public function getSkuLocationMapping(mixed $listingId, string $sku): array
    {
        return $this->get("/sell/inventory/$this->apiVersion/listing/$listingId/sku/$sku/locations");
    }

    /**
     * Check if item is compatible with inventory item
     *
     * @param string $sku
     * @return array
     */
    public function getProductCompatibility(string $sku): array
    {
        return $this->get("/sell/inventory/$this->apiVersion/inventory_item/$sku/product_compatibility");
    }
}