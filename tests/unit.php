<?php
/**
 * Testy jednostkowe: bez Joomli, bez bazy i bez sieci.
 *
 * Uruchomienie:
 *   php tests/unit.php
 *
 * Sprawdzają identyfikator zamówienia dla PayU, kwoty, podpis powiadomień,
 * zachowanie klienta REST przy różnych odpowiedziach PayU oraz dziennik.
 */

define('_JEXEC', 1);

spl_autoload_register(static function (string $klasa): void {
    $przedrostek = 'Pablop76\\Plugin\\HikashopPayment\\Payu\\';

    if (!str_starts_with($klasa, $przedrostek)) {
        return;
    }

    $sciezka = __DIR__ . '/../src/' . str_replace('\\', '/', substr($klasa, strlen($przedrostek))) . '.php';

    if (is_file($sciezka)) {
        require_once $sciezka;
    }
});

use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuApiException;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuRestClient;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\Amount;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\ExtOrderId;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\OrderPaymentData;

$zdane = 0;
$bledy = 0;

function wynik(string $opis, bool $ok, string $szczegol = ''): void
{
    global $zdane, $bledy;

    if ($ok) {
        $zdane++;
        echo '  OK   ' . $opis . PHP_EOL;

        return;
    }

    $bledy++;
    echo '  BLAD ' . $opis . ($szczegol !== '' ? ': ' . $szczegol : '') . PHP_EOL;
}

function naglowek(string $tytul): void
{
    echo PHP_EOL . $tytul . PHP_EOL;
}

/**
 * Atrapa klienta HTTP: oddaje przygotowane odpowiedzi i zapamiętuje żądania.
 */
final class HttpAtrapa
{
    /** @var list<array{metoda: string, adres: string, tresc: ?string, naglowki: array, limit: mixed}> */
    public array $zadania = [];

    /** @var list<array{0: int, 1: string}|Throwable> */
    public array $odpowiedzi = [];

    private function odpowiedz(string $metoda, string $adres, ?string $tresc, array $naglowki, $limit): object
    {
        $this->zadania[] = ['metoda' => $metoda, 'adres' => $adres, 'tresc' => $tresc, 'naglowki' => $naglowki, 'limit' => $limit];

        $nastepna = array_shift($this->odpowiedzi);

        if ($nastepna instanceof Throwable) {
            throw $nastepna;
        }

        return (object) ['code' => $nastepna[0] ?? 500, 'body' => $nastepna[1] ?? ''];
    }

    public function post($url, $data, array $headers = [], $timeout = null)
    {
        return $this->odpowiedz('POST', (string) $url, (string) $data, $headers, $timeout);
    }

    public function get($url, array $headers = [], $timeout = null)
    {
        return $this->odpowiedz('GET', (string) $url, null, $headers, $timeout);
    }

    public function delete($url, array $headers = [], $timeout = null, $data = null)
    {
        return $this->odpowiedz('DELETE', (string) $url, null, $headers, $timeout);
    }
}

const TOKEN_OK = [200, '{"access_token":"token-testowy-123456","token_type":"bearer","expires_in":43199}'];

function klient(HttpAtrapa $http, bool $sandbox = true): PayuRestClient
{
    return new PayuRestClient($sandbox, '300746', 'klucz-podpisu-testowy', '300746', 'haslo-oauth-testowe', $http);
}

// --- 1. Identyfikator zamówienia dla PayU ---------------------------------

naglowek('1. Identyfikator zamówienia dla PayU (extOrderId)');

$klucz = 'klucz-podpisu-testowy';
$a     = ExtOrderId::generate(1234, $klucz);
$b     = ExtOrderId::generate(1234, $klucz);

wynik('dwie próby zapłaty tego samego zamówienia mają różne identyfikatory', $a !== $b, $a . ' / ' . $b);
wynik('identyfikator zaczyna się od numeru zamówienia', str_starts_with($a, '1234_'));
wynik('numer zamówienia daje się odczytać', ExtOrderId::orderIdOf($a) === 1234);
wynik('identyfikator przechodzi sprawdzenie właściwym kluczem', ExtOrderId::isValid($a, $klucz));
wynik('identyfikator nie przechodzi sprawdzenia innym kluczem', !ExtOrderId::isValid($a, 'inny-klucz'));
wynik('pusty klucz niczego nie potwierdza', !ExtOrderId::isValid(ExtOrderId::generate(5, ''), ''));

