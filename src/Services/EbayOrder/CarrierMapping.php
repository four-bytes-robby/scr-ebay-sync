<?php
declare(strict_types=1);

namespace Four\ScrEbaySync\Services\EbayOrder;

/**
 * eBay Carrier Mapping Constants and Utilities
 * 
 * Maps SCR shipper names to eBay Fulfillment API carrier codes
 * and provides tracking number pattern detection.
 */
class CarrierMapping
{
    /**
     * eBay Fulfillment API carrier codes
     * 
     * @see https://developer.ebay.com/api-docs/sell/fulfillment/types/sel:ShippingCarrierEnum
     */
    public const EBAY_CARRIERS = [
        'DEUTSCHE_POST' => 'DEUTSCHE_POST',
        'DHL' => 'DHL',
        'HERMES' => 'HERMES', 
        'UPS' => 'UPS',
        'FEDEX' => 'FEDEX',
        'TNT' => 'TNT',
        'OTHER' => 'OTHER'
    ];
    
    /**
     * SCR Shipper to eBay Carrier mapping
     */
    public const SHIPPER_MAPPING = [
        // Deutsche Post variants
        'deutsche post' => self::EBAY_CARRIERS['DEUTSCHE_POST'],
        'deutschepost' => self::EBAY_CARRIERS['DEUTSCHE_POST'],
        'dp' => self::EBAY_CARRIERS['DEUTSCHE_POST'],
        'post' => self::EBAY_CARRIERS['DEUTSCHE_POST'],
        
        // DHL variants
        'dhl' => self::EBAY_CARRIERS['DHL'],
        'dhl express' => self::EBAY_CARRIERS['DHL'],
        'dhl paket' => self::EBAY_CARRIERS['DHL'],
        'dhl germany' => self::EBAY_CARRIERS['DHL'],
        
        // Hermes variants (including Evri rebrand)
        'hermes' => self::EBAY_CARRIERS['HERMES'],
        'hermes germany' => self::EBAY_CARRIERS['HERMES'],
        'evri' => self::EBAY_CARRIERS['HERMES'],
        
        // Spring GDS → OTHER (eBay doesn't have specific Spring GDS support)
        'spring gds' => self::EBAY_CARRIERS['OTHER'],
        'springgds' => self::EBAY_CARRIERS['OTHER'],
        'spring' => self::EBAY_CARRIERS['OTHER'],
        'gds' => self::EBAY_CARRIERS['OTHER'],
        
        // UPS variants
        'ups' => self::EBAY_CARRIERS['UPS'],
        'united parcel service' => self::EBAY_CARRIERS['UPS'],
        
        // FedEx variants
        'fedex' => self::EBAY_CARRIERS['FEDEX'],
        'fed ex' => self::EBAY_CARRIERS['FEDEX'],
        'federal express' => self::EBAY_CARRIERS['FEDEX'],
        
        // TNT variants
        'tnt' => self::EBAY_CARRIERS['TNT'],
        'tnt express' => self::EBAY_CARRIERS['TNT'],
        
        // Other common carriers → OTHER
        'gls' => self::EBAY_CARRIERS['OTHER'],
        'general logistics systems' => self::EBAY_CARRIERS['OTHER'],
        'dpd' => self::EBAY_CARRIERS['OTHER'],
        'dynamic parcel distribution' => self::EBAY_CARRIERS['OTHER'],
        'go!' => self::EBAY_CARRIERS['OTHER'],
        'go logistics' => self::EBAY_CARRIERS['OTHER']
    ];
    
    /**
     * Tracking number patterns for carrier detection
     */
    public const TRACKING_PATTERNS = [
        'DHL' => [
            '/^\d{10}$/',                    // 10 digits
            '/^[A-Z]{2}\d{9}[A-Z]{2}$/',     // International format
            '/^\d{11}$/',                    // 11 digits
            '/^[A-Z0-9]{10}$/'               // 10 alphanumeric
        ],
        'DEUTSCHE_POST' => [
            '/^\d{13}$/',                    // 13 digits
            '/^[A-Z]{2}\d{9}DE$/',           // International DE format
            '/^RR\d{9}DE$/',                 // Registered mail
            '/^CP\d{9}DE$/'                  // CP format
        ],
        'HERMES' => [
            '/^\d{14}$/',                    // 14 digits
            '/^H\d{13}$/',                   // H prefix + 13 digits
            '/^\d{8}[A-Z]{3}\d{3}$/'         // Mixed format
        ],
        'UPS' => [
            '/^1Z[A-Z0-9]{16}$/',            // Standard UPS format
            '/^1Z\w{6}\d{10}$/',             // Alternative format
            '/^\d{18}$/'                     // 18 digits
        ],
        'FEDEX' => [
            '/^\d{12}$/',                    // 12 digits
            '/^\d{14}$/',                    // 14 digits
            '/^\d{15}$/',                    // 15 digits
            '/^\d{20}$/'                     // 20 digits
        ]
    ];
    
    /**
     * Map SCR shipper name to eBay carrier code
     * 
     * @param string|null $scrShipper The shipper from SCR invoice
     * @return string eBay carrier code
     */
    public static function mapShipperToCarrier(?string $scrShipper): string
    {
        if (empty($scrShipper)) {
            return self::EBAY_CARRIERS['OTHER'];
        }
        
        $normalized = strtolower(trim($scrShipper));
        
        // Direct mapping check
        if (isset(self::SHIPPER_MAPPING[$normalized])) {
            return self::SHIPPER_MAPPING[$normalized];
        }
        
        // Partial match check for complex shipper names
        foreach (self::SHIPPER_MAPPING as $pattern => $carrier) {
            if (strpos($normalized, $pattern) !== false) {
                return $carrier;
            }
        }
        
        return self::EBAY_CARRIERS['OTHER'];
    }
    
    /**
     * Detect carrier from tracking number patterns
     * 
     * @param string $trackingNumber The tracking number
     * @return string eBay carrier code
     */
    public static function detectCarrierFromTracking(string $trackingNumber): string
    {
        $cleanTracking = strtoupper(trim($trackingNumber));
        
        foreach (self::TRACKING_PATTERNS as $carrier => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $cleanTracking)) {
                    return self::EBAY_CARRIERS[$carrier];
                }
            }
        }
        
        return self::EBAY_CARRIERS['OTHER'];
    }
    
    /**
     * Get all supported eBay carriers
     * 
     * @return array<string, string> Array of carrier codes
     */
    public static function getSupportedCarriers(): array
    {
        return self::EBAY_CARRIERS;
    }
    
    /**
     * Get mapping statistics for debugging
     * 
     * @return array<string, mixed> Mapping statistics
     */
    public static function getMappingStats(): array
    {
        $shipperCount = count(self::SHIPPER_MAPPING);
        $carrierCount = count(self::EBAY_CARRIERS);
        $patternCount = array_sum(array_map('count', self::TRACKING_PATTERNS));
        
        return [
            'shipper_mappings' => $shipperCount,
            'ebay_carriers' => $carrierCount,
            'tracking_patterns' => $patternCount,
            'supported_carriers' => array_keys(self::EBAY_CARRIERS)
        ];
    }
}
