# PayU Polska - HikaShop Payment Plugin

Wtyczka płatności PayU Polska dla HikaShop (Joomla 5/6).

## Funkcje

- ✅ Integracja z PayU Polska przez **REST API** (OAuth)
- ✅ Obsługa środowiska Sandbox i Produkcji
- ✅ Automatyczna zmiana statusu zamówienia po płatności (powiadomienie z weryfikacją podpisu, kwoty i waluty)
- ✅ Sprawdzenie wyniku płatności także przy powrocie klienta, gdy powiadomienie nie dotrze
- ✅ Ponowna zapłata za to samo zamówienie („Zapłać teraz” i przycisk wtyczki)
- ✅ Powiadomienia email przy zmianie statusu
- ✅ Strona po udanej płatności wybierana z listy pozycji menu Joomla (lub własny URL)
- ✅ Automatyczne aktualizacje (serwer aktualizacji Joomla)
- ✅ Tryb debug (dziennik płatności HikaShopa)
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
| Debug               | Przebieg płatności w dzienniku płatności HikaShopa       |
| Strona powrotu      | Pozycja menu Joomla, na którą klient trafia po potwierdzonej płatności |
| Własny URL powrotu  | Opcjonalny pełny adres — nadpisuje wybraną stronę menu   |
| Invalid Status      | Status zamówienia dla nieudanej płatności                |
| Verified Status     | Status zamówienia dla udanej płatności                   |

