<?php
// bootstrap.php
require_once __DIR__ . '/vendor/autoload.php';

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
    __DIR__ . '/templates'
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
