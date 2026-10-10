<?php
/**
 * Testy wtyczki PayU na zainstalowanej Joomli z HikaShopem.
 *
 * Uruchomienie:
 *   php tests/integration.php
 *
 * Co jest prawdziwe: baza sklepu, zamówienia, metody płatności, podpisy powiadomień
 * i zmiana statusu zamówienia przez HikaShopa. Co jest podstawione: PayU (atrapa
 * trzymająca się zasad potwierdzonych na sandboksie, w tym odrzucania powtórzonego
 * extOrderId), przekierowania i wysyłka wiadomości.
 *
 * Dane testowe (trzy niepublikowane metody płatności, klient, adres, zamówienia bez
 * pozycji) są fikcyjne i kasowane na końcu, także przy błędzie.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuApiException;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuClientInterface;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuRestClient;
use Pablop76\Plugin\HikashopPayment\Payu\Extension\Payu;
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
 * Atrapa PayU.
 *
 * Trzyma się zachowań potwierdzonych na publicznym sandboksie PayU 10.10.2026:
 * nowe zamówienie ma stan NEW, anulowanie zmienia stan na CANCELED, nieznane
 * zamówienie to brak danych, a powtórzony extOrderId nigdy nie daje drugiego
 * zamówienia: przy otwartym pierwszym PayU oddaje je z powrotem, po jego anulowaniu
 * odpowiada ERROR_ORDER_NOT_UNIQUE.
 */
final class PayuAtrapa implements PayuClientInterface
{
    /** @var array<string, array<string, string>> zamówienia PayU: identyfikator => dane */
    public array $zamowienia = [];

    /** @var list<array{0: string, 1: mixed}> */
    public array $wywolania = [];

    /** @var list<array> żądania utworzenia zamówienia */
    public array $utworzone = [];

    public ?PayuApiException $bladOdczytu     = null;
    public ?PayuApiException $bladTworzenia   = null;
    public ?PayuApiException $bladAnulowania  = null;

    private int $licznik = 0;

    public function __construct(private PayuRestClient $podpisy, private string $przedrostek)
    {
    }

    public function createOrder(array $order): array
    {
        $this->wywolania[] = ['create', $order['extOrderId'] ?? ''];

        if ($this->bladTworzenia !== null) {
            throw $this->bladTworzenia;
        }

        foreach ($this->zamowienia as $istniejace => $dane) {
            if ($dane['extOrderId'] !== ($order['extOrderId'] ?? '')) {
                continue;
            }

            if (\in_array($dane['status'], ['NEW', 'PENDING'], true)) {
                return [$istniejace, 'https://secure.snd.payu.com/pay/?orderId=' . $istniejace];
            }

            throw new PayuApiException('ERROR_ORDER_NOT_UNIQUE - ORDER_NOT_UNIQUE', 400, 'ERROR_ORDER_NOT_UNIQUE');
        }

        $id = $this->przedrostek . (++$this->licznik);

        $this->utworzone[]     = $order;
        $this->zamowienia[$id] = [
            'orderId'      => $id,
            'extOrderId'   => (string) ($order['extOrderId'] ?? ''),
            'status'       => 'NEW',
            'totalAmount'  => (string) ($order['totalAmount'] ?? ''),
            'currencyCode' => (string) ($order['currencyCode'] ?? ''),
        ];

        return [$id, 'https://secure.snd.payu.com/pay/?orderId=' . $id];
    }

    public function retrieveOrder(string $orderId): ?array
    {
        $this->wywolania[] = ['retrieve', $orderId];

        if ($this->bladOdczytu !== null) {
            throw $this->bladOdczytu;
        }

        return $this->zamowienia[$orderId] ?? null;
    }

    public function cancelOrder(string $orderId): void
    {
        $this->wywolania[] = ['cancel', $orderId];

        if ($this->bladAnulowania !== null) {
            throw $this->bladAnulowania;
        }

        if (isset($this->zamowienia[$orderId])) {
            $this->zamowienia[$orderId]['status'] = 'CANCELED';
        }
    }

    public function verifyNotificationSignature(string $body, ?string $signatureHeader): bool
    {
        return $this->podpisy->verifyNotificationSignature($body, $signatureHeader);
    }

    public function ile(string $co): int
    {
        return count(array_filter($this->wywolania, static fn (array $w): bool => $w[0] === $co));
    }

    public function dodaj(string $id, string $stan, string $kwota = '1234', string $waluta = 'PLN'): void
    {
        $this->zamowienia[$id] = ['orderId' => $id, 'extOrderId' => 'x-' . $id, 'status' => $stan, 'totalAmount' => $kwota, 'currencyCode' => $waluta];
    }
}

/**
 * Wtyczka z podstawionym PayU, treścią powiadomienia i przekierowaniami.
 */
final class WtyczkaTestowa extends Payu
{
    public string $tresc = '';

    public ?string $podpis = null;

    public ?PayuAtrapa $payu = null;

    /** @var list<mixed> wiadomości, które wtyczka chciała wysłać sprzedawcy */
    public array $alerty = [];

    /** @var list<array{status: string, klient: bool, sklep: bool}> zmiany statusu zamówienia */
    public array $zmiany = [];

    /** @var list<string> */
    public array $przekierowania = [];

    /** @var list<int> kody odpowiedzi dla serwera PayU */
    public array $kody = [];

    /** @var list<string> */
    public array $dziennik = [];