Dane konfiguracyjne (POS ID, Signature Key, OAuth) znajdziesz w panelu PayU —
zobacz [developers.payu.com](https://developers.payu.com/europe/pl/).

## Jak wtyczka rozlicza płatność

Zapłatę potwierdza wyłącznie PayU: podpisane powiadomienie albo odpowiedź na pytanie zadane z serwera sklepu. Sam powrót klienta ze strony płatności niczego nie potwierdza.

- **Każda próba zapłaty to osobne zamówienie PayU** z własnym identyfikatorem (`extOrderId`). PayU nie pozwala użyć tego samego identyfikatora ponownie po anulowanej albo wygasłej płatności, więc bez tego „Zapłać teraz” nie mogłoby działać.
- **Przed nową próbą** wtyczka pyta PayU o wcześniejsze. Opłaconą potwierdza od razu, a niedokończone anuluje. Wpłata, która dotrze po anulowaniu, wraca z PayU do płacącego, więc za jedno zamówienie nie da się zapłacić dwa razy.
- **Po powrocie klienta** wtyczka pyta PayU o stan płatności. Podziękowanie pokazuje dopiero po potwierdzonej zapłacie. Gdy płatność jest jeszcze w toku, strona czeka i sama sprawdza wynik. Gdy się nie powiodła, klient czyta to wprost i dostaje przycisk ponowienia.
- **Kwota i waluta** wpłaty muszą zgadzać się z zamówieniem. Przy niezgodności zamówienie nie jest oznaczane jako opłacone, a sprzedawca dostaje wiadomość na adres powiadomień o płatnościach z konfiguracji HikaShopa.
- **Spóźnione powiadomienia** nie psują zamówienia: anulowanie albo wygaśnięcie dawnej próby nie zmienia statusu zamówienia opłaconego, wysłanego, opłacanego właśnie kolejną próbą ani takiego, którego metodę płatności klient zmienił. Powtórzone powiadomienie o zapłacie nie wysyła drugiej wiadomości.
- **Kilka metod PayU w sklepie** (na przykład testowa i produkcyjna): podpis powiadomienia jest sprawdzany kluczem tej metody, którą założono płatność.
- **Stan `WAITING_FOR_CONFIRMATION`** oznacza punkt płatności bez automatycznego odbioru. Wtyczka nie potwierdza wtedy zamówienia i zawiadamia sprzedawcę, że płatność czeka na przyjęcie w panelu PayU.

### Dziennik

Błędy zapisują się zawsze, przebieg płatności po włączeniu opcji **Debug**. Wpisy trafiają do dziennika płatności HikaShopa (**Konfiguracja → Główne → „Plik dziennika płatności”**), w wierszach zaczynających się od `PayU`.

Wersje do 2.3.1 pisały do `administrator/logs/payu_debug.log`. Był to zwykły plik pod znaną nazwą, w katalogu, który bywa dostępny z sieci, z danymi kupujących w środku. Przy aktualizacji wtyczka przenosi jego treść do `administrator/logs/payu_debug.php` (plik zaczyna się od `die()`, więc nie da się go pobrać przeglądarką) i usuwa oryginał.

## Automatyczne aktualizacje

Wtyczka zgłasza się do serwera aktualizacji Joomla — nowe wersje pojawiają się w
**System → Aktualizacje → Rozszerzenia**.

## Struktura plików (Joomla 5/6)

```
plg_payu/
├── payu.php              # Legacy entry point
├── payu.xml              # Manifest instalacyjny + updateservers
├── payu_end.php          # Strony dla klienta: przejście, oczekiwanie, brak zapłaty
├── script.php            # Skrypt instalacyjny (walidacja wymagań)
├── index.html
├── language/             # Pliki językowe (en-GB, pl-PL)
├── services/
│   └── provider.php      # Service Provider (J5/6)
├── src/
│   ├── Extension/
│   │   └── Payu.php                # Główna klasa pluginu
│   ├── Client/
│   │   ├── PayuRestClient.php      # Klient REST API PayU (Joomla HTTP)
│   │   ├── PayuClientInterface.php
│   │   └── PayuApiException.php
│   └── Payment/
│       ├── ExtOrderId.php          # Identyfikator próby zapłaty dla PayU
│       ├── OrderPaymentData.php    # Dane płatności zapisane przy zamówieniu
│       ├── Amount.php
│       └── Logger.php
├── tests/                # Testy (poza paczką)
└── build.php             # Budowanie paczki (poza paczką)
```

## Budowanie paczki i testy

```
php -d extension=zip build.php
php tests/unit.php
php tests/integration.php
php tests/sandbox.php
```

- `tests/unit.php` nie potrzebuje Joomli ani sieci.
- `tests/integration.php` chodzi po prawdziwej instalacji Joomli z HikaShopem (ścieżka w zmiennej `JOOMLA_PATH`), z wtyczką zainstalowaną z paczki. PayU jest podstawione atrapą. Test zakłada niepublikowane metody płatności, fikcyjnego klienta i zamówienia bez pozycji, a na końcu je kasuje. Nie wysyła poczty.
- `tests/sandbox.php` łączy się z sandboksem PayU przez publiczny testowy punkt płatności z dokumentacji PayU. Zakłada dwa zamówienia testowe i od razu je anuluje.

## Changelog

### v2.4.0 (2026-10-10)

- **Ponowna zapłata działa:** każda próba ma własny identyfikator dla PayU; wcześniejsza niedokończona próba jest anulowana, opłacona potwierdzana
- **Nieudana płatność nie wygląda jak udana:** adres powrotu prowadzi do wtyczki, która pyta PayU o stan; podziękowanie dopiero po potwierdzonej zapłacie
- **Spóźnione powiadomienie nie cofa statusu** zamówienia opłaconego albo wysłanego; powtórzone powiadomienia są rozpoznawane
- **Kontrola kwoty i waluty** wpłaty; wiadomość do sprzedawcy przy niezgodności i przy płatności czekającej na ręczne przyjęcie
- **Fix:** waluta zamówienia zamiast zawsze PLN
- **Fix:** e-mail i telefon kupującego trafiają do PayU
- **Fix:** podpis powiadomienia sprawdzany kluczem metody płatności z zamówienia, nie pierwszej metody PayU w bazie
- **Fix:** wtyczka nie przerywa już kroku kasy przekierowaniem (koszyk opróżnia się jak przy innych metodach)
- Dziennik w dzienniku płatności HikaShopa zamiast `administrator/logs/payu_debug.log`; stary plik jest zabezpieczany przy aktualizacji
- Dosłowna nazwa wtyczki w manifeście, plik startowy bez znaku BOM, limit czasu połączeń z PayU
- Testy: jednostkowe, integracyjne i na sandboksie PayU

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
