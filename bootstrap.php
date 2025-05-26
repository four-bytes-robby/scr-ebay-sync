<?php
// bootstrap.php
require_once __DIR__ . '/vendor/autoload.php';

// Set timezone to Europe/Berlin (German local time)
date_default_timezone_set('Europe/Berlin');

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Dotenv\Dotenv;

// Load .env file if it exists
if (file_exists(__DIR__ . '/.env.local')) {
    $dotenv = new Dotenv();
    $dotenv->load(__DIR__ . '/.env.local');
} elseif (file_exists(__DIR__ . '/.env')) {
    $dotenv = new Dotenv();
    $dotenv->load(__DIR__ . '/.env');
} else {
    echo "WARNING: No .env or .env.local file found. Please create one from .env.example.\n";
}

// Ensure necessary directories exist
$dirs = [
    __DIR__ . '/logs',
    __DIR__ . '/var',
    __DIR__ . '/var/cache',
    __DIR__ . '/var/cache/doctrine_queries'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo "WARNING: Could not create directory {$dir}. Make sure PHP has write permissions.\n";
        }
    }
}

// Create a default template file if it doesn't exist
$templateFile = __DIR__ . '/templates/description_template.html';
if (!file_exists($templateFile)) {
    $defaultTemplate = <<<HTML
<div style="font-family: 'Market Sans', Arial, sans-serif;">
<h3>\$name</h1>
<p>\$text</p>
<p>\$releaseYear \$label</p>

<p style="color:#800;">
<b>Versandzeiten</b><br>
Leider zeigt eBay außerhalb Deutschlands falsche Schätzwerte für die Lieferzeit von Artikeln an. Speziell Überseegebiete haben längere Lieferzeiten. Im Zweifel bitten wir dich, nachzufragen.<br>
In Deutschland wird speziell bei Warenpost (z.B. bei Versand einer CD) bei eBay eine erfolgte Zustellung gemeldet, obwohl die Sendung erst im lokalen Verteilzentrum eingetroffen ist. Bitte prüfe hierzu den detaillierten Sendungsstatus, dort steht es oftmals korrekt.<br>
<br>
<b>Delivery times</b><br>
Unfortunately, eBay displays incorrect estimates for the delivery time of items outside Germany. Overseas areas in particular have longer delivery times. If in doubt, please ask us.<br>
</p>

<p style="color:#800;">
<b>Versand von Schallplatten/LPs</b><br>
Wir versenden die Schallplatten in der Regel eingeschweißt als Originalware.<br>
Wenn Du explizit Platten außerhalb des Covers verschickt haben möchtest und damit das Risiko einer Beschädigung des Covers vermeiden möchtest, informiere uns bitte mit einer kurzen Nachricht im Anmerkungsfeld beim Bestellvorgang. Wir senden die Platten in diesem Fall außerhalb des Covers, um Schäden an Cover und/oder Innenhülle zu vermeiden. Bei bedruckten Innenhüllen werden die Platten selbst in neuen antistatischen Innenhüllen versendet.<br>
<br>
<b>Shipping of vinyl records/LPs</b><br>
We will send you records - if possible - new and sealed.<br>
If you explicitely want us to ship your records outside of the cover and therfor minimize the risk of damages to the cover please inform us with a short message in the annotation field in the checkout. We will then send your records separated from the covers to avoid damages like seam splits on the cover and/or inner sleeve. We will place records housed in a printed inner sleeve in new antistatic bags.
</p>
</div>
HTML;
    
    if (!file_put_contents($templateFile, $defaultTemplate)) {
        echo "WARNING: Could not create template file {$templateFile}. Make sure PHP has write permissions.\n";
    }
}

// Basic PHP version check
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    echo "ERROR: This application requires PHP 8.1 or higher. Your PHP version is " . PHP_VERSION . ".\n";
    exit(1);
}

// Check for required PHP extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'mbstring'];
$missingExtensions = [];

foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $missingExtensions[] = $extension;
    }
}

if (!empty($missingExtensions)) {
    echo "ERROR: The following required PHP extensions are missing: " . implode(', ', $missingExtensions) . ".\n";
    echo "Please install them and try again.\n";
    exit(1);
}

// === DOCTRINE ORM 3.x + DBAL 4.x SETUP ===

