<?php
// config/doctrine.php

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

// Load .env file if it exists
if (file_exists(__DIR__ . '/../.env.local')) {
    $dotenv = new Dotenv();
    $dotenv->load(__DIR__ . '/../.env.local');
} elseif (file_exists(__DIR__ . '/../.env')) {
    $dotenv = new Dotenv();
    $dotenv->load(__DIR__ . '/../.env');
}

// Define paths to Entities
$paths = [__DIR__ . '/../src/Entity'];
$isDevMode = true;

// Database connection parameters
$dbUrl = $_ENV['DATABASE_URL'] ?? 'mysql://user:password@localhost:3306/scrmetal';

// Parse the URL to get components
$dbParams = parse_url($dbUrl);
$dbPath = ltrim($dbParams['path'] ?? '/', '/');

// Setup connection parameters
$connectionParams = [
    'dbname' => $dbPath,
    'user' => $dbParams['user'] ?? 'root',
    'password' => $dbParams['pass'] ?? '',
    'host' => $dbParams['host'] ?? 'localhost',
    'port' => $dbParams['port'] ?? 3306,
    'driver' => 'pdo_mysql',
    'charset' => 'utf8mb4'
];

try {
    // Setup the configuration
    $config = ORMSetup::createAttributeMetadataConfiguration(
        $paths,
        $isDevMode
    );

    // Configure cache for development environment
    $cache = new PhpFilesAdapter('doctrine_queries', 0, __DIR__ . '/../var/cache');
    $config->setMetadataCache($cache);
    $config->setQueryCache($cache);

    // Get DB connection
    $connection = DriverManager::getConnection($connectionParams);
    
    // Create the EntityManager
    return new EntityManager($connection, $config);
} catch (Exception $e) {
    echo "Error connecting to database: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
