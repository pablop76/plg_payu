<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.4.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 */

namespace Pablop76\Plugin\HikashopPayment\Payu\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuApiException;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuClientInterface;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuRestClient;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\Amount;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\ExtOrderId;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Payu\Payment\OrderPaymentData;

defined('_JEXEC') or die('Restricted access');

// HikaShop definiuje hikashopPaymentPlugin leniwie: dopiero pierwsze załadowanie jego
// helper.php rejestruje autoload dla tej klasy. W kontekstach, gdzie HikaShop jeszcze nie
// "wystartował" w danym żądaniu (np. podczas instalacji/aktualizacji tej wtyczki w Menedżerze
// Rozszerzeń), ta klasa bazowa nie jest dostępna i "class Payu extends hikashopPaymentPlugin"
// rzuca fatal "Class not found". Guard musi być w TYM pliku, przed deklaracją klasy - PHP
// odkłada kompilację "class X extends Y" do wykonania tej linii, więc wystarczy wcześniej
// w tym samym pliku załadować helper.php, niezależnie od tego, co wywołało autoload.
if (!class_exists('hikashopPaymentPlugin', false)) {
    $payuHikashopHelper = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';
    if (is_file($payuHikashopHelper)) {
        require_once $payuHikashopHelper;
    }
}

/**
 * PayU Payment Plugin for HikaShop
 *
 * Zapłatę potwierdza wyłącznie PayU: podpisane powiadomienie albo odpowiedź na pytanie
 * zadane z serwera sklepu (OrderRetrieve). Powrót klienta ze strony płatności niczego
 * nie potwierdza, jest tylko okazją, żeby o stan zapytać.
 *
 * Sygnatury metod on* celowo zostają bez typów parametrów, zgodne z klasą bazową HikaShopa.
 *
 * @since  2.1.0
 */
class Payu extends \hikashopPaymentPlugin
{
    /**
     * Dopisek adresów, które otwiera klient. Zadanie notify HikaShopa przechwytuje
     * wtyczka systemowa i oddaje wynik bez szablonu witryny; z tym parametrem żądanie
     * idzie przez komponent i strona ma zwykły wygląd sklepu.
     */
    protected const CUSTOMER_PAGE = '&skip_system_notification=1';

    /** Wynik przeglądu wcześniejszych prób zapłaty. */
    protected const ATTEMPTS_UNPAID   = 'unpaid';
    protected const ATTEMPTS_PAID     = 'paid';
    protected const ATTEMPTS_WAITING  = 'waiting';
    protected const ATTEMPTS_MISMATCH = 'mismatch';

    /**
     * Accepted currencies for PayU
     *
     * @var    array
     * @since  2.1.0
     */
    public $accepted_currencies = ['PLN', 'EUR', 'USD'];

    /**
     * Allow multiple payment methods
     *
     * @var    boolean
     * @since  2.1.0
     */
    public $multiple = true;

    /**
     * Plugin name
     *
     * @var    string
     * @since  2.1.0
     */
    public $name = 'payu';

    /**
     * Autoload language files
     *
     * @var    boolean
     * @since  2.1.0
     */
    protected $autoloadLanguage = true;

    /**
     * Plugin configuration for HikaShop
     *
     * @var    array
     * @since  2.1.0
     */
    public $pluginConfig = [
        'pos_id'                 => ['POS_ID', 'input'],
        'signature_key'          => ['SIGNATURE_KEY', 'input'],
        'oauth_client_id'        => ['OAUTH_CLIENT_ID', 'input'],
        'oauth_client_secret'    => ['OAUTH_CLIENT_SECRET', 'input'],
        'sandbox'                => ['SANDBOX_MODE', 'boolean', 1],
        'debug'                  => ['DEBUG', 'boolean', 0],
        // Lista pozycji menu Joomla - opcje wypełniane dynamicznie w konstruktorze (tylko admin)
        'return_page'            => ['RETURN_PAGE', 'list', ['' => 'RETURN_PAGE_DEFAULT']],
        'return_url'             => ['RETURN_URL', 'input'],
        'invalid_status'         => ['INVALID_STATUS', 'orderstatus'],
        'verified_status'        => ['VERIFIED_STATUS', 'orderstatus']
    ];

    /**
     * Dane dla widoku payu_end.php.
     *
     * @since  2.4.0
     */
    public $payu_redirect_url  = '';
    public $payu_error         = '';
    public $payu_paid_notice   = '';
    public $payu_unpaid_notice = '';
    public $payu_retry_url     = '';
    public $payu_waiting       = false;
    public $payu_status_url    = '';
    public $payu_check_url     = '';

    /**
     * Metoda płatności HikaShopa, której parametry są wczytane.
     *
     * @var    integer
     * @since  2.4.0
     */
    protected $payuMethodId = 0;

