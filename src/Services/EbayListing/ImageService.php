<?php
// src/Services/EbayListing/ImageService.php
namespace Four\ScrEbaySync\Services\EbayListing;

use DateTime;
use Four\ScrEbaySync\Entity\ScrItem;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

/**
 * Service for managing item images for eBay
 */
class ImageService
{
    private ScrItem $scrItem;
    private ?Logger $logger;
    private static array $pictureUrls = [];

    /**
     * @param ScrItem $scrItem The SCR item entity
     * @param Logger|null $logger Optional logger
     */
    public function __construct(ScrItem $scrItem, ?Logger $logger = null)
    {
        $this->scrItem = $scrItem;
        $this->logger = $logger ?? new Logger('image_service');
    }

    /**
     * Get the image URLs for the item
     *
     * @return array Array of image URLs
     */
    public function getImageUrls(): array
    {
        $mainUrl = $this->getReferencePictureUrl();
        if (empty($mainUrl)) {
            $this->logger->error("No main image found for item {$this->scrItem->getId()}!");
            return [];
        }
        return [$mainUrl];
    }

    /**
     * Get the reference picture URL for eBay listing
     *
     * @return string The reference picture URL or empty string if not found
     */
    public function getReferencePictureUrl(): string
    {
        $pictureUrl = $this->getPictureUrl();
        if (empty($pictureUrl)) {
            return '';
        }

        return "https://scrmetal.de/shopimg.php?id={$this->scrItem->getId()}&format=webp&timestamp=" .
               (new DateTime())->format("d-m-Y") . "&for=ebay";
    }

    /**
     * Get the main image URL for the item
     *
     * @return string|null The image URL or null if not found
     */
    public function getMainImageUrl(): ?string
    {
        $url = $this->getReferencePictureUrl();
        return !empty($url) ? $url : null;
    }

    /**
     * Get additional image URLs for the item
     *
     * @return array Array of additional image URLs
     */
    public function getAdditionalImageUrls(): array
    {
        // Always return empty array as we only use one main image
        return [];
    }

    /**
     * Get the embedded picture URL for description
     *
     * @param string $size Size of the image (default: 400x400)
     * @return string The image URL for embedding in description
     */
    public function getEmbeddPictureUrl(string $size = '400x400'): string
    {
        $pictureUrl = $this->getPictureUrl();
        if (empty($pictureUrl)) {
            return '';
        }

        $pictureUrl = str_replace('/media/', '/thumbnail/', $pictureUrl);
        $ext = pathinfo($pictureUrl, PATHINFO_EXTENSION);

        return substr($pictureUrl, 0, -strlen($ext) - 1) . "_{$size}.{$ext}";
    }

    /**
     * Get the picture URL from the shop
     *
     * @return string The picture URL or empty string if not found
     */
    private function getPictureUrl(): string
    {
        $itemId = $this->scrItem->getId();

        if (isset(self::$pictureUrls[$itemId])) {
            return self::$pictureUrls[$itemId];
        }

        $pictureUrl = '';
        $client = new Client();
        $htmlContent = '';
        $reTries = 3;
        $shopwareId = $this->scrItem->getShopwareId();
        $productUrl = "https://supremechaos.com/detail/{$shopwareId}";

        while (empty($htmlContent) && $reTries > 0) {
            try {
                $response = $client->get($productUrl);
                $htmlContent = $response->getBody()->getContents();
            } catch (GuzzleException $e) {
                $this->logger->warning("Failed to get shop page: {$e->getMessage()}");
                $reTries--;
            }

            if ($reTries == 0) {
                $this->logger->error("Error getting shop page: {$productUrl}");
                self::$pictureUrls[$itemId] = '';
                return '';
            }
        }

        if (preg_match('/og:image"\\s+content="[^"]+\\/media\\/([^"]+)"/', $htmlContent, $matches)) {
            $name = $matches[1];
            // eBay kann mit eckigen Klammern nicht umgehen
            $name = str_replace(['[', ']'], ['%5B', '%5D'], $name);
            $pictureUrl = "https://supremechaos.com/media/{$name}";
        }

        self::$pictureUrls[$itemId] = $pictureUrl;
        return $pictureUrl;
    }

    /**
     * Check if an item has at least one image
     *
     * @return bool Whether the item has at least one image
     */
    public function hasImages(): bool
    {
        return !empty($this->getReferencePictureUrl());
    }

    /**
     * Get the number of images for this item
     *
     * @return int Number of images
     */
    public function getImageCount(): int
    {
        return $this->hasImages() ? 1 : 0;
    }

    /**
     * Check if image exists
     *
     * @param string $url
     * @return bool
     */
    private function checkIfImageExists(string $url): bool
    {
        $client = new Client();

        try {
            $client->head($url);
            return true;
        } catch (GuzzleException) {
            return false;
        }
    }
}