    /**
     * Status zmienia się naprawdę, ale bez wysyłania wiadomości: odkładamy tylko to,
     * o co wtyczka prosiła.
     */
    public function modifyOrder(&$order_id, $order_status, $history = null, $email = null, $payment_params = null)
    {
        if ($order_id === false) {
            $this->alerty[] = $email;

            return;
        }

        $this->zmiany[] = ['status' => (string) $order_status, 'klient' => $history === true, 'sklep' => $email === true];

        // Fałsz w miejscu historii wyłącza wiadomość do klienta. Wiadomość do sklepu
        // wyłącza dopiero null: fałsz HikaShop zamienia na pusty dopisek i wysyła.
        parent::modifyOrder($order_id, $order_status, false, null, $payment_params);
    }

    protected function getPayuClient(): PayuClientInterface
    {
        // Podpisy zawsze kluczem tej metody płatności, którą wtyczka właśnie wczytała.
        return new class ($this->payu, new PayuRestClient(true, '1', $this->signatureKey(), '1', 'x')) implements PayuClientInterface {
            public function __construct(private PayuAtrapa $payu, private PayuRestClient $podpisy)
            {
            }

            public function createOrder(array $order): array
            {
                return $this->payu->createOrder($order);
            }

            public function retrieveOrder(string $orderId): ?array
            {
                return $this->payu->retrieveOrder($orderId);
            }

            public function cancelOrder(string $orderId): void
            {
                $this->payu->cancelOrder($orderId);
            }

            public function verifyNotificationSignature(string $body, ?string $signatureHeader): bool
            {
                return $this->podpisy->verifyNotificationSignature($body, $signatureHeader);
            }
        };
    }

    protected function buildLogger(): Logger
    {
        return new Logger('payu', true, [], function (string $wiersz): void {
            $this->dziennik[] = $wiersz;
        });
    }

    protected function readNotificationBody()
    {
        return $this->tresc;
    }

    protected function readSignatureHeader()
    {
        return $this->podpis;
    }

    protected function redirectTo($url)
    {
        $this->przekierowania[] = (string) $url;
    }

    protected function reply($code, $text)
    {
        $this->kody[] = (int) $code;

        return (string) $text;
    }

    protected function jsonReply(array $dane)
    {
        return (string) json_encode($dane, JSON_UNESCAPED_SLASHES);
    }

    public function writeToLog($data = null)
    {
        $this->dziennik[] = (string) $data;
    }

    public function wDzienniku(string $fragment): bool
    {
        return str_contains(implode("\n", $this->dziennik), $fragment);
    }
}

// --- przygotowanie ---------------------------------------------------

$pdo    = polaczenieZBaza();
$prefix = przedrostekTabel();
$app    = Factory::getApplication();
$znak   = bin2hex(random_bytes(4));

$kluczA = 'klucz-a-' . $znak . '-aaaaaaaa';
$kluczB = 'klucz-b-' . $znak . '-bbbbbbbb';
$kluczC = 'klucz-c-' . $znak . '-cccccccc';

$dodajMetode = static function (string $klucz, array $nadpisania = []) use ($pdo, $prefix, $znak): int {
    $parametry = (object) array_merge([
        'pos_id'              => '300746',
        'signature_key'       => $klucz,
        'oauth_client_id'     => '300746',
        'oauth_client_secret' => 'haslo-testowe-' . $znak,
        'sandbox'             => 1,
        'debug'               => 0,
        'return_page'         => '',
        'return_url'          => '',
        'invalid_status'      => 'cancelled',
        'verified_status'     => 'confirmed',
    ], $nadpisania);

    $pdo->prepare("
        INSERT INTO {$prefix}hikashop_payment
            (payment_name, payment_description, payment_images, payment_params, payment_type,
             payment_published, payment_currency, payment_shipping_methods)
        VALUES (:nazwa, '', '', :parametry, 'payu', 0, '', '')
    ")->execute([':nazwa' => 'PayU TEST ' . $znak, ':parametry' => serialize($parametry)]);

    return (int) $pdo->lastInsertId();
};

// Metoda A istnieje tylko po to, żeby być „pierwszą metodą PayU w bazie”: do 2.3.1
// wtyczka sprawdzała podpis powiadomień właśnie jej kluczem.
$metodaA = $dodajMetode($kluczA);
$metodaB = $dodajMetode($kluczB);
$metodaC = $dodajMetode($kluczC, ['return_url' => '/dziekujemy-test-' . $znak]);

$email = 'test-payu-' . $znak . '@example.invalid';

$pdo->prepare("INSERT INTO {$prefix}hikashop_user (user_cms_id, user_email, user_partner_email, user_params) VALUES (0, :e, '', '')")
    ->execute([':e' => $email]);
$idKlienta = (int) $pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO {$prefix}hikashop_address
        (address_user_id, address_type, address_firstname, address_lastname, address_street,
         address_post_code, address_city, address_telephone, address_published)
    VALUES (:k, 'billing', 'Jan', 'Testowy', 'Testowa 1', '00-001', 'Testowo', '600100200', 1)
")->execute([':k' => $idKlienta]);
$idAdresu = (int) $pdo->lastInsertId();

$zlotowka = (int) $pdo->query("SELECT currency_id FROM {$prefix}hikashop_currency WHERE currency_code = 'PLN'")->fetchColumn();
$euro     = (int) $pdo->query("SELECT currency_id FROM {$prefix}hikashop_currency WHERE currency_code = 'EUR'")->fetchColumn();

/** @var list<int> $zamowieniaTestowe */
$zamowieniaTestowe = [];
$tokeny            = [];

$nowe = static function (array $n = []) use ($pdo, $prefix, $znak, $idKlienta, $idAdresu, $zlotowka, $metodaB, &$zamowieniaTestowe, &$tokeny): int {
    $token = bin2hex(random_bytes(16));

    $pdo->prepare("
        INSERT INTO {$prefix}hikashop_order
            (order_number, order_user_id, order_created, order_modified, order_status, order_type,
             order_full_price, order_currency_id, order_payment_id, order_payment_method,
             order_billing_address_id, order_shipping_address_id, order_token, order_payment_params)
        VALUES
            (:numer, :klient, :teraz, :teraz, :status, 'sale',
             :cena, :waluta, :platnosc, :metoda, :adres, :adres, :token, :parametry)
    ")->execute([
        ':numer'     => 'PAYUT' . strtoupper($znak) . count($zamowieniaTestowe),
        ':klient'    => $idKlienta,
        ':teraz'     => time(),
        ':status'    => $n['status'] ?? 'created',
        ':cena'      => $n['cena'] ?? 12.34,
        ':waluta'    => $n['waluta'] ?? $zlotowka,
        ':platnosc'  => $n['platnosc'] ?? $metodaB,
        ':metoda'    => $n['metoda'] ?? 'payu',
        ':adres'     => $idAdresu,
        ':token'     => $token,
        ':parametry' => isset($n['dane']) ? serialize((object) $n['dane']) : '',
    ]);

    $id                  = (int) $pdo->lastInsertId();
    $zamowieniaTestowe[] = $id;
    $tokeny[$id]         = $token;

    return $id;
};

