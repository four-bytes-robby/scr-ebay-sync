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
    const int ATTRIBUTE_MAX_LENGTH = 65;
    private TitleFormatter $titleFormatter;
    private CategoryResolver $categoryResolver;
    private DescriptionFormatter $descriptionFormatter;
    private ImageService $imageService;
    
    /**
     * @param ScrItem $scrItem The SCR item entity
     * @param EntityManagerInterface $entityManager Entity manager for accessing DB
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        private readonly ScrItem                $scrItem,
        EntityManagerInterface $entityManager,
        private ?Logger                         $logger = null
    ) {
        $this->logger = $this->logger ?? new Logger('item_converter');
        
        // Initialize helper services
        $this->titleFormatter = new TitleFormatter($scrItem);
        $this->categoryResolver = new CategoryResolver($scrItem, $entityManager);
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
//            'packageWeightAndSize' => [
//                'packageType' => $this->isBigItem() ? 'PARCEL_OR_PADDED_ENVELOPE' : 'PACKAGE_THICK_ENVELOPE'
//            ]
        ];

        // Add EAN field (required by eBay)
        if ($this->scrItem->getEan() > 0) {
            $inventoryItem['product']['ean'] = [str_pad($this->scrItem->getEan(), 13, '0', STR_PAD_LEFT)];
        } else {
            // Generate fake EAN if no EAN exists
            $inventoryItem['product']['ean'] = [$this->generateFakeEan($this->scrItem->getId())];
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
            'marketplaceId' => 'EBAY_DE', // Assuming always the German marketplace
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
                'fulfillmentPolicyId' => $this->isBigItem() ? '254882671020' : '255300405020',
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

        // Add store categories if available
        $offer['storeCategoryNames'] = $this->categoryResolver->getStoreCategoryNames();
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
            'Interpret' => [$this->titleFormatter->shortenByWord($this->titleFormatter->getInterpret(), self::ATTRIBUTE_MAX_LENGTH)],
            'Musiktitel' => [$this->titleFormatter->shortenByWord($this->titleFormatter->getMusiktitel(), self::ATTRIBUTE_MAX_LENGTH)],
            'Format' => [$this->titleFormatter->getFormat()]
        ];
        
        // Book-specific aspects
        if ($this->isBook()) {
            $aspects['Autor'] = [$this->titleFormatter->shortenByWord($this->titleFormatter->getInterpret(), self::ATTRIBUTE_MAX_LENGTH)];
            $aspects['Buchtitel'] = [$this->titleFormatter->shortenByWord($this->titleFormatter->getMusiktitel(), self::ATTRIBUTE_MAX_LENGTH)];
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
     * Gibt 0 zurück wenn das Item nicht verfügbar ist (wird dann gelöscht)
     *
     * @return int The quantity (max 3, oder 0 wenn unavailable)
     */
    public function getQuantity(): int
    {
        $quantity = $this->scrItem->getQuantity();
        
        // Wenn quantity 0 oder negativ, gib 0 zurück (Item wird gelöscht)
        if ($quantity <= 0) {
            return 0;
        }
        
        return min($quantity, 3);
    }

    /**
     * Get the price with the appropriate surcharge
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
        return strtolower($this->scrItem->getGroupId()) == 'books' ||
               $this->titleFormatter->getFormat() == 'BOOK';
    }
    
    /**
     * Generate a fake EAN from item ID for eBay compatibility
     * Creates a valid checksum EAN-13 starting with 2 (internal use)
     *
     * @param string $itemId Item ID
     * @return string 13-digit EAN
     */
    private function generateFakeEan(string $itemId): string
    {
        // Start with 2 (indicates internal/store use)
        $ean = '2';
        
        // Take numeric characters from item ID and pad
        $numericId = preg_replace('/[^0-9]/', '', $itemId);
        $numericId = str_pad($numericId, 11, '0');
        $ean .= substr($numericId, 0, 11);
        
        // Calculate checksum
        $checksum = 0;
        for ($i = 0; $i < 12; $i++) {
            $checksum += $ean[$i] * (($i % 2 === 0) ? 1 : 3);
        }
        $checksum = (10 - ($checksum % 10)) % 10;
        
        return $ean . $checksum;
    }

    /**
     * @return bool
     */
    function isBigItem() : bool {
        return $this->getPosWeightClass(
            $this->scrItem->getName(),
            $this->scrItem->getGroupId()) != "small";
    }

    /**
     * Gibt die Gewichtsklasse (large, middle, small) zurÃ¼ck
     * @param string $name
     * @param string $category
     * @return string
     */
    private function getPosWeightClass(string $name, string $category) : string {
        $weight = $this->getPosWeight($name, $category);
        if ($weight >= 0.8) return "large";
        if ($weight >= 0.2) return "middle";
        return "small";
    }

    /**
     * @param string $name
     * @param string $category
     * @return float
     */
    private function getPosWeight(string $name, string $category) : float {
        $posWeight = 0.1;
        $format = $this->getFormat($name);
        switch (strtolower($category)) {
            case "vinyl":
                // Vinyl-Gewichte
                if (preg_match("/BOX/i", $format)) $posWeight = 0.9;
                elseif (preg_match("/DLP/i", $format)) $posWeight = 0.6;
                elseif (preg_match("/LP/i", $format)) $posWeight = 0.4;
                elseif (preg_match("/(MLP|10)/i", $format)) $posWeight = 0.3;
                elseif (preg_match("/(EP|7)/i", $format)) $posWeight = 0.12;
                else $posWeight = 0.4;
                if (preg_match("/BOOK/i", $format)) {
                    $posWeight = 0.7;
                }
                if (preg_match("/BUNDLE/i", $format)) {
                    $posWeight = 1;
                }
                break;
            case "cds":
            case "tapes":
                // CD-Gewichte
                if (preg_match("/BOX/i", $format)) $posWeight = 0.3;
                elseif (preg_match("/(DCD|2|3)/i", $format)) $posWeight = 0.2;
                if (preg_match("/BOOK/i", $format)) {
                    $posWeight = 0.4;
                }
                if (preg_match("/BUNDLE/i", $format)) {
                    $posWeight = 1;
                }
                break;
            case "clothes":
                // Kleidung
                $posWeight = 0.2;
                if (preg_match("/ZIP|JACKET/i", $format)) $posWeight = 0.4;
                if (preg_match("/BEANIE/i", $format)) $posWeight = 0.2;
                if (preg_match("/WRIST/i", $format)) $posWeight = 0.1;
                break;
            case "others":
                $weights = [
                    "BOOK" => 0.5,
                    "BEANIE" => 0.2,
                    "PUZZLE" => 0.3,
                    "POSTER" => 0.2,
                    "BAG" => 0.2,
                    "TICKET" => 0.02,
                    "COASTER" => 0.02,
                    "MUG" => 0.35,
                    "FANZINE" => 0.4,
                    "CUP" => 0.2,
                    "LANYARD" => 0.3,
                    "METAL PIN" => 0.02,
                    "BUTTON" => 0.02,
                    "PATCH" => 0.05,
                    "WRIST" => 0.1,
                    "BUNDLE" => 0.3,
                    "CARDS" => 0.15
                ];
                foreach ($weights as $search => $value) {
                    if (preg_match("/$search/i", $format)) {
                        $posWeight = $value;
                    }
                }
                break;
        }
        return $posWeight;
    }

    /**
     * Gibt das Format eines Artikels zurück als String
     * @param string $name
     * @return string
     */
    private function getFormat(string $name) : string {
        $format = "";
        $detail = "";
        if (preg_match("/\(([^)]+)\)$/", $name, $matches)) {
            $format = mb_convert_case($matches[1], MB_CASE_UPPER);
        }
        if (preg_match("/\[([^]]+)]$/m", $name, $matches)) {
            if ($matches[1] != '485') { // Sonderlogik Agrypnie - 16 485
                $detail = mb_convert_case($matches[1], MB_CASE_UPPER) . " ";
            }
        }
        return trim($detail . $format);
    }
}