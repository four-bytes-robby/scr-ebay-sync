<?php
// cli/sync.php - eBay Sync Tool (Entschlackt)
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

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

// Initialize eBay services if credentials are available
$syncService = null;
$inventoryService = null;
$orderService = null;
$hasEbayCredentials = !empty($_ENV['EBAY_CLIENT_ID']) && !empty($_ENV['EBAY_CLIENT_SECRET']);

if ($hasEbayCredentials) {
    try {
        // Initialize eBay Auth
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
            try {
                $auth->getAccessToken();
                
                $inventoryApi = new Inventory($auth, $logger);
                $fulfillmentApi = new Fulfillment($auth, $logger);
                
                $syncService = new SyncService($inventoryApi, $fulfillmentApi, $entityManager, $logger);
                $inventoryService = new EbayInventoryService($inventoryApi, $entityManager, $logger);
                $orderService = new EbayOrderService($fulfillmentApi, $entityManager, $logger);
                
                $logger->info('eBay services initialized successfully');
            } catch (\Exception $e) {
                echo "🔐 eBay Token Refresh Failed - Re-authorization required.\n";
                echo "Run: php cli/sync.php auth\n\n";
                $hasEbayCredentials = false;
            }
        } else {
            echo "🔐 eBay Authentication Required\n";
            echo "Run: php cli/sync.php auth\n\n";
            $hasEbayCredentials = false;
        }
    } catch (\Exception $e) {
        $logger->warning('Failed to initialize eBay services', ['error' => $e->getMessage()]);
        echo "⚠️ eBay service initialization failed: " . $e->getMessage() . "\n";
        echo "Run: php cli/sync.php auth\n\n";
        $hasEbayCredentials = false;
    }
}