register_shutdown_function(static function () use ($pdo, $prefix, &$zamowieniaTestowe, $metodaA, $metodaB, $metodaC, $idKlienta, $idAdresu): void {
    foreach ($zamowieniaTestowe as $id) {
        $pdo->prepare("DELETE FROM {$prefix}hikashop_order WHERE order_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM {$prefix}hikashop_history WHERE history_order_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM {$prefix}hikashop_email_log WHERE email_log_ref_id = :id")->execute([':id' => (string) $id]);
    }

    foreach ([$metodaA, $metodaB, $metodaC] as $id) {
        $pdo->prepare("DELETE FROM {$prefix}hikashop_payment WHERE payment_id = :id AND payment_type = 'payu' AND payment_published = 0")->execute([':id' => $id]);
    }

    $pdo->prepare("DELETE FROM {$prefix}hikashop_address WHERE address_id = :id")->execute([':id' => $idAdresu]);
    $pdo->prepare("DELETE FROM {$prefix}hikashop_user WHERE user_id = :id")->execute([':id' => $idKlienta]);
});

$orderClass = hikashop_get('class.order');

$status = static function (int $id) use ($pdo, $prefix): string {
    $q = $pdo->prepare("SELECT order_status FROM {$prefix}hikashop_order WHERE order_id = :id");
    $q->execute([':id' => $id]);

    return (string) $q->fetchColumn();
};

$dane = static function (int $id) use ($pdo, $prefix): object {
    $q = $pdo->prepare("SELECT order_payment_params FROM {$prefix}hikashop_order WHERE order_id = :id");
    $q->execute([':id' => $id]);

    return OrderPaymentData::read((object) ['order_payment_params' => (string) $q->fetchColumn()]);
};

$ustaw = static function (int $id, array $pola) use ($pdo, $prefix): void {
    foreach ($pola as $kolumna => $wartosc) {
        $pdo->prepare("UPDATE {$prefix}hikashop_order SET {$kolumna} = :w WHERE order_id = :id")->execute([':w' => $wartosc, ':id' => $id]);
    }
};

$wtyczka = static function (?PayuAtrapa $payu = null) use ($znak): WtyczkaTestowa {
    $dispatcher = Factory::getContainer()->get('dispatcher');
    $w          = new WtyczkaTestowa($dispatcher, ['name' => 'payu', 'type' => 'hikashoppayment']);
    $w->payu    = $payu ?? new PayuAtrapa(new PayuRestClient(true, '1', 'nieuzywany', '1', 'x'), 'TP' . strtoupper($znak));

    return $w;
};

$zadanie = static function (array $parametry) use ($app): void {
    $wejscie = $app->getInput();

    foreach (['payu_action', 'order_id', 'payu_return', 'payu_retry', 'payu_status', 'payu_ask', 'payu_waited', 'error'] as $klucz) {
        $wejscie->set($klucz, null);
    }

    foreach ($parametry as $klucz => $wartosc) {
        $wejscie->set($klucz, $wartosc);
    }
};

$znacznik = static function (string $cel, int $id) use (&$tokeny): string {
    return hash_hmac('sha256', 'payu-' . $cel . '|' . $id, $tokeny[$id]);
};

$powiadomienie = static function (int $id, string $payuId, string $stan, string $klucz, array $n = []): array {
    $tresc = (string) json_encode([
        'order' => array_merge([
            'orderId'      => $payuId,
            'extOrderId'   => ExtOrderId::generate($id, $klucz),
            'totalAmount'  => '1234',
            'currencyCode' => 'PLN',
            'status'       => $stan,
        ], $n['order'] ?? []),
        'properties' => [['name' => 'PAYMENT_ID', 'value' => '5001']],
    ]);

    return [$tresc, 'sender=checkout;signature=' . md5($tresc . ($n['kluczPodpisu'] ?? $klucz)) . ';algorithm=MD5;content=DOCUMENT'];
};

$wyslij = static function (WtyczkaTestowa $w, array $powiadomienie) use ($zadanie): string {
    $zadanie([]);
    $w->tresc  = $powiadomienie[0];
    $w->podpis = $powiadomienie[1];
    $statusy   = [];

    return (string) $w->onPaymentNotification($statusy);
};

$otworz = static function (WtyczkaTestowa $w, array $parametry) use ($zadanie): string {
    $zadanie($parametry);
    $statusy = [];

    return (string) $w->onPaymentNotification($statusy);
};

