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
    private const int MAX_TITLE_LENGTH = 80;
    
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
        return $this->ShortenByWord($this->getTitle(), self::MAX_TITLE_LENGTH);
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
        $name = $this->getTitle();

        $format = "";
        $detail = "";
        if (preg_match('/\(([^)]+)\)$/', $name, $matches)) {
            $format = mb_convert_case($matches[1], MB_CASE_UPPER);
        }
        if (preg_match('/\[([^]]+)]$/m', $name, $matches)) {
            // Sonderlogik Agrypnie - 16 485
            if ($matches[1] != '485') {
                $detail = mb_convert_case($matches[1], MB_CASE_UPPER) . " ";
            }
        }
        return trim($detail . $format);
    }

    /**
     * @param string $str
     * @param int $maxLen
     * @param string $ellipsis
     * @return string
     */
    public static function shortenByWord(string $str, int $maxLen, string $ellipsis = "…") : string
    {
        if (mb_strlen($str) <= $maxLen) {
            return $str;
        }
        $ellipsisLength = mb_strlen($ellipsis);
        if (preg_match("/^(.{1," . ($maxLen - $ellipsisLength) . "})[\s,;.–:].+$/su", $str, $matches)) {
            $str = mb_trim($matches[1]);
        } else {
            $str = mb_trim(mb_substr($str, 0, $maxLen - $ellipsisLength));
        }
        return $str . $ellipsis;
    }
}