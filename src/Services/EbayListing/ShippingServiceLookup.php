<?php
// src/Services/EbayListing/ShippingServiceLookup.php
namespace Four\ScrEbaySync\Services\EbayListing;

use Monolog\Logger;

/**
 * Dynamic eBay Shipping Service Code lookup
 */
class ShippingServiceLookup
{
    private static array $validCodes = [
        'EBAY_DE' => [
            'DOMESTIC' => [
                'DE_StandardDispatch',
                'DE_DHL',
                'DE_DHLPackage', 
                'DE_Express',
                'Pickup'
            ],
            'INTERNATIONAL' => [
                'DE_StandardInternational',
                'DE_DHLInternational',
                'DE_ExportInternational',
                'DE_Express'
            ]
        ]
    ];
    
    /**
     * Get valid shipping service code
     */
    public static function getValidCode(string $marketplace, string $type, int $priority = 0): string
    {
        $codes = self::$validCodes[$marketplace][$type] ?? [];
        return $codes[$priority] ?? $codes[0] ?? 'UNKNOWN';
    }
    
    /**
     * Get all valid codes for marketplace
     */
    public static function getAllCodes(string $marketplace): array
    {
        return self::$validCodes[$marketplace] ?? [];
    }
}
