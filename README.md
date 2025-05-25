# eBay-SCR Sync Tool

Ein robustes CLI-Tool zur Synchronisation zwischen dem SCR-System und eBay.

## 🚀 Features

- **Vollständige Quantitäts-Synchronisation** mit 3-Stück eBay-Limit
- **Preissynchronisation** mit konfigurierbaren Schwellenwerten
- **Overselling-Erkennung und automatische Korrektur**
- **Neue Listings erstellen** basierend auf SCR-Verfügbarkeit
- **eBay Order Import und Status-Updates** (Payment/Shipping)
- **Shipped Status Abgleich** basierend auf SCR Invoice Dispatch Dates
- **Umfassendes Dashboard** für Status-Überwachung
- **Robuste Fallback-Mechanismen** für verschiedene MySQL/Doctrine-Versionen

## 📋 Verfügbare Kommandos

### 🔍 Analyse & Monitoring
```bash
php cli/sync.php status                    # Status-Übersicht anzeigen
php cli/sync.php dashboard                 # Interaktives Dashboard
php cli/sync.php oversold [limit]          # Oversold Items finden (default: 25)
php cli/sync.php prices [threshold]        # Preis-Updates finden (default: 0.50)
php cli/sync.php recent [hours]            # Kürzlich aktualisierte Items (default: 6)
php cli/sync.php new-items [limit]         # Items bereit für eBay-Listing (default: 20)
```

### 🚀 eBay Synchronisation
```bash
php cli/sync.php sync-all                  # Vollständige Synchronisation (Items + Orders)
php cli/sync.php sync-quantities [batch]   # Mengen synchronisieren (default: 50)
php cli/sync.php sync-new-items [limit]    # Neue eBay-Listings erstellen (default: 20)
php cli/sync.php sync-prices [threshold]   # eBay-Preise aktualisieren (default: 0.50)
php cli/sync.php sync-fix-oversold         # Notfall-Fix für oversold Items
```

### 📦 Order Management
```bash
php cli/sync.php sync-orders [days]        # Vollständige Order-Synchronisation (default: 10 Tage)
php cli/sync.php sync-orders-paid          # Nur Payment Status Updates
php cli/sync.php sync-orders-shipped       # Nur Shipping Status Updates (mit Carrier Mapping)
php cli/sync.php sync-orders-canceled      # Nur Cancellation Status Updates
```

### 🧪 Testing & Debugging
```bash
php cli/sync.php test                      # Schnelle Performance-Tests
php cli/sync.php test-detailed             # Umfassende Test-Suite
php cli/sync.php test-queries              # MySQL/Doctrine Kompatibilität testen
```

## ⚙️ eBay Quantity Logic

### Kritische Business-Regel: `quantity < 0` = "nicht auf eBay"

```php
// eBay Status Logic:
ebay_items.quantity >= 0  →  Item IST auf eBay gelistet
ebay_items.quantity < 0   →  Item ist NICHT auf eBay / Listing beendet

// Quantity Calculation:
scr_items.quantity <= 0   →  ebay_items.quantity = -1 (nicht listen)
scr_items.quantity > 0    →  ebay_items.quantity = min(scr_quantity, 3)
```

### 3-Stück eBay-Limit
- Alle eBay-Quantities werden automatisch auf maximal 3 Stück begrenzt
- Bei SCR-Mengen > 3 wird eine Warnung ausgegeben
- System verhindert automatisch Overselling-Situationen

## 🚨 Production Quick Start

### Tägliche Wartung
```bash
# 1. Status prüfen
php cli/sync.php dashboard

# 2. Kritische Probleme beheben  
php cli/sync.php sync-fix-oversold

# 3. Quantities synchronisieren
php cli/sync.php sync-quantities 50
```

### Wöchentliche Synchronisation
```bash
# Vollständige Sync (Items + Orders)
php cli/sync.php sync-all
```

### Order Management
```bash
# Vollständige Order-Synchronisation (letzte 10 Tage)
php cli/sync.php sync-orders 10

# Spezifische Status Updates
php cli/sync.php sync-orders-paid          # Payment Status
php cli/sync.php sync-orders-shipped       # Shipping Status + Carrier Mapping
php cli/sync.php sync-orders-canceled      # Cancellation Status

# Shipped Status Update basierend auf SCR Invoices
# (automatisch in sync-all und sync-orders enthalten)
```

### Bei Bedarf
```bash
# Neue Produkte listen
php cli/sync.php sync-new-items 20

# Preise aktualisieren (€1+ Unterschied)
php cli/sync.php sync-prices 1.00
```

## 📊 Monitoring Metriken

