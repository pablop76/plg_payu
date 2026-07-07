# PayU Polska - HikaShop Payment Plugin

Wtyczka płatności PayU Polska dla HikaShop (Joomla 5/6).

## Funkcje

- ✅ Integracja z PayU Polska przez **REST API** (OAuth)
- ✅ Obsługa środowiska Sandbox i Produkcji
- ✅ Automatyczna zmiana statusu zamówienia po płatności (webhook z weryfikacją podpisu)
- ✅ Powiadomienia email przy zmianie statusu
- ✅ Strona powrotu wybierana z listy pozycji menu Joomla (lub własny URL)
- ✅ Automatyczne aktualizacje (serwer aktualizacji Joomla)
- ✅ Tryb debug (logowanie)
- ✅ Kompatybilność z Joomla 5/6, gotowe na PHP 8.4

## Wymagania

- **Joomla 5.x / 6.x**
- **HikaShop 5.x**
- **PHP 8.1+**

## Instalacja

1. Pobierz paczkę `plg_payu-X.Y.Z.zip` z sekcji **Releases** (lub zbuduj ZIP z repozytorium)
2. W panelu Joomla: **System → Rozszerzenia → Instaluj**
3. Wgraj plik ZIP
4. Przejdź do **Komponenty → HikaShop → Konfiguracja → Płatności**
5. Kliknij **Nowy** i wybierz **PayU**

## Konfiguracja

| Opcja               | Opis                                                     |
| ------------------- | -------------------------------------------------------- |
| POS ID              | ID punktu płatności z PayU                               |
| Signature Key       | Klucz podpisu z PayU (weryfikacja notyfikacji)           |
| OAuth Client ID     | Client ID OAuth z PayU                                   |
| OAuth Client Secret | Client Secret OAuth z PayU                               |
| Sandbox Mode        | Tryb testowy (włącz dla testów)                          |
| Debug               | Logowanie do `administrator/logs/payu_debug.log`         |
| Strona powrotu      | Pozycja menu Joomla, na którą wraca klient po płatności  |
| Własny URL powrotu  | Opcjonalny pełny adres — nadpisuje wybraną stronę menu   |
| Invalid Status      | Status zamówienia dla nieudanej płatności                |
| Verified Status     | Status zamówienia dla udanej płatności                   |

Dane konfiguracyjne (POS ID, Signature Key, OAuth) znajdziesz w panelu PayU —
zobacz [developers.payu.com](https://developers.payu.com/europe/pl/).

## Automatyczne aktualizacje

Wtyczka zgłasza się do serwera aktualizacji Joomla — nowe wersje pojawiają się w
**System → Aktualizacje → Rozszerzenia**.

## Struktura plików (Joomla 5/6)

```
plg_payu/
├── payu.php              # Legacy entry point
├── payu.xml              # Manifest instalacyjny + updateservers
├── payu_end.php          # Szablon przekierowania
├── script.php            # Skrypt instalacyjny (walidacja wymagań)
├── index.html
├── language/             # Pliki językowe (en-GB, pl-PL)
├── services/
│   └── provider.php      # Service Provider (J5/6)
└── src/
    ├── Extension/
    │   └── Payu.php            # Główna klasa pluginu
    └── Client/
        └── PayuRestClient.php  # Klient REST API PayU (Joomla HTTP)
```

## Changelog

### v2.3.0 (2026-07-07)

- **Własny klient REST** (`PayuRestClient` oparty o `Joomla\CMS\Http`) zamiast biblioteki `openpayu` — usunięta zależność `vendor/`
- Bezpośrednia integracja z **REST API PayU v2.1**: OAuth, OrderCreate, OrderRetrieve, weryfikacja podpisu notyfikacji
- Lżejsza paczka, gotowe na PHP 8.4

### v2.2.4 (2026-07-07)

- **Strona powrotu jako lista pozycji menu Joomla** + opcjonalny własny URL (nadpisuje wybór)

### v2.2.3 (2026-07-07)

- **Fix:** `Class "hikashopPaymentPlugin" not found` podczas auto-aktualizacji — guard ładujący HikaShop `helper.php` przed deklaracją klasy

### v2.2.2 (2026-07-07)

- **Fix:** względny Return URL (np. `/zamowienia`) budowany jest do pełnego adresu `https`

### v2.2.1 (2026-07-07)

- **Fix:** `ERROR_VALUE_INVALID - Invalid continueUrl` — wymuszenie `https` na continueUrl/notifyUrl + diagnostyka w logu

### v2.2.0 (2026-01-18)

- **Uproszczona konfiguracja return_url** - jedno pole (puste = domyślna strona HikaShop, wartość = własny URL)
- **Elastyczne URL-e** - wszystkie adresy dynamiczne (HIKASHOP_LIVE, checkout_itemid z konfiguracji)
- **Fix redirect po płatności** - poprawne przekierowanie z Itemid
- **Link do zamówienia** - wyświetlany tylko dla zalogowanych użytkowników
- **Komunikaty po płatności** - wyświetlane przy własnym return_url

### v2.1.0 (2026-01-17)

- Refaktoryzacja dla Joomla 5/6
- Dodano `script.php` z walidacją wymagań
- Poprawiono wysyłanie emaili przy zmianie statusu
- Nowoczesna konfiguracja XML z fieldsets
- Pełna dokumentacja PHPDoc
- Typowanie PHP 8.1+

### v2.0.0 (2026-01-14)

- Pełna kompatybilność z Joomla 5/6
- Namespace `Pablop76\Plugin\HikashopPayment\Payu`
- Dodano `services/provider.php`
- Zamieniono przestarzałe klasy JFactory, JText
- Wymagane PHP 8.1+

### v1.0.0 (2025-10-15)

- Pierwsza wersja

## Licencja

GNU/GPL v2

## Autor

Paweł Półtoraczyk - [web-service.com.pl](https://web-service.com.pl)
