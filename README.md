# PayU Polska - HikaShop Payment Plugin

Wtyczka płatności PayU Polska dla HikaShop (Joomla 5/6).

## Funkcje

-  Integracja z PayU Polska
-  Obsługa środowiska Sandbox i Produkcji
-  Automatyczna zmiana statusu zamówienia po płatności
-  Powiadomienia email przy zmianie statusu
-  **Sprawdzanie statusu po powrocie** (rozwiązuje problem localhost/sandbox)
-  Tryb debug (logowanie)
-  Kompatybilność z Joomla 5/6

## Wymagania

- **Joomla 5.x / 6.x**
- **HikaShop 5.x**
- **PHP 8.1+**

## Instalacja

1. Pobierz paczkę ZIP z [Releases](https://github.com/pablop76/plg_payu/releases)
2. W panelu Joomla: **System  Rozszerzenia  Instaluj**
3. Wgraj plik ZIP
4. Przejdź do **Komponenty  HikaShop  Konfiguracja  Płatności**
5. Kliknij **Nowy** i wybierz **PayU**

## Konfiguracja

| Opcja | Opis |
|-------|------|
| POS ID | ID punktu płatności z PayU |
| Signature Key | Klucz podpisu (MD5) z PayU |
| OAuth Client ID | Client ID OAuth z PayU |
| OAuth Client Secret | Client Secret OAuth z PayU |
| Sandbox Mode | Tryb testowy (włącz dla testów) |
| Debug | Logowanie do `administrator/logs/payu_debug.log` |
| Return URL | URL powrotu (opcjonalny) |
| Check Status on Return | Sprawdza status płatności po powrocie (dla localhost) |
| Invalid Status | Status dla nieudanych płatności |
| Verified Status | Status dla udanych płatności |

## Problem z localhost/sandbox

Na localhost notyfikacje PayU nie docierają (PayU nie może wysłać webhooków na adres lokalny). 

**Rozwiązanie**: Włącz opcję **"Check Status on Return"** - wtyczka sprawdzi status płatności bezpośrednio w API PayU po powrocie użytkownika.

## Struktura plików (Joomla 5/6)

```
plg_payu/
 payu.php              # Legacy entry point
 payu.xml              # Manifest instalacyjny
 payu_end.php          # Szablon przekierowania
 index.html
 language/             # Pliki językowe (en-GB, pl-PL)
 services/
    provider.php      # Service Provider (J5/6)
 src/
    Extension/
        Payu.php      # Główna klasa pluginu
 vendor/               # PayU SDK (openpayu)
```

## Changelog

### v2.0.1 (2026-01-17)

- Poprawiono wysyłanie emaili przy zmianie statusu (do klienta i admina)
- Używa modifyOrder() zamiast bezpośredniego SQL

### v2.0.0 (2026-01-14)

- Pełna kompatybilność z Joomla 5/6
- Namespace `Pablop76\Plugin\HikashopPayment\Payu`
- Dodano `services/provider.php`
- **NOWOŚĆ**: Sprawdzanie statusu płatności po powrocie (dla localhost)
- Zamieniono przestarzałe klasy JFactory, JText
- Wymagane PHP 8.1+

### v1.0.0 (2025-10-15)

- Pierwsza wersja

## Licencja

GNU/GPL v2

## Autor

Paweł Półtoraczyk - [web-service.com.pl](https://web-service.com.pl)
