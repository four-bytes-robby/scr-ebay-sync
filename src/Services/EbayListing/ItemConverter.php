<?php
// src/Services/EbayListing/ItemConverter.php
namespace Four\ScrEbaySync\Services\EbayListing;

use Four\ScrEbaySync\Entity\ScrItem;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Main item converter service that coordinates the conversion process
 */
class ItemConverter
{
    private ScrItem $scrItem;
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    private TitleFormatter $titleFormatter;
    private CategoryResolver $categoryResolver;
    private ShippingPolicyGenerator $shippingPolicy;
    private DescriptionFormatter $descriptionFormatter;
    private ImageService $imageService;
    
    /**
     * @param ScrItem $scrItem The SCR item entity
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        ScrItem $scrItem,
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->scrItem = $scrItem;
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('item_converter');
        
        // Initialize helper services
        $this->titleFormatter = new TitleFormatter($scrItem);
        $this->categoryResolver = new CategoryResolver($scrItem, $entityManager);
        $this->shippingPolicy = new ShippingPolicyGenerator($scrItem, $entityManager);
        $this->descriptionFormatter = new DescriptionFormatter($scrItem, $this->logger);
        $this->imageService = new ImageService($scrItem, $this->logger);
        
        // Set image service for description formatter
        $this->descriptionFormatter->setImageService($this->imageService);
    }
    
    /**
     * Convert to eBay Inventory Item format for REST API
     *
     * @return array The inventory item data
     */
    public function createInventoryItem(): array
    {
        $inventoryItem = [
            'product' => [
                'title' => $this->titleFormatter->getShortenedTitle(),
                'description' => $this->descriptionFormatter->getDescription(),
                'aspects' => $this->getAspects(),
                'imageUrls' => $this->imageService->getImageUrls()
            ],
            'condition' => 'NEW',
            'availability' => [
                'shipToLocationAvailability' => [
                    'quantity' => $this->getQuantity()
                ]
            ],
            'packageWeightAndSize' => [
                'packageType' => $this->shippingPolicy->isBigItem() ? 'PACKAGE_THICK_ENVELOPE' : 'LETTER'
            ]
        ];

        // Add EAN/ISBN if available
        if ($this->scrItem->getEan() > 0) {
            $ean = (string)$this->scrItem->getEan();
            if (strlen($ean) === 13) {
                $inventoryItem['product']['identifiers'] = [
                    [
                        'type' => $this->isBook() ? 'ISBN' : 'EAN',
                        'value' => $ean
                    ]
                ];
            }
        }

        return $inventoryItem;
    }

    /**
     * Create eBay Offer data for REST API
     *
     * @return array The offer data
     */
    public function createOffer(): array
    {
        $offer = [
            'sku' => $this->scrItem->getId(),
            'marketplaceId' => 'EBAY_DE', // Assuming always German marketplace
            'format' => 'FIXED_PRICE',
            'availableQuantity' => $this->getQuantity(),
            'categoryId' => (string)$this->categoryResolver->getCategoryId(),
            'listingDescription' => $this->descriptionFormatter->getDescription(),
            'listingPolicies' => [
                'returnPolicy' => [
                    'returnsAccepted' => true,
                    'returnPeriod' => 'DAYS_14',
                    'returnShippingCostPayer' => 'BUYER'
                ],
                'paymentPolicy' => [
                    'paymentPolicyId' => 'DEFAULT_PAYMENT_POLICY'
                ],
                'fulfillmentPolicy' => $this->shippingPolicy->getFulfillmentPolicy()
            ],
            'pricingSummary' => [
                'price' => [
                    'currency' => 'EUR',
                    'value' => (string)$this->getPrice()
                ]
            ],
            'merchantLocationKey' => 'DEFAULT', 
            'tax' => [
                'vatPercentage' => (string)$this->getVATPercent()
            ]
        ];

        // Add store category if available
        $storeCategoryId = $this->categoryResolver->getStoreCategoryId();
        if ($storeCategoryId) {
            $offer['storeCategoryNames'] = [$storeCategoryId];
        }
        return $offer;
    }

    /**
     * Get eBay item aspects
     *
     * @return array The aspects
     */
    private function getAspects(): array
    {
        $aspects = [
            'Interpret' => [$this->titleFormatter->shortenByWords($this->titleFormatter->getInterpret(), 65)],
            'Musiktitel' => [$this->titleFormatter->shortenByWords($this->titleFormatter->getMusiktitel(), 65)],
            'Format' => [$this->titleFormatter->getFormat()]
        ];
        
        // Book-specific aspects
        if ($this->isBook()) {
            $aspects['Autor'] = [$this->titleFormatter->shortenByWords($this->titleFormatter->getInterpret(), 65)];
            $aspects['Buchtitel'] = [$this->titleFormatter->shortenByWords($this->titleFormatter->getMusiktitel(), 65)];
            $aspects['Sprache'] = ['deutsch'];
        }
        
        // Tickets with date
        if (strtolower($this->scrItem->getGroupId()) === 'tickets' && $this->scrItem->getAvailableUntil()) {
            $date = $this->scrItem->getAvailableUntil();
            $aspects['Jahr'] = [$date->format('Y')];
            
            $months = [
                'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
            ];
            $aspects['Monat'] = [$months[$date->format('n') - 1]];
            $aspects['Tag'] = [$date->format('j')];
        }
        
        return $aspects;
    }
    
    /**
     * Get the maximum quantity for eBay
     *
     * @return int The quantity (max 3)
     */
    public function getQuantity(): int
    {
        return min($this->scrItem->getQuantity(), 3);
    }

    /**
     * Get the price with appropriate surcharge
     *
     * @return float The price
     */
    public function getPrice(): float
    {
        $surcharge = 1.0;
        
        // No surcharge for books (fixed price law)
        if ($this->isBook()) {
            $surcharge = 0;
        }
        
        return $this->scrItem->getPrice() + $surcharge;
    }

    /**
     * Get VAT percentage
     *
     * @return float VAT percentage
     */
    public function getVATPercent(): float
    {
        $vat19Percent = 19.0;
        $vat7Percent = 7.0;
        
        // Adjust VAT for special period if needed
        if (time() > strtotime('2020-06-30') && time() < strtotime('2021-01-01')) {
            $vat19Percent = 16.0;
            $vat7Percent = 5.0;
        }
        
        $vatPercent = $vat19Percent;
        $taxCategory = $this->getTaxCategory();
        
        // Special VAT rates for books and tickets
        if (in_array($taxCategory, ['Books', 'Tickets'])) {
            $vatPercent = $vat7Percent;
        }
        
        return $vatPercent;
    }

    /**
     * Get tax category
     *
     * @return string Tax category
     */
    public function getTaxCategory(): string
    {
        $format = $this->titleFormatter->getFormat();
        
        if (preg_match('/book/i', $format)) {
            return 'Books';
        }
        
        if (preg_match('/ticket/i', $format)) {
            return 'Tickets';
        }
        
        return 'Regular';
    }

    /**
     * Check if item is a book
     *
     * @return bool Whether item is a book
     */
    public function isBook(): bool
    {
        return strpos($this->scrItem->getName(), 'BOOK)') !== false || 
               $this->scrItem->getGroupId() == 'BOOK' ||
               $this->titleFormatter->getFormat() == 'Book';
    }
}