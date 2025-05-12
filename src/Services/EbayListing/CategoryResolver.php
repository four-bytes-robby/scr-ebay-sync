<?php
// src/Services/EbayListing/CategoryResolver.php
namespace Four\ScrEbaySync\Services\EbayListing;

use Four\ScrEbaySync\Entity\ScrItem;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for resolving eBay categories
 */
class CategoryResolver
{
    private ScrItem $scrItem;
    private EntityManagerInterface $entityManager;
    private ?Logger $logger;
    
    // Category mappings based on ebayConsts.cs
    private const CATEGORY_MAPPING = [
        'CDs-Black & Death Metal' => 33288,
        'CDs-Gothic Metal' => 56622,
        'CDs-Grindcore' => 56623,
        'CDs-Heavy Metal' => 1574,
        'CDs-Metalcore' => 87348,
        'CDs-New Metal' => 21730,
        'CDs-Progressive Metal' => 7772,
        'CDs-Speed & Thrash Metal' => 33290,
        'CDs-Alternative & Grunge' => 1572,
        'CDs-Emo- & Hardcore' => 87349,
        'CDs-Gothic & Darkwave' => 21726,
        'CDs-Hardrock' => 1055,
        'CDs-Psychedelic' => 3362,
        'CDs-Progressive Rock' => 37184,
        'CDs-Punk' => 2252,
        'CDs-Rock' => 14732,
        'Vinyl-Black & Death Metal' => 33293,
        'Vinyl-Gothic Metal' => 56624,
        'Vinyl-Grindcore' => 56625,
        'Vinyl-Heavy Metal' => 1594,
        'Vinyl-Metalcore' => 87353,
        'Vinyl-New Metal' => 21743,
        'Vinyl-Progressive Metal' => 7781,
        'Vinyl-Speed & Thrash Metal' => 33294,
        'Vinyl-Alternative & Grunge' => 1592,
        'Vinyl-Emo- & Hardcore' => 87354,
        'Vinyl-Gothic & Darkwave' => 21742,
        'Vinyl-Hardrock' => 1077,
        'Vinyl-Psychedelic' => 3366,
        'Vinyl-Progressive Rock' => 37188,
        'Vinyl-Punk' => 2262,
        'Vinyl-Rock' => 56626,
        'Clothes-Patches' => 30691,
        'Clothes-T-Shirt' => 68726,
        'Clothes-Girlie' => 68694,
        'Clothes-Sweater / Kapu' => 68719,
        'Clothes-Caps / Beanies' => 70781,
        'Others-Casettes' => 52071,
        'Others-Patches' => 30691,
        'Others-Books Music' => 43777,
        'Others-Books Fantasy' => 2229,
        'Others-Books Comics' => 8586,
        'Others-Others' => 21756,
        'Tickets-Tickets' => 34814,
    ];
    
    // Store category mappings
    private const STORE_CATEGORY_MAPPING = [
        'SCR-Releases' => 19249599,
        'CDs-Black, Death, Thrash Metal' => 2640031010,
        'CDs-Heavy, Doom, Gothic Metal' => 2640032010,
        'CDs-Progressive Rock & Metal' => 2640033010,
        'CDs-Hardcore, Metalcore, New Metal' => 2640034010,
        'CDs-Alternative, Hardrock, Gothic' => 2640035010,
        'Vinyl-Black, Death, Thrash Metal' => 2640121010,
        'Vinyl-Heavy, Doom, Gothic Metal' => 2640122010,
        'Vinyl-Progressive Rock & Metal' => 2640123010,
        'Vinyl-Hardcore, Metalcore, New Metal' => 2640124010,
        'Vinyl-Alternative, Hardrock, Gothic' => 2640125010,
        'Clothes-Merchandise' => 16741957,
        'Others-Merchandise' => 16741957,
        'Others-Others' => 1,
        'Tickets-Tickets' => 3510352010,
    ];
    
    // Genre mapping for store categories
    private const GENRE_TO_STORE_CATEGORY = [
        'Black & Death Metal' => 'Black, Death, Thrash Metal',
        'Gothic Metal' => 'Heavy, Doom, Gothic Metal',
        'Grindcore' => 'Black, Death, Thrash Metal',
        'Heavy Metal' => 'Heavy, Doom, Gothic Metal',
        'Metalcore' => 'Hardcore, Metalcore, New Metal',
        'New Metal' => 'Hardcore, Metalcore, New Metal',
        'Progressive Metal' => 'Progressive Rock & Metal',
        'Speed & Thrash Metal' => 'Black, Death, Thrash Metal',
        'Alternative & Grunge' => 'Alternative, Hardrock, Gothic',
        'Emo- & Hardcore' => 'Hardcore, Metalcore, New Metal',
        'Gothic & Darkwave' => 'Alternative, Hardrock, Gothic',
        'Hardrock' => 'Alternative, Hardrock, Gothic',
        'Psychedelic' => 'Alternative, Hardrock, Gothic',
        'Progressive Rock' => 'Progressive Rock & Metal',
        'Punk' => 'Alternative, Hardrock, Gothic',
        'Rock' => 'Alternative, Hardrock, Gothic',
        'Others' => 'Others',
        'Tickets' => 'Tickets',
    ];
    
