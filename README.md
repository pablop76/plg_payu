# PayU Polska - HikaShop Payment Plugin

Wtyczka płatności PayU Polska dla HikaShop (Joomla 4/5/6).

## Funkcje

-  Integracja z PayU Polska
-  Obsługa środowiska Sandbox i Produkcji
-  Automatyczna zmiana statusu zamówienia po płatności
-  **Sprawdzanie statusu po powrocie** (rozwiązuje problem localhost/sandbox)
-  Tryb debug (logowanie)
-  Kompatybilność z Joomla 5/6

## Wymagania

- **Joomla 4.x / 5.x / 6.x**
- **HikaShop 4.x / 5.x**
- **PHP 8.1+**

## Instalacja

1. Pobierz paczkę ZIP
2. W panelu Joomla: **System  Rozszerzenia  Instaluj**
3. Wgraj plik ZIP
4. Przejdź do **Komponenty  HikaShop  Konfiguracja  Płatności**
5. Kliknij **Nowy** i wybierz **PayU**

## Konfiguracja

| Opcja | Opis |
|-------|------|
| POS ID | ID punktu płatności z PayU |
| SIGNATURE KEY | Klucz podpisu z PayU |
| OAUTH CLIENT ID | Client ID OAuth z PayU |
| OAUTH CLIENT SECRET | Client Secret OAuth z PayU |
| SANDBOX MODE | Tryb testowy (włącz dla testów) |
| DEBUG | Logowanie do pliku |
| RETURN URL | URL powrotu (opcjonalny) |
| CHECK STATUS ON RETURN | Sprawdza status płatności po powrocie (dla localhost) |
| INVALID STATUS | Status dla nieudanych płatności |
| VERIFIED STATUS | Status dla udanych płatności |

## Problem z localhost/sandbox

Na localhost notyfikacje PayU nie docierają (PayU nie może wysłać webhooków na adres lokalny). 

**Rozwiązanie**: Włącz opcję **"CHECK STATUS ON RETURN"** - wtyczka sprawdzi status płatności bezpośrednio w API PayU po powrocie użytkownika.

## Struktura plików (Joomla 5/6)

```
plg_payu/
 payu.php              # Legacy entry point
 payu.xml              # Manifest instalacyjny
 payu_end.php          # Szablon przekierowania
 index.html
 services/
    provider.php      # Service Provider (J5/6)
 src/
    Extension/
        Payu.php      # Główna klasa pluginu
 vendor/               # PayU SDK
```

## Changelog

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

Pablop76 - https://github.com/pablop76
