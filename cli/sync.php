<?php
// cli/sync.php - eBay Sync Tool using existing Services
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Four\ScrEbaySync\Entity\ScrItem;
use Four\ScrEbaySync\Entity\EbayItem;
use Four\ScrEbaySync\Repository\ScrItemRepository;
use Four\ScrEbaySync\Services\SyncDashboardService;
use Four\ScrEbaySync\Services\SyncService;
use Four\ScrEbaySync\Services\EbayInventoryService;
use Four\ScrEbaySync\Services\EbayOrderService;
use Four\ScrEbaySync\Api\eBay\Auth;
use Four\ScrEbaySync\Api\eBay\Inventory;
use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Get EntityManager from global scope
$entityManager = $GLOBALS['entityManager'];

// Setup logger
$logger = new Logger('sync');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/sync.log', Logger::DEBUG));
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Parse command line arguments
$command = $argv[1] ?? 'help';
$dryRun = in_array('--dry-run', $argv) || in_array('-n', $argv);

// Initialize dashboard service (always available)
$dashboardService = new SyncDashboardService($entityManager, $logger);

// Initialize eBay services if credentials are available and not dry-run
$syncService = null;
$inventoryService = null;
$orderService = null;
$hasEbayCredentials = !empty($_ENV['EBAY_CLIENT_ID']) && !empty($_ENV['EBAY_CLIENT_SECRET']);