echo 'Joomla: ' . JPATH_BASE . ', metody testowe: ' . $metodaA . ', ' . $metodaB . ', ' . $metodaC . PHP_EOL;

// --- 1. Powiadomienie o zapłacie ------------------------------------------

naglowek('1. Powiadomienie o zrealizowanej płatności');

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-1', 'payu_orders' => ['PAYU-1'], 'payu_payment_id' => $metodaB]]);
$w  = $wtyczka();
$odp = $wyslij($w, $powiadomienie($id, 'PAYU-1', 'COMPLETED', $kluczB));

wynik('podpis sprawdzany kluczem metody z zamówienia, nie pierwszej metody w bazie', $w->kody === [200] && $odp === 'OK', implode(',', $w->kody) . ' ' . $odp);
wynik('zamówienie dostaje status opłaconego', $status($id) === 'confirmed', $status($id));
wynik('klient i sklep mają dostać wiadomość o zapłacie', $w->zmiany === [['status' => 'confirmed', 'klient' => true, 'sklep' => true]]);
wynik('zapisany jest znacznik potwierdzenia i zamówienie PayU', !empty($dane($id)->payu_paid_at) && ($dane($id)->payu_paid_order ?? '') === 'PAYU-1');

$w   = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-1', 'COMPLETED', $kluczB));
wynik('powtórzone powiadomienie niczego nie zmienia i nie wysyła wiadomości', $w->kody === [200] && $w->zmiany === [] && $w->wDzienniku('Powtórzone powiadomienie'));

$w = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-INNE', 'COMPLETED', $kluczB));
wynik('druga wpłata za opłacone zamówienie nie zmienia statusu, zostaje wpis w dzienniku', $w->zmiany === [] && $w->wDzienniku('Druga wpłata') && ($dane($id)->payu_paid_order ?? '') === 'PAYU-1');

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-2']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-2', 'COMPLETED', $kluczB, ['kluczPodpisu' => $kluczA]));
wynik('podpis kluczem innej metody jest odrzucany', $w->kody === [400] && $status($id) === 'created', implode(',', $w->kody) . ' ' . $status($id));

$w = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-2', 'COMPLETED', $kluczB, ['order' => ['extOrderId' => $id . '_aabbccdd_' . str_repeat('0', 64)]]));
wynik('poprawny podpis, ale podrobiony identyfikator zamówienia: odrzucone', $w->kody === [400] && $status($id) === 'created');

$w = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-2', 'COMPLETED', $kluczB, ['order' => ['extOrderId' => $id . '_' . hash_hmac('sha256', (string) $id, $kluczB)]]));
wynik('powiadomienie z identyfikatorem sprzed 2.4.0 jest rozliczane', $w->kody === [200] && $status($id) === 'confirmed', implode(',', $w->kody) . ' ' . $status($id));

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-3']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-3', 'COMPLETED', $kluczB, ['order' => ['totalAmount' => '100']]));
wynik('wpłata w innej kwocie nie potwierdza zamówienia', $w->kody === [200] && $status($id) === 'created' && empty($dane($id)->payu_paid_at), $status($id));
wynik('sprzedawca dostaje wiadomość o niezgodnej kwocie', count($w->alerty) === 1 && str_contains((string) ($w->alerty[0]->body ?? ''), '1,00 PLN') && str_contains((string) ($w->alerty[0]->body ?? ''), '12,34 PLN'), (string) ($w->alerty[0]->body ?? ''));

$w = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-3', 'COMPLETED', $kluczB, ['order' => ['totalAmount' => '100']]));
wynik('powtórka nie wysyła sprzedawcy drugiej wiadomości', $w->alerty === [] && $status($id) === 'created');

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-4']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-4', 'COMPLETED', $kluczB, ['order' => ['currencyCode' => 'EUR']]));
wynik('wpłata w innej walucie nie potwierdza zamówienia', $status($id) === 'created' && count($w->alerty) === 1);

$id = $nowe(['status' => 'confirmed', 'dane' => ['payu_order_id' => 'PAYU-5']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-5', 'COMPLETED', $kluczB));
wynik('zamówienie potwierdzone ręcznie: znacznik zapisany, bez drugiej zmiany statusu i wiadomości', $w->zmiany === [] && !empty($dane($id)->payu_paid_at) && $status($id) === 'confirmed');

$id = $nowe(['status' => 'shipped', 'dane' => ['payu_order_id' => 'PAYU-6']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-6', 'COMPLETED', $kluczB));
wynik('zamówienie wysłane nie wraca do statusu potwierdzonego', $status($id) === 'shipped' && $w->zmiany === []);

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-7']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-7', 'WAITING_FOR_CONFIRMATION', $kluczB));
wynik('wpłata czekająca na przyjęcie nie potwierdza zamówienia, sprzedawca dostaje wiadomość', $w->kody === [200] && $status($id) === 'created' && count($w->alerty) === 1);

$w = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-7', 'PENDING', $kluczB));
wynik('powiadomienie o płatności w toku niczego nie zmienia', $w->kody === [200] && $status($id) === 'created' && $w->zmiany === []);

$w = $wtyczka();
$w->tresc = '';
$zadanie([]);
$statusy = [];
$w->onPaymentNotification($statusy);
wynik('puste powiadomienie dostaje odpowiedź 400', $w->kody === [400]);

$w = $wtyczka();
$wyslij($w, $powiadomienie(999999991, 'PAYU-X', 'COMPLETED', $kluczB));
wynik('powiadomienie dla nieznanego zamówienia dostaje odpowiedź 404', $w->kody === [404]);

// --- 2. Powiadomienie o anulowaniu ----------------------------------------

naglowek('2. Powiadomienie o anulowanej albo wygasłej płatności');

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-10', 'payu_orders' => ['PAYU-10']]]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-10', 'CANCELED', $kluczB));
wynik('anulowanie bieżącej próby przy nieopłaconym zamówieniu nadaje status nieudanej płatności', $status($id) === 'cancelled' && count($w->zmiany) === 1, $status($id));

