<?php
/**
 * Test klienta REST na żywym sandboksie PayU.
 *
 * Uruchomienie:
 *   php tests/sandbox.php
 *
 * Używa publicznego testowego punktu płatności z dokumentacji PayU (POS 300746),
 * więc nie potrzebuje żadnych danych dostępowych. Zakłada w sandboksie dwa zamówienia
 * z fikcyjnymi danymi i od razu je anuluje. Nic nie jest opłacane.
 *
 * Sprawdza to, czego atrapa nie udowodni: że PayU naprawdę odrzuca powtórzony
 * extOrderId (stąd osobny identyfikator dla każdej próby zapłaty), że przyjmuje dane
 * kupującego w postaci wysyłanej przez wtyczkę i że anulowanie działa.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuApiException;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuRestClient;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\ExtOrderId;

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

// Publiczny testowy punkt płatności z dokumentacji PayU.
$klucz  = 'b6ca15b0d1020e8094d9b5f8d163db54';
$klient = new PayuRestClient(true, '300746', $klucz, '300746', '2ee86a66e5d97e3fadc400c9f19b065d');

$numer = random_int(900000, 999999);

$zamowienie = static function (string $extOrderId): array {
    return [
        'continueUrl'  => 'https://example.com/powrot',
        'notifyUrl'    => 'https://example.com/powiadomienie',
        'customerIp'   => '127.0.0.1',
        'description'  => 'Test wtyczki HikaShop',
        'currencyCode' => 'PLN',
        'totalAmount'  => 1234,
        'extOrderId'   => $extOrderId,
        'validityTime' => 600,
        'products'     => [['name' => 'Produkt testowy', 'unitPrice' => 1234, 'quantity' => 1]],
        'buyer'        => ['email' => 'jan.testowy@example.com', 'phone' => '600100200', 'firstName' => 'Jan', 'lastName' => 'Testowy', 'language' => 'pl'],
    ];
};

$doAnulowania = [];

register_shutdown_function(static function () use ($klient, &$doAnulowania): void {
    foreach ($doAnulowania as $id) {
        try {
            $klient->cancelOrder($id);
        } catch (Throwable $blad) {
            // sandbox sam anuluje zamówienie po upływie ważności
        }
    }
});

echo 'Sandbox PayU, zamówienie sklepu nr ' . $numer . PHP_EOL . PHP_EOL;

try {
    $pierwszy = ExtOrderId::generate($numer, $klucz);
    [$idA, $adresA] = $klient->createOrder($zamowienie($pierwszy));
    $doAnulowania[] = $idA;

    wynik('pierwsza próba zapłaty zostaje założona', $idA !== '' && str_starts_with($adresA, 'https://'), $idA);

    // Powtórzony extOrderId nigdy nie daje drugiego zamówienia PayU. Dopóki pierwsze
    // jest otwarte, PayU oddaje je z powrotem albo odmawia; nowego nie zakłada.
    try {
        [$powtorzone] = $klient->createOrder($zamowienie($pierwszy));
        wynik('powtórzony identyfikator przy otwartej płatności nie zakłada drugiego zamówienia PayU', $powtorzone === $idA, $powtorzone);
    } catch (PayuApiException $wyjatek) {
        wynik('powtórzony identyfikator przy otwartej płatności nie zakłada drugiego zamówienia PayU', $wyjatek->getStatusCode() === 'ERROR_ORDER_NOT_UNIQUE', $wyjatek->getMessage());
    }

    $drugi = ExtOrderId::generate($numer, $klucz);
    [$idB] = $klient->createOrder($zamowienie($drugi));
    $doAnulowania[] = $idB;

    wynik('druga próba za to samo zamówienie sklepu przechodzi z nowym identyfikatorem', $idB !== '' && $idB !== $idA, $idB);

    $dane = $klient->retrieveOrder($idA);
    wynik('odczyt zamówienia zwraca stan, kwotę i walutę', ($dane['status'] ?? '') === 'NEW' && (int) ($dane['totalAmount'] ?? 0) === 1234 && ($dane['currencyCode'] ?? '') === 'PLN', json_encode($dane));
    wynik('odczyt oddaje nasz identyfikator zamówienia', ($dane['extOrderId'] ?? '') === $pierwszy && ExtOrderId::orderIdOf((string) ($dane['extOrderId'] ?? '')) === $numer);

    $klient->cancelOrder($idA);
    $dane = $klient->retrieveOrder($idA);
    wynik('anulowane zamówienie ma stan CANCELED', ($dane['status'] ?? '') === 'CANCELED', (string) ($dane['status'] ?? ''));

    // Sedno sprawy: po anulowanej albo wygasłej płatności tego samego identyfikatora
    // nie da się użyć ponownie. Dlatego każda próba zapłaty musi mieć własny.
    try {
        $klient->createOrder($zamowienie($pierwszy));
        wynik('po anulowanej płatności PayU odrzuca ten sam identyfikator', false, 'przyjęło');
    } catch (PayuApiException $wyjatek) {
        wynik('po anulowanej płatności PayU odrzuca ten sam identyfikator', $wyjatek->getStatusCode() === 'ERROR_ORDER_NOT_UNIQUE', $wyjatek->getMessage());
    }

    $bezBledu = true;

    try {
        $klient->cancelOrder($idA);
    } catch (Throwable $blad) {
        $bezBledu = false;
    }

    wynik('ponowne anulowanie nie jest błędem', $bezBledu);

    $klient->cancelOrder($idB);
    wynik('druga próba też daje się anulować', ($klient->retrieveOrder($idB)['status'] ?? '') === 'CANCELED');

    wynik('nieznane zamówienie to brak danych, nie wyjątek', $klient->retrieveOrder('NIEMA' . $numer) === null);

    $zlyKlient = new PayuRestClient(true, '300746', $klucz, '300746', 'zle-haslo');

    try {
        $zlyKlient->retrieveOrder($idA);
        wynik('złe hasło OAuth jest rozpoznawane jako błąd danych dostępowych', false);
    } catch (PayuApiException $wyjatek) {
        wynik('złe hasło OAuth jest rozpoznawane jako błąd danych dostępowych', $wyjatek->isAuthenticationFailure(), 'HTTP ' . $wyjatek->getHttpStatus() . ' ' . $wyjatek->getMessage());
    }
} catch (Throwable $blad) {
    wynik('sandbox PayU odpowiada', false, get_class($blad) . ': ' . $blad->getMessage());
}

echo PHP_EOL . 'Zdane: ' . $zdane . ', błędy: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
