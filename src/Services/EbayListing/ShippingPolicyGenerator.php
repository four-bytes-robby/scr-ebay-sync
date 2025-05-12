<?php
// src/Services/EbayListing/ShippingPolicyGenerator.php
namespace Four\ScrEbaySync\Services\EbayListing;

use Four\ScrEbaySync\Entity\ScrItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for generating shipping policies for eBay
 */
class ShippingPolicyGenerator
{
    private ScrItem $scrItem;
    private EntityManagerInterface $entityManager;
    
    // Default shipping costs
    private const SHIPPING_COSTS = [
        'DE' => [
            'STANDARD' => 2.99,
            'EXPEDITED' => 5.99,
            'BIG_ITEM' => 4.99
        ],
        'EU' => [
            'STANDARD' => 8.99,
            'EXPEDITED' => 12.99,
            'BIG_ITEM' => 14.99
        ],
        'INTERNATIONAL' => [
            'STANDARD' => 12.99,
            'EXPEDITED' => 19.99,
            'BIG_ITEM' => 24.99
        ]
    ];
    
    // Group IDs that are considered big items
    private const BIG_ITEM_GROUPS = ['LP', 'BOX', 'BOXSET'];
    
    /**
     * @param ScrItem $scrItem The SCR item entity
     * @param EntityManagerInterface $entityManager Entity manager for DB access
     */
    public function __construct(
        ScrItem $scrItem,
        EntityManagerInterface $entityManager
    ) {
        $this->scrItem = $scrItem;
        $this->entityManager = $entityManager;
    }
    
    /**
     * Get fulfillment policy for eBay item
     *
     * @return array Fulfillment policy data
     */
    public function getFulfillmentPolicy(): array
    {
        $shippingOptions = [
            [
                'costType' => 'FLAT_RATE',
                'optionType' => 'DOMESTIC',
                'shippingServices' => [
                    [
                        'shippingServiceCode' => 'DE_DHLPaket',
                        'shippingCost' => [
                            'currency' => 'EUR',
                            'value' => (string)$this->getDomesticShippingCost()
                        ],
                        'additionalShippingCost' => [
                            'currency' => 'EUR',
                            'value' => '0.00'
                        ],
                        'shippingServiceType' => 'DOMESTIC_STANDARD'
                    ]
                ]
            ],
            [
                'costType' => 'FLAT_RATE',
                'optionType' => 'INTERNATIONAL',
                'shippingServices' => [
                    [
                        'shippingServiceCode' => 'DE_DHLPaketInternational',
                        'shippingCost' => [
                            'currency' => 'EUR',
                            'value' => (string)$this->getInternationalShippingCost()
                        ],
                        'additionalShippingCost' => [
                            'currency' => 'EUR',
                            'value' => '0.00'
                        ],
                        'shipToLocations' => [
                            'regionIncluded' => [
                                [
                                    'regionName' => 'WORLDWIDE'
                                ]
                            ]
                        ],
                        'shippingServiceType' => 'INTERNATIONAL_STANDARD'
                    ]
                ]
            ]
        ];
        
        return [
            'fulfillmentPolicyId' => 'DEFAULT_FULFILLMENT_POLICY',
            'shippingOptions' => $shippingOptions,
            'handlingTime' => $this->getHandlingTime()
        ];
    }
    
    /**
     * Get domestic shipping cost
     *
     * @return float Shipping cost
     */
    private function getDomesticShippingCost(): float
    {
        $type = $this->isBigItem() ? 'BIG_ITEM' : 'STANDARD';
        return self::SHIPPING_COSTS['DE'][$type];
    }
    
    /**
     * Get international shipping cost
     *
     * @return float Shipping cost
     */
    private function getInternationalShippingCost(): float
    {
        $type = $this->isBigItem() ? 'BIG_ITEM' : 'STANDARD';
        return self::SHIPPING_COSTS['INTERNATIONAL'][$type];
    }
    
    /**
     * Check if item is a big item (LP, box, etc.)
     *
     * @return bool Whether item is a big item
     */
    public function isBigItem(): bool
    {
        $groupId = $this->scrItem->getGroupId();
        
        // Check if group is in big item groups
        if (in_array($groupId, self::BIG_ITEM_GROUPS)) {
            return true;
        }
        
        // Check item name for box sets
        $name = $this->scrItem->getName();
        if (preg_match('/BOX(SET)?|DELUXE|SPECIAL EDITION/i', $name)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get handling time in days
     *
     * @return int Handling time
     */
    private function getHandlingTime(): int
    {
        // If item is a pre-order and has a release date in the future, calculate days
        if ($this->scrItem->getReleasedate() && $this->scrItem->getReleasedate() > new \DateTime()) {
            $now = new \DateTime();
            $interval = $this->scrItem->getReleasedate()->diff($now);
            return max(1, min(30, $interval->days + 1)); // Max 30 days for eBay
        }
        
        // Different handling times based on item group
        $groupId = $this->scrItem->getGroupId();
        
        if ($groupId == 'PREORDER') {
            return 10; // Higher handling time for pre-orders
        }
        
        // Default handling time: 1-3 days based on priority
        return 1;
    }
}