$w = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-10', 'CANCELED', $kluczB));
wynik('powtórzone anulowanie niczego nie zmienia', $w->zmiany === [] && $w->kody === [200]);

foreach (['confirmed' => 'potwierdzone', 'shipped' => 'wysłane'] as $stan => $opis) {
    $id = $nowe(['status' => $stan, 'dane' => ['payu_order_id' => 'PAYU-11' . $stan]]);
    $w  = $wtyczka();
    $wyslij($w, $powiadomienie($id, 'PAYU-11' . $stan, 'CANCELED', $kluczB));
    wynik('spóźnione anulowanie nie rusza zamówienia, które jest już ' . $opis, $status($id) === $stan && $w->zmiany === [], $status($id));
}

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-12', 'payu_paid_at' => gmdate('c'), 'payu_paid_order' => 'PAYU-12B']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-12', 'CANCELED', $kluczB));
wynik('anulowanie nie rusza zamówienia z potwierdzoną zapłatą', $status($id) === 'created' && $w->zmiany === []);

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-13B', 'payu_orders' => ['PAYU-13A', 'PAYU-13B'], 'payu_cancelled' => ['PAYU-13A']]]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-13A', 'CANCELED', $kluczB));
wynik('anulowanie próby, którą wtyczka sama anulowała przed ponowieniem, nie rusza zamówienia', $status($id) === 'created' && $w->zmiany === []);