$podmieniony = preg_replace('/^1234_/', '9999_', $a);
wynik('podmiana numeru zamówienia unieważnia identyfikator', !ExtOrderId::isValid($podmieniony, $klucz));

$stary = '77_' . hash_hmac('sha256', '77', $klucz);
wynik('identyfikator sprzed 2.4.0 jest nadal przyjmowany', ExtOrderId::isValid($stary, $klucz) && ExtOrderId::orderIdOf($stary) === 77);
wynik('identyfikator sprzed 2.4.0 z innym kluczem jest odrzucany', !ExtOrderId::isValid($stary, 'inny-klucz'));
wynik('śmieci nie są identyfikatorem', !ExtOrderId::isValid('abc', $klucz) && ExtOrderId::orderIdOf('abc') === 0 && !ExtOrderId::isValid('', $klucz));
wynik('identyfikator ma stały dopisek, gdy go podano', ExtOrderId::generate(8, $klucz, 'aabbcc') === ExtOrderId::generate(8, $klucz, 'aabbcc'));

// --- 2. Kwoty --------------------------------------------------------------

naglowek('2. Kwoty');

wynik('19,99 to 1999', Amount::toMinor(19.99) === 1999);
wynik('kwota z bazy jako tekst', Amount::toMinor('12.34000') === 1234);
wynik('10,005 zaokrągla się do 1001', Amount::toMinor('10.00500') === 1001);
wynik('pełne złote', Amount::toMinor(100) === 10000);
wynik('zero i śmieci dają zero', Amount::toMinor(0) === 0 && Amount::toMinor('') === 0);
wynik('0,1 + 0,2 nie gubi grosza', Amount::toMinor(0.1 + 0.2) === 30);

// --- 3. Podpis powiadomienia ----------------------------------------------

naglowek('3. Podpis powiadomienia');

$http   = new HttpAtrapa();
$klient = klient($http);
$tresc  = '{"order":{"orderId":"ABC","status":"COMPLETED"}}';
$md5    = 'sender=checkout;signature=' . md5($tresc . $klucz) . ';algorithm=MD5;content=DOCUMENT';
$sha    = 'signature=' . hash('sha256', $tresc . $klucz) . ';algorithm=SHA-256';

wynik('poprawny podpis MD5 jest przyjmowany', $klient->verifyNotificationSignature($tresc, $md5));
wynik('poprawny podpis SHA-256 jest przyjmowany', $klient->verifyNotificationSignature($tresc, $sha));
wynik('podpis zapisany wielkimi literami jest przyjmowany', $klient->verifyNotificationSignature($tresc, 'signature=' . strtoupper(md5($tresc . $klucz)) . ';algorithm=MD5'));
wynik('zmieniona treść unieważnia podpis', !$klient->verifyNotificationSignature($tresc . ' ', $md5));
wynik('podpis innym kluczem jest odrzucany', !$klient->verifyNotificationSignature($tresc, 'signature=' . md5($tresc . 'inny') . ';algorithm=MD5'));
wynik('brak nagłówka podpisu jest odrzucany', !$klient->verifyNotificationSignature($tresc, null) && !$klient->verifyNotificationSignature($tresc, ''));
wynik('nagłówek bez algorytmu jest odrzucany', !$klient->verifyNotificationSignature($tresc, 'signature=' . md5($tresc . $klucz)));

$bezKlucza = new PayuRestClient(true, '1', '', '1', 'x', $http);
wynik('bez klucza podpisu nic nie jest przyjmowane', !$bezKlucza->verifyNotificationSignature($tresc, 'signature=' . md5($tresc) . ';algorithm=MD5'));
wynik('żadne z tych sprawdzeń nie łączy się z siecią', $http->zadania === []);

// --- 4. Klient REST --------------------------------------------------------