if ($hasEbayCredentials && !$dryRun) {
    try {
        // Initialize eBay Auth directly
        $isSandbox = filter_var($_ENV['EBAY_SANDBOX'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $ruName = $_ENV['EBAY_RUNAME'] ?? 'urn:ebay:identity:v1:ebay:applications:user';
        
        $auth = new Auth(
            $_ENV['EBAY_CLIENT_ID'],
            $_ENV['EBAY_CLIENT_SECRET'],
            $ruName,
            $isSandbox,
            $logger
        );
        
        if ($auth->hasAccessToken()) {
            // Try to get valid token (will refresh if needed)
            try {
                $auth->getAccessToken();
                
                $inventoryApi = new Inventory($auth, $logger);
                $fulfillmentApi = new Fulfillment($auth, $logger);
                
                $syncService = new SyncService($inventoryApi, $fulfillmentApi, $entityManager, $logger);
                $inventoryService = new EbayInventoryService($inventoryApi, $entityManager, $logger);
                $orderService = new EbayOrderService($fulfillmentApi, $entityManager, $logger);
                
                $logger->info('eBay services initialized successfully');
            } catch (\Exception $e) {
                echo "🔐 eBay Token Refresh Failed\n";
                echo "===========================\n";
                echo "Stored tokens are expired and cannot be refreshed.\n";
                echo "Re-authorization required.\n\n";
                echo "Run: composer sync auth\n\n";
                $hasEbayCredentials = false;
            }
        } else {
            // No valid tokens - show auth instructions
            echo "🔐 eBay Authentication Required\n";
            echo "==============================\n";
            echo "No eBay tokens found - initial authorization required.\n";
            echo "\nRun: composer sync auth\n\n";
            $hasEbayCredentials = false;
        }
    } catch (\Exception $e) {
        $logger->warning('Failed to initialize eBay services', ['error' => $e->getMessage()]);
        echo "⚠️ eBay service initialization failed: " . $e->getMessage() . "\n";
        echo "Run: composer sync auth\n\n";
        $hasEbayCredentials = false;
    }
}

/** @var ScrItemRepository $repo */
$repo = $entityManager->getRepository(ScrItem::class);

try {
    echo "=== eBay-SCR Sync Tool ===\n";
    echo "Command: {$command}\n";
    
    $mode = $dryRun ? "DRY RUN (Test)" : ($hasEbayCredentials ? "PRODUCTION (Live eBay)" : "DATABASE ONLY");
    echo "Mode: {$mode}\n";
    echo "Timestamp: " . (new DateTime())->format('Y-m-d H:i:s') . "\n\n";
    
    switch ($command) {
        // ===== PRODUCTION SYNC COMMANDS =====
        
        case 'sync-all':
        case 'sync-full':
            echo "🚀 Full eBay Synchronization\n";
            echo "===========================\n";
            
            if ($dryRun) {
                echo "⚠️  DRY RUN MODE - No eBay API calls will be made\n\n";
                // Simulate what would be done
                $items = [
                    'oversold' => count($dashboardService->getItemsForSync('oversold', 100)),
                    'quantities' => count($dashboardService->getItemsForSync('quantities', 50)),
                    'prices' => count($repo->findItemsNeedingPriceUpdates(0.50, 30)),
                    'new' => count($dashboardService->getItemsForSync('new', 20))
                ];
                
                echo "📊 WOULD SYNCHRONIZE:\n";
                echo sprintf("🚨 Oversold items to fix:    %d\n", $items['oversold']);
                echo sprintf("🔄 Quantities to update:     %d\n", $items['quantities']);
                echo sprintf("💰 Prices to update:         %d\n", $items['prices']);
                echo sprintf("🆕 New listings to create:   %d\n", $items['new']);
                echo "\n💡 Run without --dry-run to execute live sync\n";
                break;
            }
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required for full sync!\n";
                echo "Set EBAY_CLIENT_ID and EBAY_CLIENT_SECRET in .env file.\n";
                break;
            }
            
            $results = $syncService->runSync(
                addNewItems: true,
                updateItems: true,
                updateQuantities: true,
                endListings: true,
                importOrders: true,
                updateOrderStatus: true
            );
            
            echo "📊 SYNC RESULTS (LIVE):\n";
            echo sprintf("🆕 New items added:       %d\n", $results['newItems']);
            echo sprintf("🔄 Items updated:         %d\n", $results['updatedItems']);
            echo sprintf("📦 Quantities updated:    %d\n", $results['updatedQuantities']);
            echo sprintf("❌ Listings ended:        %d\n", $results['endedListings']);
            echo sprintf("📥 Orders imported:       %d\n", $results['importedOrders']);
            echo sprintf("🚚 Order status updated:  %d\n", $results['updatedOrderStatus']);
            break;

        case 'sync-quantities':
        case 'quantities':
            $batchSize = (int)($argv[2] ?? 50);
            echo "🔄 Sync Quantities (batch: {$batchSize})\n";
            echo "========================================\n";
            
            if ($dryRun || !$hasEbayCredentials) {
                // Database-only mode or dry-run
                $quantityChanges = $repo->findItemsWithChangedQuantities($batchSize);
                
                if (empty($quantityChanges)) {
                    echo "✅ No quantity changes found!\n";
                    break;
                }
                
                echo sprintf("Found %d items with quantity changes:\n\n", count($quantityChanges));
                
                $updated = 0;
                foreach ($quantityChanges as $item) {
                    $ebayItem = $item->getEbayItem();
                    if ($ebayItem) {
                        $oldQty = $ebayItem->getQuantity();
                        $scrQty = $item->getQuantity();
                        $newQty = ScrItemRepository::calculateEbayQuantity($scrQty);
                        
                        if (!$dryRun) {
                            $ebayItem->setQuantity($newQty);
                            $ebayItem->setUpdated(new DateTime());
                            $entityManager->persist($ebayItem);
                        }
                        
                        echo sprintf("✅ %s: %d → %d (SCR: %d) %s\n", 
                            $item->getId(), 
                            $oldQty, 
                            $newQty,
                            $scrQty,
                            $dryRun ? "(DRY RUN)" : ""
                        );
                        $updated++;
                    }
                }
                
                if (!$dryRun && $updated > 0) {
                    $entityManager->flush();
                }
                
                echo sprintf("\n✅ Updated %d quantities (%s)\n", $updated, $dryRun ? "DRY RUN" : "DATABASE");
            } else {
                // Production mode - use SyncService
                $updated = $syncService->updateQuantities();
                echo sprintf("✅ Updated %d quantities (LIVE eBay API)\n", $updated);
            }
            break;

        case 'sync-new-items':
        case 'create-listings':
            $limit = (int)($argv[2] ?? 20);
            echo "🆕 Create New Listings (limit: {$limit})\n";
            echo "========================================\n";
            
            if ($dryRun || !$hasEbayCredentials) {
                // Database-only mode or dry-run
                $entityManager->clear();
                $newItems = $repo->findNewItems($limit);
                
                if (empty($newItems)) {
                    echo "✅ No new items ready for listing.\n";
                    break;
                }
                
                echo sprintf("Found %d items ready for listing:\n\n", count($newItems));
                
                $created = 0;
                foreach ($newItems as $item) {
                    $existingEbayItem = $entityManager->getRepository(EbayItem::class)
                        ->findOneBy(['item_id' => $item->getId()]);
                    
                    if ($existingEbayItem) {
                        continue;
                    }
                    
                    if (!$dryRun) {
                        $ebayQuantity = ScrItemRepository::calculateEbayQuantity($item->getQuantity());
                        
                        $ebayItem = new EbayItem();
                        $ebayItem->setItemId($item->getId());
                        $ebayItem->setEbayItemId('EB_' . $item->getId() . '_' . time());
                        $ebayItem->setQuantity($ebayQuantity);
                        $ebayItem->setPrice((string)$item->getPrice());
                        $ebayItem->setCreated(new DateTime());
                        $ebayItem->setUpdated(new DateTime());
                        $ebayItem->setDeleted(null);
                        $ebayItem->setScrItem($item);
                        
                        $entityManager->persist($ebayItem);
                    }
                    
                    echo sprintf("✅ %s: €%.2f (Qty: %d) %s\n", 
                        $item->getId(),
                        $item->getPrice(),
                        $item->getQuantity(),
                        $dryRun ? "(DRY RUN)" : ""
                    );
                    $created++;
                }
                
                if (!$dryRun && $created > 0) {
                    $entityManager->flush();
                }
                
                echo sprintf("\n✅ Created %d listings (%s)\n", $created, $dryRun ? "DRY RUN" : "DATABASE");
            } else {
                // Production mode - use SyncService
                $created = $syncService->addNewItems();
                echo sprintf("✅ Created %d listings (LIVE eBay API)\n", $created);
            }
            break;

        case 'sync-prices':
        case 'prices':
            $threshold = (float)($argv[2] ?? 0.50);
            $limit = (int)($argv[3] ?? 30);
            echo "💰 Sync Prices (threshold: €{$threshold}, limit: {$limit})\n";
            echo "=======================================================\n";
            
            $priceItems = $repo->findItemsNeedingPriceUpdates($threshold, $limit);
            
            if (empty($priceItems)) {
                echo "✅ No price updates needed.\n";
                break;
            }
            
            echo sprintf("Found %d items needing price updates:\n\n", count($priceItems));
            
            $updated = 0;
            foreach ($priceItems as $item) {
                $ebayItem = $item->getEbayItem();
                if ($ebayItem) {
                    $oldPrice = (float)$ebayItem->getPrice();
                    $newPrice = $item->getPrice();
                    
                    if (!$dryRun) {
                        $ebayItem->setPrice((string)$newPrice);
                        $ebayItem->setUpdated(new DateTime());
                        $entityManager->persist($ebayItem);
                    }
                    
                    echo sprintf("✅ %s: €%.2f → €%.2f %s\n", 
                        $item->getId(),
                        $oldPrice,
                        $newPrice,
                        $dryRun ? "(DRY RUN)" : ""
                    );
                    $updated++;
                }
            }
            
            if (!$dryRun && $updated > 0) {
                $entityManager->flush();
            }
            
            echo sprintf("\n✅ Updated %d prices (%s)\n", $updated, $dryRun ? "DRY RUN" : "DATABASE");
            break;

        case 'fix-oversold':
        case 'emergency-fix':
            $limit = (int)($argv[2] ?? 100);
            echo "🚨 Fix Oversold Items (limit: {$limit})\n";
            echo "=======================================\n";
            
            try {
                $oversoldItems = $repo->findOversoldItems($limit);
            } catch (\Exception $e) {
                echo "⚠️  Using safe query fallback...\n";
                $oversoldItems = $repo->findOversoldItemsSafe($limit);
            }
            
            if (empty($oversoldItems)) {
                echo "✅ No oversold items found!\n";
                break;
            }
            
            echo sprintf("Found %d oversold items:\n\n", count($oversoldItems));
            
            $fixed = 0;
            foreach ($oversoldItems as $item) {
                $ebayItem = $item->getEbayItem();
                if ($ebayItem) {
                    $oldQty = $ebayItem->getQuantity();
                    $scrQty = $item->getQuantity();
                    $newQty = ScrItemRepository::calculateEbayQuantity($scrQty);
                    $oversold = $oldQty - $newQty;
                    
                    if (!$dryRun) {
                        $ebayItem->setQuantity($newQty);
                        $ebayItem->setUpdated(new DateTime());
                        $entityManager->persist($ebayItem);
                    }
                    
                    echo sprintf("🚨 %s: %d → %d (fixed oversold: %d) %s\n", 
                        $item->getId(),
                        $oldQty,
                        $newQty,
                        $oversold,
                        $dryRun ? "(DRY RUN)" : ""
                    );
                    $fixed++;
                }
            }
            
            if (!$dryRun && $fixed > 0) {
                $entityManager->flush();
            }
            
            echo sprintf("\n✅ Fixed %d oversold items (%s)\n", $fixed, $dryRun ? "DRY RUN" : "DATABASE");
            break;

        case 'pull-inventory':
        case 'get-inventory':
            echo "📥 Pull ALL Inventory from eBay\n";
            echo "==============================\n";
            
            if ($dryRun) {
                echo "⚠️  DRY RUN MODE - Would pull ALL inventory from eBay API\n";
                echo "This would sync all eBay inventory items to the database.\n";
                break;
            }
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required for inventory pull!\n";
                break;
            }
            
            $limit = (int)($argv[2] ?? 0); // 0 = no limit
            echo sprintf("Pulling %s inventory items from eBay...\n\n", $limit > 0 ? $limit : "ALL");
            
            $results = $inventoryService->pullAllInventory($limit);
            
            echo "📊 INVENTORY PULL RESULTS:\n";
            echo sprintf("📦 Items processed:       %d\n", $results['processed']);
            echo sprintf("🔄 Items updated:         %d\n", $results['updated']);
            echo sprintf("🆕 Items created:         %d\n", $results['created']);
            echo sprintf("❌ Errors:                %d\n", $results['errors']);
            
            if (!empty($results['changes']) && count($results['changes']) <= 20) {
                echo "\n📋 CHANGES DETECTED:\n";
                foreach ($results['changes'] as $change) {
                    $quantityChange = $change['changes']['quantity'];
                    $priceChange = $change['changes']['price'];
                    
                    echo sprintf("  • %s (%s): Qty %d→%d, Price €%.2f→€%.2f\n",
                        $change['sku'],
                        $change['action'],
                        $quantityChange['old'],
                        $quantityChange['new'],
                        $priceChange['old'],
                        $priceChange['new']
                    );
                }
            } elseif (count($results['changes']) > 20) {
                echo "\n📋 " . count($results['changes']) . " changes detected (showing first 5):\n";
                for ($i = 0; $i < 5; $i++) {
                    $change = $results['changes'][$i];
                    $quantityChange = $change['changes']['quantity'];
                    $priceChange = $change['changes']['price'];
                    
                    echo sprintf("  • %s (%s): Qty %d→%d, Price €%.2f→€%.2f\n",
                        $change['sku'],
                        $change['action'],
                        $quantityChange['old'],
                        $quantityChange['new'],
                        $priceChange['old'],
                        $priceChange['new']
                    );
                }
                echo "  ... and " . (count($results['changes']) - 5) . " more\n";
            }
            
            if (!empty($results['error_items']) && count($results['error_items']) <= 10) {
                echo "\n❌ ERRORS:\n";
                foreach ($results['error_items'] as $error) {
                    echo sprintf("  • %s: %s\n", $error['sku'], $error['error']);
                }
            } elseif (count($results['error_items']) > 10) {
                echo "\n❌ " . count($results['error_items']) . " errors occurred (showing first 5):\n";
                for ($i = 0; $i < 5; $i++) {
                    $error = $results['error_items'][$i];
                    echo sprintf("  • %s: %s\n", $error['sku'], $error['error']);
                }
                echo "  ... and " . (count($results['error_items']) - 5) . " more errors\n";
            }
            break;

        case 'migrate-listings':
        case 'bulk-migrate':
            echo "🔄 Migrate Old Listings to Inventory Format\n";
            echo "==========================================\n";
            
            if ($dryRun) {
                echo "⚠️  DRY RUN MODE - Would migrate old format listings\n";
                echo "This would find and migrate legacy listings to inventory format.\n";
                break;
            }
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required for listing migration!\n";
                break;
            }
            
            // Get listing IDs from command line or find them automatically
            $listingIds = array_slice($argv, 2); // Get all arguments after command
            
            if (empty($listingIds)) {
                echo "🔍 Finding old format listings that need migration...\n";
                $listingIds = $inventoryService->findListingsToMigrate();
                
                if (empty($listingIds)) {
                    echo "✅ No old format listings found that need migration!\n";
                    break;
                }
                
                echo sprintf("Found %d listings that need migration:\n", count($listingIds));
                foreach (array_slice($listingIds, 0, 10) as $i => $listingId) {
                    echo sprintf("  %d. %s\n", $i + 1, $listingId);
                }
                if (count($listingIds) > 10) {
                    echo sprintf("  ... and %d more\n", count($listingIds) - 10);
                }
                echo "\n";
            } else {
                echo sprintf("Migrating %d specified listings...\n\n", count($listingIds));
            }
            
            // Process in batches (eBay API limit)
            $batchSize = 5; // eBay recommended batch size
            $totalResults = [
                'total' => count($listingIds),
                'migrated' => 0,
                'failed' => 0,
                'errors' => []
            ];
            
            $batches = array_chunk($listingIds, $batchSize);
            
            foreach ($batches as $batchIndex => $batch) {
                echo sprintf("Processing batch %d/%d (%d listings)...\n", 
                    $batchIndex + 1, 
                    count($batches), 
                    count($batch)
                );
                
                $batchResults = $inventoryService->bulkMigrateListings($batch);
                
                $totalResults['migrated'] += $batchResults['migrated'];
                $totalResults['failed'] += $batchResults['failed'];
                $totalResults['errors'] = array_merge($totalResults['errors'], $batchResults['errors']);
                
                echo sprintf("  ✅ Migrated: %d, ❌ Failed: %d\n", 
                    $batchResults['migrated'], 
                    $batchResults['failed']
                );
                
                // Small delay between batches to avoid rate limiting
                if ($batchIndex < count($batches) - 1) {
                    sleep(1);
                }
            }
            
            echo "\n📊 MIGRATION RESULTS:\n";
            echo sprintf("📦 Total listings:        %d\n", $totalResults['total']);
            echo sprintf("✅ Successfully migrated: %d\n", $totalResults['migrated']);
            echo sprintf("❌ Failed migrations:     %d\n", $totalResults['failed']);
            
            if (!empty($totalResults['errors']) && count($totalResults['errors']) <= 10) {
                echo "\n❌ ERRORS:\n";
                foreach ($totalResults['errors'] as $error) {
                    echo sprintf("  • %s: %s\n", $error['listingId'], $error['error']);
                }
            } elseif (count($totalResults['errors']) > 10) {
                echo "\n❌ " . count($totalResults['errors']) . " errors occurred (showing first 5):\n";
                for ($i = 0; $i < 5; $i++) {
                    $error = $totalResults['errors'][$i];
                    echo sprintf("  • %s: %s\n", $error['listingId'], $error['error']);
                }
                echo "  ... and " . (count($totalResults['errors']) - 5) . " more errors\n";
            }
            break;

        case 'sync-orders':
        case 'import-orders':
            echo "📦 eBay Order Synchronization\n";
            echo "============================\n";
            
            if ($dryRun) {
                echo "⚠️  DRY RUN MODE - Would import/update orders via eBay API\n";
                $days = (int)($argv[2] ?? 10);
                echo "Would import orders from last {$days} days\n";
                break;
            }
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required for order sync!\n";
                break;
            }
            
            $days = (int)($argv[2] ?? 10);

            $result = $orderService->syncOrders($days);

            echo sprintf("📥 Orders imported: %d\n", $result['imported']);
            echo sprintf("🚚 Order status updated: %d\n", $result['updated']);
            break;

        // ===== AUTHENTICATION COMMANDS =====
        
        case 'auth':
        case 'auth-status':
            echo "📊 eBay Authentication Status\n";
            echo "============================\n";
            
            if (empty($_ENV['EBAY_CLIENT_ID']) || empty($_ENV['EBAY_CLIENT_SECRET'])) {
                echo "❌ eBay credentials not configured!\n";
                echo "Please set EBAY_CLIENT_ID and EBAY_CLIENT_SECRET in .env file.\n";
                break;
            }
            
            $isSandbox = filter_var($_ENV['EBAY_SANDBOX'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
            $ruName = $_ENV['EBAY_RUNAME'] ?? 'urn:ebay:identity:v1:ebay:applications:user';
            
            $auth = new Auth($_ENV['EBAY_CLIENT_ID'], $_ENV['EBAY_CLIENT_SECRET'], $ruName, $isSandbox, $logger);
            
            echo "🔧 Configuration:\n";
            echo "Client ID: " . substr($_ENV['EBAY_CLIENT_ID'], 0, 8) . "...\n";
            echo "RU Name: " . $ruName . "\n";
            echo "Environment: " . ($isSandbox ? 'Sandbox' : 'Production') . "\n\n";
            
            if ($auth->hasAccessToken()) {
                try {
                    $auth->getAccessToken(); // Test refresh
                    echo "✅ eBay API authentication successful\n";
                    echo "Access token is valid and ready for API calls.\n";
                } catch (\Exception $e) {
                    echo "⚠️ Token refresh failed: " . $e->getMessage() . "\n";
                    echo "Re-authorization required.\n\n";
                    echo "🔗 Authorization URL:\n";
                    echo $auth->getAuthorizationUrl() . "\n\n";
                    echo "Run: composer sync auth-exchange [code]\n";
                }
            } else {
                echo "❌ No eBay tokens found - authorization required\n\n";
                echo "🔗 Authorization URL:\n";
                echo $auth->getAuthorizationUrl() . "\n\n";
                echo "1. Open the URL above in your browser\n";
                echo "2. Authorize the application\n";
                echo "3. Copy the authorization code from redirect URL\n";
                echo "4. Run: composer sync auth-exchange [code]\n";
            }
            break;
            
        case 'auth-url':
            echo "🔗 eBay Authorization URL\n";
            echo "========================\n";
            
            if (empty($_ENV['EBAY_CLIENT_ID']) || empty($_ENV['EBAY_CLIENT_SECRET'])) {
                echo "❌ eBay credentials not configured!\n";
                break;
            }
            
            $isSandbox = filter_var($_ENV['EBAY_SANDBOX'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
            $ruName = $_ENV['EBAY_RUNAME'] ?? 'urn:ebay:identity:v1:ebay:applications:user';
            
            $auth = new Auth($_ENV['EBAY_CLIENT_ID'], $_ENV['EBAY_CLIENT_SECRET'], $ruName, $isSandbox, $logger);
            
            if ($auth->hasAccessToken()) {
                echo "✅ Already authenticated - no authorization needed.\n";
            } else {
                echo $auth->getAuthorizationUrl() . "\n\n";
                echo "Open this URL in your browser to authorize the application.\n";
                echo "Then run: composer sync auth-exchange [code]\n";
            }
            break;
            
        case 'auth-exchange':
            $authCode = $argv[2] ?? '';
            
            if (empty($authCode)) {
                echo "❌ No authorization code provided!\n";
                echo "Usage: composer sync auth-exchange [code]\n";
                echo "Get the code by running: composer sync auth-url\n";
                break;
            }
            
            echo "🔄 Exchanging Authorization Code\n";
            echo "===============================\n";
            
            if (empty($_ENV['EBAY_CLIENT_ID']) || empty($_ENV['EBAY_CLIENT_SECRET'])) {
                echo "❌ eBay credentials not configured!\n";
                break;
            }
            
            $isSandbox = filter_var($_ENV['EBAY_SANDBOX'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
            $ruName = $_ENV['EBAY_RUNAME'] ?? 'urn:ebay:identity:v1:ebay:applications:user';
            
            $auth = new Auth($_ENV['EBAY_CLIENT_ID'], $_ENV['EBAY_CLIENT_SECRET'], $ruName, $isSandbox, $logger);
            
            echo "Code: " . substr($authCode, 0, 20) . "...\n\n";
            
            $success = $auth->exchangeCodeForTokens($authCode);
            
            if ($success) {
                echo "✅ SUCCESS: Authorization code exchanged successfully!\n";
                echo "🎉 eBay API authentication is now configured.\n";
                echo "You can now run sync commands:\n";
                echo "  composer sync status\n";
                echo "  composer sync sync-all\n";
            } else {
                echo "❌ FAILED: Could not exchange authorization code.\n";
                echo "Please check the logs for more details.\n";
                echo "Make sure:\n";
                echo "  - The authorization code is correct and not expired\n";
                echo "  - Your eBay app credentials are correct\n";
                echo "  - Your RU Name matches your eBay app configuration\n";
            }
            break;

        // ===== DASHBOARD AND ANALYSIS COMMANDS =====
        
        case 'status':
        case 'dashboard':
            echo "📊 Sync Status Dashboard\n";
            echo "=======================\n";
            
            $status = $dashboardService->getSyncStatus();
            
            echo "📈 OVERVIEW:\n";
            foreach ($status['overview'] as $key => $value) {
                echo sprintf("  %-20s: %s\n", ucfirst(str_replace('_', ' ', $key)), $value);
            }
            
            echo "\n🎯 PRIORITY ACTIONS:\n";
            foreach ($status['report']['priority_score'] as $level => $count) {
                if ($count > 0) {
                    $emoji = match($level) {
                        'critical' => '🚨',
                        'important' => '⚠️',
                        'routine' => 'ℹ️',
                        'total' => '📊',
                        default => '🔄'
                    };
                    echo sprintf("  %s %-10s: %d items\n", $emoji, ucfirst($level), $count);
                }
            }
            
            echo "\n📋 RECOMMENDATIONS:\n";
            $recommendations = $dashboardService->getPriorityRecommendations();
            if (empty($recommendations)) {
                echo "  ✅ No urgent actions needed\n";
            } else {
                foreach ($recommendations as $rec) {
                    $emoji = match($rec['priority']) {
                        'critical' => '🚨',
                        'important' => '⚠️',
                        'routine' => 'ℹ️',
                        default => '🔄'
                    };
                    echo sprintf("  %s %s\n", $emoji, $rec['description']);
                    echo sprintf("     composer sync %s\n", $rec['action']);
                }
            }
            
            echo "\n🔧 SYSTEM STATUS:\n";
            echo sprintf("  eBay API: %s\n", $hasEbayCredentials ? '✅ Available' : '❌ Not configured');
            echo sprintf("  Current Mode: %s\n", $mode);
            break;

        case 'show-quantities':
        case 'show-oversold':
        case 'show-prices':
        case 'show-new':
        case 'show-recent':
        case 'show-stale':
            $type = str_replace('show-', '', $command);
            $limit = (int)($argv[2] ?? 25);
            
            echo "📋 " . ucfirst($type) . " Items (limit: {$limit})\n";
            echo str_repeat("=", 30) . "\n";
            
            $items = $dashboardService->getItemsForSync($type, $limit);
            $dashboardService->displayItems($type, $items);
            break;

        case 'test':
        case 'test-performance':
            echo "⚡ Performance Testing\n";
            echo "====================\n";
            
            $results = $dashboardService->performanceTest();
            
            foreach ($results as $name => $data) {
                echo sprintf("%-20s: %.3fs (%d results)\n", $name, $data['time'], $data['count']);
            }
            break;

        case 'help':
        case '--help':
        case '-h':
        default:
            echo "📚 Available Commands:\n";
            echo "=====================\n\n";
            
            echo "🔐 AUTHENTICATION:\n";
            echo "  auth                          - Check eBay authentication status\n";
            echo "  auth-url                      - Get eBay authorization URL\n";
            echo "  auth-exchange [code]          - Exchange authorization code for tokens\n\n";
            
            echo "🚀 SYNC COMMANDS (Production eBay API):\n";
            echo "  sync-all                      - Full eBay synchronization\n";
            echo "  sync-quantities [batch]       - Sync quantities (default: 50)\n";
            echo "  sync-new-items [limit]        - Create new listings (default: 20)\n";
            echo "  sync-prices [threshold] [limit] - Update prices (default: 0.50, 30)\n";
            echo "  fix-oversold [limit]          - Emergency fix oversold items\n";
            echo "  pull-inventory [limit]        - Pull ALL eBay inventory to database\n";
            echo "  migrate-listings [ids...]     - Migrate old listings to inventory format\n";
            echo "  sync-orders [days]            - Import/update orders (default: 10 days)\n\n";
            
            echo "📊 ANALYSIS & DASHBOARD:\n";
            echo "  status                        - Show sync dashboard\n";
            echo "  show-quantities [limit]       - Show quantity changes\n";
            echo "  show-oversold [limit]         - Show oversold items\n";
            echo "  show-prices [limit]           - Show price changes\n";
            echo "  show-new [limit]              - Show items ready for listing\n";
            echo "  show-recent [limit]           - Show recently updated items\n";
            echo "  show-stale [limit]            - Show stale items\n";
            echo "  test                          - Performance testing\n\n";
            
            echo "⚙️  MODES & OPTIONS:\n";
            echo "  --dry-run, -n                 - Test mode (safe, no eBay API calls)\n\n";
            
            echo "📋 SYNC MODES:\n";
            echo "  PRODUCTION       - Default: Live eBay API calls\n";
            echo "  DRY RUN          - Test mode with --dry-run flag\n";
            echo "  DATABASE ONLY    - No eBay credentials configured\n\n";
            
            echo "💡 EXAMPLES:\n";
            echo "  composer sync auth                      # Check auth status\n";
            echo "  composer sync auth-url                  # Get authorization URL\n";
            echo "  composer sync auth-exchange [code]      # Exchange auth code\n";
            echo "  composer sync status                    # Check sync status\n";
            echo "  composer sync sync-quantities -- --dry-run  # Test quantity sync\n";
            echo "  composer sync sync-quantities           # Live quantity sync\n";
            echo "  composer sync sync-all                  # Full production sync\n";
            echo "  composer sync pull-inventory            # Pull ALL eBay inventory\n";
            echo "  composer sync migrate-listings         # Auto-find and migrate old listings\n";
            echo "  composer sync migrate-listings 123 456 # Migrate specific listing IDs\n";
            echo "  composer sync fix-oversold -- --dry-run     # Test oversold fix\n\n";
            
            echo "🔄 TYPICAL WORKFLOW:\n";
            echo "  1. composer sync auth                   # Check authentication\n";
            echo "  2. composer sync migrate-listings       # Migrate old listings (one-time)\n";
            echo "  3. composer sync pull-inventory         # Full inventory sync from eBay\n";
            echo "  4. composer sync status                 # Check what needs sync\n";
            echo "  5. composer sync fix-oversold           # Fix critical issues\n";
            echo "  6. composer sync sync-quantities        # Sync quantities\n";
            echo "  7. composer sync sync-new-items         # Create new listings\n";
            break;
    }
    
    echo "\n✨ Operation completed!\n";
    
} catch (\Exception $e) {
    $logger->error('Sync failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
