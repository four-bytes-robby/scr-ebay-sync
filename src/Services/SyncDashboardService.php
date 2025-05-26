<?php
declare(strict_types=1);

namespace Four\ScrEbaySync\Services;

use DateTime;
use Four\ScrEbaySync\Entity\ScrItem;
use Four\ScrEbaySync\Repository\ScrItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;

/**
 * Service for dashboard, analysis and non-API sync command functionalities
 */
class SyncDashboardService
{
    private EntityManagerInterface $entityManager;
    private Logger $logger;
    private ScrItemRepository $repo;

    public function __construct(
        EntityManagerInterface $entityManager,
        Logger $logger
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->repo = $entityManager->getRepository(ScrItem::class);
    }

    /**
     * Get comprehensive sync status and analysis
     */
    public function getSyncStatus(): array
    {
        $overview = $this->repo->getSyncStatusOverview();
        $report = $this->repo->getComprehensiveUpdateReport();
        
        return [
            'overview' => $overview,
            'report' => $report,
            'timestamp' => (new DateTime())->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Get items for specific sync operations
     */
    public function getItemsForSync(string $type, int $limit = 25): array
    {
        return match($type) {
            'quantities' => $this->repo->findItemsWithChangedQuantities($limit),
            'oversold' => $this->repo->findOversoldItems($limit),
            'prices' => $this->repo->findItemsNeedingPriceUpdates(0.50, $limit),
            'new' => $this->repo->findNewItems($limit),
            'recent' => $this->repo->findRecentlyUpdatedItems(new DateTime('-6 hours'), $limit),
            'stale' => $this->repo->findStaleOutOfStockItems(new DateTime('-7 days'), $limit),
            default => []
        };
    }

    /**
     * Display detailed item information for analysis
     */
    public function displayItems(string $type, array $items): void
    {
        if (empty($items)) {
            echo "✅ No {$type} items found!\n";
            return;
        }

        foreach ($items as $i => $item) {
            echo sprintf("[%d] %s\n", $i + 1, $item->getId());
            
            switch ($type) {
                case 'quantities':
                    $ebayItem = $item->getEbayItem();
                    $scrQty = $item->getQuantity();
                    $ebayQty = $ebayItem?->getQuantity() ?? 0;
                    $newQty = ScrItemRepository::calculateEbayQuantity($scrQty);
                    
                    echo sprintf("    SCR: %d → eBay: %d (would be: %d)\n", 
                        $scrQty, $ebayQty, $newQty
                    );
                    
                    if ($scrQty > 3) {
                        echo "    ℹ️  Will be limited to max 3 on eBay\n";
                    }
                    if ($newQty < 0) {
                        echo "    ⚠️  Will be marked as NOT LISTED\n";
                    }
                    break;
                    
                case 'oversold':
                    $ebayItem = $item->getEbayItem();
                    $oversold = ($ebayItem?->getQuantity() ?? 0) - $item->getQuantity();
                    echo sprintf("    🚨 SCR=%d, eBay=%d (oversold by %d)\n", 
                        $item->getQuantity(),
                        $ebayItem?->getQuantity() ?? 0,
                        $oversold
                    );
                    break;
                    
                case 'prices':
                    $ebayItem = $item->getEbayItem();
                    $scrPrice = $item->getPrice();
                    $ebayPrice = (float)($ebayItem?->getPrice() ?? 0);
                    $diff = $scrPrice - $ebayPrice;
                    $percentDiff = $ebayPrice > 0 ? (($diff / $ebayPrice) * 100) : 0;
                    
                    echo sprintf("    SCR: €%.2f, eBay: €%.2f (diff: %+.2f, %+.1f%%)\n", 
                        $scrPrice, $ebayPrice, $diff, $percentDiff
                    );
                    break;
                    
                case 'new':
                    echo sprintf("    €%.2f (Qty: %d)\n", 
                        $item->getPrice(), 
                        $item->getQuantity()
                    );
                    echo sprintf("    %s\n", substr($item->getName(), 0, 60));
                    break;
                    
                case 'recent':
                    $ebayItem = $item->getEbayItem();
                    $hoursAgo = (new DateTime())->diff($item->getUpdated())->h;
                    echo sprintf("    Updated %dh ago\n", $hoursAgo);
                    echo sprintf("    SCR: %s, eBay: %s\n", 
                        $item->getUpdated()->format('H:i:s'),
                        $ebayItem?->getUpdated()->format('H:i:s') ?? 'Never'
                    );
                    break;
                    
                case 'stale':
                    $ebayItem = $item->getEbayItem();
                    $daysOld = $ebayItem ? (new DateTime())->diff($ebayItem->getUpdated())->days : 0;
                    echo sprintf("    Out of stock for %d days\n", $daysOld);
                    break;
            }
        }
        
        echo sprintf("\nTotal: %d items\n", count($items));
    }

    /**
     * Perform performance testing on repository methods
     */
    public function performanceTest(): array
    {
        $functions = [
            'Status Overview' => fn() => $this->repo->getSyncStatusOverview(),
            'Quantity Changes' => fn() => $this->repo->findItemsWithChangedQuantities(10),
            'Oversold Items' => fn() => $this->repo->findOversoldItems(10),
            'Price Updates' => fn() => $this->repo->findItemsNeedingPriceUpdates(0.50, 10),
            'New Items' => fn() => $this->repo->findNewItems(10),
            'Recent Updates' => fn() => $this->repo->findRecentlyUpdatedItems(new DateTime('-1 hour'), 10),
        ];
        
        $results = [];
        
        foreach ($functions as $name => $func) {
            $start = microtime(true);
            $result = $func();
            $time = microtime(true) - $start;
            $count = is_array($result) ? count($result) : 1;
            
            $results[$name] = [
                'time' => $time,
                'count' => $count
            ];
        }
        
        return $results;
    }

    /**
     * Generate priority recommendations based on current state
     */
    public function getPriorityRecommendations(): array
    {
        $status = $this->getSyncStatus();
        $recommendations = [];
        
        // Critical: Oversold items
        if (($status['report']['priority_score']['critical'] ?? 0) > 0) {
            $recommendations[] = [
                'priority' => 'critical',
                'action' => 'fix-oversold',
                'description' => 'Fix oversold items immediately',
                'command' => 'fix-oversold'
            ];
        }
        
        // Important: Quantity changes
        if (($status['report']['priority_score']['important'] ?? 0) > 0) {
            $recommendations[] = [
                'priority' => 'important', 
                'action' => 'sync-quantities',
                'description' => 'Sync quantity changes',
                'command' => 'sync-quantities'
            ];
            
            $recommendations[] = [
                'priority' => 'important',
                'action' => 'sync-prices', 
                'description' => 'Update prices',
                'command' => 'sync-prices'
            ];
        }
        
        // Routine: New items
        if (($status['overview']['unlisted_eligible'] ?? 0) > 0) {
            $recommendations[] = [
                'priority' => 'routine',
                'action' => 'sync-new-items',
                'description' => 'Create new eBay listings',
                'command' => 'sync-new-items'
            ];
        }
        
        return $recommendations;
    }
}