    /**
     * @param ScrItem $scrItem The SCR item entity
     * @param EntityManagerInterface $entityManager Entity manager for DB access
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        ScrItem $scrItem,
        EntityManagerInterface $entityManager,
        ?Logger $logger = null
    ) {
        $this->scrItem = $scrItem;
        $this->entityManager = $entityManager;
        $this->logger = $logger ?? new Logger('category_resolver');
    }
    
    /**
     * Get the category search parts (firstPart and secondPart)
     * 
     * @return array [firstPart, secondPart]
     */
    private function getCategorySearchParts(): array
    {
        $firstPart = $this->scrItem->getGroupId();
        $secondPart = $this->scrItem->getGroupId();
        $name = $this->scrItem->getName();
        
        // Determine secondPart for CDs and Vinyl based on genre
        if ($firstPart == 'CDs' || $firstPart == 'Vinyl') {
            $secondPart = $this->matchGenre($this->scrItem->getGenre());
        }
        
        // Special cases for "Others" group
        if ($firstPart == 'Others') {
            if (strpos($name, 'PATCH)') !== false) {
                $secondPart = 'Patches';
            }
            if (strpos($name, 'BOOK)') !== false) {
                $secondPart = 'Books Music';
            }
            if (strpos($name, 'CASS)') !== false) {
                $secondPart = 'Casettes';
            }
        }
        
        // Special cases for "Clothes" group
        if ($firstPart == 'Clothes') {
            // Match T-SHIRT, GIRLIE, ZIPPER, HOODIE, etc.
            if (preg_match('/[\[(](T-SHIRT|GIRLIE|ZIPPER|HOODIE|TS|GS|LS|HSW|HSWZ)([ \-]\w+)?[\])]]/i', $name, $matches)) {
                $secondPart = $matches[1];
                $secondPart = preg_replace('/T-SHIRT|TS/i', 'T-Shirt', $secondPart);
                $secondPart = preg_replace('/GIRLIE|GS/i', 'Girlie', $secondPart);
                $secondPart = preg_replace('/ZIPPER|HSWZ|HOODIE|HSW|LONGSLEEVE|LS/i', 'Sweater / Kapu', $secondPart);
            } else {
                $secondPart = 'T-Shirt';
            }
        }
        
        return [$firstPart, $secondPart];
    }
    
    /**
     * Match genre to eBay category genre
     * 
     * @param string $genre The genre from SCR item
     * @return string Matched eBay genre
     */
    private function matchGenre(string $genre): string
    {
        // This would contain logic to match your system genre to eBay genre
        // For now, we'll just return the genre as-is, assuming they match
        return $genre;
    }
    
    /**
     * Get the eBay category ID for the item
     *
     * @return int The category ID
     */
    public function getCategoryId(): int
    {
        list($firstPart, $secondPart) = $this->getCategorySearchParts();
        $categoryKey = "{$firstPart}-{$secondPart}";
        
        $this->logger->debug("Searching for eBay category with key: {$categoryKey}");
        
        // Default to Others-Others if not found
        $categoryId = self::CATEGORY_MAPPING['Others-Others'];
        
        if (isset(self::CATEGORY_MAPPING[$categoryKey])) {
            $categoryId = self::CATEGORY_MAPPING[$categoryKey];
            $this->logger->debug("Found eBay category ID: {$categoryId} for key: {$categoryKey}");
        } else {
            $this->logger->debug("No specific eBay category found for {$categoryKey}, using default: {$categoryId}");
        }
        
        return $categoryId;
    }
    
    /**
     * Get the eBay store category ID for the item
     *
     * @return string|null The store category ID or null if not mapped
     */
    public function getStoreCategoryId(): ?string
    {
        list($firstPart, $secondPart) = $this->getCategorySearchParts();
        
        // Map genre to store category
        if ($firstPart == 'CDs' || $firstPart == 'Vinyl') {
            if (isset(self::GENRE_TO_STORE_CATEGORY[$secondPart])) {
                $secondPart = self::GENRE_TO_STORE_CATEGORY[$secondPart];
            }
        }
        
        // Special cases based on group_id
        if ($firstPart == 'Others') {
            $secondPart = 'Others';
        }
        
        if ($firstPart == 'Clothes') {
            $secondPart = 'Merchandise';
        }
        
        $storeKey = "{$firstPart}-{$secondPart}";
        $this->logger->debug("Searching for store category with key: {$storeKey}");
        
        // Default to Others-Others
        $storeCategoryId = self::STORE_CATEGORY_MAPPING['Others-Others'];
        
        if (isset(self::STORE_CATEGORY_MAPPING[$storeKey])) {
            $storeCategoryId = self::STORE_CATEGORY_MAPPING[$storeKey];
            $this->logger->debug("Found store category ID: {$storeCategoryId} for key: {$storeKey}");
        }
        
        // Special case for SCR and PM items
        $itemId = $this->scrItem->getId();
        if (strpos($itemId, 'SCR') === 0 || strpos($itemId, 'PM') === 0) {
            $storeCategoryId = self::STORE_CATEGORY_MAPPING['SCR-Releases'];
            $this->logger->debug("Item ID {$itemId} is SCR/PM release, using special store category: {$storeCategoryId}");
        }
        
        return (string)$storeCategoryId;
    }
}