Das System überwacht automatisch:
- **Oversold Items** (sollte < 10 sein)
- **Preis-Unterschiede** > €1.00
- **Nicht-synchronisierte Items** > 48h
- **Query Performance** und Fallback-Usage

## 🔧 Technische Details

### Database Schema
```sql
-- EbayItem Entity mit korrekter NULL-Handling
ebay_items.quantity     INTEGER NOT NULL
ebay_items.deleted      DATETIME NULL      -- NULL = aktiv, DateTime = beendet
ebay_items.item_id      VARCHAR(20)        -- PK, Referenz zu scr_items.id
```

### Performance Optimierungen
- **Eager Loading** für OneToOne Relationships (89.7% Performance-Verbesserung)
- **Batch Processing** für große Datenmengen
- **SQL Fallbacks** für verschiedene MySQL-Versionen
- **Query Caching** für wiederholte Operationen

### Robust Fallback System
```php
// Automatische Fallbacks bei SQL-Kompatibilitätsproblemen:
try {
    $results = $repo->findItemsWithChangedQuantities($limit);
} catch (\Exception $e) {
    // Fallback zu PHP-basierter Safe Query
    $results = $repo->findItemsWithChangedQuantitiesSafe($limit);
}
```

## 🔗 Service Architecture

### Aktuelle Saubere Architektur:

```php
// Main Facade Service
SyncService
├── EbayInventoryService (Listings, Quantities, Prices)
└── EbayOrderService (Import, Status Updates)
    ├── OrderImportService
    ├── OrderStatusService (Shipped Status Abgleich)
    ├── TransactionProcessor
    ├── CustomerProcessor
    └── InvoiceProcessor
```

### SyncService - Main API:
```php
$syncService->runSync(
    $addNewItems = true,
    $updateItems = true, 
    $updateQuantities = true,
    $endListings = true,
    $importOrders = true,        // ✅ Orders Import
    $updateOrderStatus = true    // ✅ Shipped Status Sync
);
```

### Order Status Logic:
```php
// Automatischer Shipped Status Abgleich:
// SCR Invoice dispatchdat IS NOT NULL → eBay Order marked as shipped

// Intelligentes Carrier Mapping:
// 1. Primär: SCR Invoice.shipper → eBay Carrier
// 2. Fallback: Tracking-Nummer Pattern → eBay Carrier

// Unterstützte Carrier:
// Deutsche Post, DHL, Hermes, UPS, FedEx, TNT → Korrekte eBay Codes
// Spring GDS, GLS, DPD → eBay "OTHER"
// Automatische Pattern-Erkennung für 15+ Tracking-Formate
```

## 🔗 Integration mit eBay API

**Aktueller Status:** Das Tool synchronisiert die **lokale Datenbank** perfekt.
**Nächste Schritte für Production:**
1. **eBay API Integration** für echte Listing-Erstellung/Updates
2. **eBay API Integration** für echten Order Import/Status Updates
3. **Rücksynchronisation** von eBay Status/Mengen/Preisen
4. **Conflict Resolution** für unterschiedliche Daten
5. **Real-time Monitoring** für eBay API Rate Limits

## ⚠️ Wichtige Hinweise

### Production Ready Features
✅ **Database Synchronisation** - Vollständig implementiert  
✅ **Overselling Prevention** - Automatische Erkennung und Korrektur  
✅ **3-Stück eBay-Limit** - Korrekt implementiert  
✅ **Order Infrastructure** - Vollständige Services vorhanden  
✅ **Shipped Status Logic** - Automatischer Abgleich mit SCR Invoices  
✅ **Status Monitoring** - Umfassendes Dashboard  
✅ **Error Handling** - Robuste Fallback-Mechanismen  

### Noch benötigt für Live eBay
❌ **eBay API Calls** - Listings/Orders werden nur in DB verwaltet  
❌ **eBay → SCR Sync** - Keine Rücksynchronisation  
❌ **Live Status Updates** - Kein Real-time eBay Status  

## 🚀 Composer Scripts

```bash
# Shortcuts für häufige Operationen
composer sync dashboard
composer sync sync-all
composer sync test-detailed
```

## 📈 Performance Benchmarks

Typische Ausführungszeiten:
- **Status Overview**: ~0.15s (für 33.000+ Items)
- **Quantity Changes**: ~0.03s (25 Items)
- **Oversold Detection**: ~0.04s (25 Items)
- **Price Updates**: ~0.05s (25 Items)

## 🔒 Sicherheit

- **SQL Injection Prevention** durch Doctrine ORM
- **Parameter Validation** für alle Eingaben
- **Error Logging** mit strukturierten Daten
- **Transaction Safety** für Batch-Operationen

---

**Status:** ✅ Production-ready für Database-Synchronisation + Order Management  
**Next:** eBay API Integration für Live-Synchronisation