naglowek('4. Klient REST: tworzenie, odczyt i anulowanie zamówienia');

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [302, '{"status":{"statusCode":"SUCCESS"},"redirectUri":"https://secure.snd.payu.com/pay/?orderId=X1","orderId":"X1","extOrderId":"e1"}']];
[$id, $adres] = klient($http)->createOrder(['extOrderId' => 'e1', 'totalAmount' => 1000]);

wynik('utworzone zamówienie zwraca identyfikator i adres strony płatności', $id === 'X1' && $adres === 'https://secure.snd.payu.com/pay/?orderId=X1');
wynik('sandbox woła adres testowy PayU', str_starts_with($http->zadania[1]['adres'], 'https://secure.snd.payu.com/api/v2_1/orders'));
wynik('żądanie niesie numer punktu płatności', str_contains((string) $http->zadania[1]['tresc'], '"merchantPosId":"300746"'));
wynik('żądanie niesie token dostępu', ($http->zadania[1]['naglowki']['Authorization'] ?? '') === 'Bearer token-testowy-123456');
wynik('każde żądanie ma limit czasu', $http->zadania[0]['limit'] > 0 && $http->zadania[1]['limit'] > 0);

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [200, '{"orders":[{"orderId":"X1","status":"NEW"}]}']];
klient($http, false)->retrieveOrder('X1');
wynik('tryb produkcyjny woła adres produkcyjny PayU', str_starts_with($http->zadania[1]['adres'], 'https://secure.payu.com/api/v2_1/orders/X1'));

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [400, '{"status":{"statusCode":"ERROR_ORDER_NOT_UNIQUE","code":"110","codeLiteral":"ORDER_NOT_UNIQUE","statusDesc":"Order with given extOrderId already exists"}}']];

try {
    klient($http)->createOrder(['extOrderId' => 'e1']);
    wynik('powtórzony identyfikator kończy się wyjątkiem', false);
} catch (PayuApiException $wyjatek) {
    wynik('powtórzony identyfikator kończy się wyjątkiem', $wyjatek->getStatusCode() === 'ERROR_ORDER_NOT_UNIQUE' && $wyjatek->getHttpStatus() === 400);
    wynik('odmowa PayU to nie błąd danych dostępowych', !$wyjatek->isAuthenticationFailure());
}

$http = new HttpAtrapa();
$http->odpowiedzi = [[401, '{"error":"invalid_client","error_description":"Bad client credentials"}']];

try {
    klient($http)->createOrder(['extOrderId' => 'e2']);
    wynik('złe dane OAuth kończą się wyjątkiem', false);
} catch (PayuApiException $wyjatek) {
    wynik('złe dane OAuth kończą się wyjątkiem', $wyjatek->isAuthenticationFailure());
    wynik('po odmowie tokenu zamówienie nie jest wysyłane', count($http->zadania) === 1);
}

$http = new HttpAtrapa();
$http->odpowiedzi = [new RuntimeException('Connection timed out')];

try {
    klient($http)->retrieveOrder('X1');
    wynik('brak odpowiedzi PayU kończy się wyjątkiem wtyczki', false);
} catch (PayuApiException $wyjatek) {
    wynik('brak odpowiedzi PayU kończy się wyjątkiem wtyczki', $wyjatek->getHttpStatus() === 0 && str_contains($wyjatek->getMessage(), 'timed out'));
}

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [302, '{"status":{"statusCode":"SUCCESS"},"redirectUri":"https://x"}']];

try {
    klient($http)->createOrder(['extOrderId' => 'e3']);
    wynik('odpowiedź bez identyfikatora zamówienia PayU kończy się wyjątkiem', false);
} catch (PayuApiException $wyjatek) {
    wynik('odpowiedź bez identyfikatora zamówienia PayU kończy się wyjątkiem', true);
}

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [200, '{"orders":[{"orderId":"X1","status":"COMPLETED","totalAmount":"1234","currencyCode":"PLN"}],"status":{"statusCode":"SUCCESS"}}']];
$dane = klient($http)->retrieveOrder('X1');
wynik('odczyt zamówienia zwraca stan, kwotę i walutę', ($dane['status'] ?? '') === 'COMPLETED' && ($dane['totalAmount'] ?? '') === '1234' && ($dane['currencyCode'] ?? '') === 'PLN');
wynik('odczyt idzie metodą GET pod adres zamówienia', $http->zadania[1]['metoda'] === 'GET' && str_ends_with($http->zadania[1]['adres'], '/orders/X1'));

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [404, '{"status":{"statusCode":"DATA_NOT_FOUND"}}']];
wynik('nieznane zamówienie to null, nie wyjątek', klient($http)->retrieveOrder('NIEMA') === null);

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [500, 'Internal Server Error']];

