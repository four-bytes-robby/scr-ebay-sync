<?php
// public/index.php
require_once __DIR__ . '/../bootstrap.php';

use ScrEbaySync\Api\eBay\Auth;
use ScrEbaySync\Api\eBay\Inventory;
use ScrEbaySync\Service\SyncService;
use ScrEbaySync\Controllers\SyncController;

// Container holen
$container = require_once __DIR__ . '/../bootstrap.php';
$entityManager = $container['entityManager'];
$logger = $container['logger'];

// Parameter aus URL holen
$action = $_GET['action'] ?? 'sync';
$token = $_GET['token'] ?? null;

// Die entsprechenden Services initialisieren
$auth = new Auth(
    $_ENV['EBAY_CLIENT_ID'],
    $_ENV['EBAY_CLIENT_SECRET'],
    $_ENV['EBAY_RUNAME'],
    $_ENV['EBAY_REFRESH_TOKEN']
);

$inventory = new Inventory($auth);

$syncService = new SyncService(
    $entityManager,
    $logger,
    $auth,
    $inventory
);

// Controller instanziieren und aufrufen
$controller = new SyncController($logger, $syncService);

// Inhaltstyp auf JSON setzen
header('Content-Type: application/json');

// Aktion ausführen
if ($action === 'sync') {
    echo $controller->syncAction($token);
} else {
    echo json_encode(['error' => 'Unknown action']);
}