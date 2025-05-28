# eBay Sync CLI Commands

## Übersicht

Das eBay Sync CLI Tool wurde entschlackt und fokussiert sich auf die wesentlichen Funktionen:

## Hauptkommandos

### Authentication
```bash
php cli/sync.php auth                    # Status prüfen
php cli/sync.php auth-exchange [code]    # Token austauschen
```

### Vollständige Synchronisation
```bash
php cli/sync.php sync-all               # Komplett-Sync (alles)
```

### Order Management
```bash
php cli/sync.php sync-orders-import [days]   # Orders von eBay holen (Standard: 10 Tage)
php cli/sync.php sync-orders-update          # Order-Status zu eBay senden
```

### Inventory Management
```bash
php cli/sync.php sync-inventory-update       # Bestände & Preise aktualisieren
php cli/sync.php sync-inventory-add          # Neue Produkte hinzufügen
php cli/sync.php sync-inventory-delete       # Ausverkaufte Produkte entfernen
php cli/sync.php sync-inventory-pull [limit] # Von eBay holen (0 = alle)
php cli/sync.php sync-inventory-migrate      # Legacy-Listings migrieren
```

## Entfernte Features

- **DryRun Mode**: Entfernt, da Dashboard die Vorschau liefert
- **Oversold**: War redundant zu inventory-update
- **sync-quantities**: Integriert in sync-inventory-update

## Korrekte Bestandsaktualierung

Die Bestandsaktualierung verwendet jetzt die korrekte eBay Inventory API:

```php
// Korrekte API-Struktur nach eBay Dokumentation
$inventoryItemData = [
    'availability' => [
        'shipToLocationAvailability' => [
            'quantity' => $newQuantity
        ]
    ]
];

$this->inventoryApi->createOrUpdateInventoryItem($sku, $inventoryItemData);
```

## Typischer Workflow

```bash
# 1. Authentication prüfen
php cli/sync.php auth

# 2. Einmalige Migration (falls nötig)
php cli/sync.php sync-inventory-migrate

# 3. Vollständiger Sync von eBay
php cli/sync.php sync-inventory-pull

# 4. Regelmäßiger Sync
php cli/sync.php sync-all

# Oder einzeln:
php cli/sync.php sync-inventory-update    # Bestände & Preise
php cli/sync.php sync-orders-import       # Orders holen
php cli/sync.php sync-orders-update       # Order-Status senden
```

## Technische Details

### Bestandsaktualierung
- Verwendet `createOrUpdateInventoryItem` API
- Struktur: `availability.shipToLocationAvailability.quantity`
- Keine deprecated `updateQuantity` Methoden mehr

### Rate Limiting
- Automatisches Rate-Limiting verhindert doppelte Updates
- 1-Stunden-Fenster für wiederholte Versuche
- Robuste Fehlerbehandlung

### Logging
- Alle Aktionen werden geloggt
- Rate-Limiting wird sichtbar gemacht
- Debug-Informationen für Fehleranalyse
