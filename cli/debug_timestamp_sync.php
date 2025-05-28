<?php
/**
 * Spezifisches Debug Script für Timestamp-Synchronisation zwischen SCR und eBay Items
 * 
 * Analysiert das Problem der sich wiederholenden Updates bei sync-inventory-update
 */

require_once __DIR__ . '/../bootstrap.php';

use Four\ScrEbaySync\Entity\ScrItem;

try {
    echo "==================== TIMESTAMP SYNC DEBUG ANALYSE ====================\n";
    echo "Datum: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Repository laden
    $scrItemRepo = $entityManager->getRepository(ScrItem::class);
    
    // 1. Zeige Items die als "zu updaten" erkannt werden
    echo "1. Items die als Update-Kandidaten erkannt werden:\n";
    echo str_repeat("-", 70) . "\n";
    
    $updateItems = $scrItemRepo->findUpdatedItems(15);
    
    if (empty($updateItems)) {
        echo "✅ Keine Items benötigen Updates - Problem könnte gelöst sein!\n\n";
    } else {
        printf("%-12s %-20s %-20s %-10s %-10s %-15s\n",
            "Item ID", "SCR Updated", "eBay Updated", "Diff (min)", "Grund", "Status"
        );
        echo str_repeat("-", 90) . "\n";
        
        foreach ($updateItems as $item) {
            $ebayItem = $item->getEbayItem();
            if (!$ebayItem) continue;
            
            $scrUpdated = $item->getUpdated();
            $ebayUpdated = $ebayItem->getUpdated();
            $timeDiffMinutes = ($scrUpdated->getTimestamp() - $ebayUpdated->getTimestamp()) / 60;
            $priceDiff = abs($item->getPrice() - (float)$ebayItem->getPrice());
            
            $reason = '';
            if ($scrUpdated > $ebayUpdated) $reason .= 'TIME ';
            if ($priceDiff >= 0.01) $reason .= 'PRICE ';
            
            $status = '';
            if ($timeDiffMinutes > 0 && $timeDiffMinutes < 5) $status = '⚠️RECENT';
            elseif ($timeDiffMinutes > 1440) $status = '🔴OLD';
            else $status = '⚡NORMAL';
            
            printf("%-12s %-20s %-20s %-10.1f %-10s %-15s\n",
                $item->getId(),
                $scrUpdated->format('H:i:s'),
                $ebayUpdated->format('H:i:s'),
                $timeDiffMinutes,
                trim($reason),
                $status
            );
        }
        echo "\n";
    }
    
    // 2. Zeige die letzten eBay-Updates
    echo "2. Letzte eBay-Updates (sollten nach sync-inventory-update aktuell sein):\n";
    echo str_repeat("-", 70) . "\n";
    
    $recentEbayUpdates = $scrItemRepo->createEbayOnlineItemsQueryBuilder()
        ->orderBy('e.updated', 'DESC')
        ->setMaxResults(10)
        ->getQuery()
        ->getResult();
    
    $now = new DateTime();
    
    printf("%-12s %-20s %-15s %-15s\n",
        "Item ID", "eBay Updated", "Age (min)", "Sync Quality"
    );
    echo str_repeat("-", 65) . "\n";
    
    foreach ($recentEbayUpdates as $item) {
        $ebayItem = $item->getEbayItem();
        if (!$ebayItem) continue;
        
        $ebayUpdated = $ebayItem->getUpdated();
        $ageMinutes = ($now->getTimestamp() - $ebayUpdated->getTimestamp()) / 60;
        
        $quality = '';
        if ($ageMinutes < 5) $quality = '🟢FRESH';
        elseif ($ageMinutes < 60) $quality = '🟡RECENT';
        elseif ($ageMinutes < 1440) $quality = '🟠OLD';
        else $quality = '🔴STALE';
        
        printf("%-12s %-20s %-15.1f %-15s\n",
            $item->getId(),
            $ebayUpdated->format('H:i:s'),
            $ageMinutes,
            $quality
        );
    }
    echo "\n";
    
    // 3. Simuliere einen Update-Cycle
    echo "3. Simulation eines Update-Cycles:\n";
    echo str_repeat("-", 50) . "\n";
    
    if (!empty($updateItems)) {
        $testItem = $updateItems[0];
        $ebayItem = $testItem->getEbayItem();
        
        echo "Test mit Item: " . $testItem->getId() . "\n";
        echo "Vor simuliertem Update:\n";
        printf("  SCR Updated:  %s\n", $testItem->getUpdated()->format('Y-m-d H:i:s'));
        printf("  eBay Updated: %s\n", $ebayItem->getUpdated()->format('Y-m-d H:i:s'));
        printf("  Würde Update: %s\n", ($testItem->getUpdated() > $ebayItem->getUpdated()) ? 'JA' : 'NEIN');
        
        // Simuliere erfolgreichen eBay-Update durch Setzen des updated Feldes
        $simulatedUpdateTime = new DateTime();
        echo "\nSimuliere erfolgreichen eBay-Update um " . $simulatedUpdateTime->format('H:i:s') . ":\n";
        
        $originalEbayUpdated = clone $ebayItem->getUpdated();
        $ebayItem->setUpdated($simulatedUpdateTime);
        $entityManager->persist($ebayItem);
        $entityManager->flush();
        
        printf("  eBay Updated: %s (neu)\n", $ebayItem->getUpdated()->format('Y-m-d H:i:s'));
        printf("  Würde Update: %s\n", ($testItem->getUpdated() > $ebayItem->getUpdated()) ? 'JA' : 'NEIN');
        
        // Wiederherstellen des ursprünglichen Zustands
        $ebayItem->setUpdated($originalEbayUpdated);
        $entityManager->persist($ebayItem);
        $entityManager->flush();
        
        echo "  Status: Ursprünglicher Zustand wiederhergestellt\n";
    } else {
        echo "Keine Test-Items verfügbar.\n";
    }
    echo "\n";
    
    // 4. Empfohlene Maßnahmen
    echo "4. Empfohlene Maßnahmen:\n";
    echo str_repeat("-", 50) . "\n";
    
    $stats = $scrItemRepo->getUpdateDetectionStats();
    
    if ($stats['timestamp_updates_needed'] > 5) {
        echo "🔴 KRITISCH: {$stats['timestamp_updates_needed']} Items haben veraltete eBay-Updates\n";
        echo "   → Das ebay_items.updated Feld wird nicht korrekt aktualisiert\n";
        echo "   → Überprüfe updateQuantity() und updateListing() Methoden\n";
        echo "   → Stelle sicher, dass updated nur bei erfolgreichen API-Calls gesetzt wird\n\n";
    } elseif ($stats['timestamp_updates_needed'] > 0) {
        echo "⚠️  WARNUNG: {$stats['timestamp_updates_needed']} Items haben veraltete eBay-Updates\n";
        echo "   → Normale Anzahl, aber beobachte die Entwicklung\n\n";
    } else {
        echo "✅ GUT: Keine Items mit veralteten eBay-Updates\n\n";
    }
    
    if ($stats['avg_sync_age_hours'] > 24) {
        echo "⚠️  WARNUNG: Durchschnittliches Sync-Alter beträgt {$stats['avg_sync_age_hours']} Stunden\n";
        echo "   → Sync läuft möglicherweise nicht regelmäßig genug\n\n";
    } else {
        echo "✅ GUT: Durchschnittliches Sync-Alter beträgt {$stats['avg_sync_age_hours']} Stunden\n\n";
    }
    
    // 5. Konkrete Lösungsschritte
    echo "5. Sofortige Lösungsschritte:\n";
    echo str_repeat("-", 50) . "\n";
    echo "A) Führe einen Test-Sync aus:\n";
    echo "   php cli/sync.php --updateQuantities --limit=5 --verbose\n\n";
    echo "B) Überwache die Logs während dem Sync:\n";
    echo "   tail -f logs/sync.log\n\n";
    echo "C) Prüfe nach dem Sync erneut:\n";
    echo "   php cli/debug_timestamp_sync.php\n\n";
    echo "D) Bei anhaltenden Problemen:\n";
    echo "   - Überprüfe eBay API Response-Handling\n";
    echo "   - Validiere Exception-Handling in updateQuantity()\n";
    echo "   - Stelle sicher, dass DB-Transaktion erst nach erfolgreichem API-Call committed wird\n\n";
    
} catch (Exception $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "==================== TIMESTAMP DEBUG BEENDET ====================\n";
