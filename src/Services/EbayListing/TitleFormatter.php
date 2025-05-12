<?php
// src/Services/EbayListing/TitleFormatter.php
namespace Four\ScrEbaySync\Services\EbayListing;

use Four\ScrEbaySync\Entity\ScrItem;

/**
 * Service for formatting item titles for eBay
 */
class TitleFormatter
{
    private ScrItem $scrItem;
    private const MAX_TITLE_LENGTH = 80;
    
    /**
     * @param ScrItem $scrItem The SCR item entity
     */
    public function __construct(ScrItem $scrItem)
    {
        $this->scrItem = $scrItem;
    }
    
    /**
     * Get the full title
     *
     * @return string The full title
     */
    public function getTitle(): string
    {
        return trim($this->scrItem->getName());
    }
    
    /**
     * Get the shortened title that fits eBay's character limit
     *
     * @return string The shortened title
     */
    public function getShortenedTitle(): string
    {
        $title = $this->getTitle();
        
        if (strlen($title) <= self::MAX_TITLE_LENGTH) {
            return $title;
        }
        
        return substr($title, 0, self::MAX_TITLE_LENGTH - 3) . '...';
    }
    
    /**
     * Extract the interpret/artist from the title
     *
     * @return string The interpret/artist
     */
    public function getInterpret(): string
    {
        $title = $this->getTitle();
        
        // Extract interpret using typical pattern "Interpret - Title (Format)"
        if (preg_match('/^(.*?)\s+-\s+/', $title, $matches)) {
            return trim($matches[1]);
        }
        
        // Try other common patterns
        if (preg_match('/^(.*?):/', $title, $matches)) {
            return trim($matches[1]);
        }
        
        // Extract from "by Artist" pattern
        if (preg_match('/by\s+(.*?)(\s+\(|\s*$)/', $title, $matches)) {
            return trim($matches[1]);
        }
        
        // Default to using the first part of the title
        $parts = explode(' ', $title);
        return trim(implode(' ', array_slice($parts, 0, min(3, count($parts)))));
    }
    
    /**
     * Extract the music/book title from the full title
     *
     * @return string The music/book title
     */
    public function getMusiktitel(): string
    {
        $title = $this->getTitle();
        
        // Extract title from "Interpret - Title (Format)" pattern
        if (preg_match('/^.*?\s+-\s+(.*?)(\s+\(|\s*$)/', $title, $matches)) {
            return trim($matches[1]);
        }
        
        // Extract from "Title: Subtitle" pattern
        if (preg_match('/^.*?:\s+(.*?)(\s+\(|\s*$)/', $title, $matches)) {
            return trim($matches[1]);
        }
        
        // Remove format information if present
        if (preg_match('/(.*?)\s+\(.*?\)$/', $title, $matches)) {
            return trim($matches[1]);
        }
        
        // Default to using the rest of the title after the interpret
        $interpret = $this->getInterpret();
        $titleWithoutInterpret = trim(str_replace($interpret, '', $title));
        
        // Remove leading separator if present
        $titleWithoutInterpret = ltrim($titleWithoutInterpret, ':-');
        
        return trim($titleWithoutInterpret);
    }
    
    /**
     * Extract the format from the title
     *
     * @return string The format
     */
    public function getFormat(): string
    {
        $title = $this->getTitle();
        
        // Extract format from common patterns like "(CD)" or "(LP)" or "(Book)"
        if (preg_match('/\((.*?)\)\s*$/', $title, $matches)) {
            return trim($matches[1]);
        }
        
        // Default format based on item type or group
        $groupId = $this->scrItem->getGroupId();
        
        if ($this->isBook()) {
            return 'Book';
        }
        
        if ($groupId == 'DVD' || $groupId == 'BD') {
            return $groupId;
        }
        
        if ($groupId == 'LP' || $groupId == 'SINGLE') {
            return 'Vinyl';
        }
        
        return 'CD';
    }
    
    /**
     * Check if the item is a book
     *
     * @return bool Whether the item is a book
     */
    public function isBook(): bool
    {
        $title = $this->getTitle();
        return strpos($title, 'BOOK)') !== false || $this->scrItem->getGroupId() == 'BOOK';
    }
    
    /**
     * Shorten text by number of words
     *
     * @param string $text The text to shorten
     * @param int $maxWords Maximum number of words
     * @return string The shortened text
     */
    public function shortenByWords(string $text, int $maxWords): string
    {
        $words = preg_split('/\s+/', $text);
        
        if (count($words) <= $maxWords) {
            return $text;
        }
        
        return implode(' ', array_slice($words, 0, $maxWords)) . '...';
    }
}