try {
    echo "=== eBay-SCR Sync Tool ===\n";
    echo "Command: {$command}\n";
    echo "Mode: " . ($hasEbayCredentials ? "PRODUCTION (Live eBay)" : "DATABASE ONLY") . "\n";
    echo "Timestamp: " . (new DateTime())->format('Y-m-d H:i:s') . "\n\n";
    
    switch ($command) {
        
        // ===== MAIN SYNC COMMANDS =====
        
        case 'sync-all':
            echo "🚀 Full eBay Synchronization\n";
            echo "===========================\n";
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required for full sync!\n";
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
            
            echo "📊 SYNC RESULTS:\n";
            echo sprintf("🆕 New items added:       %d\n", $results['newItems']);
            echo sprintf("🔄 Items updated:         %d\n", $results['updatedItems']);
            echo sprintf("📦 Quantities updated:    %d\n", $results['updatedQuantities']);
            echo sprintf("❌ Listings ended:        %d\n", $results['endedListings']);
            echo sprintf("📥 Orders imported:       %d\n", $results['importedOrders']);
            echo sprintf("🚚 Order status updated:  %d\n", $results['updatedOrderStatus']);
            break;

        // ===== ORDER SYNC COMMANDS =====
        
        case 'sync-orders-import':
            echo "📥 Import Orders from eBay\n";
            echo "==========================\n";
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required!\n";
                break;
            }
            
            $days = (int)($argv[2] ?? 10);
            $fromDate = new DateTime();
            $fromDate->modify("-{$days} days");
            
            $imported = $orderService->importOrders($fromDate);
            echo sprintf("📥 Orders imported: %d (from last %d days)\n", $imported, $days);
            break;
            
        case 'sync-orders-update':
            echo "🚚 Update Order Status\n";
            echo "======================\n";
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required!\n";
                break;
            }
            
            $updated = $orderService->updateOrderStatus();
            echo sprintf("🚚 Order status updated: %d\n", $updated);
            break;

        // ===== INVENTORY SYNC COMMANDS =====
        
        case 'sync-inventory-update':
            echo "🔄 Update Inventory (Quantities & Prices)\n";
            echo "==========================================\n";
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required!\n";
                break;
            }
            
            // Update quantities first, then items (which includes prices)
            $quantityResults = $syncService->updateQuantities();
            $itemResults = $syncService->updateItems();
            
            echo sprintf("📦 Quantities updated: %d\n", $quantityResults);
            echo sprintf("🔄 Items updated:      %d\n", $itemResults);
            echo sprintf("📊 Total updated:      %d\n", $quantityResults + $itemResults);
            break;
            
        case 'sync-inventory-add':
            echo "🆕 Add New Products to eBay\n";
            echo "============================\n";
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required!\n";
                break;
            }
            
            $added = $syncService->addNewItems();
            echo sprintf("🆕 New items added: %d\n", $added);
            break;
            
        case 'sync-inventory-delete':
            echo "❌ Remove Unavailable Products\n";
            echo "===============================\n";
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required!\n";
                break;
            }
            
            $ended = $syncService->endListings();
            echo sprintf("❌ Listings ended: %d\n", $ended);
            break;
            
        case 'sync-inventory-pull':
            echo "📥 Pull Inventory from eBay\n";
            echo "============================\n";
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required!\n";
                break;
            }
            
            $limit = (int)($argv[2] ?? 0); // 0 = no limit
            echo sprintf("Pulling %s inventory items from eBay...\n\n", $limit > 0 ? $limit : "ALL");
            
            $results = $inventoryService->pullAllInventory($limit);
            
            echo "📊 PULL RESULTS:\n";
            echo sprintf("📦 Items processed:  %d\n", $results['processed']);
            echo sprintf("🔄 Items updated:    %d\n", $results['updated']);
            echo sprintf("🆕 Items created:    %d\n", $results['created']);
            echo sprintf("❌ Errors:           %d\n", $results['errors']);
            
            if (!empty($results['changes']) && count($results['changes']) <= 10) {
                echo "\n📋 CHANGES:\n";
                foreach ($results['changes'] as $change) {
                    $qtyChange = $change['changes']['quantity'];
                    $priceChange = $change['changes']['price'];
                    echo sprintf("  • %s: Qty %d→%d, Price €%.2f→€%.2f\n",
                        $change['sku'],
                        $qtyChange['old'], $qtyChange['new'],
                        $priceChange['old'], $priceChange['new']
                    );
                }
            } elseif (count($results['changes']) > 10) {
                echo "\n📋 " . count($results['changes']) . " changes detected (showing first 5):\n";
                for ($i = 0; $i < 5; $i++) {
                    $change = $results['changes'][$i];
                    $qtyChange = $change['changes']['quantity'];
                    $priceChange = $change['changes']['price'];
                    echo sprintf("  • %s: Qty %d→%d, Price €%.2f→€%.2f\n",
                        $change['sku'],
                        $qtyChange['old'], $qtyChange['new'],
                        $priceChange['old'], $priceChange['new']
                    );
                }
                echo "  ... and " . (count($results['changes']) - 5) . " more\n";
            }
            break;
            
        case 'sync-inventory-migrate':
            echo "🔄 Migrate Legacy Listings\n";
            echo "===========================\n";
            
            if (!$hasEbayCredentials) {
                echo "❌ eBay credentials required!\n";
                break;
            }
            
            // Get listing IDs from command line or find them automatically
            $listingIds = array_slice($argv, 2);
            
            if (empty($listingIds)) {
                echo "🔍 Finding legacy listings...\n";
                $listingIds = $inventoryService->findListingsToMigrate();
                
                if (empty($listingIds)) {
                    echo "✅ No legacy listings found!\n";
                    break;
                }
                
                echo sprintf("Found %d listings to migrate.\n\n", count($listingIds));
            }
            
            // Process in batches
            $batchSize = 5;
            $totalResults = ['total' => count($listingIds), 'migrated' => 0, 'failed' => 0, 'errors' => []];
            $batches = array_chunk($listingIds, $batchSize);
            
            foreach ($batches as $batchIndex => $batch) {
                echo sprintf("Processing batch %d/%d...\n", $batchIndex + 1, count($batches));
                
                $batchResults = $inventoryService->bulkMigrateListings($batch);
                $totalResults['migrated'] += $batchResults['migrated'];
                $totalResults['failed'] += $batchResults['failed'];
                $totalResults['errors'] = array_merge($totalResults['errors'], $batchResults['errors']);
                
                sleep(1); // Rate limiting
            }
            
            echo "\n📊 MIGRATION RESULTS:\n";
            echo sprintf("📦 Total listings:  %d\n", $totalResults['total']);
            echo sprintf("✅ Migrated:        %d\n", $totalResults['migrated']);
            echo sprintf("❌ Failed:          %d\n", $totalResults['failed']);
            break;

        // ===== AUTHENTICATION COMMANDS =====
        
        case 'auth':
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
            echo "Environment: " . ($isSandbox ? 'Sandbox' : 'Production') . "\n\n";
            
            if ($auth->hasAccessToken()) {
                try {
                    $auth->getAccessToken();
                    echo "✅ eBay API authentication successful\n";
                } catch (\Exception $e) {
                    echo "⚠️ Token refresh failed: " . $e->getMessage() . "\n";
                    echo "Re-authorization required.\n\n";
                    echo "🔗 Authorization URL:\n";
                    echo $auth->getAuthorizationUrl() . "\n\n";
                    echo "Run: php cli/sync.php auth-exchange [code]\n";
                }
            } else {
                echo "❌ No eBay tokens found - authorization required\n\n";
                echo "🔗 Authorization URL:\n";
                echo $auth->getAuthorizationUrl() . "\n\n";
                echo "1. Open the URL above in your browser\n";
                echo "2. Authorize the application\n";
                echo "3. Copy the authorization code from redirect URL\n";
                echo "4. Run: php cli/sync.php auth-exchange [code]\n";
            }
            break;
            
        case 'auth-exchange':
            $authCode = $argv[2] ?? '';
            
            if (empty($authCode)) {
                echo "❌ No authorization code provided!\n";
                echo "Usage: php cli/sync.php auth-exchange [code]\n";
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
            
            $success = $auth->exchangeCodeForTokens($authCode);
            
            if ($success) {
                echo "✅ SUCCESS: Authorization completed!\n";
                echo "You can now run sync commands.\n";
            } else {
                echo "❌ FAILED: Could not exchange authorization code.\n";
                echo "Check the logs for details.\n";
            }
            break;

        // ===== HELP =====
        
        case 'help':
        case '--help':
        case '-h':
        default:
            echo "📚 Available Commands:\n";
            echo "=====================\n\n";
            
            echo "🔐 AUTHENTICATION:\n";
            echo "  auth                    - Check eBay authentication status\n";
            echo "  auth-exchange [code]    - Exchange authorization code for tokens\n\n";
            
            echo "🚀 MAIN SYNC:\n";
            echo "  sync-all                - Complete eBay synchronization\n\n";
            
            echo "📦 ORDER MANAGEMENT:\n";
            echo "  sync-orders-import [days]  - Import orders from eBay (default: 10 days)\n";
            echo "  sync-orders-update      - Update order status on eBay\n\n";
            
            echo "📋 INVENTORY MANAGEMENT:\n";
            echo "  sync-inventory-update   - Update quantities and prices\n";
            echo "  sync-inventory-add      - Add new products to eBay\n";
            echo "  sync-inventory-delete   - Remove unavailable products\n";
            echo "  sync-inventory-pull [limit] - Pull inventory from eBay (0 = all)\n";
            echo "  sync-inventory-migrate [ids...] - Migrate legacy listings\n\n";
            
            echo "💡 EXAMPLES:\n";
            echo "  php cli/sync.php auth                     # Check authentication\n";
            echo "  php cli/sync.php sync-all                 # Full sync\n";
            echo "  php cli/sync.php sync-orders-import 7     # Import last 7 days\n";
            echo "  php cli/sync.php sync-inventory-update    # Update inventory\n";
            echo "  php cli/sync.php sync-inventory-pull 100  # Pull 100 items\n";
            echo "  php cli/sync.php sync-inventory-migrate   # Auto-migrate legacy\n\n";
            
            echo "🔄 TYPICAL WORKFLOW:\n";
            echo "  1. php cli/sync.php auth                  # Check authentication\n";
            echo "  2. php cli/sync.php sync-inventory-migrate # One-time migration\n";
            echo "  3. php cli/sync.php sync-inventory-pull   # Full sync from eBay\n";
            echo "  4. php cli/sync.php sync-all              # Regular sync\n";
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
