<?php
// src/Api/eBay/Inventory.php
namespace Four\ScrEbaySync\Api\eBay;

use Monolog\Logger;

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
        return $this->put("/sell/inventory/{$this->apiVersion}/inventory_item/{$sku}", $itemData);
    }

    /**
     * Get an inventory item
     *
     * @param string $sku The SKU of the item
     * @return array Response data
     */
    public function getInventoryItem(string $sku): array
    {
        return $this->get("/sell/inventory/{$this->apiVersion}/inventory_item/{$sku}");
    }

    /**
     * Delete an inventory item
     *
     * @param string $sku The SKU of the item
     * @return array Response data
     */
    public function deleteInventoryItem(string $sku): array
    {
        return $this->delete("/sell/inventory/{$this->apiVersion}/inventory_item/{$sku}");
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
        
        return $this->get("/sell/inventory/{$this->apiVersion}/inventory_item", $query);
    }

    /**
     * Update availability of an inventory item
     *
     * @param string $sku The SKU of the item
     * @param int $quantity The new quantity
     * @return array Response data
     */
    public function updateInventoryItemAvailability(string $sku, int $quantity): array
    {
        $data = [
            'availability' => [
                'shipToLocationAvailability' => [
                    'quantity' => $quantity
                ]
            ]
        ];
        
        return $this->put("/sell/inventory/{$this->apiVersion}/inventory_item/{$sku}/availability", $data);
    }

    /**
     * Create an offer for an inventory item
     *
     * @param array $offerData The offer data
     * @return array Response data with offer ID
     */
    public function createOffer(array $offerData): array
    {
        return $this->post("/sell/inventory/{$this->apiVersion}/offer", $offerData);
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
        return $this->put("/sell/inventory/{$this->apiVersion}/offer/{$offerId}", $offerData);
    }

    /**
     * Get an existing offer
     *
     * @param string $offerId The offer ID
     * @return array Response data
     */
    public function getOffer(string $offerId): array
    {
        return $this->get("/sell/inventory/{$this->apiVersion}/offer/{$offerId}");
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
        
        return $this->get("/sell/inventory/{$this->apiVersion}/offer", $query);
    }

    /**
     * Delete an offer
     *
     * @param string $offerId The offer ID
     * @return array Response data
     */
    public function deleteOffer(string $offerId): array
    {
        return $this->delete("/sell/inventory/{$this->apiVersion}/offer/{$offerId}");
    }

    /**
     * Publish an offer to eBay (create/update a listing)
     *
     * @param string $offerId The offer ID
     * @return array Response data with listing ID
     */
    public function publishOffer(string $offerId): array
    {
        return $this->post("/sell/inventory/{$this->apiVersion}/offer/{$offerId}/publish", []);
    }

    /**
     * Withdraw an offer from eBay (end the listing)
     *
     * @param string $offerId The offer ID
     * @return array Response data
     */
    public function withdrawOffer(string $offerId): array
    {
        return $this->post("/sell/inventory/{$this->apiVersion}/offer/{$offerId}/withdraw", []);
    }

    /**
     * Create an eBay product listing from inventory item
     * 
     * This is a higher-level helper method that handles both the inventory item and offer creation
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
        
        // Step 2: Create an offer for this inventory item
        $offerResponse = $this->createOffer($offerData);
        
        if (!isset($offerResponse['offerId'])) {
            throw new \RuntimeException('Failed to create offer: No offer ID returned');
        }
        
        // Step 3: Publish the offer to create the listing
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
     *
     * @param string $sku The SKU of the item
     * @param int $quantity The new quantity
     * @return array Response data
     */
    public function updateQuantity(string $sku, int $quantity): array
    {
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
}