$id = $nowe(['dane' => ['payu_order_id' => 'PAYU-14B', 'payu_orders' => ['PAYU-14A', 'PAYU-14B']]]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-14A', 'CANCELED', $kluczB));
wynik('wygaśnięcie wcześniejszej próby nie rusza zamówienia z nowszą próbą', $status($id) === 'created' && $w->zmiany === []);

$id = $nowe(['metoda' => 'banktransfer', 'platnosc' => 2, 'dane' => ['payu_order_id' => 'PAYU-15', 'payu_payment_id' => $metodaB]]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-15', 'CANCELED', $kluczB));
wynik('anulowanie nie rusza zamówienia, którego metodę płatności klient zmienił', $w->kody === [200] && $status($id) === 'created' && $w->zmiany === [], implode(',', $w->kody) . ' ' . $status($id));

$w = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-15', 'COMPLETED', $kluczB));
wynik('wpłata PayU za takie zamówienie jest mimo to rozliczana', $status($id) === 'confirmed');

$id = $nowe(['status' => 'cancelled', 'dane' => ['payu_order_id' => 'PAYU-16']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'PAYU-16', 'EXPIRED', $kluczB));
wynik('zamówienie już anulowane nie dostaje kolejnej zmiany statusu', $w->zmiany === []);

// --- 3. Założenie płatności przy zamówieniu -------------------------------

naglowek('3. Założenie płatności przy składaniu zamówienia');

$id    = $nowe();
$payu  = new PayuAtrapa(new PayuRestClient(true, '1', 'x', '1', 'x'), 'TP' . strtoupper($znak) . 'A');
$w     = $wtyczka($payu);
$metody = [$metodaB => (object) [
    'payment_id'     => $metodaB,
    'payment_name'   => 'PayU test',
    'payment_type'   => 'payu',
    'payment_params' => unserialize((string) $pdo->query("SELECT payment_params FROM {$prefix}hikashop_payment WHERE payment_id = " . $metodaB)->fetchColumn()),
]];

$zKasy                   = $orderClass->get($id);
$zKasy->cart             = new stdClass();
$zKasy->cart->full_total = (object) ['prices' => [(object) ['price_value_with_tax' => 12.3400001]]];
$zKasy->cart->products   = [(object) ['order_product_name' => 'Sok testowy', 'order_product_price' => 10.03, 'order_product_tax' => 2.31, 'order_product_quantity' => 1]];
// Po zapisie nowego zamówienia HikaShop potrafi trzymać adres e-mail klienta jako tablicę.
$zKasy->customer         = (object) ['user_id' => $idKlienta, 'user_cms_id' => 0, 'user_email' => [$email]];

$zadanie([]);
ob_start();
$w->onAfterOrderConfirm($zKasy, $metody, $metodaB);
$strona  = (string) ob_get_clean();
$wyslane = $payu->utworzone[0] ?? [];

wynik('płatność została założona w PayU', $payu->ile('create') === 1 && $wyslane !== []);
wynik('wtyczka nie przekierowuje w środku tworzenia zamówienia', $w->przekierowania === []);
wynik('strona przejścia zawiera adres strony płatności PayU', str_contains($strona, 'hikashop_payu_redirect') && str_contains($strona, 'secure.snd.payu.com/pay/?orderId='), substr(strip_tags($strona), 0, 120));
wynik('kwota w groszach z zamówienia w bazie', ($wyslane['totalAmount'] ?? null) === 1234, var_export($wyslane['totalAmount'] ?? null, true));
wynik('waluta zamówienia', ($wyslane['currencyCode'] ?? '') === 'PLN');
wynik('e-mail kupującego trafia do PayU (także gdy HikaShop podaje go jako tablicę)', ($wyslane['buyer']['email'] ?? '') === $email, var_export($wyslane['buyer'] ?? null, true));
wynik('telefon, imię i nazwisko kupującego trafiają do PayU', ($wyslane['buyer']['phone'] ?? '') === '600100200' && ($wyslane['buyer']['firstName'] ?? '') === 'Jan' && ($wyslane['buyer']['lastName'] ?? '') === 'Testowy');
wynik('pozycja zamówienia z ceną brutto w groszach', ($wyslane['products'][0]['name'] ?? '') === 'Sok testowy' && ($wyslane['products'][0]['unitPrice'] ?? 0) === 1234);
wynik('identyfikator dla PayU jest podpisany kluczem tej metody', ExtOrderId::isValid((string) ($wyslane['extOrderId'] ?? ''), $kluczB) && !ExtOrderId::isValid((string) ($wyslane['extOrderId'] ?? ''), $kluczA));
wynik('adres powrotu prowadzi do wtyczki, nie wprost do podziękowania', str_starts_with((string) ($wyslane['continueUrl'] ?? ''), 'https://') && str_contains($wyslane['continueUrl'], 'payu_action=return') && str_contains($wyslane['continueUrl'], 'skip_system_notification=1') && !str_contains($wyslane['continueUrl'], 'after_end'), (string) ($wyslane['continueUrl'] ?? ''));
wynik('adres powiadomień nie niesie znacznika zamówienia klienta', str_contains((string) ($wyslane['notifyUrl'] ?? ''), 'notif_payment=payu') && !str_contains($wyslane['notifyUrl'], 'order_token') && !str_contains($wyslane['notifyUrl'], 'payu_action'));

$zapisane = $dane($id);
wynik('przy zamówieniu zapisana jest próba i metoda płatności', ($zapisane->payu_order_id ?? '') === array_key_first($payu->zamowienia) && OrderPaymentData::getList((object) ['order_payment_params' => $zapisane], 'payu_orders') === [array_key_first($payu->zamowienia)] && (int) ($zapisane->payu_payment_id ?? 0) === $metodaB);

$idEur = $nowe(['waluta' => $euro]);
$payuE = new PayuAtrapa(new PayuRestClient(true, '1', 'x', '1', 'x'), 'TP' . strtoupper($znak) . 'E');
$w     = $wtyczka($payuE);
$otworz($w, ['payu_action' => 'retry', 'order_id' => $idEur, 'payu_retry' => $znacznik('retry', $idEur)]);
wynik('zamówienie w euro idzie do PayU w euro, nie w złotych', ($payuE->utworzone[0]['currencyCode'] ?? '') === 'EUR' && $euro > 0, var_export($payuE->utworzone[0]['currencyCode'] ?? null, true));

// --- 4. Ponowna zapłata ----------------------------------------------------

naglowek('4. Ponowna zapłata za to samo zamówienie');

$pierwsza = array_key_first($payu->zamowienia);
$w        = $wtyczka($payu);
$odp      = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
$druga    = array_key_last($payu->zamowienia);

wynik('druga próba zapłaty zostaje założona (PayU nie odrzuca identyfikatora)', $payu->ile('create') === 2 && $druga !== $pierwsza && !$w->wDzienniku('NOT_UNIQUE'), implode(' | ', $w->dziennik));
wynik('druga próba ma inny identyfikator niż pierwsza', ($payu->utworzone[0]['extOrderId'] ?? 'a') !== ($payu->utworzone[1]['extOrderId'] ?? 'a'));
wynik('klient jest przekierowany na stronę płatności nowej próby', $w->przekierowania === ['https://secure.snd.payu.com/pay/?orderId=' . $druga]);
wynik('niedokończona pierwsza próba została anulowana w PayU', ($payu->zamowienia[$pierwsza]['status'] ?? '') === 'CANCELED');
wynik('anulowana próba jest zapisana jako anulowana przez wtyczkę', OrderPaymentData::getList((object) ['order_payment_params' => $dane($id)], 'payu_cancelled') === [$pierwsza]);
wynik('bieżącą próbą jest druga, obie są zapamiętane', ($dane($id)->payu_order_id ?? '') === $druga && OrderPaymentData::attemptsOf((object) ['order_payment_params' => $dane($id)]) === [$druga, $pierwsza]);

// PayU zawiadamia o anulowaniu pierwszej próby: zamówienie zostaje do zapłaty.
$w = $wtyczka($payu);
$wyslij($w, $powiadomienie($id, $pierwsza, 'CANCELED', $kluczB));
wynik('powiadomienie o anulowaniu pierwszej próby nie anuluje zamówienia', $status($id) === 'created' && $w->zmiany === []);

// Klient płaci drugą próbę, ale powiadomienie nie dociera. Klika „Zapłać teraz”.
$payu->zamowienia[$druga]['status'] = 'COMPLETED';
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);

wynik('opłacona próba jest potwierdzana zamiast zakładania następnej', $payu->ile('create') === 2 && $status($id) === 'confirmed', $status($id));
wynik('klient czyta, że zapłata jest potwierdzona, i nie jest wysyłany do PayU', $w->przekierowania === [] && str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_CONFIRMED')) && !str_contains($odp, 'payu_action=retry'));

$pytanPrzed = $payu->ile('retrieve');
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
wynik('za opłacone zamówienie nie da się zapłacić ponownie i PayU nie jest już pytane', str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_ALREADY_PAID')) && $payu->ile('retrieve') === $pytanPrzed && $payu->ile('create') === 2);

$id   = $nowe(['dane' => ['payu_order_id' => 'STARA-1', 'payu_orders' => ['STARA-1']]]);
$payu = new PayuAtrapa(new PayuRestClient(true, '1', 'x', '1', 'x'), 'TP' . strtoupper($znak) . 'B');
$payu->dodaj('STARA-1', 'COMPLETED', '100');
$w    = $wtyczka($payu);
$odp  = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
wynik('wcześniejsza wpłata w innej kwocie: bez nowej płatności, bez potwierdzenia, wiadomość do sprzedawcy', $payu->ile('create') === 0 && $status($id) === 'created' && count($w->alerty) === 1 && str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_MISMATCH')));

$id   = $nowe(['dane' => ['payu_order_id' => 'STARA-2', 'payu_orders' => ['STARA-2']]]);
$payu = new PayuAtrapa(new PayuRestClient(true, '1', 'x', '1', 'x'), 'TP' . strtoupper($znak) . 'C');
$payu->dodaj('STARA-2', 'WAITING_FOR_CONFIRMATION');
$w    = $wtyczka($payu);
$odp  = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
wynik('wpłata czekająca na przyjęcie blokuje nową płatność', $payu->ile('create') === 0 && str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_WAITING')) && $payu->ile('cancel') === 0);

$id   = $nowe(['dane' => ['payu_order_id' => 'STARA-3', 'payu_orders' => ['STARA-3']]]);
$payu = new PayuAtrapa(new PayuRestClient(true, '1', 'x', '1', 'x'), 'TP' . strtoupper($znak) . 'D');
$payu->dodaj('STARA-3', 'PENDING');
$payu->bladOdczytu = new PayuApiException('PayU nie odpowiada: timeout', 0);
$w    = $wtyczka($payu);
$odp  = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
wynik('gdy PayU nie odpowiada na pytanie o wcześniejszą próbę, nowa nie powstaje', $payu->ile('create') === 0 && $w->przekierowania === [] && str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_CHECK')));
wynik('klient może spróbować ponownie za chwilę', str_contains($odp, 'payu_action=retry'));

$payu->bladOdczytu    = null;
$payu->bladAnulowania = new PayuApiException('HTTP 500', 500);
$w    = $wtyczka($payu);
$odp  = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
wynik('gdy PayU nie przyjmie anulowania wcześniejszej próby, nowa nie powstaje', $payu->ile('create') === 0 && ($payu->zamowienia['STARA-3']['status'] ?? '') === 'PENDING');
wynik('nieanulowana próba nie zostaje oznaczona jako anulowana', OrderPaymentData::getList((object) ['order_payment_params' => $dane($id)], 'payu_cancelled') === []);

$payu->bladAnulowania = null;
$payu->bladTworzenia  = new PayuApiException('ERROR_VALUE_INVALID - Invalid continueUrl', 400, 'ERROR_VALUE_INVALID');
$w    = $wtyczka($payu);
$odp  = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
wynik('odmowa PayU przy zakładaniu płatności: komunikat dla klienta i wpis w dzienniku', str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_CREATE')) && $w->wDzienniku('Invalid continueUrl') && $w->przekierowania === []);

$payu->bladTworzenia = new PayuApiException('PayU OAuth failed: Bad client credentials', 401);
$w    = $wtyczka($payu);
$odp  = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
wynik('odrzucone dane dostępowe: osobny komunikat', str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_AUTH')));

$id  = $nowe(['status' => 'cancelled']);
$w   = $wtyczka();
$odp = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('retry', $id)]);
wynik('za anulowane zamówienie nie da się zapłacić', $w->payu->ile('create') === 0 && str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETRY_UNAVAILABLE')));

$id  = $nowe();
$w   = $wtyczka();
$odp = $otworz($w, ['payu_action' => 'retry', 'order_id' => $id, 'payu_retry' => $znacznik('return', $id)]);
wynik('znacznik powrotu nie otwiera ponowienia', $w->payu->ile('create') === 0 && str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_LINK_INVALID')));

// --- 5. Powrót klienta ze strony płatności --------------------------------

naglowek('5. Powrót klienta ze strony płatności');

$przygotuj = static function (string $stan, array $n = []) use ($nowe, $znak): array {
    static $numer = 0;
    $numer++;

    $payuId = 'POWROT-' . $numer;
    $id     = $nowe(array_merge(['dane' => ['payu_order_id' => $payuId, 'payu_orders' => [$payuId]]], $n));
    $payu   = new PayuAtrapa(new PayuRestClient(true, '1', 'x', '1', 'x'), 'TP' . strtoupper($znak) . 'R' . $numer);
    $payu->dodaj($payuId, $stan);

    return [$id, $payu, $payuId];
};

[$id, $payu] = $przygotuj('COMPLETED');
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id)]);
wynik('zapłacone w PayU, powiadomienie nie doszło: zapłata potwierdzona przy powrocie', $status($id) === 'confirmed' && !empty($dane($id)->payu_paid_at));
wynik('klient trafia na podziękowanie dopiero po potwierdzeniu', count($w->przekierowania) === 1 && str_contains($w->przekierowania[0], 'task=after_end') && str_contains($w->przekierowania[0], 'order_id=' . $id), implode(' ', $w->przekierowania));

$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id)]);
wynik('przy opłaconym zamówieniu powrót nie pyta już PayU', $payu->ile('retrieve') === 1 && count($w->przekierowania) === 1);

[$id, $payu] = $przygotuj('PENDING');
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id), 'error' => '501']);
wynik('nieudana płatność NIE kończy się podziękowaniem', $w->przekierowania === [] && $status($id) === 'created');
wynik('klient czyta, że płatność się nie powiodła, i dostaje przycisk ponowienia', str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_FAILED')) && str_contains($odp, 'payu_action=retry') && str_contains($odp, 'hikashop_payu_unpaid'));
wynik('powrót niczego w PayU nie anuluje', $payu->ile('cancel') === 0);

[$id, $payu] = $przygotuj('PENDING');
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id)]);
wynik('płatność w toku bez zgłoszonego błędu: strona czeka na potwierdzenie', str_contains($odp, 'hikashop_payu_waiting') && str_contains($odp, 'payu_action=status') && str_contains($odp, 'payu_waited=1') && $w->przekierowania === []);
wynik('strona oczekiwania nie mówi klientowi, że nie zapłacił', !str_contains($odp, 'hikashop_payu_unpaid'));

