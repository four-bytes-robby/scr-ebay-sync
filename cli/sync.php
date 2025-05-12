<?php
// cli/sync.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use Four\ScrEbaySync\Api\eBay\Auth;
use Four\ScrEbaySync\Api\eBay\Inventory;
use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Four\ScrEbaySync\Services\SyncService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Doctrine\ORM\EntityManagerInterface;

// Initialize logger
$logger = new Logger('ebay_sync');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/sync.log', Logger::DEBUG));
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
$logger->pushProcessor(new PsrLogMessageProcessor());

$logger->info('Starting eBay synchronization...');

try {
    // Load Doctrine configuration
    $entityManager = null;
    try {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = require_once __DIR__ . '/../config/doctrine.php';
        $logger->info('Database connection established');
    } catch (\Exception $e) {
        $logger->error('Failed to connect to database: ' . $e->getMessage());
        exit(1);
    }

    // Check if required environment variables are set
    $requiredEnvVars = ['EBAY_CLIENT_ID', 'EBAY_CLIENT_SECRET', 'EBAY_RUNAME'];
    $missingVars = [];
    
    foreach ($requiredEnvVars as $var) {
        if (empty($_ENV[$var])) {
            $missingVars[] = $var;
        }
    }
    
    if (!empty($missingVars)) {
        $logger->error('Missing required environment variables: ' . implode(', ', $missingVars));
        $logger->error('Please add these variables to your .env.local file and try again');
        exit(1);
    }

    // Initialize eBay API clients
    $auth = new Auth(
        $_ENV['EBAY_CLIENT_ID'],
        $_ENV['EBAY_CLIENT_SECRET'],
        $_ENV['EBAY_RUNAME'],
        isset($_ENV['EBAY_SANDBOX']) && $_ENV['EBAY_SANDBOX'] === 'true',
        $logger
    );

    // Check if we need to authenticate
    if (!$auth->hasAccessToken()) {
        // Output the authorization URL
        $logger->info('eBay authentication required. Please follow these steps:');
        $logger->info('1. Visit the following URL in your browser:');
        $logger->info($auth->getAuthorizationUrl());
        $logger->info('2. Log in to your eBay account and authorize the application');
        $logger->info('3. You will be redirected to a URL. Copy the "code" parameter from the URL');
        $logger->info('4. Enter the authorization code here:');
        
        // Get authorization code from input
        $handle = fopen('php://stdin', 'r');
        $code = trim(fgets($handle));
        fclose($handle);
        
        // Exchange code for tokens
        if (!$auth->exchangeCodeForTokens($code)) {
            $logger->error('Failed to authenticate with eBay.');
            $logger->error('Please check that the code is correct and try again.');
            $logger->error('Make sure your eBay application credentials (Client ID, Client Secret, RuName) are correct in your .env file.');
            exit(1);
        }
        
        $logger->info('Authentication successful! You can now run the sync commands.');
    } else {
        $logger->info('eBay authentication is already set up.');
    }

    // Initialize API clients
    $inventoryApi = new Inventory($auth, $logger);
    $fulfillmentApi = new Fulfillment($auth, $logger);

    // Initialize sync service
    $syncService = new SyncService(
        $inventoryApi,
        $fulfillmentApi,
        $entityManager,
        $logger
    );

    // Parse command line arguments
    $options = getopt('', [
        'new-items::',      // Add new items
        'update-items::',   // Update existing items
        'quantities::',     // Update quantities
        'end-listings::',   // End unavailable listings
        'import-orders::',  // Import orders
        'order-status::',   // Update order status
        'all::'            // Run all sync operations
    ]);

    // Determine which operations to run
    $runNewItems = isset($options['new-items']) || isset($options['all']);
    $runUpdateItems = isset($options['update-items']) || isset($options['all']);
    $runQuantities = isset($options['quantities']) || isset($options['all']);
    $runEndListings = isset($options['end-listings']) || isset($options['all']);
    $runImportOrders = isset($options['import-orders']) || isset($options['all']);
    $runOrderStatus = isset($options['order-status']) || isset($options['all']);

if (empty($options)) {
        // If no specific options provided, check for help flag
        if (isset($argv[1]) && ($argv[1] === '--help' || $argv[1] === '-h')) {
            echo "eBay Synchronization Tool\n";
            echo "========================\n\n";
            echo "Usage: php cli/sync.php [options]\n\n";
            echo "Options:\n";
            echo "  --new-items       Add new items to eBay\n";
            echo "  --update-items    Update existing items on eBay\n";
            echo "  --quantities      Update quantities of items on eBay\n";
            echo "  --end-listings    End unavailable listings on eBay\n";
            echo "  --import-orders   Import orders from eBay\n";
            echo "  --order-status    Update order status on eBay\n";
            echo "  --all             Run all sync operations\n";
            echo "  --auth-only       Only perform authentication, don't sync\n";
            echo "  --help, -h        Display this help message\n\n";
            echo "If no options are provided, all operations will be performed.\n";
            exit(0);
        }
        
        // If only authentication was requested
        if (isset($argv[1]) && $argv[1] === '--auth-only') {
            $logger->info('Authentication only mode, no synchronization will be performed.');
            exit(0);
        }
        
        // Run everything by default
        $runNewItems = $runUpdateItems = $runQuantities = $runEndListings = $runImportOrders = $runOrderStatus = true;
    }

    // Run synchronization
    $results = $syncService->runSync(
        $runNewItems,
        $runUpdateItems,
        $runQuantities,
        $runEndListings,
        $runImportOrders,
        $runOrderStatus
    );

    // Output results
    $logger->info('Synchronization completed successfully', $results);
    
    foreach ($results as $operation => $count) {
        $logger->info(sprintf('  - %s: %d', $operation, $count));
    }

    exit(0);
} catch (Exception $e) {
    $logger->error('Error during synchronization: ' . $e->getMessage());
    $logger->error($e->getTraceAsString());
    exit(1);
}
