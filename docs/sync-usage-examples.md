# eBay Sync Tool - Enhanced Usage Guide

# eBay Sync Tool - Vereinfachte Authentication

## 🔐 eBay Authentication Setup

Das System nutzt die bereits vorhandene Auth.php mit Code Exchange.

### Erstmalige Einrichtung

1. **Credentials konfigurieren** (.env):
```bash
EBAY_CLIENT_ID=your_client_id
EBAY_CLIENT_SECRET=your_client_secret
EBAY_RUNAME=your_ru_name
EBAY_SANDBOX=false  # Optional, default: false
```

2. **Auth-Status prüfen**:
```bash
composer auth status
```

3. **Initial-Authentifizierung** (falls erforderlich):
```bash
# 1. Authorization URL abrufen
composer auth url

# 2. URL im Browser öffnen und autorisieren
# 3. Auth-Code aus Redirect URL kopieren
# 4. Code austauschen
composer auth [your_auth_code]
```

### Auth-Commands

```bash
composer auth status        # Auth-Status prüfen
composer auth url          # Authorization URL abrufen  
composer auth test         # Auth testen
composer auth [code]       # Auth-Code austauschen
```

## 🚀 Sync-Modi

### **PRODUCTION** (Standard)
- **Live eBay API-Calls** ohne Flags
- Automatische Token-Refresh  
- Echte Synchronisation

### **DRY RUN** (Test)
- **Nur Datenbank-Updates** mit `--dry-run`
- Keine eBay API-Calls
- Sichere Tests

### **DATABASE ONLY**
- Keine eBay-Credentials konfiguriert
- Nur lokale DB-Operationen

## 📊 Dashboard und Analyse

### Status-Dashboard
```bash
composer sync status
```
Zeigt:
- Sync-Übersicht mit Prioritäten
- Handlungsempfehlungen
- System-Status (Auth, Modi)

### Item-Analysen
```bash
composer sync show-quantities 25    # Mengen-Änderungen
composer sync show-oversold 10      # Überteuerte Items  
composer sync show-prices 20        # Preis-Änderungen
composer sync show-new 15           # Neue Items für Listing
composer sync show-recent 30        # Kürzlich geändert
composer sync show-stale 10         # Veraltete Items
```

## 🔄 Produktive Sync-Commands

### Vollständige Synchronisation
```bash
# Test-Modus (sicher)
composer sync sync-all -- --dry-run

# Produktiv-Modus (live eBay)
composer sync sync-all
```

### Spezifische Sync-Operationen
```bash
# Mengen synchronisieren (50 Items)
composer sync sync-quantities 50 -- --dry-run  # Test
composer sync sync-quantities 50               # Live

# Neue Listings erstellen (20 Items)
composer sync sync-new-items 20 -- --dry-run   # Test  
composer sync sync-new-items 20                # Live

# Preise synchronisieren (€0.50 Schwellwert, 30 Items)
composer sync sync-prices 0.50 30 -- --dry-run  # Test
composer sync sync-prices 0.50 30               # Live

# Überteuerte Items reparieren (Emergency)
composer sync fix-oversold -- --dry-run         # Test
composer sync fix-oversold                      # Live
```

### Inventory-Abgleich (eBay → Database)
```bash
# Aktuelle eBay-Bestände zurückholen
composer sync pull-inventory 100 -- --dry-run   # Test
composer sync pull-inventory 100                # Live
```

### Bestellungen synchronisieren
```bash
# Orders der letzten 10 Tage
composer sync sync-orders 10 -- --dry-run       # Test  
composer sync sync-orders 10                    # Live
```

## 🏗️ Typische Workflows

### 1. Täglicher Sync-Workflow
```bash
# 1. Status prüfen
composer sync status

# 2. Kritische Issues beheben  
composer sync fix-oversold

# 3. Normale Synchronisation
composer sync sync-quantities
composer sync sync-prices  

# 4. Neue Items listen
composer sync sync-new-items
```

### 2. Wöchentlicher Inventory-Abgleich
```bash
# eBay-Daten zurückholen (Anti-Manipulation)
composer sync pull-inventory
```

### 3. Setup / Problem-Lösung
```bash
# 1. Auth-Status prüfen
composer auth status

# 2. Bei Auth-Problemen: Re-Authorization
composer auth url
# [Browser-Auth] 
composer auth [code]

# 3. Vollständiger Test
composer sync sync-all -- --dry-run

# 4. Live ausführen
composer sync sync-all
```

## 🔧 Architektur

### Services genutzt:
- **SyncService**: Produktive eBay-Sync-Operationen
- **EbayInventoryService**: Inventory-Management  
- **EbayOrderService**: Bestellungs-Synchronisation
- **SyncDashboardService**: Dashboard & Analyse
- **Auth**: eBay Authentication (bereits vorhanden)

### Auth-Flow:
1. **Auto-Detection**: Prüft gespeicherte Tokens via Auth.php
2. **Auto-Refresh**: Erneuert abgelaufene Tokens automatisch
3. **Code Exchange**: Bei Bedarf neue Authorization über Auth.php
4. **Persistent Storage**: Tokens in `var/ebay_tokens_*.json`

## 📋 Modi-Übersicht

| Modus | eBay API | Datenbank | Verwendung |
|-------|----------|-----------|------------|
| **PRODUCTION** | ✅ Live | ✅ | Standard-Sync |
| **DRY RUN** | ❌ | ✅ Test | Sichere Tests |
| **DATABASE ONLY** | ❌ | ✅ | Keine Auth |

## 🚨 Sicherheitsfeatures

- **Auto-Auth-Check**: Prüft Auth vor jedem Sync
- **Dry-Run Standard**: `--dry-run` für sichere Tests
- **Detailed Logging**: Vollständige Operation-Logs
- **Error Recovery**: Robuste Fehlerbehandlung
- **Token Persistence**: Automatisches Token-Management

Das neue System bietet maximale Sicherheit durch automatisierte Auth-Prüfung und klare Modi-Trennung!