$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id), 'payu_waited' => 1]);
wynik('po upływie oczekiwania: informacja o braku potwierdzenia i ponowienie', str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_PENDING')) && str_contains($odp, 'payu_action=retry') && !str_contains($odp, 'hikashop_payu_waiting'));

[$id, $payu] = $przygotuj('NEW');
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id)]);
wynik('klient wrócił bez rozpoczęcia płatności: brak zapłaty i ponowienie', str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_UNPAID')) && str_contains($odp, 'payu_action=retry'));

[$id, $payu] = $przygotuj('PENDING');
$payu->bladOdczytu = new PayuApiException('PayU nie odpowiada', 0);
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id)]);
wynik('PayU nie odpowiada: neutralna informacja, bez podziękowania i bez ponowienia', str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_UNKNOWN')) && $w->przekierowania === [] && !str_contains($odp, 'payu_action=retry') && str_contains($odp, 'payu_action=return'));

[$id, $payu] = $przygotuj('COMPLETED');
$payu->zamowienia[array_key_first($payu->zamowienia)]['totalAmount'] = '100';
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id)]);
wynik('wpłata w innej kwocie: bez podziękowania i bez ponowienia', $w->przekierowania === [] && $status($id) === 'created' && str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_MISMATCH')) && !str_contains($odp, 'payu_action=retry'));

[$id, $payu] = $przygotuj('CANCELED', ['status' => 'cancelled']);
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id)]);
wynik('zamówienie anulowane: brak zapłaty bez przycisku ponowienia', str_contains($odp, 'hikashop_payu_unpaid') && !str_contains($odp, 'payu_action=retry'));