try {
    klient($http)->retrieveOrder('X1');
    wynik('błąd serwera PayU przy odczycie kończy się wyjątkiem', false);
} catch (PayuApiException $wyjatek) {
    wynik('błąd serwera PayU przy odczycie kończy się wyjątkiem', $wyjatek->getHttpStatus() === 500);
}

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [200, '{"orderId":"X1","status":{"statusCode":"SUCCESS"}}']];
klient($http)->cancelOrder('X1');
wynik('anulowanie idzie metodą DELETE pod adres zamówienia', $http->zadania[1]['metoda'] === 'DELETE' && str_ends_with($http->zadania[1]['adres'], '/orders/X1'));

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [404, '{"status":{"statusCode":"DATA_NOT_FOUND"}}']];
$bezWyjatku = true;

try {
    klient($http)->cancelOrder('NIEMA');
} catch (Throwable $wyjatek) {
    $bezWyjatku = false;
}

wynik('anulowanie nieznanego zamówienia nie jest błędem', $bezWyjatku);

$http = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [500, '']];

try {
    klient($http)->cancelOrder('X1');
    wynik('nieudane anulowanie kończy się wyjątkiem', false);
} catch (PayuApiException $wyjatek) {
    wynik('nieudane anulowanie kończy się wyjątkiem', true);
}

$http   = new HttpAtrapa();
$http->odpowiedzi = [TOKEN_OK, [200, '{"orders":[{"status":"NEW"}]}'], [200, '{"orders":[{"status":"NEW"}]}']];
$jeden  = klient($http);
$jeden->retrieveOrder('A');
$jeden->retrieveOrder('B');
wynik('token dostępu jest pobierany raz na żądanie strony', count(array_filter($http->zadania, static fn ($z) => str_contains($z['adres'], 'oauth'))) === 1);

// --- 5. Dane płatności przy zamówieniu ------------------------------------

naglowek('5. Dane płatności przy zamówieniu');

$zamowienie = (object) ['order_payment_params' => serialize((object) [
    'payu_order_id' => 'C3',
    'payu_orders'   => ['A1', 'B2', 'C3'],
    'payu_cancelled' => ['A1'],
])];

wynik('próby zapłaty są zwracane od najnowszej', OrderPaymentData::attemptsOf($zamowienie) === ['C3', 'B2', 'A1']);
wynik('lista anulowanych prób daje się odczytać', OrderPaymentData::getList($zamowienie, OrderPaymentData::CANCELLED) === ['A1']);

$stare = (object) ['order_payment_params' => (object) ['payu_order_id' => 'STARE1']];
wynik('zamówienie sprzed 2.4.0 ma jedną próbę', OrderPaymentData::attemptsOf($stare) === ['STARE1']);
wynik('zamówienie bez danych płatności nie ma prób', OrderPaymentData::attemptsOf((object) []) === [] && OrderPaymentData::attemptsOf(null) === []);
wynik('uszkodzone dane nie wywracają odczytu', OrderPaymentData::attemptsOf((object) ['order_payment_params' => 'to nie jest serialize']) === []);

// --- 6. Dziennik -----------------------------------------------------------

naglowek('6. Dziennik');

$wiersze = [];
$dziennik = new Logger('payu', false, ['tajny-klucz-podpisu', 'ab'], static function (string $w) use (&$wiersze): void {
    $wiersze[] = $w;
});

