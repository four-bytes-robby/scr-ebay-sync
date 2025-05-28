<?php
/**
 * Debug Script für eBay Sync Update-Detection Problem
 * 
 * Analysiert warum Items immer wieder als "zu updaten" erkannt werden
 */

require_once __DIR__ . '/../bootstrap.php';

use Four\ScrEbaySync\Entity\ScrItem;

try {
    echo "==================== EBAY SYNC DEBUG ANALYSE ====================\n";
    echo "Datum: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Repository laden
    $scrItemRepo = $entityManager->getRepository(ScrItem::class);
    
    // 1. Update Detection Statistiken
    echo "1. Update Detection Statistiken:\n";
    echo str_repeat("-", 50) . "\n";
    
    $stats = $scrItemRepo->getUpdateDetectionStats();
    foreach ($stats as $key => $value) {
        echo sprintf("%-30s: %s\n", $key, $value);
    }
    echo "\n";
    
    // 2. Detaillierte Analyse von Items die als "zu updaten" erkannt werden
    echo "2. Detaillierte Analyse problematischer Items:\n";
    echo str_repeat("-", 80) . "\n";
    
    $debugData = $scrItemRepo->debugUpdateDetection(10);
    
    if (empty($debugData)) {
        echo "Keine Items gefunden, die als 'zu updaten' erkannt werden.\n";
    } else {
        printf("%-12s %-20s %-20s %-10s %-10s %-6s %-8s %-8s %-20s\n",
            "Item ID", "SCR Updated", "eBay Updated", "SCR €", "eBay €", "Diff", "Time?", "Price?", "Last Sync"
        );
        echo str_repeat("-", 140) . "\n";
        
        foreach ($debugData as $item) {
            printf("%-12s %-20s %-20s %-10.2f %-10.2f %-6.2f %-8s %-8s %-20s\n",
                $item['item_id'],
                $item['scr_updated'],
                $item['ebay_updated'],
                $item['scr_price'],
                $item['ebay_price'],
                $item['price_diff'],
                $item['timestamp_update_needed'] ? 'JA' : 'NEIN',
                $item['price_update_needed'] ? 'JA' : 'NEIN',
                $item['time_since_ebay_update']
            );
        }
    }
    echo "\n";
    
    // 3. Finde Items die tatsächlich Updates benötigen
    echo "3. Items die tatsächlich Updates benötigen:\n";
    echo str_repeat("-", 50) . "\n";
    
    $itemsNeedingUpdates = $scrItemRepo->findUpdatedItems(10);
    echo "Anzahl Items die Updates benötigen: " . count($itemsNeedingUpdates) . "\n";
    
    if (!empty($itemsNeedingUpdates)) {
        echo "\nDetaillierte Auflistung:\n";
        foreach ($itemsNeedingUpdates as $item) {
            $ebayItem = $item->getEbayItem();
            if (!$ebayItem) continue;
            
            $scrUpdated = $item->getUpdated();
            $ebayUpdated = $ebayItem->getUpdated();
            $priceDiff = abs($item->getPrice() - (float)$ebayItem->getPrice());
            
            echo sprintf("Item: %s\n", $item->getId());
            echo sprintf("  SCR Updated:  %s\n", $scrUpdated->format('Y-m-d H:i:s'));
            echo sprintf("  eBay Updated: %s\n", $ebayUpdated->format('Y-m-d H:i:s'));
            echo sprintf("  Zeit-Diff:    %s\n", $scrUpdated > $ebayUpdated ? 'SCR neuer' : 'eBay neuer/gleich');
            echo sprintf("  SCR Preis:    %.2f €\n", $item->getPrice());
            echo sprintf("  eBay Preis:   %.2f €\n", (float)$ebayItem->getPrice());
            echo sprintf("  Preis-Diff:   %.2f €\n", $priceDiff);
            echo sprintf("  Update-Grund: %s\n", 
                ($scrUpdated > $ebayUpdated ? 'Timestamp' : '') . 
                ($priceDiff >= 0.01 ? ' + Preis' : '')
            );
            echo "\n";
        }
    }
    
    // 4. Teste die Sync-Logik mit einem konkreten Beispiel
    echo "4. Test der Sync-Logik mit konkretem Beispiel:\n";
    echo str_repeat("-", 50) . "\n";
    
    if (!empty($itemsNeedingUpdates)) {
        $testItem = $itemsNeedingUpdates[0];
        $ebayItem = $testItem->getEbayItem();
        
        echo "Test-Item: " . $testItem->getId() . "\n";
        echo "Vor dem simulierten Update:\n";
        echo sprintf("  SCR Updated:  %s\n", $testItem->getUpdated()->format('Y-m-d H:i:s'));
        echo sprintf("  eBay Updated: %s\n", $ebayItem->getUpdated()->format('Y-m-d H:i:s'));
        
        // Simuliere ein eBay-Update (setze eBay updated auf jetzt)
        $now = new DateTime();
        $ebayItem->setUpdated($now);
        $entityManager->persist($ebayItem);
        $entityManager->flush();
        
        echo "\nNach simuliertem eBay-Update:\n";
        echo sprintf("  SCR Updated:  %s\n", $testItem->getUpdated()->format('Y-m-d H:i:s'));
        echo sprintf("  eBay Updated: %s\n", $ebayItem->getUpdated()->format('Y-m-d H:i:s'));
        
        // Prüfe ob Item jetzt noch als "zu updaten" erkannt wird
        $stillNeedsUpdate = $testItem->getUpdated() > $ebayItem->getUpdated();
        $priceDiff = abs($testItem->getPrice() - (float)$ebayItem->getPrice());
        $priceNeedsUpdate = $priceDiff >= 0.01;
        
        echo sprintf("Benötigt noch Update? %s\n", 
            ($stillNeedsUpdate || $priceNeedsUpdate) ? 'JA' : 'NEIN'
        );
        echo sprintf("Grund: %s\n", 
            ($stillNeedsUpdate ? 'Timestamp' : '') . 
            ($priceNeedsUpdate ? ' + Preis' : '')
        );
    } else {
        echo "Keine Items zum Testen verfügbar.\n";
    }
    
    // 5. Empfehlungen
    echo "\n5. Empfehlungen zur Problemlösung:\n";
    echo str_repeat("-", 50) . "\n";
    
    if ($stats['timestamp_updates_needed'] > 0) {
        echo "PROBLEM ERKANNT: {$stats['timestamp_updates_needed']} Items haben SCR-Updates neuer als eBay-Updates\n";
        echo "LÖSUNG: Stelle sicher, dass nach erfolgreichem eBay-Update das ebay_items.updated Feld gesetzt wird.\n\n";
    }
    
    if ($stats['price_updates_needed'] > 0) {
        echo "INFO: {$stats['price_updates_needed']} Items haben Preisunterschiede >= 1 Cent\n";
        echo "NORMAL: Diese Items benötigen legitime Preis-Updates.\n\n";
    }
    
    if ($stats['items_never_synced'] > 0) {
        echo "WARNUNG: {$stats['items_never_synced']} Items wurden sehr lange nicht synchronisiert\n";
        echo "EMPFEHLUNG: Überprüfe diese Items manuell.\n\n";
    }
    
    echo "TECHNISCHE LÖSUNG:\n";
    echo "1. In EbayInventoryService.php: Stelle sicher, dass \$ebayItem->setUpdated(new DateTime()) nur bei erfolgreichen API-Calls gesetzt wird\n";
    echo "2. In ScrItemRepository.php: Die findUpdatedItems() Methode sollte '(i.updated > e.updated) OR (ABS(i.price - e.price) >= 0.01)' verwenden\n";
    echo "3. Teste mit dem Debug-Kommando: php cli/debug_sync_issue.php\n\n";
    
} catch (Exception $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "==================== DEBUG ANALYSE BEENDET ====================\n";