[$id, $payu] = $przygotuj('COMPLETED');
$w   = $wtyczka($payu);
$odp = $otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => 'zly-znacznik']);
wynik('powrót z błędnym znacznikiem niczego nie potwierdza', $status($id) === 'created' && $w->przekierowania === [] && $payu->ile('retrieve') === 0 && str_contains($odp, Text::_('PLG_HIKASHOPPAYMENT_PAYU_LINK_INVALID')));

[$id, $payu] = $przygotuj('COMPLETED', ['platnosc' => $metodaC]);
$w   = $wtyczka($payu);
$otworz($w, ['payu_action' => 'return', 'order_id' => $id, 'payu_return' => $znacznik('return', $id)]);
wynik('własny adres z konfiguracji metody jest celem po potwierdzonej zapłacie', count($w->przekierowania) === 1 && str_ends_with($w->przekierowania[0], '/dziekujemy-test-' . $znak), implode(' ', $w->przekierowania));

// --- 6. Pytanie strony oczekiwania o wynik --------------------------------

naglowek('6. Pytanie strony oczekiwania o wynik');

[$id, $payu, $payuId] = $przygotuj('PENDING');
$w   = $wtyczka($payu);
$odp = json_decode($otworz($w, ['payu_action' => 'status', 'order_id' => $id, 'payu_status' => $znacznik('status', $id)]), true);
wynik('zwykłe pytanie czyta tylko zamówienie ze sklepu', ($odp['state'] ?? '') === 'waiting' && $payu->ile('retrieve') === 0);

$w   = $wtyczka($payu);
$odp = json_decode($otworz($w, ['payu_action' => 'status', 'order_id' => $id, 'payu_status' => $znacznik('status', $id), 'payu_ask' => 1]), true);
wynik('pytanie z prośbą o sprawdzenie w PayU: płatność nadal w toku', ($odp['state'] ?? '') === 'waiting' && $payu->ile('retrieve') === 1);

$payu->zamowienia[$payuId]['status'] = 'COMPLETED';
$w   = $wtyczka($payu);
$odp = json_decode($otworz($w, ['payu_action' => 'status', 'order_id' => $id, 'payu_status' => $znacznik('status', $id), 'payu_ask' => 1]), true);
wynik('gdy PayU potwierdzi, strona dostaje adres podziękowania, a zamówienie status opłaconego', ($odp['state'] ?? '') === 'paid' && str_contains((string) ($odp['redirect'] ?? ''), 'after_end') && $status($id) === 'confirmed');

$w   = $wtyczka($payu);
$odp = json_decode($otworz($w, ['payu_action' => 'status', 'order_id' => $id, 'payu_status' => $znacznik('return', $id)]), true);
wynik('pytanie z cudzym znacznikiem dostaje odpowiedź „invalid”', ($odp['state'] ?? '') === 'invalid');

// --- 7. Wybór metody płatności dla starszych zamówień ----------------------

naglowek('7. Zamówienia sprzed 2.4.0');

$id = $nowe(['platnosc' => $metodaC, 'dane' => ['payu_order_id' => 'DAWNE-1']]);
$w  = $wtyczka();
$wyslij($w, $powiadomienie($id, 'DAWNE-1', 'COMPLETED', $kluczC, ['order' => ['extOrderId' => $id . '_' . hash_hmac('sha256', (string) $id, $kluczC)]]));
wynik('płatność założona starszą wersją jest rozliczana kluczem metody z zamówienia', $w->kody === [200] && $status($id) === 'confirmed', implode(',', $w->kody) . ' ' . $status($id));

// --- podsumowanie ---------------------------------------------------------

echo PHP_EOL . 'Zdane: ' . $zdane . ', błędy: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