try {
    // Get database configuration from environment
    // Priority: DATABASE_URL > individual DB_* variables
    $databaseUrl = $_ENV['DATABASE_URL'] ?? null;
    
    if ($databaseUrl) {
        // Parse DATABASE_URL (format: mysql://user:password@host:port/dbname)
        $parsedUrl = parse_url($databaseUrl);
        
        if (!$parsedUrl) {
            throw new \Exception('Invalid DATABASE_URL format');
        }
        
        $dbParams = [
            'driver' => 'pdo_mysql',
            'host' => $parsedUrl['host'] ?? 'localhost',
            'port' => $parsedUrl['port'] ?? 3306,
            'dbname' => ltrim($parsedUrl['path'] ?? '', '/'),
            'user' => $parsedUrl['user'] ?? 'root',
            'password' => $parsedUrl['pass'] ?? '',
            'charset' => 'utf8mb4',
        ];
        
        // Handle different database drivers based on scheme
        if (isset($parsedUrl['scheme'])) {
            switch ($parsedUrl['scheme']) {
                case 'mysql':
                case 'mysqli':
                    $dbParams['driver'] = 'pdo_mysql';
                    break;
                case 'postgresql':
                case 'postgres':
                    $dbParams['driver'] = 'pdo_pgsql';
                    break;
                case 'sqlite':
                    $dbParams['driver'] = 'pdo_sqlite';
                    $dbParams['path'] = $parsedUrl['path'];
                    break;
            }
        }
    } else {
        // Fallback to individual environment variables
        $dbParams = [
            'driver' => 'pdo_mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? 3306,
            'dbname' => $_ENV['DB_NAME'] ?? 'scr_ebay_sync',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
        ];
    }

    // Create Doctrine ORM configuration (ORM 3.x + DBAL 4.x style)
    $isDevMode = ($_ENV['APP_ENV'] ?? 'dev') === 'dev';
    $proxyDir = __DIR__ . '/var/cache/doctrine/proxies';
    $cacheDir = __DIR__ . '/var/cache/doctrine';

    // Ensure proxy and cache directories exist
    if (!is_dir($proxyDir)) {
        mkdir($proxyDir, 0755, true);
    }
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // Modern Doctrine ORM 3.x + DBAL 4.x setup
    $config = ORMSetup::createAttributeMetadataConfiguration(
        paths: [__DIR__ . '/src/Entity'],
        isDevMode: $isDevMode,
        proxyDir: $proxyDir,
        cache: null // Use default cache in dev mode
    );

    // Set namespace for proxy classes
    $config->setProxyNamespace('DoctrineProxies');

    // Create connection (DBAL 4.x style)
    $connection = DriverManager::getConnection($dbParams);

    // Create EntityManager (ORM 3.x style)
    $entityManager = new EntityManager($connection, $config);

    // Test database connection (DBAL 4.x - connection is tested automatically)
    // No need to call ->connect() explicitly, DBAL 4.x handles this internally
    
    if ($isDevMode) {
        echo "✅ Doctrine ORM 3.x + DBAL 4.x EntityManager initialized successfully\n";
        if ($databaseUrl) {
            // Mask password in URL for display
            $displayUrl = preg_replace('/(:\/\/[^:]+:)[^@]+(@)/', '$1***$2', $databaseUrl);
            echo "📊 Database URL: {$displayUrl}\n";
        } else {
            echo "📊 Database: {$dbParams['host']}:{$dbParams['port']}/{$dbParams['dbname']}\n";
        }
        
        // Test connection by executing a simple query
        try {
            $connection->executeQuery('SELECT 1');
            echo "🔌 Database connection verified\n";
        } catch (\Exception $connTest) {
            echo "⚠️  Database connection test failed: " . $connTest->getMessage() . "\n";
        }
    }

} catch (\Exception $e) {
    echo "❌ Failed to initialize Doctrine EntityManager: " . $e->getMessage() . "\n";
    echo "🔧 Please check your database configuration in .env file\n";
    
    // In CLI mode, show more details
    if (php_sapi_name() === 'cli') {
        if ($databaseUrl ?? false) {
            $displayUrl = preg_replace('/(:\/\/[^:]+:)[^@]+(@)/', '$1***$2', $databaseUrl);
            echo "DATABASE_URL: {$displayUrl}\n";
        } else {
            echo "Database parameters:\n";
            foreach ($dbParams ?? [] as $key => $value) {
                if ($key === 'password') {
                    $value = str_repeat('*', strlen($value));
                }
                echo "  {$key}: {$value}\n";
            }
        }
        
        // Show more specific error information
        if ($e->getPrevious()) {
            echo "Previous exception: " . $e->getPrevious()->getMessage() . "\n";
        }
    }
    
    exit(1);
}

// Make EntityManager globally available for CLI scripts
$GLOBALS['entityManager'] = $entityManager;