    /**
     * Constructor
     *
     * @param   object  $subject  The object to observe
     * @param   array   $config   An array that holds the plugin configuration
     *
     * @since   2.1.0
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $app = Factory::getApplication();

        // Load language file
        $lang = $app->getLanguage();
        $lang->load('plg_hikashoppayment_payu', JPATH_PLUGINS . '/hikashoppayment/payu');

        // Rozwijana lista stron powrotu = pozycje menu Joomla. Budujemy tylko w panelu admina
        // (tam renderowana jest konfiguracja HikaShop), żeby nie odpytywać bazy na froncie.
        if ($app->isClient('administrator')) {
            $this->pluginConfig['return_page'][2] = $this->buildReturnPageOptions();
        }
    }

    /**
     * Build the "return page" dropdown from published Joomla site menu items.
     *
     * @return  array  Map of Itemid => menu title (with an empty default option first)
     *
     * @since   2.2.4
     */
    private function buildReturnPageOptions(): array
    {
        $options = ['' => 'RETURN_PAGE_DEFAULT'];

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'title']))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->order($db->quoteName('lft') . ' ASC');
            $db->setQuery($query);

            foreach ((array) $db->loadObjectList() as $item) {
                $options[(int) $item->id] = $item->title;
            }
        } catch (\Exception $e) {
            // Brak dostępu do menu = zostaje tylko opcja domyślna (strona HikaShop)
        }

        return $options;
    }

    /**
     * Set default values for payment method
     *
     * @param   object  $element  The payment element
     *
     * @return  void
     *
     * @since   2.1.0
     */
    public function getPaymentDefaultValues(&$element): void
    {
        $element->payment_name                         = 'PayU';
        $element->payment_description                  = Text::_('PLG_HIKASHOPPAYMENT_PAYU_DESCRIPTION');
        $element->payment_params->sandbox              = 1;
        $element->payment_params->debug                = 0;
        $element->payment_params->invalid_status       = 'cancelled';
        $element->payment_params->verified_status      = 'confirmed';
    }

    /**
     * Zakłada płatność w PayU po złożeniu zamówienia i pokazuje stronę przejścia.
     *
     * Tę samą metodę HikaShop woła przy „Zapłać teraz” z listy zamówień.
     *
     * Do 2.3.1 wtyczka przekierowywała do PayU w środku tej metody. HikaShop nie kończył
     * wtedy kroku kasy: nie zapisywał numeru zamówienia w sesji i nie opróżniał koszyka.
     * Teraz metoda wypisuje stronę przejścia, a przekierowanie robi przeglądarka.
     *
     * @param   object  $order      The order object
     * @param   array   $methods    Payment methods
     * @param   int     $method_id  The selected method ID
     *
     * @return  boolean
     *
     * @since   2.1.0
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        $this->payuMethodId   = (int) $method_id;
        $this->payu_retry_url = $this->buildRetryUrl($order);

        $this->startPayment($order, $this->buildLogger());

        return $this->showPage('end');
    }

    /**
     * Zakłada w PayU nową próbę zapłaty i przygotowuje adres strony płatności.
     *
     * Wspólne dla złożenia zamówienia, „Zapłać teraz” i ponowienia z przycisku wtyczki.
     * Przy niepowodzeniu ustawia komunikat dla klienta (payu_error albo payu_paid_notice)
     * i zwraca fałsz.
     *
     * @return  boolean  Czy adres strony płatności jest gotowy (payu_redirect_url)
     *
     * @since   2.4.0
     */
    protected function startPayment($order, Logger $logger)
    {
        $orderId = (int) ($order->order_id ?? 0);

        try {
            if (!$this->isConfigured()) {
                $logger->error('Metoda płatności PayU nie ma kompletu danych dostępowych', ['order_id' => $orderId]);

                $this->payu_error     = Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_NOT_CONFIGURED');
                $this->payu_retry_url = '';

                return false;
            }

            // Stan zamówienia czytamy z bazy, nie z obiektu, który dostaliśmy: kasa podaje
            // zamówienie bez danych płatności, a o tym, czy już zapłacono, decyduje to, co zapisane.
            $zapisane = $this->getOrder($orderId);
            $stan     = \is_object($zapisane) ? $zapisane : $order;

            // Zamówienie opłacone nie może być opłacone po raz drugi.
            if ($this->isAlreadyPaid($stan)) {
                $logger->warning('Próba ponownej zapłaty za opłacone zamówienie', [
                    'order_id' => $orderId,
                    'status'   => $stan->order_status ?? '',
                ]);

                $this->payu_paid_notice = Text::_('PLG_HIKASHOPPAYMENT_PAYU_ALREADY_PAID');
                $this->payu_retry_url   = '';

                return false;
            }

            $amount   = Amount::toMinor($this->orderTotal($order));
            $currency = $this->currencyCode();

            if ($amount <= 0) {
                $logger->error('Kwota zamówienia jest zerowa lub ujemna', ['order_id' => $orderId]);

                $this->payu_error     = Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_CREATE');
                $this->payu_retry_url = '';

                return false;
            }

            $client = $this->getPayuClient();

            // Powiadomienie o zapłacie potrafi nie dotrzeć, a klient chce wtedy zapłacić
            // jeszcze raz. Zanim założymy nową próbę, pytamy PayU o wcześniejsze: opłaconą
            // potwierdzamy, niedokończone anulujemy, żeby nie dało się zapłacić dwa razy.
            try {
                [$wczesniejsze] = $this->reviewAttempts($stan, $client, $logger, $amount, $currency, true);
            } catch (PayuApiException $exception) {
                // Skoro nie wiemy, czy poprzednia próba nie została opłacona, nowej nie
                // zakładamy. Klient może spróbować za chwilę.
                $logger->error('Nie udało się sprawdzić w PayU wcześniejszej próby zapłaty', [
                    'order_id' => $orderId,
                    'http'     => $exception->getHttpStatus(),
                    'powod'    => $exception->getMessage(),
                ]);

                $this->payu_error = $exception->isAuthenticationFailure()
                    ? Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_AUTH')
                    : Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_CHECK');

                return false;
            }

            if ($wczesniejsze !== self::ATTEMPTS_UNPAID) {
                $this->payu_retry_url = '';

                if ($wczesniejsze === self::ATTEMPTS_PAID) {
                    $this->payu_paid_notice = Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_CONFIRMED');
                } elseif ($wczesniejsze === self::ATTEMPTS_WAITING) {
                    $this->payu_paid_notice = Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_WAITING');
                } else {
                    $this->payu_error = Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_MISMATCH');
                }

                return false;
            }

            // Każda próba ma własny extOrderId: PayU odrzuca powtórzony.
            $extOrderId = ExtOrderId::generate($orderId, $this->signatureKey());
            $request    = $this->buildOrderRequest($order, $extOrderId, $amount, $currency);

            [$payuOrderId, $redirectUri] = $client->createOrder($request);

            OrderPaymentData::startAttempt($orderId, $payuOrderId, $this->payuMethodId, $amount, $currency);

            $logger->info('Płatność założona w PayU', [
                'order_id'   => $orderId,
                'payu_order' => $payuOrderId,
                'kwota'      => $amount,
                'waluta'     => $currency,
            ]);

            $this->payu_redirect_url = $redirectUri;

            return true;
        } catch (PayuApiException $exception) {
            $logger->error('Założenie płatności w PayU nie powiodło się', [
                'order_id' => $orderId,
                'http'     => $exception->getHttpStatus(),
                'powod'    => $exception->getMessage(),
            ]);

            $this->payu_error = $exception->isAuthenticationFailure()
                ? Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_AUTH')
                : Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_CREATE');
        } catch (\Throwable $exception) {
            $logger->error('Nieoczekiwany błąd przy zakładaniu płatności', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            $this->payu_error = Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_CREATE');
        }

        return false;
    }

    /**
     * Dane zamówienia wysyłane do PayU.
     *
     * @since   2.4.0
     */
    protected function buildOrderRequest($order, string $extOrderId, int $amount, string $currency): array
    {
        $request = [
            'continueUrl'  => $this->httpsOnly($this->buildReturnUrl($order)),
            'notifyUrl'    => $this->httpsOnly($this->buildNotifyUrl()),
            'customerIp'   => $this->clientIp(),
            'description'  => Text::sprintf('PLG_HIKASHOPPAYMENT_PAYU_ORDER_DESCRIPTION', $order->order_number ?? $order->order_id),
            'currencyCode' => $currency,
            'totalAmount'  => $amount,
            'extOrderId'   => $extOrderId,
            'validityTime' => 86400,
            'products'     => $this->buildProductsArray($order),
        ];

        // PayU wymaga co najmniej jednej pozycji.
        if ($request['products'] === []) {
            $request['products'][] = [
                'name'      => $request['description'],
                'unitPrice' => $amount,
                'quantity'  => 1,
            ];
        }

        $buyer = $this->buildBuyer($order);

        if ($buyer !== []) {
            $request['buyer'] = $buyer;
        }

        return $request;
    }

    /**
     * Dane kupującego dla PayU.
     *
     * Do 2.3.1 wtyczka czytała pola address_email i address_phone, których adres
     * HikaShopa nie ma, więc do PayU szły puste i klient wpisywał e-mail drugi raz
     * na stronie płatności. E-mail jest przy koncie klienta, telefon w address_telephone.
     * Pustych pól nie wysyłamy wcale.
     *
     * @since   2.4.0
     */
    protected function buildBuyer($order): array
    {
        $buyer = [
            'email'     => $this->customerEmail($order),
            'phone'     => $this->billingField($order, 'address_telephone'),
            'firstName' => $this->billingField($order, 'address_firstname'),
            'lastName'  => $this->billingField($order, 'address_lastname'),
        ];

        $buyer = array_filter($buyer, static fn (string $value): bool => $value !== '');

        // Język strony płatności: tylko te, które PayU obsługuje.
        $jezyk = strtolower((string) ($this->locale ?? ''));

        if ($buyer !== [] && \in_array($jezyk, ['pl', 'en', 'cs', 'de', 'es', 'fr', 'it', 'pt', 'sk', 'hu', 'ro', 'uk'], true)) {
            $buyer['language'] = $jezyk;
        }

        return $buyer;
    }

    /**
     * Build products array for PayU order
     *
     * @param   object  $order  The order object
     *
     * @return  array
     *
     * @since   2.1.0
     */
    private function buildProductsArray($order): array
    {
        $products = [];

        if (empty($order->cart->products) || !is_array($order->cart->products)) {
            return $products;
        }

        foreach ($order->cart->products as $p) {
            $price = $p->order_product_price_with_tax
                ?? (($p->order_product_price ?? 0) + ($p->order_product_tax ?? 0))
                ?: ($p->product_price_with_tax ?? $p->product_price ?? 0);

            $quantity = (int) ($p->order_product_quantity ?? $p->product_quantity ?? 1);

            if ($quantity <= 0) {
                continue;
            }

            $products[] = [
                'name'      => (string) ($p->order_product_name ?? $p->product_name ?? 'Produkt'),
                'unitPrice' => Amount::toMinor($price),
                'quantity'  => $quantity
            ];
        }

        return $products;
    }

    /**
     * Przegląda w PayU dotychczasowe próby zapłaty zamówienia.
     *
     * Każda próba to osobne zamówienie PayU. Wpłata mogła trafić do dowolnej z nich,
     * więc pytamy o wszystkie, od najnowszej:
     *
     * - COMPLETED w kwocie i walucie zamówienia: potwierdzamy zapłatę,
     * - COMPLETED w innej kwocie lub walucie: nie potwierdzamy, sprzedawca dostaje wiadomość,
     * - WAITING_FOR_CONFIRMATION: pieniądze czekają na przyjęcie w panelu PayU,
     * - NEW i PENDING: na życzenie ($cancelUnfinished) anulujemy w PayU; wpłata, która
     *   dotrze po anulowaniu, wraca do płacącego.
     *
     * @return  array{0: string, 1: bool}  [jedna ze stałych ATTEMPTS_*, czy któraś próba jest w toku]
     *
     * @throws  PayuApiException  gdy PayU nie odpowiada
     *
     * @since   2.4.0
     */
    protected function reviewAttempts($order, PayuClientInterface $client, Logger $logger, int $amount, string $currency, bool $cancelUnfinished): array
    {
        $orderId   = (int) ($order->order_id ?? 0);
        $anulowane = OrderPaymentData::getList($order, OrderPaymentData::CANCELLED);
        $wToku     = false;

        foreach (OrderPaymentData::attemptsOf($order) as $payuOrderId) {
            if (\in_array($payuOrderId, $anulowane, true)) {
                continue;
            }

            $dane = $client->retrieveOrder($payuOrderId);

            if ($dane === null) {
                continue;
            }

            $status = strtoupper((string) ($dane['status'] ?? ''));

            if ($status === 'COMPLETED') {
                if (!$this->amountMatches($dane, $amount, $currency)) {
                    $this->reportMismatch($orderId, $order, $payuOrderId, $dane, $amount, $currency, $logger);

                    return [self::ATTEMPTS_MISMATCH, false];
                }

                $logger->info('PayU ma już wpłatę za to zamówienie, potwierdzam ją bez czekania na powiadomienie', [
                    'order_id'   => $orderId,
                    'payu_order' => $payuOrderId,
                ]);

                $this->markPaid($orderId, $payuOrderId, $logger);

                return [self::ATTEMPTS_PAID, false];
            }

            if ($status === 'WAITING_FOR_CONFIRMATION') {
                $this->reportWaiting($orderId, $order, $payuOrderId, $logger);

                return [self::ATTEMPTS_WAITING, false];
            }

            if ($status !== 'NEW' && $status !== 'PENDING') {
                continue;
            }

            if (!$cancelUnfinished) {
                $wToku = $wToku || $status === 'PENDING';

                continue;
            }

            // Znacznik zapisujemy PRZED anulowaniem: powiadomienie CANCELED z PayU potrafi
            // przyjść natychmiast i nie może anulować całego zamówienia w sklepie.
            OrderPaymentData::markCancelled($orderId, $payuOrderId);

            try {
                $client->cancelOrder($payuOrderId);
            } catch (PayuApiException $exception) {
                OrderPaymentData::unmarkCancelled($orderId, $payuOrderId);

                throw $exception;
            }

            $logger->info('Niedokończona próba zapłaty anulowana w PayU przed założeniem nowej', [
                'order_id'   => $orderId,
                'payu_order' => $payuOrderId,
                'stan'       => $status,
            ]);
        }

        return [self::ATTEMPTS_UNPAID, $wToku];
    }

    /**
     * Czy kwota i waluta zamówienia PayU zgadzają się z zamówieniem w sklepie.
     *
     * @since   2.4.0
     */
    protected function amountMatches(array $payuOrder, int $amount, string $currency): bool
    {
        return (int) ($payuOrder['totalAmount'] ?? -1) === $amount
            && strcasecmp((string) ($payuOrder['currencyCode'] ?? ''), $currency) === 0;
    }

    /**
     * Obsługuje żądania kierowane do wtyczki przez zadanie notify HikaShopa.
     *
     * Bez parametru payu_action jest to powiadomienie serwera PayU o stanie płatności.
     * Z nim: powrót klienta ze strony płatności, ponowienie zapłaty albo pytanie strony
     * oczekiwania o wynik.
     *
     * @param   array  $statuses  Order statuses
     *
     * @return  string|boolean
     *
     * @since   2.1.0
     */
    public function onPaymentNotification(&$statuses)
    {
        $akcja = Factory::getApplication()->getInput()->getCmd('payu_action', '');

        if ($akcja === 'return') {
            return $this->handleReturn();
        }

        if ($akcja === 'retry') {
            return $this->handleRetry();
        }

        if ($akcja === 'status') {
            return $this->handleStatus();
        }

        return $this->handleNotification();
    }

    /**
     * Powiadomienie serwera PayU o stanie płatności.
     *
     * Odpowiedź 200 znaczy „przyjąłem, nie powtarzaj”. Inny kod każe PayU ponowić
     * powiadomienie, więc zwracamy go tylko tam, gdzie powtórka może coś zmienić.
     *
     * @since   2.4.0
     */
    protected function handleNotification()
    {
        $body = trim($this->readNotificationBody());

        if ($body === '') {
            return $this->reply(400, 'EMPTY');
        }

        $notification = json_decode($body, true);
        $notifOrder   = \is_array($notification) && \is_array($notification['order'] ?? null) ? $notification['order'] : [];
        $extOrderId   = (string) ($notifOrder['extOrderId'] ?? '');
        $payuOrderId  = (string) ($notifOrder['orderId'] ?? '');
        $status       = strtoupper((string) ($notifOrder['status'] ?? ''));

        // Numer zamówienia z treści służy tu tylko do znalezienia właściwej metody
        // płatności, czyli właściwego klucza podpisu. Treści wierzymy dopiero po
        // sprawdzeniu podpisu tym kluczem.
        $orderId = ExtOrderId::orderIdOf($extOrderId);
        $dbOrder = $orderId > 0 ? $this->getOrder($orderId) : null;

        if (empty($dbOrder)) {
            $this->writeToLog('PayU [BŁĄD] Powiadomienie dla nieznanego zamówienia | extOrderId=' . substr($extOrderId, 0, 40));

            return $this->reply(404, 'ORDER NOT FOUND');
        }

        // Do 2.3.1 wtyczka brała tu parametry pierwszej metody PayU z bazy. Przy dwóch
        // metodach (np. testowej i produkcyjnej) podpis sprawdzała złym kluczem i opłacone
        // zamówienia zostawały nieopłacone.
        if (!$this->loadParamsForOrder($dbOrder)) {
            $this->writeToLog('PayU [BŁĄD] Brak parametrów metody płatności dla zamówienia | order_id=' . $orderId);

            return $this->reply(500, 'NO PAYMENT METHOD');
        }

        $logger = $this->buildLogger();
        $client = $this->getPayuClient();

        if (!$client->verifyNotificationSignature($body, $this->readSignatureHeader())) {
            $logger->error('Powiadomienie z błędnym podpisem', ['order_id' => $orderId]);

            return $this->reply(400, 'INVALID SIGNATURE');
        }

        if (!ExtOrderId::isValid($extOrderId, $this->signatureKey()) || $payuOrderId === '') {
            $logger->error('Powiadomienie z błędnym identyfikatorem zamówienia', ['order_id' => $orderId]);

            return $this->reply(400, 'INVALID ORDER');
        }

        try {
            $this->loadOrderData($dbOrder);

            $logger->info('Powiadomienie przyjęte', [
                'order_id'   => $orderId,
                'payu_order' => $payuOrderId,
                'stan'       => $status,
            ]);

            if ($status === 'COMPLETED') {
                $this->notifiedCompleted($dbOrder, $notifOrder, $payuOrderId, $logger);
            } elseif ($status === 'WAITING_FOR_CONFIRMATION') {
                $this->reportWaiting($orderId, $dbOrder, $payuOrderId, $logger);
            } elseif (\in_array($status, ['CANCELED', 'REJECTED', 'EXPIRED'], true)) {
                $this->notifiedCancelled($dbOrder, $payuOrderId, $status, $logger);
            }

            // PENDING, NEW i nieznane stany niczego nie zmieniają.
            return $this->reply(200, 'OK');
        } catch (\Throwable $exception) {
            $logger->error('Nieoczekiwany błąd przy obsłudze powiadomienia', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            return $this->reply(500, 'ERROR');
        }
    }

    /**
     * Powiadomienie o zrealizowanej płatności.
     *
     * @since   2.4.0
     */
    protected function notifiedCompleted($dbOrder, array $notifOrder, string $payuOrderId, Logger $logger): void
    {
        $orderId = (int) $dbOrder->order_id;

        // Powiadomienie potrafi przyjść więcej niż raz. Sprawdzamy to przed jakimkolwiek
        // zapisem: bez tego każde powtórzenie wysyłałoby klientowi kolejny e-mail
        // i dokładało wpis do historii zamówienia.
        if (OrderPaymentData::getString($dbOrder, OrderPaymentData::PAID_AT) !== '') {
            $znane = OrderPaymentData::getString($dbOrder, OrderPaymentData::PAID_ORDER);

            if ($znane !== '' && $znane !== $payuOrderId) {
                $logger->warning('Druga wpłata PayU za zamówienie już opłacone inną próbą', [
                    'order_id'   => $orderId,
                    'payu_order' => $payuOrderId,
                    'oplacone'   => $znane,
                ]);
            } else {
                $logger->info('Powtórzone powiadomienie pominięte, zapłata jest już potwierdzona', ['order_id' => $orderId]);
            }

            return;
        }

        $amount   = Amount::toMinor($dbOrder->order_full_price ?? 0);
        $currency = $this->currencyCode();

        if (!$this->amountMatches($notifOrder, $amount, $currency)) {
            $this->reportMismatch($orderId, $dbOrder, $payuOrderId, $notifOrder, $amount, $currency, $logger);

            return;
        }

        $this->markPaid($orderId, $payuOrderId, $logger);
    }

    /**
     * Powiadomienie o anulowanej albo wygasłej płatności.
     *
     * Do 2.3.1 każde takie powiadomienie nadawało zamówieniu status nieudanej płatności.
     * PayU wysyła je także po upływie ważności dawno porzuconej próby, więc zamówienie
     * w międzyczasie opłacone inną drogą (przelewem, kolejną próbą) i nawet wysłane
     * wracało do „anulowane”, z e-mailem do klienta.
     *
     * @since   2.4.0
     */
    protected function notifiedCancelled($dbOrder, string $payuOrderId, string $status, Logger $logger): void
    {
        $orderId = (int) $dbOrder->order_id;
        $invalid = trim((string) ($this->payment_params->invalid_status ?? ''));
        $current = (string) ($dbOrder->order_status ?? '');
        $powod   = '';

        if ($invalid === '' || $current === $invalid) {
            return;
        }

        if ($this->isAlreadyPaid($dbOrder)) {
            $powod = 'zamówienie jest już opłacone';
        } elseif (\in_array($current, $this->closedStatuses(), true)) {
            $powod = 'zamówienie jest już zamknięte';
        } elseif (\in_array($payuOrderId, OrderPaymentData::getList($dbOrder, OrderPaymentData::CANCELLED), true)) {
            $powod = 'tę próbę anulowała sama wtyczka przed założeniem następnej';
        } elseif (OrderPaymentData::getString($dbOrder, OrderPaymentData::PAYU_ORDER_ID) !== ''
            && OrderPaymentData::getString($dbOrder, OrderPaymentData::PAYU_ORDER_ID) !== $payuOrderId
        ) {
            $powod = 'to nie jest bieżąca próba zapłaty';
        } elseif (($dbOrder->order_payment_method ?? '') !== $this->name) {
            $powod = 'klient zmienił metodę płatności';
        }

        if ($powod !== '') {
            $logger->info('Anulowanie płatności w PayU nie zmienia statusu zamówienia: ' . $powod, [
                'order_id'   => $orderId,
                'payu_order' => $payuOrderId,
                'stan'       => $status,
                'status'     => $current,
            ]);

            return;
        }

        $this->modifyOrder($orderId, $invalid, true, true);

        $logger->info('Zamówienie oznaczone jako nieopłacone', [
            'order_id' => $orderId,
            'status'   => $invalid,
            'stan'     => $status,
        ]);
    }

    /**
     * Zapisuje potwierdzoną zapłatę i nadaje zamówieniu status opłaconego.
     *
     * Wspólne dla obu dróg, którymi dowiadujemy się o zapłacie: powiadomienia z PayU
     * i własnego pytania do PayU. Wołać wyłącznie dla zamówienia PayU w stanie COMPLETED,
     * po sprawdzeniu kwoty i waluty.
     *
     * @return  boolean  Czy zamówienie jest w statusie opłaconego
     *
     * @since   2.4.0
     */
    protected function markPaid($orderId, string $payuOrderId, Logger $logger)
    {
        $orderId = (int) $orderId;

        // Powiadomienie i klient wracający ze strony płatności potrafią trafić do sklepu
        // w tej samej chwili. Kto przyszedł drugi, ten niczego nie powtarza.
        $przed = $this->getOrder($orderId);

        if (\is_object($przed) && OrderPaymentData::getString($przed, OrderPaymentData::PAID_AT) !== '') {
            return $this->hasPaidStatus($przed);
        }

        OrderPaymentData::store($orderId, [
            OrderPaymentData::PAID_AT    => gmdate('c'),
            OrderPaymentData::PAID_ORDER => $payuOrderId,
        ]);

        // Zamówienie mogło dostać status opłaconego wcześniej, na przykład ręcznie od
        // sprzedawcy, albo pójść już dalej, do wysyłki. Statusu nie cofamy i klienta
        // drugi raz nie zawiadamiamy.
        $aktualne = $this->getOrder($orderId);

        if ($this->hasPaidStatus($aktualne)) {
            $logger->info('Zapłata potwierdzona, status zamówienia zostaje bez zmian', [
                'order_id' => $orderId,
                'status'   => (string) ($aktualne->order_status ?? ''),
            ]);

            return true;
        }

        $verified = $this->verifiedStatus();

        try {
            $this->modifyOrder($orderId, $verified, true, true);
        } catch (\Throwable $exception) {
            $logger->error('Błąd przy zmianie statusu zamówienia', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);
        }

        if (!$this->hasPaidStatus($this->getOrder($orderId))) {
            $logger->error('Zapłata potwierdzona, ale status zamówienia się nie zmienił', [
                'order_id'          => $orderId,
                'oczekiwany_status' => $verified,
            ]);

            return false;
        }

        $logger->info('Zamówienie oznaczone jako opłacone', [
            'order_id'   => $orderId,
            'payu_order' => $payuOrderId,
            'status'     => $verified,
        ]);

        return true;
    }

    /**
     * Wpłata w PayU nie zgadza się z zamówieniem: nie potwierdzamy, zawiadamiamy sprzedawcę.
     *
     * @since   2.4.0
     */
    protected function reportMismatch($orderId, $order, string $payuOrderId, array $payuOrder, int $amount, string $currency, Logger $logger): void
    {
        $logger->error('W PayU jest wpłata za to zamówienie, ale w innej kwocie lub walucie niż zamówienie', [
            'order_id'    => $orderId,
            'payu_order'  => $payuOrderId,
            'wplata'      => (string) ($payuOrder['totalAmount'] ?? ''),
            'waluta_payu' => (string) ($payuOrder['currencyCode'] ?? ''),
            'zamowienie'  => $amount,
            'waluta'      => $currency,
        ]);

        $this->alertMerchant($orderId, $order, Text::sprintf(
            'PLG_HIKASHOPPAYMENT_PAYU_ALERT_MISMATCH',
            (string) ($order->order_number ?? $orderId),
            $payuOrderId,
            number_format(((int) ($payuOrder['totalAmount'] ?? 0)) / 100, 2, ',', ' ') . ' ' . (string) ($payuOrder['currencyCode'] ?? ''),
            number_format($amount / 100, 2, ',', ' ') . ' ' . $currency
        ), $logger);
    }

    /**
     * Wpłata czeka w PayU na ręczne przyjęcie (punkt płatności bez automatycznego odbioru).
     *
     * @since   2.4.0
     */
    protected function reportWaiting($orderId, $order, string $payuOrderId, Logger $logger): void
    {
        $logger->warning('Wpłata czeka w PayU na przyjęcie przez sprzedawcę (WAITING_FOR_CONFIRMATION)', [
            'order_id'   => $orderId,
            'payu_order' => $payuOrderId,
        ]);

        $this->alertMerchant($orderId, $order, Text::sprintf(
            'PLG_HIKASHOPPAYMENT_PAYU_ALERT_WAITING',
            (string) ($order->order_number ?? $orderId),
            $payuOrderId
        ), $logger);
    }

    /**
     * Zawiadamia sprzedawcę o kłopocie z płatnością, jeden raz na zamówienie.
     *
     * Wiadomość idzie na adres powiadomień o płatnościach z konfiguracji HikaShopa,
     * tą samą drogą, której używają wtyczki rdzenia.
     *
     * @since   2.4.0
     */
    protected function alertMerchant($orderId, $order, string $body, Logger $logger): void
    {
        $swiezy = $this->getOrder((int) $orderId);

        if (OrderPaymentData::getString(\is_object($swiezy) ? $swiezy : $order, OrderPaymentData::ALERT_AT) !== '') {
            return;
        }

        try {
            $adresat = \function_exists('hikashop_config')
                ? trim((string) hikashop_config()->get('payment_notification_email', ''))
                : '';

            if ($adresat === '') {
                $logger->warning('Brak adresu powiadomień o płatnościach w konfiguracji HikaShopa, sprzedawca nie dostanie wiadomości', [
                    'order_id' => $orderId,
                ]);

                return;
            }

            $email          = new \stdClass();
            $email->subject = Text::sprintf('PLG_HIKASHOPPAYMENT_PAYU_ALERT_SUBJECT', (string) ($order->order_number ?? $orderId));
            $email->body    = $body;

            // Fałsz w miejscu numeru zamówienia każe klasie bazowej wysłać samą
            // wiadomość, bez zapisywania czegokolwiek w zamówieniu.
            $bezZamowienia = false;
            $this->modifyOrder($bezZamowienia, null, null, $email);

            OrderPaymentData::store((int) $orderId, [OrderPaymentData::ALERT_AT => gmdate('c')]);

            $logger->warning('Sprzedawca zawiadomiony o kłopocie z płatnością', ['order_id' => $orderId]);
        } catch (\Throwable $blad) {
            $logger->error('Nie udało się zawiadomić sprzedawcy', [
                'order_id' => $orderId,
                'powod'    => $blad->getMessage(),
            ]);
        }
    }

    /**
     * Przyjmuje klienta wracającego ze strony płatności PayU.
     *
     * PayU odsyła klienta na ten sam adres po zapłacie, po błędzie i po rezygnacji.
     * Do 2.3.1 adres prowadził wprost do podziękowania, więc nieudana płatność wyglądała
     * w sklepie jak udana. Teraz pytamy PayU o stan:
     *
     * - wpłata jest: potwierdzamy ją i dopiero wtedy pokazujemy podziękowanie,
     * - płatność jest w toku: strona czeka na potwierdzenie i sama sprawdza wynik,
     * - wpłaty nie ma: klient czyta, że nie zapłacił, i dostaje przycisk ponowienia,
     * - PayU nie odpowiada: nie zgadujemy, klient dostaje neutralną informację.
     *
     * @since   2.4.0
     */
    protected function handleReturn()
    {
        $input   = Factory::getApplication()->getInput();
        $orderId = (int) $input->get('order_id', 0, 'int');
        $token   = (string) $input->get('payu_return', '', 'string');
        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder)
            || !$this->loadParamsForOrder($dbOrder)
            || !hash_equals($this->urlToken('return', $dbOrder), $token)
        ) {
            $this->writeToLog('PayU [BŁĄD] Powrót ze strony płatności z błędnym znacznikiem | order_id=' . $orderId);

            $this->payu_error     = Text::_('PLG_HIKASHOPPAYMENT_PAYU_LINK_INVALID');
            $this->payu_retry_url = '';

            return $this->renderPage('end');
        }

        $logger = $this->buildLogger();

        $this->loadOrderData($dbOrder);

        // Powiadomienie z PayU zwykle zdąża przed klientem.
        if ($this->isAlreadyPaid($dbOrder)) {
            $this->redirectTo($this->buildThanksUrl($dbOrder));

            return true;
        }

        $zamkniete = \in_array((string) ($dbOrder->order_status ?? ''), $this->closedStatuses(), true);

        $this->payu_retry_url = $zamkniete ? '' : $this->buildRetryUrl($dbOrder);

        try {
            [$wynik, $wToku] = $this->reviewAttempts(
                $dbOrder,
                $this->getPayuClient(),
                $logger,
                Amount::toMinor($dbOrder->order_full_price ?? 0),
                $this->currencyCode(),
                false
            );
        } catch (\Throwable $exception) {
            $logger->error('Nie udało się sprawdzić stanu płatności po powrocie klienta', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            $this->payu_unpaid_notice = Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_UNKNOWN');
            $this->payu_retry_url     = '';
            $this->payu_check_url     = $this->buildReturnUrl($dbOrder);

            return $this->renderPage('end');
        }

        if ($wynik === self::ATTEMPTS_PAID) {
            $this->redirectTo($this->buildThanksUrl($dbOrder));

            return true;
        }

        if ($wynik === self::ATTEMPTS_WAITING) {
            $this->payu_paid_notice = Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_WAITING');
            $this->payu_retry_url   = '';

            return $this->renderPage('end');
        }

        if ($wynik === self::ATTEMPTS_MISMATCH) {
            $this->payu_error     = Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_MISMATCH');
            $this->payu_retry_url = '';

            return $this->renderPage('end');
        }

        // Przy nieudanej płatności PayU dopisuje do adresu powrotu parametr error.
        // To słowo przeglądarki, więc decyduje tylko o treści komunikatu, nie o zapłacie.
        $zgloszonyBlad = (string) $input->get('error', '', 'string') !== '';
        $juzCzekal     = (int) $input->get('payu_waited', 0, 'int') === 1;

        $logger->info('Klient wrócił ze strony płatności bez potwierdzonej zapłaty', [
            'order_id' => $orderId,
            'w_toku'   => $wToku,
            'blad'     => $zgloszonyBlad,
        ]);

        // Po udanej płatności PayU potwierdza ją zwykle w kilka sekund. Klient, który
        // wrócił wcześniej, nie powinien czytać, że nie zapłacił: strona czeka i sama
        // sprawdza wynik.
        if ($wToku && !$zgloszonyBlad && !$juzCzekal && !$zamkniete) {
            $this->payu_waiting    = true;
            $this->payu_status_url = $this->buildStatusUrl($dbOrder);
            $this->payu_check_url  = $this->buildReturnUrl($dbOrder) . '&payu_waited=1';

            return $this->renderPage('end');
        }

        if ($zgloszonyBlad) {
            $this->payu_unpaid_notice = Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_FAILED');
        } elseif ($wToku) {
            $this->payu_unpaid_notice = Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_PENDING');
        } else {
            $this->payu_unpaid_notice = Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_UNPAID');
        }

        return $this->renderPage('end');
    }

    /**
     * Ponawia zapłatę za zamówienie z przycisku wtyczki.
     *
     * HikaShop ma własne „Zapłać teraz” dopiero od wersji Essential, a zadanie notify
     * jest dostępne w każdej. Po udanym założeniu płatności przekierowuje prosto do PayU.
     *
     * @since   2.4.0
     */
    protected function handleRetry()
    {
        $input   = Factory::getApplication()->getInput();
        $orderId = (int) $input->get('order_id', 0, 'int');
        $token   = (string) $input->get('payu_retry', '', 'string');
        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder)
            || !$this->loadParamsForOrder($dbOrder)
            || !hash_equals($this->urlToken('retry', $dbOrder), $token)
        ) {
            $this->writeToLog('PayU [BŁĄD] Odrzucone ponowienie płatności | order_id=' . $orderId);

            $this->payu_error     = Text::_('PLG_HIKASHOPPAYMENT_PAYU_LINK_INVALID');
            $this->payu_retry_url = '';

            return $this->renderPage('end');
        }

        $logger = $this->buildLogger();

        $this->loadOrderData($dbOrder);

        // Zamówienia zwróconego albo anulowanego nie wolno opłacić ponownie:
        // towar mógł już wrócić na stan.
        $status = (string) ($dbOrder->order_status ?? '');

        if (\in_array($status, $this->closedStatuses(), true)) {
            $logger->warning('Ponowienie zapłaty za zamknięte zamówienie', ['order_id' => $orderId, 'status' => $status]);

            $this->payu_error     = Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETRY_UNAVAILABLE');
            $this->payu_retry_url = '';

            return $this->renderPage('end');
        }

        $this->payu_retry_url = $this->buildRetryUrl($dbOrder);

        $logger->info('Klient ponawia zapłatę', ['order_id' => $orderId]);

        if (!$this->startPayment($dbOrder, $logger)) {
            return $this->renderPage('end');
        }

        $this->redirectTo($this->payu_redirect_url);

        return true;
    }

    /**
     * Odpowiada stronie oczekiwania, czy płatność została już potwierdzona.
     *
     * Odpowiedź to JSON z polem state: paid (w redirect adres podziękowania), check
     * (sprawa wymaga strony powrotu), waiting albo invalid. Zwykle zapłatę potwierdza
     * powiadomienie z PayU i wtedy wystarcza odczyt zamówienia z bazy; PayU pytamy tylko
     * na prośbę skryptu (payu_ask). Skrypt o niczym nie decyduje.
     *
     * @since   2.4.0
     */
    protected function handleStatus()
    {
        $input   = Factory::getApplication()->getInput();
        $orderId = (int) $input->get('order_id', 0, 'int');
        $token   = (string) $input->get('payu_status', '', 'string');
        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder)
            || !$this->loadParamsForOrder($dbOrder)
            || !hash_equals($this->urlToken('status', $dbOrder), $token)
        ) {
            return $this->jsonReply(['state' => 'invalid']);
        }

        $this->loadOrderData($dbOrder);

        if ($this->isAlreadyPaid($dbOrder)) {
            return $this->jsonReply(['state' => 'paid', 'redirect' => $this->buildThanksUrl($dbOrder)]);
        }

        $powrot = $this->buildReturnUrl($dbOrder) . '&payu_waited=1';

        if (\in_array((string) ($dbOrder->order_status ?? ''), $this->closedStatuses(), true)) {
            return $this->jsonReply(['state' => 'check', 'redirect' => $powrot]);
        }

        if ((int) $input->get('payu_ask', 0, 'int') !== 1) {
            return $this->jsonReply(['state' => 'waiting']);
        }

        $logger = $this->buildLogger();

        try {
            [$wynik] = $this->reviewAttempts(
                $dbOrder,
                $this->getPayuClient(),
                $logger,
                Amount::toMinor($dbOrder->order_full_price ?? 0),
                $this->currencyCode(),
                false
            );
        } catch (\Throwable $exception) {
            $logger->error('Nie udało się sprawdzić stanu płatności na prośbę strony oczekiwania', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            return $this->jsonReply(['state' => 'waiting']);
        }

        if ($wynik === self::ATTEMPTS_PAID) {
            return $this->jsonReply(['state' => 'paid', 'redirect' => $this->buildThanksUrl($dbOrder)]);
        }

        if ($wynik !== self::ATTEMPTS_UNPAID) {
            return $this->jsonReply(['state' => 'check', 'redirect' => $powrot]);
        }

        return $this->jsonReply(['state' => 'waiting']);
    }

    /**
     * Wczytuje parametry metody płatności, którą założono płatność zamówienia.
     *
     * @return  boolean
     *
     * @since   2.4.0
     */
    protected function loadParamsForOrder($order)
    {
        $this->payment_params = null;
        $this->payuMethodId   = 0;

        $kandydaci = [OrderPaymentData::getInt($order, OrderPaymentData::PAYMENT_ID)];

        if (($order->order_payment_method ?? '') === $this->name) {
            $kandydaci[] = (int) ($order->order_payment_id ?? 0);
        }

        foreach (array_unique(array_filter($kandydaci)) as $id) {
            if ($this->pluginParams($id) && \is_object($this->plugin_params)) {
                return $this->useMethod($id);
            }
        }

        // Płatność założona przed 2.4.0, a klient zmienił potem metodę płatności
        // zamówienia: jeśli sklep ma dokładnie jedną metodę PayU, to była ta.
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('payment_id'))
                ->from($db->quoteName('#__hikashop_payment'))
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote($this->name));
            $db->setQuery($query);
            $wszystkie = array_map('intval', (array) $db->loadColumn());
        } catch (\Throwable $blad) {
            $wszystkie = [];
        }

        if (\count($wszystkie) === 1 && $this->pluginParams($wszystkie[0]) && \is_object($this->plugin_params)) {
            return $this->useMethod($wszystkie[0]);
        }

        return false;
    }

    private function useMethod(int $id): bool
    {
        $this->payment_params = $this->plugin_params;
        $this->payuMethodId   = $id;

        if (!empty($this->plugin_data->payment_name)) {
            $this->payment_name = $this->plugin_data->payment_name;
        }

        return true;
    }

    /**
     * Build a PayU REST client from the current payment parameters.
     *
     * @return  PayuClientInterface
     *
     * @since   2.3.0
     */
    protected function getPayuClient(): PayuClientInterface
    {
        return new PayuRestClient(
            !empty($this->payment_params->sandbox),
            trim((string) ($this->payment_params->pos_id ?? '')),
            $this->signatureKey(),
            trim((string) ($this->payment_params->oauth_client_id ?? '')),
            trim((string) ($this->payment_params->oauth_client_secret ?? ''))
        );
    }

    protected function buildLogger(): Logger
    {
        return new Logger($this->name, !empty($this->payment_params->debug), [
            $this->signatureKey(),
            trim((string) ($this->payment_params->oauth_client_secret ?? '')),
        ]);
    }

    protected function isConfigured(): bool
    {
        foreach (['pos_id', 'signature_key', 'oauth_client_id', 'oauth_client_secret'] as $pole) {
            if (trim((string) ($this->payment_params->$pole ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    protected function signatureKey(): string
    {
        return trim((string) ($this->payment_params->signature_key ?? ''));
    }

    protected function verifiedStatus(): string
    {
        $status = trim((string) ($this->payment_params->verified_status ?? ''));

        return $status !== '' ? $status : 'confirmed';
    }

    /**
     * Czy za zamówienie już zapłacono.
     *
     * Najpewniejszym dowodem jest nasz własny znacznik potwierdzenia. Status zamówienia
     * jest dowodem słabszym, ale jedynym dla zamówień potwierdzonych ręcznie.
     *
     * @since   2.4.0
     */
    protected function isAlreadyPaid($order)
    {
        if (\is_object($order) && OrderPaymentData::getString($order, OrderPaymentData::PAID_AT) !== '') {
            return true;
        }

        return $this->hasPaidStatus($order);
    }

    /**
     * Czy zamówienie jest w statusie oznaczającym zapłatę.
     *
     * Sam status potwierdzenia z konfiguracji metody nie wystarcza: zamówienie wysłane
     * też jest opłacone. Listę takich statusów HikaShop trzyma u siebie, jako statusy,
     * w których wystawia fakturę.
     *
     * @since   2.4.0
     */
    protected function hasPaidStatus($order)
    {
        $current = (string) ($order->order_status ?? '');

        if ($current === '') {
            return false;
        }

        $paid = [$this->verifiedStatus()];

        if (\function_exists('hikashop_config')) {
            $lista = (string) hikashop_config()->get('invoice_order_statuses', 'confirmed,shipped');
            $paid  = array_merge($paid, array_map('trim', explode(',', $lista)));
        }

        return \in_array($current, $paid, true);
    }

    /**
     * Statusy zamówień zamkniętych: anulowanych i zwróconych.
     *
     * @return  list<string>
     *
     * @since   2.4.0
     */
    protected function closedStatuses()
    {
        $statusy = ['cancelled', 'refunded'];

        if (\function_exists('hikashop_config')) {
            $lista   = (string) hikashop_config()->get('cancelled_order_status', 'cancelled,refunded');
            $statusy = array_merge($statusy, array_map('trim', explode(',', $lista)));
        }

        return array_values(array_unique(array_filter($statusy, static fn ($status): bool => $status !== '')));
    }

    /**
     * Adres, na który PayU wysyła powiadomienia o stanie płatności.
     *
     * @since   2.4.0
     */
    protected function buildNotifyUrl()
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&tmpl=component'
            . '&lang=' . urlencode((string) ($this->locale ?? ''));
    }

    /**
     * Adres, pod który PayU odsyła klienta ze strony płatności (continueUrl).
     *
     * @since   2.4.0
     */
    protected function buildReturnUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&payu_action=return'
            . self::CUSTOMER_PAGE
            . '&order_id=' . (int) $order->order_id
            . '&payu_return=' . $this->urlToken('return', $order)
            . $this->customerUrlSuffix();
    }

    /**
     * Adres ponowienia zapłaty za to samo zamówienie.
     *
     * @since   2.4.0
     */
    protected function buildRetryUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&payu_action=retry'
            . self::CUSTOMER_PAGE
            . '&order_id=' . (int) $order->order_id
            . '&payu_retry=' . $this->urlToken('retry', $order)
            . $this->customerUrlSuffix();
    }

    /**
     * Adres, pod którym strona oczekiwania pyta o wynik płatności.
     *
     * Bez dopisku CUSTOMER_PAGE i celowo: odpowiedzią jest JSON dla skryptu, a wtyczka
     * systemowa HikaShopa oddaje wynik zadania notify bez szablonu witryny. Adres jest
     * względny, żeby pytanie zawsze szło do tej samej domeny, z której klient ogląda stronę.
     *
     * @since   2.4.0
     */
    protected function buildStatusUrl($order)
    {
        return Uri::root(true) . '/index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&payu_action=status'
            . '&tmpl=component&format=raw'
            . '&lang=' . urlencode((string) ($this->locale ?? ''))
            . '&order_id=' . (int) $order->order_id
            . '&payu_status=' . $this->urlToken('status', $order);
    }

    /**
     * Adres, na który klient trafia po potwierdzonej zapłacie.
     *
     * Kolejność: własny adres z konfiguracji metody, wybrana pozycja menu, na końcu
     * strona podziękowania HikaShopa.
     *
     * @since   2.4.0
     */
    protected function buildThanksUrl($order)
    {
        $custom = trim((string) ($this->payment_params->return_url ?? ''));
        $page   = (int) ($this->payment_params->return_page ?? 0);

        if ($custom !== '') {
            return preg_match('#^https?://#i', $custom) === 1
                ? $custom
                : rtrim(HIKASHOP_LIVE, '/') . '/' . ltrim($custom, '/');
        }

        if ($page > 0) {
            return HIKASHOP_LIVE . 'index.php?Itemid=' . $page;
        }

        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end'
            . '&order_id=' . (int) $order->order_id
            . $this->customerUrlSuffix();
    }

    /**
     * Znacznik adresu, osobny dla powrotu, ponowienia i pytania o wynik.
     *
     * Wyprowadzony z tajnego order_token zamówienia, więc adresu nie da się ułożyć
     * dla cudzego zamówienia, a jeden adres nie otwiera drugiego.
     *
     * @since   2.4.0
     */
    protected function urlToken(string $cel, $order)
    {
        return hash_hmac('sha256', 'payu-' . $cel . '|' . (int) ($order->order_id ?? 0), (string) ($order->order_token ?? ''));
    }

    /**
     * Dopisek adresów stron, które ogląda klient: znacznik zamówienia gościa i pozycja menu.
     *
     * „Zapłać teraz” z e-maila albo z listy zamówień niesie pozycję menu sklepu lub konta
     * i strony płatności wyświetlałyby się w cudzym układzie. Gdy w konfiguracji HikaShopa
     * jest wskazana pozycja menu kasy, strony płatności zawsze jej używają.
     *
     * @since   2.4.0
     */
    protected function customerUrlSuffix()
    {
        $suffix = (string) ($this->url_itemid ?? '');
        $kasa   = \function_exists('hikashop_config') ? (int) hikashop_config()->get('checkout_itemid', 0) : 0;

        if ($kasa <= 0) {
            return $suffix;
        }

        return preg_replace('/&Itemid=\d*/', '', $suffix) . '&Itemid=' . $kasa;
    }

    /**
     * PayU odrzuca continueUrl i notifyUrl bez https (ERROR_VALUE_INVALID - Invalid continueUrl).
     *
     * @since   2.4.0
     */
    protected function httpsOnly(string $url): string
    {
        return (string) preg_replace('#^http://#i', 'https://', $url);
    }

    /**
     * Wartość zamówienia brutto.
     *
     * Kwotę bierzemy z bazy, czyli z tego samego miejsca, z którego weźmie ją potem
     * obsługa powiadomienia. Koszyk trzyma ją z pełną precyzją, a baza zaokrągla,
     * więc tuż pod granicą pół grosza obie drogi dawałyby różne kwoty.
     *
     * @since   2.4.0
     */
    protected function orderTotal($order)
    {
        $zapisane = $this->getOrder((int) ($order->order_id ?? 0));

        if (\is_object($zapisane) && isset($zapisane->order_full_price) && (float) $zapisane->order_full_price > 0) {
            return $zapisane->order_full_price;
        }

        if (isset($order->cart->full_total->prices[0]->price_value_with_tax)) {
            return $order->cart->full_total->prices[0]->price_value_with_tax;
        }

        return $order->order_full_price ?? 0;
    }

    /**
     * Kod waluty zamówienia.
     *
     * Do 2.3.1 wtyczka czytała pole price_currency, którego HikaShop nie ma, i do PayU
     * szło zawsze PLN, także dla zamówień w euro i dolarach.
     *
     * @since   2.4.0
     */
    protected function currencyCode()
    {
        $code = \is_object($this->currency ?? null) ? (string) ($this->currency->currency_code ?? '') : '';

        return $code !== '' ? strtoupper($code) : 'PLN';
    }

    /**
     * Adres e-mail klienta.
     *
     * Najpierw właściciel zamówienia, dopiero potem zalogowany użytkownik: przy
     * ponowieniu zapłaty przeglądarka nie musi mieć sesji klienta.
     *
     * @since   2.4.0
     */
    protected function customerEmail($order)
    {
        $email = self::emailFrom($order->customer->user_email ?? '');

        if ($email === '' && !empty($order->order_user_id)) {
            $customer = hikashop_get('class.user')->get((int) $order->order_user_id);
            $email    = self::emailFrom($customer->user_email ?? '');
        }

        if ($email === '') {
            $email = self::emailFrom($this->user->user_email ?? '');
        }

        return $email;
    }

    /**
     * Wyciąga adres z pola user_email.
     *
     * Po zapisie nowego zamówienia HikaShop potrafi trzymać w nim user_email jako
     * tablicę. Rzutowanie na tekst dałoby wtedy "Array".
     */
    private static function emailFrom($value): string
    {
        if (\is_array($value)) {
            $value = reset($value);
        }

        if (!\is_string($value)) {
            return '';
        }

        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
    }

    protected function billingField($order, $field)
    {
        return trim((string) ($order->cart->billing_address->$field ?? ''));
    }

    protected function clientIp(): string
    {
        $ip = (string) Factory::getApplication()->getInput()->server->getString('REMOTE_ADDR', '');

        return $ip !== '' ? $ip : '127.0.0.1';
    }

    /**
     * Treść powiadomienia z PayU. Osobna metoda, żeby testy mogły ją podstawić.
     */
    protected function readNotificationBody()
    {
        return (string) file_get_contents('php://input');
    }

    protected function readSignatureHeader()
    {
        return PayuRestClient::readIncomingSignatureHeader();
    }

    /**
     * Odpowiedź dla serwera PayU: kod HTTP i krótka treść.
     */
    protected function reply($code, $text)
    {
        if (!headers_sent()) {
            http_response_code((int) $code);
        }

        return (string) $text;
    }

    protected function jsonReply(array $dane)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        return (string) json_encode($dane, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Zwraca widok wtyczki jako tekst.
     *
     * Zadanie notify HikaShopa przechwytuje wszystko, co zostanie wypisane, i odkłada
     * to do dziennika. Na ekran trafia tylko zwrócony tekst.
     */
    protected function renderPage($name)
    {
        ob_start();
        $this->showPage($name);

        return (string) ob_get_clean();
    }

    protected function redirectTo($url)
    {
        Factory::getApplication()->redirect($url);
    }
}
