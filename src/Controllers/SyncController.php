<?php
// src/Controllers/SyncController.php
namespace Four\ScrEbaySync\Controllers;

use Monolog\Logger;
use Four\ScrEbaySync\Api\eBay\Auth;
use Four\ScrEbaySync\Api\eBay\Inventory;
use Four\ScrEbaySync\Api\eBay\Fulfillment;
use Four\ScrEbaySync\Services\SyncService;
use Doctrine\ORM\EntityManagerInterface;

class SyncController
{
    private Logger $logger;
    private EntityManagerInterface $entityManager;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        // Doctrine EntityManager laden
        $this->entityManager = require __DIR__ . '/../../config/doctrine.php';
    }

    public function runSync()
    {
        try {
            // eBay API authentifizieren
            $auth = new Auth(
                $_ENV['EBAY_CLIENT_ID'],
                $_ENV['EBAY_CLIENT_SECRET'],
                $_ENV['EBAY_RUNAME'],
                isset($_ENV['EBAY_SANDBOX']) && $_ENV['EBAY_SANDBOX'] === 'true',
                $this->logger
            );

            // Check if authentication is needed
            if (!$auth->hasAccessToken()) {
                return "eBay authentication required. Please run the CLI sync command first.";
            }

            // Benötigte Services initialisieren
            $inventoryApi = new Inventory($auth, $this->logger);
            $fulfillmentApi = new Fulfillment($auth, $this->logger);

            // Haupt-Service starten
            $syncService = new SyncService(
                $inventoryApi,
                $fulfillmentApi,
                $this->entityManager,
                $this->logger
            );

            // Aktionen ausführen
            $results = $syncService->runSync();

            $summary = "Synchronization completed:<br>";
            foreach ($results as $key => $value) {
                $summary .= "- {$key}: {$value}<br>";
            }

            return $summary;
        } catch (\Exception $e) {
            $this->logger->error('Sync error: ' . $e->getMessage());
            throw $e;
        }
    }
}