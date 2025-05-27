<?php
// public/webhook.php - eBay Webhook Endpoint
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Four\ScrEbaySync\Controllers\WebhookController;
use Four\ScrEbaySync\Services\EbayWebhook\WebhookService;
use Four\ScrEbaySync\Services\EbayOrder\OrderImportService;
use Four\ScrEbaySync\Services\EbayOrder\OrderStatusService;
use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Four\ScrEbaySync\Api\eBay\Auth;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Get EntityManager from global scope
$entityManager = $GLOBALS['entityManager'];

// Setup webhook-specific logger
$logger = new Logger('webhook');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/webhook.log', Logger::DEBUG));
$logger->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

try {
    // Check if webhook is enabled
    $webhookEnabled = $_ENV['EBAY_WEBHOOK_ENABLED'] ?? 'false';
    if ($webhookEnabled !== 'true') {
        http_response_code(503);
        echo json_encode(['error' => 'Webhook service not enabled']);
        exit;
    }
    
    // Initialize eBay API services
    $webhookSecret = $_ENV['EBAY_WEBHOOK_SECRET'] ?? '';
    
    if (empty($webhookSecret)) {
        $logger->error('Webhook secret not configured');
        http_response_code(500);
        echo json_encode(['error' => 'Webhook not properly configured']);
        exit;
    }
    
    // Initialize eBay services (when API is ready)
    $ebayAuth = new Auth(
        $_ENV['EBAY_CLIENT_ID'] ?? '',
        $_ENV['EBAY_CLIENT_SECRET'] ?? '',
        $_ENV['EBAY_RUNAME'] ?? '',
        logger: $logger
    );
    
    $fulfillmentApi = new Fulfillment($ebayAuth, $logger);
    
    $orderImportService = new OrderImportService($fulfillmentApi, $entityManager, $logger);
    $orderStatusService = new OrderStatusService($fulfillmentApi, $entityManager, $logger);
    
    // Initialize webhook service
    $webhookService = new WebhookService(
        $orderImportService,
        $orderStatusService,
        $entityManager,
        $logger,
        $webhookSecret
    );
    
    // Initialize controller
    $controller = new WebhookController($webhookService, $logger);
    
    // Handle the webhook
    $controller->handleWebhook();
    
} catch (\Exception $e) {
    $logger->critical('Webhook endpoint failure', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
    ]);
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'timestamp' => date('c')
    ]);
}