$dziennik->info('przebieg', ['a' => 1]);
$dziennik->error('klucz tajny-klucz-podpisu wyciekł', ['order_id' => 5, 'klucz' => 'tajny-klucz-podpisu']);
$dziennik->warning('ostrzeżenie');

wynik('przy wyłączonym debugowaniu przebieg nie jest zapisywany', count($wiersze) === 2);
wynik('błędy są zapisywane zawsze', str_contains($wiersze[0] ?? '', 'PayU [BŁĄD]') && str_contains($wiersze[0] ?? '', 'order_id=5'));
wynik('sekrety są wymazywane z wpisów', !str_contains(implode(' ', $wiersze), 'tajny-klucz-podpisu') && str_contains($wiersze[0] ?? '', '***'));
wynik('krótkie ciągi nie są wymazywane (nie tną komunikatu)', str_contains($wiersze[0] ?? '', 'tajny') === false && str_contains($wiersze[1] ?? '', 'ostrzeżenie'));

$wiersze  = [];
$dziennik = new Logger('payu', true, [], static function (string $w) use (&$wiersze): void {
    $wiersze[] = $w;
});
$dziennik->info('przebieg', ['a' => 1]);
wynik('przy włączonym debugowaniu przebieg jest zapisywany', count($wiersze) === 1 && str_contains($wiersze[0], 'a=1'));

// --- 7. Pliki paczki -------------------------------------------------------

naglowek('7. Pliki paczki');

$manifest = simplexml_load_file(__DIR__ . '/../payu.xml');
$wersja   = trim((string) $manifest->version);
$nazwa    = trim((string) $manifest->name);

wynik('nazwa w manifeście jest dosłowna, nie kluczem językowym', $nazwa !== '' && !preg_match('/^[A-Z0-9_]+$/', $nazwa), $nazwa);

$pliki = ['payu.php', 'payu_end.php', 'script.php', 'services/provider.php'];

$katalogSrc = (string) realpath(__DIR__ . '/../src');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($katalogSrc, FilesystemIterator::SKIP_DOTS)) as $plik) {
    $pliki[] = 'src/' . str_replace('\\', '/', substr((string) $plik, strlen($katalogSrc) + 1));
}

$bezWersji = [];
$zBom      = [];

foreach ($pliki as $plik) {
    $poczatek = (string) file_get_contents(__DIR__ . '/../' . $plik, false, null, 0, 400);

    if (!str_contains($poczatek, '@version     ' . $wersja)) {
        $bezWersji[] = $plik;
    }

    if (str_starts_with($poczatek, "\xEF\xBB\xBF")) {
        $zBom[] = $plik;
    }
}

wynik('wszystkie pliki PHP mają wersję z manifestu', $bezWersji === [], implode(', ', $bezWersji));
wynik('żaden plik PHP nie zaczyna się od znaku BOM', $zBom === [], implode(', ', $zBom));

$kod = '';

foreach (array_merge($pliki, ['payu_end.php']) as $plik) {
    $kod .= (string) file_get_contents(__DIR__ . '/../' . $plik);
}

preg_match_all('/PLG_HIKASHOPPAYMENT_PAYU_[A-Z0-9_]*[A-Z0-9]/', $kod, $trafienia);

foreach (['pl-PL', 'en-GB'] as $jezyk) {
    $ini   = (string) file_get_contents(__DIR__ . '/../language/' . $jezyk . '/plg_hikashoppayment_payu.ini');
    $braki = [];

    foreach (array_unique($trafienia[0]) as $klucz) {
        if (!preg_match('/^' . preg_quote($klucz, '/') . '=/m', $ini)) {
            $braki[] = $klucz;
        }
    }

    wynik('plik ' . $jezyk . ' ma wszystkie teksty używane w kodzie', $braki === [], implode(', ', $braki));
}

$staryDziennik = 'administrator/logs/payu_debug.log';
wynik('kod wtyczki nie pisze już do ' . $staryDziennik, !str_contains((string) file_get_contents(__DIR__ . '/../src/Extension/Payu.php'), 'payu_debug'));

// --- podsumowanie ---------------------------------------------------------

echo PHP_EOL . 'Zdane: ' . $zdane . ', błędy: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
