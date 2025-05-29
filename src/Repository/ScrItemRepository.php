<?php declare(strict_types=1);

namespace Four\ScrEbaySync\Repository;

use DateTime;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Four\ScrEbaySync\Entity\EbayItem;
use Four\ScrEbaySync\Entity\ScrItem;

class ScrItemRepository extends EntityRepository
{

    /**
     * Enhanced base query builder that uses Entity-level OneToOne relationships
     * @return QueryBuilder
     */
    private function createBaseJoinedQueryBuilder(): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i');
        $qb->leftJoin('i.ebayItem', 'e')
           ->addSelect('e'); // Eager load the eBay relationship
        return $qb;
    }

    /**
     * Query builder for items that NEED eBay item data loaded
     * Guarantees that ebayItem is always available without additional queries
     * 
     * @return QueryBuilder
     */
    private function createEagerEbayQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.ebayItem', 'e')
            ->addSelect('e'); // Forces eager loading
    }

    /**
     * Online items - uses eager loading for eBay items
     * UPDATED: Only items that are actually ON eBay (quantity >= 0)
     *
     * @return QueryBuilder
     */
    private function createEbayOnlineItemsQueryBuilder(): QueryBuilder
    {
        return $this->createEagerEbayQueryBuilder()
            ->andWhere('e IS NOT NULL')
            ->andWhere('e.quantity >= 0');  // Only items actually ON eBay
    }

    /**
     * Gets items available for ebay - with eBay items eager loaded
     * This uses the OneToOne relationship for optimal performance
     *
     * @return QueryBuilder
     */
    private function createEbayItemAvailableQueryBuilder(): QueryBuilder
    {
        $preorderDate = new DateTime('+3 days');
        $date180daysAgo = new DateTime('-180 days');
        $date90daysAgo = new DateTime('-90 days');

        $qb = $this->createEagerEbayQueryBuilder();
        $qb->where('i.id LIKE :scr OR i.id LIKE :pm OR i.id LIKE :em OR i.id LIKE :ckr')
            ->andWhere('i.ebay = 1')
            ->andWhere('i.price > 0')
            ->andWhere('i.quantity > 0')
            ->andWhere('(i.releasedate <= :preorderDate OR i.releasedate IS NULL)')
            ->andWhere('i.available_from <= :now')
            ->andWhere('(i.available_until IS NULL OR i.available_until >= :now)')
            ->andWhere('(i.updated > :date90daysAgo OR EXISTS (
                SELECT ip.id FROM Four\ScrEbaySync\Entity\ScrInvoicePos ip
                JOIN Four\ScrEbaySync\Entity\ScrInvoice inv WITH ip.invoice_id = inv.id
                WHERE ip.item_id = i.id AND inv.paydat > :date180daysAgo
            ))')
            ->setParameter('scr', 'SCR%')
            ->setParameter('pm', 'PM%')
            ->setParameter('em', 'EM%')
            ->setParameter('ckr', 'CKR%')
            ->setParameter('preorderDate', $preorderDate)
            ->setParameter('now', new DateTime())
            ->setParameter('date90daysAgo', $date90daysAgo)
            ->setParameter('date180daysAgo', $date180daysAgo);

        return $qb;
    }

    /**
     * Find items eligible for listing on eBay (not currently listed)
     * UPDATED: Items are not listed if ebayItem doesn't exist OR quantity < 0
     * @param int $limit Maximum number of items to return (default 20)
     * @return scrItem[]
     */
    public function findNewItems(int $limit = 20): array
    {
        // Only include items that aren't already on eBay
        $qb = $this->createEbayItemAvailableQueryBuilder()
            ->andWhere('e IS NULL OR e.quantity < 0')  // Not listed or marked as not on eBay
            ->setMaxResults($limit);
        return $qb->getQuery()->getResult();
    }

    /**
     * Find items with changed quantities - OPTIMIZED with OneToOne
     * Now uses entity relationships for clean, readable code
     * IMPORTANT: eBay quantities are capped at max 3 pieces
     * LOGIC: quantity < 0 means item is NOT on eBay
     * 
     * @param int $limit
     * @return ScrItem[]
     */
    public function findItemsWithChangedQuantities(int $limit = 50): array
    {
        return $this->createEbayOnlineItemsQueryBuilder()
            ->andWhere('e.quantity != CASE 
                WHEN i.quantity <= 0 THEN -1 
                WHEN i.quantity > 3 THEN 3 
                ELSE i.quantity END')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get array of eligible item IDs (reusable helper method)
     * 
     * @return array Array of item IDs that are eligible for eBay
     */
    private function getEligibleItemIds(): array
    {
        $result = $this->createEbayItemAvailableQueryBuilder()
            ->select('i.id')
            ->getQuery()
            ->getArrayResult();
            
        return array_column($result, 'id');
    }

    /**
     * Find items that should no longer be available on eBay - IMPROVED
     * Uses the existing eligibility logic and inverts it for efficiency
     * 
     * @param int $limit
     * @return ScrItem[]
     */
    public function findUnavailableItems(int $limit = 50): array
    {
        $eligibleIds = $this->getEligibleItemIds();
        
        if (empty($eligibleIds)) {
            // If no eligible items, all online items are unavailable
            return $this->createEbayOnlineItemsQueryBuilder()
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }
        
        // Find online items that are NOT in the eligible list
        return $this->createEbayOnlineItemsQueryBuilder()
            ->andWhere('i.id NOT IN (:eligibleIds)')
            ->setParameter('eligibleIds', $eligibleIds)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items with updated information (name, description, etc.) - ENHANCED
     * Now includes price changes detection and better filtering
     * WICHTIG: Nur Items, die seit dem letzten eBay-Sync geändert wurden
     * 
     * Logik:
     * - i.updated > e.updated: SCR Item wurde seit letztem eBay-Update geändert
     * - Preisunterschiede >= 1 Cent werden immer als Update erkannt
     * 
     * @param int $limit
     * @return ScrItem[]
     */
    public function findUpdatedItems(int $limit = 50): array
    {
        $qb = $this->createEbayOnlineItemsQueryBuilder();
        return $qb->andWhere($qb->expr()->orX(
            'i.updated > e.updated',
            'ABS(i.price - e.price) >= 0.01',
            'MAX(i.quantity, 3) <> e.quantity'))
            ->orderBy('i.updated', 'ASC') // Älteste Änderungen zuerst
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all SCR items with their corresponding eBay items using OneToOne LEFT JOIN
     * This provides a complete overview with both entities loaded
     *
     * @return array Array of ScrItem entities with loaded EbayItem relationships
     */
    public function findAllWithEbayItems(): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.ebayItem', 'e')
            ->addSelect('e')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find SCR items that are NOT listed on eBay yet but are eligible
     *
     * @return array ScrItem entities without eBay listings
     */
    public function findUnlistedEligibleItems(): array
    {
        return $this->createEbayItemAvailableQueryBuilder()
            ->andWhere('e IS NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Complex analysis: Find SCR items with price differences to their eBay listings
     *
     * @param float $threshold Minimum price difference threshold (default 0.01)
     * @return array ScrItem entities where prices differ significantly
     */
    public function findPriceMismatches(float $threshold = 0.01): array
    {
        return $this->createBaseJoinedQueryBuilder()
            ->andWhere('e IS NOT NULL')
            ->andWhere('(i.price - e.price) >= :threshold OR (e.price - i.price) >= :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * Performance-optimized: Batch load SCR items with eBay relationships by IDs
     *
     * @param array $itemIds Array of SCR item IDs
     * @return array Indexed array of ScrItem entities with eBay relationships
     */
    public function findByIdsWithEbayItems(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->leftJoin('i.ebayItem', 'e')
            ->addSelect('e')
            ->where('i.id IN (:itemIds)')
            ->setParameter('itemIds', $itemIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Analytics: Get synchronization status overview
     *
     * @return array Associative array with sync statistics
     */
    public function getSyncStatusOverview(): array
    {
        $total = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $eligible = $this->createEbayItemAvailableQueryBuilder()
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $listed = $this->createEagerEbayQueryBuilder()
            ->select('COUNT(i.id)')
            ->andWhere('e IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $active = $this->createEbayOnlineItemsQueryBuilder()
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_scr_items' => (int)$total,
            'eligible_for_ebay' => (int)$eligible,
            'listed_on_ebay' => (int)$listed,
            'active_on_ebay' => (int)$active,
            'unlisted_eligible' => (int)$eligible - (int)$listed,
            'sync_rate' => $eligible > 0 ? round(($listed / $eligible) * 100, 2) : 0
        ];
    }

    /**
     * CONVENIENCE METHODS for guaranteed eBay item loading
     * ====================================================
     */

    /**
     * Find all SCR items with eBay items GUARANTEED loaded (no lazy loading issues)
     * Perfect for iterating through items and accessing $item->getEbayItem()
     * 
     * @param int|null $limit Optional limit
     * @return ScrItem[] Array with eBay items always loaded
     */
    public function findAllWithEbayItemsLoaded(?int $limit = null): array
    {
        $qb = $this->createEagerEbayQueryBuilder();
        
        if ($limit) {
            $qb->setMaxResults($limit);
        }
        
        return $qb->getQuery()->getResult();
    }

    /**
     * Find SCR items by IDs with eBay items GUARANTEED loaded
     * No N+1 queries, perfect performance
     * 
     * @param array $itemIds
     * @return ScrItem[]
     */
    public function findByIdsWithEbayItemsLoaded(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        return $this->createEagerEbayQueryBuilder()
            ->where('i.id IN (:itemIds)')
            ->setParameter('itemIds', $itemIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find single SCR item with eBay item GUARANTEED loaded
     * 
     * @param string $itemId
     * @return ScrItem|null
     */
    public function findByIdWithEbayItemLoaded(string $itemId): ?ScrItem
    {
        return $this->createEagerEbayQueryBuilder()
            ->where('i.id = :itemId')
            ->setParameter('itemId', $itemId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find items that have eBay listings with GUARANTEED loading
     * Perfect for sync operations where you need both entities
     * 
     * @param int $limit
     * @return ScrItem[]
     */
    public function findItemsWithEbayListings(int $limit = 100): array
    {
        return $this->createEagerEbayQueryBuilder()
            ->where('e IS NOT NULL')
            ->andWhere('e.quantity > 0')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * DEBUGGING: Test if eBay items are properly loaded
     * Returns detailed info about loading behavior
     * 
     * @param int $sampleSize
     * @return array Debug information
     */
    public function debugEbayItemLoading(int $sampleSize = 10): array
    {
        // Test 1: Without explicit loading (should trigger lazy loading)
        $itemsLazy = $this->createQueryBuilder('i')
            ->setMaxResults($sampleSize)
            ->getQuery()
            ->getResult();

        // Test 2: With explicit loading
        $itemsEager = $this->createEagerEbayQueryBuilder()
            ->setMaxResults($sampleSize)
            ->getQuery()
            ->getResult();

        $debug = [
            'sample_size' => $sampleSize,
            'lazy_loaded_items' => count($itemsLazy),
            'eager_loaded_items' => count($itemsEager),
            'ebay_items_found' => [
                'lazy' => 0,
                'eager' => 0
            ]
        ];

        // Count eBay items
        foreach ($itemsLazy as $item) {
            if ($item->getEbayItem()) {
                $debug['ebay_items_found']['lazy']++;
            }
        }

        foreach ($itemsEager as $item) {
            if ($item->getEbayItem()) {
                $debug['ebay_items_found']['eager']++;
            }
        }

        return $debug;
    }

    /**
     * Calculate eBay quantity with 3-piece maximum limit
     * This is the core business rule for eBay quantity synchronization
     * 
     * IMPORTANT: Negative quantity means item is NOT listed on eBay
     * - quantity >= 0: Item is active on eBay (capped at max 3)
     * - quantity < 0: Item is NOT on eBay / ended listing
     * 
     * @param int $scrQuantity The quantity from SCR system
     * @return int The eBay quantity: min($scrQuantity, 3) or negative for unlisted
     */
    public static function calculateEbayQuantity(int $scrQuantity): int
    {
        // If SCR quantity is 0 or negative, item should not be on eBay
        if ($scrQuantity <= 0) {
            return -1; // Mark as not listed on eBay
        }
        
        // Otherwise, cap at eBay maximum of 3 pieces
        return min($scrQuantity, 3);
    }

    /**
     * ========================================================================
     * EBAY UPDATE SPECIALIZED METHODS - Using OneToOne Relationships
     * ========================================================================
     */

    /**
     * Find items with changed quantities - SAFE VERSION
     * Alternative approach that works with all MySQL/Doctrine versions
     * IMPORTANT: eBay quantities are capped at max 3 pieces
     * 
     * @param int $limit
     * @return ScrItem[]
     */
    public function findItemsWithChangedQuantitiesSafe(int $limit = 50): array
    {
        // Get all online items and filter in PHP to avoid MySQL function issues
        $items = $this->createEbayOnlineItemsQueryBuilder()
            ->setMaxResults($limit * 3) // Get more to account for filtering
            ->getQuery()
            ->getResult();
            
        $result = [];
        foreach ($items as $item) {
            $ebayItem = $item->getEbayItem();
            if ($ebayItem && $ebayItem->getQuantity() >= 0) {  // Only check items ON eBay
                $expectedEbayQty = self::calculateEbayQuantity($item->getQuantity());
                if ($ebayItem->getQuantity() !== $expectedEbayQty) {
                    $result[] = $item;
                    if (count($result) >= $limit) {
                        break;
                    }
                }
            }
        }
        
        return $result;
    }

    /**
     * Find oversold items - SAFE VERSION
     * Alternative approach that works with all MySQL/Doctrine versions
     * 
     * @param int $limit
     * @return ScrItem[]
     */
    public function findOversoldItemsSafe(int $limit = 25): array
    {
        // Get all online items and filter in PHP
        $items = $this->createEbayOnlineItemsQueryBuilder()
            ->setMaxResults($limit * 3) // Get more to account for filtering
            ->getQuery()
            ->getResult();
            
        $result = [];
        foreach ($items as $item) {
            $ebayItem = $item->getEbayItem();
            if ($ebayItem) {
                $expectedEbayQty = self::calculateEbayQuantity($item->getQuantity());
                if ($ebayItem->getQuantity() > $expectedEbayQty) {
                    $result[] = $item;
                    if (count($result) >= $limit) {
                        break;
                    }
                }
            }
        }
        
        // Sort by most critical (highest oversold amount)
        usort($result, function($a, $b) {
            $aOverSold = $a->getEbayItem()->getQuantity() - self::calculateEbayQuantity($a->getQuantity());
            $bOverSold = $b->getEbayItem()->getQuantity() - self::calculateEbayQuantity($b->getQuantity());
            return $bOverSold <=> $aOverSold;
        });
        
        return $result;
    }

    /**
     * Find items where eBay quantity is higher than SCR quantity (oversold detection)
     * Critical for preventing overselling situations
     * IMPORTANT: Takes into account 3-piece eBay limit and negative = not listed logic
     * 
     * @param int $limit
     * @return ScrItem[]
     */
    public function findOversoldItems(int $limit = 25): array
    {
        return $this->createEbayOnlineItemsQueryBuilder()
            ->andWhere('e.quantity > CASE 
                WHEN i.quantity <= 0 THEN -1 
                WHEN i.quantity > 3 THEN 3 
                ELSE i.quantity END')
            ->addOrderBy('e.quantity', 'DESC')
            ->addOrderBy('i.quantity', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items that need price updates on eBay
     * Detects significant price differences that require sync
     * 
     * @param float $threshold Minimum price difference (default: 0.50)
     * @param int $limit
     * @return ScrItem[]
     */
    public function findItemsNeedingPriceUpdates(float $threshold = 0.50, int $limit = 50): array
    {
        // Use a simpler approach without complex ORDER BY expressions
        return $this->createEbayOnlineItemsQueryBuilder()
            ->andWhere('(i.price - e.price) >= :threshold OR (e.price - i.price) >= :threshold')
            ->setParameter('threshold', $threshold)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items that were recently updated in SCR but not yet synced to eBay
     * Perfect for incremental synchronization workflows
     * 
     * @param \DateTime|null $since Only items updated since this time (default: 1 hour ago)
     * @param int $limit
     * @return ScrItem[]
     */
    public function findRecentlyUpdatedItems(?\DateTime $since = null, int $limit = 100): array
    {
        if (!$since) {
            $since = new DateTime('-1 hour');
        }

        return $this->createEbayOnlineItemsQueryBuilder()
            ->andWhere('i.updated > :since')
            ->andWhere('i.updated > e.updated')
            ->setParameter('since', $since)
            ->orderBy('i.updated', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items that have been out of stock on eBay for a while and should be ended
     * Helps clean up inactive listings
     * 
     * @param \DateTime|null $outOfStockSince Items out of stock since this time (default: 7 days ago)
     * @param int $limit
     * @return ScrItem[]
     */
    public function findStaleOutOfStockItems(?\DateTime $outOfStockSince = null, int $limit = 50): array
    {
        if (!$outOfStockSince) {
            $outOfStockSince = new DateTime('-7 days');
        }

        return $this->createEagerEbayQueryBuilder()
            ->andWhere('e IS NOT NULL')
            ->andWhere('e.quantity = 0')
            ->andWhere('i.quantity = 0')
            ->andWhere('e.updated < :outOfStockSince')
            ->setParameter('outOfStockSince', $outOfStockSince)
            ->orderBy('e.updated', 'ASC') // Oldest first
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items eligible for eBay repricing based on market conditions
     * Advanced method for dynamic pricing strategies
     * 
     * @param float $maxPriceIncrease Maximum allowed price increase percentage (default: 0.15 = 15%)
     * @param \DateTime|null $lastPriceUpdate Only items not repriced since this time (default: 30 days ago)
     * @param int $limit
     * @return ScrItem[]
     */
    public function findItemsEligibleForRepricing(float $maxPriceIncrease = 0.15, ?\DateTime $lastPriceUpdate = null, int $limit = 20): array
    {
        if (!$lastPriceUpdate) {
            $lastPriceUpdate = new DateTime('-30 days');
        }

        // Simplified query without complex mathematical expressions
        return $this->createEbayOnlineItemsQueryBuilder()
            ->andWhere('e.updated < :lastPriceUpdate')
            ->andWhere('i.price > e.price') // SCR price is higher
            ->andWhere('i.price <= e.price * :maxMultiplier') // Simple multiplication check
            ->setParameter('lastPriceUpdate', $lastPriceUpdate)
            ->setParameter('maxMultiplier', 1 + $maxPriceIncrease)
            ->orderBy('i.price', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Batch update method: Get comprehensive sync report for dashboard
     * Returns all types of items needing updates in one efficient query
     * 
     * @return array Comprehensive sync report
     */
    public function getComprehensiveUpdateReport(): array
    {
        $report = [
            'timestamp' => new DateTime(),
            'summary' => $this->getSyncStatusOverview(),
            'updates_needed' => []
        ];

        // Use simpler count queries to avoid complex expressions
        try {
            // Quantity changes (with 3-piece eBay limit)
            $report['updates_needed']['quantity_changes'] = $this->createEbayOnlineItemsQueryBuilder()
                ->select('COUNT(i.id)')
                ->andWhere('e.quantity != CASE WHEN i.quantity > 3 THEN 3 ELSE i.quantity END')
                ->getQuery()
                ->getSingleScalarResult();
                
            // Price updates (simplified)
            $report['updates_needed']['price_updates'] = $this->createEbayOnlineItemsQueryBuilder()
                ->select('COUNT(i.id)')
                ->andWhere('(i.price - e.price) >= 0.01 OR (e.price - i.price) >= 0.01')
                ->getQuery()
                ->getSingleScalarResult();
                
            // Recent updates
            $report['updates_needed']['recent_updates'] = $this->createEbayOnlineItemsQueryBuilder()
                ->select('COUNT(i.id)')
                ->andWhere('i.updated > :since')
                ->andWhere('i.updated > e.updated')
                ->setParameter('since', new DateTime('-6 hours'))
                ->getQuery()
                ->getSingleScalarResult();
                
            // Oversold items (with 3-piece limit consideration)
            $report['updates_needed']['oversold_items'] = $this->createEbayOnlineItemsQueryBuilder()
                ->select('COUNT(i.id)')
                ->andWhere('e.quantity > CASE WHEN i.quantity > 3 THEN 3 ELSE i.quantity END')
                ->getQuery()
                ->getSingleScalarResult();
                
            // Stale out of stock (simplified)
            $report['updates_needed']['stale_out_of_stock'] = $this->createEagerEbayQueryBuilder()
                ->select('COUNT(i.id)')
                ->andWhere('e IS NOT NULL')
                ->andWhere('e.quantity = 0')
                ->andWhere('i.quantity = 0')
                ->andWhere('e.updated < :staleDate')
                ->setParameter('staleDate', new DateTime('-3 days'))
                ->getQuery()
                ->getSingleScalarResult();
                
            // Unavailable items (now using the centralized helper method)
            $eligibleIds = $this->getEligibleItemIds();
            
            if (empty($eligibleIds)) {
                $report['updates_needed']['unavailable_items'] = $this->createEbayOnlineItemsQueryBuilder()
                    ->select('COUNT(i.id)')
                    ->getQuery()
                    ->getSingleScalarResult();
            } else {
                $report['updates_needed']['unavailable_items'] = $this->createEbayOnlineItemsQueryBuilder()
                    ->select('COUNT(i.id)')
                    ->andWhere('i.id NOT IN (:eligibleIds)')
                    ->setParameter('eligibleIds', $eligibleIds)
                    ->getQuery()
                    ->getSingleScalarResult();
            }
            
        } catch (\Exception $e) {
            // If any query fails, set to 0 and continue
            foreach (['quantity_changes', 'price_updates', 'recent_updates', 'oversold_items', 'stale_out_of_stock', 'unavailable_items'] as $key) {
                if (!isset($report['updates_needed'][$key])) {
                    $report['updates_needed'][$key] = 0;
                }
            }
        }

        // Calculate priority score
        $critical = (int)$report['updates_needed']['oversold_items'];
        $important = (int)($report['updates_needed']['quantity_changes'] + $report['updates_needed']['price_updates']);
        $routine = (int)($report['updates_needed']['recent_updates'] + $report['updates_needed']['stale_out_of_stock']);

        $report['priority_score'] = [
            'critical' => $critical,
            'important' => $important,
            'routine' => $routine,
            'total' => $critical + $important + $routine
        ];

        return $report;
    }
}