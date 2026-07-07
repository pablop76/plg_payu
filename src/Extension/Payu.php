<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.3.1
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 */

namespace Pablop76\Plugin\HikashopPayment\Payu\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Pablop76\Plugin\HikashopPayment\Payu\Client\PayuRestClient;

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
 * @since  2.1.0
 */
class Payu extends \hikashopPaymentPlugin
{
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
     * Handle order confirmation and create PayU payment
     *
     * @param   object  $order      The order object
     * @param   array   $methods    Payment methods
     * @param   int     $method_id  The selected method ID
     *
     * @return  boolean  True on success
     *
     * @since   2.1.0
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        if (empty($this->payment_params->pos_id) || empty($this->payment_params->signature_key) || empty($this->payment_params->oauth_client_id) || empty($this->payment_params->oauth_client_secret)) {
            $this->app->enqueueMessage(Text::_('PLG_HIKASHOPPAYMENT_PAYU_ERROR_NOT_CONFIGURED'), 'error');
            $this->logError('PayU plugin misconfiguration: missing credentials');
            return false;
        }

        $client = $this->getPayuClient();

        // URL powrotu użytkownika. Priorytet:
        //   1) własny URL z konfiguracji (return_url) - może być względny,
        //   2) wybrana pozycja menu Joomla (return_page = Itemid),
        //   3) domyślna strona potwierdzenia HikaShop (after_end).
        // Status zamówienia aktualizuje webhook (notifyUrl). $this->url_itemid pochodzi z klasy bazowej.
        $custom_url  = isset($this->payment_params->return_url) ? trim((string) $this->payment_params->return_url) : '';
        $return_page = isset($this->payment_params->return_page) ? (int) $this->payment_params->return_page : 0;

        if ($custom_url !== '') {
            // Dopuszczamy adres względny (np. /zamowienia) - budujemy absolutny na bazie HIKASHOP_LIVE,
            // bo PayU wymaga pełnego adresu https (Invalid continueUrl).
            $return_url = preg_match('#^https?://#i', $custom_url)
                ? $custom_url
                : rtrim(HIKASHOP_LIVE, '/') . '/' . ltrim($custom_url, '/');
        } elseif ($return_page > 0) {
            // Wybrana strona z menu Joomla - absolutny URL po Itemid (Joomla rozwiąże do właściwej strony).
            $return_url = HIKASHOP_LIVE . 'index.php?Itemid=' . $return_page;
        } else {
            $return_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . $order->order_id . $this->url_itemid;
        }

        // Webhook URL dla powiadomień serwer-serwer (z tmpl=component i lang)
        $notify_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=payu&tmpl=component&lang=' . $this->locale . $this->url_itemid;

        // PayU odrzuca continueUrl/notifyUrl bez https (ERROR_VALUE_INVALID - Invalid continueUrl).
        // Wymuszamy schemat https na adresach wysyłanych do PayU.
        $return_url = preg_replace('#^http://#i', 'https://', $return_url);
        $notify_url = preg_replace('#^http://#i', 'https://', $notify_url);

        // Diagnostyka: PayU wymaga absolutnego adresu https z hostem publicznym.
        $return_scheme = strtolower((string) parse_url($return_url, PHP_URL_SCHEME));
        $return_host   = (string) parse_url($return_url, PHP_URL_HOST);
        $is_ip         = filter_var($return_host, FILTER_VALIDATE_IP) !== false;
        $is_public_ip  = filter_var($return_host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if ($return_scheme !== 'https' || $return_host === '') {
            $this->logError('continueUrl nie jest poprawnym absolutnym adresem https (' . $return_url . ') - PayU odrzuci go jako Invalid continueUrl. Sprawdź HIKASHOP_LIVE (Live Site w konfiguracji Joomla) lub pole Return URL.');
        } elseif (strcasecmp($return_host, 'localhost') === 0 || ($is_ip && !$is_public_ip)) {
            $this->logError('continueUrl wskazuje na localhost/adres prywatny (' . $return_host . ') - PayU odrzuci go jako Invalid continueUrl. Ustaw publiczną domenę https lub pole Return URL.');
        }

        $this->logDebug('continueUrl set to: ' . $return_url);
        $this->logDebug('notifyUrl set to: ' . $notify_url);
        
        $currency = $order->cart->full_total->prices[0]->price_currency ?? 'PLN';
        $amount = (int) round(($order->cart->full_total->prices[0]->price_value_with_tax ?? 0) * 100);

        // Przygotowanie danych zamówienia
        $orderData = [
            'continueUrl'   => $return_url,
            'notifyUrl'     => $notify_url,
            'customerIp'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'description'   => Text::sprintf('PLG_HIKASHOPPAYMENT_PAYU_ORDER_DESCRIPTION', $order->order_number),
            'currencyCode'  => $currency,
            'totalAmount'   => $amount,
            'extOrderId'    => $this->getOrderToken($order->order_id),
            'validityTime'  => 86400
        ];

        // Produkty
        $orderData['products'] = $this->buildProductsArray($order);

        // Adres billing
        $billing = $this->getBillingAddress($order);

        if ($billing) {
            $orderData['buyer'] = [
                'email'     => $billing->address_email ?? '',
                'phone'     => $billing->address_phone ?? '',
                'firstName' => $billing->address_firstname ?? '',
                'lastName'  => $billing->address_lastname ?? ''
            ];
        }

        if (!empty($this->payment_params->debug)) {
            $this->logDebug('PayU Order Request: ' . print_r($orderData, true));
        }

        try {
            [$payuOrderId, $redirectUri] = $client->createOrder($orderData);

            $this->logDebug('PayU Order created. orderId: ' . $payuOrderId . ' | redirectUri: ' . $redirectUri);

            if (!empty($payuOrderId)) {
                $this->storePayuOrderId($order->order_id, $payuOrderId);
            }

            if (!empty($redirectUri)) {
                $this->app->redirect($redirectUri);
                return true;
            }

            throw new \Exception('No redirect URI received from PayU');

        } catch (\Exception $e) {
            $error_msg = 'PayU order creation failed: ' . $e->getMessage();
            $this->app->enqueueMessage($error_msg, 'error');
            $this->logError($error_msg . ' | Stack: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Build a PayU REST client from the current payment parameters.
     *
     * @return  PayuRestClient
     *
     * @since   2.3.0
     */
    private function getPayuClient(): PayuRestClient
    {
        return new PayuRestClient(
            !empty($this->payment_params->sandbox),
            trim((string) ($this->payment_params->pos_id ?? '')),
            trim((string) ($this->payment_params->signature_key ?? '')),
            trim((string) ($this->payment_params->oauth_client_id ?? '')),
            trim((string) ($this->payment_params->oauth_client_secret ?? ''))
        );
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
            $unitPrice = (int) round($price * 100);
            $productName = $p->order_product_name ?? $p->product_name ?? 'Produkt';

            $products[] = [
                'name'      => $productName,
                'unitPrice' => $unitPrice,
                'quantity'  => $quantity
            ];

            if (!empty($this->payment_params->debug)) {
                $this->logDebug("Product added: $productName | unitPrice: $unitPrice | quantity: $quantity");
            }
        }

        return $products;
    }

    /**
     * Get billing address from order
     *
     * @param   object  $order  The order object
     *
     * @return  object|null
     *
     * @since   2.1.0
     */
    private function getBillingAddress($order): ?object
    {
        if (!empty($order->cart->billing_address)) {
            return $order->cart->billing_address;
        }
        
        if (!empty($order->addresses) && is_array($order->addresses)) {
            foreach ($order->addresses as $a) {
                if (isset($a->address_type) && $a->address_type === 'billing') {
                    return $a;
                }
            }
        }

        return null;
    }

    /**
     * Handle PayU payment notifications (webhooks)
     * This is called only for server-to-server notifications from PayU
     *
     * @param   array  $statuses  Order statuses
     *
     * @return  void
     *
     * @since   2.1.0
     */
    public function onPaymentNotification(&$statuses): void
    {
        // Załaduj parametry
        $this->loadPayuParams();
        
        $this->logDebug('--- PayU Webhook Notification ---');

        $client = $this->getPayuClient();

        try {
            $body = trim((string) file_get_contents('php://input'));
            $this->logDebug('PayU Notification Body: ' . $body);

            if (empty($body)) {
                $this->logDebug('Empty notification body');
                header("HTTP/1.1 400 Bad Request");
                exit;
            }

            // Weryfikacja podpisu PayU (ochrona przed sfałszowaną notyfikacją)
            $signature = PayuRestClient::readIncomingSignatureHeader();

            if (!$client->verifyNotificationSignature($body, $signature)) {
                $this->logError('Invalid PayU notification signature');
                header("HTTP/1.1 400 Bad Request");
                exit;
            }

            $notification = json_decode($body, true);
            $notifOrder   = $notification['order'] ?? null;

            $this->logDebug('PayU Notification Response: ' . print_r($notification, true));

            if (empty($notifOrder['extOrderId'])) {
                $this->logDebug('No extOrderId in notification');
                header("HTTP/1.1 400 Bad Request");
                exit;
            }

            $order_id = $this->getOrderIdFromToken($notifOrder['extOrderId']);

            if (empty($order_id)) {
                $this->logDebug('Invalid order token: ' . $notifOrder['extOrderId']);
                header("HTTP/1.1 400 Bad Request");
                exit;
            }

            $orderClass = hikashop_get('class.order');
            $order = $orderClass->get($order_id);

            if (empty($order)) {
                $this->logDebug('Order not found: ' . $order_id);
                header("HTTP/1.1 404 Not Found");
                exit;
            }

            $status = strtoupper($notifOrder['status'] ?? '');
            $this->logDebug('PayU Order Status: ' . $status . ' for order: ' . $order_id);

            switch ($status) {
                case 'COMPLETED':
                    $new_status = $this->payment_params->verified_status ?? 'confirmed';
                    $this->modifyOrder($order_id, $new_status, true, true);
                    $this->logDebug('Order ' . $order_id . ' status changed to: ' . $new_status);
                    break;

                case 'CANCELED':
                case 'REJECTED':
                case 'EXPIRED':
                    $new_status = $this->payment_params->invalid_status ?? 'cancelled';
                    $this->modifyOrder($order_id, $new_status, true, true);
                    $this->logDebug('Order ' . $order_id . ' status changed to: ' . $new_status);
                    break;

                case 'PENDING':
                    $this->logDebug('Order ' . $order_id . ' status is PENDING - no change');
                    break;

                default:
                    $this->logDebug('Unknown PayU status: ' . $status . ' for order: ' . $order_id);
                    break;
            }

            if (!empty($notifOrder['orderId'])) {
                $this->storePayuOrderId($order_id, $notifOrder['orderId']);
            }

            $this->logDebug('PayU Notification processed successfully.');
            header("HTTP/1.1 200 OK");
            echo "OK";
            exit;
        } catch (\Exception $e) {
            $error_msg = 'PayU notification processing failed: ' . $e->getMessage();
            $this->logDebug($error_msg);
            header("HTTP/1.1 500 Internal Server Error");
            exit;
        }
    }

    /**
     * Load payment parameters from database
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function loadPayuParams(): void
    {
        if (!empty($this->payment_params) && is_object($this->payment_params) && !empty($this->payment_params->pos_id)) {
            return;
        }
        
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('payment_params'))
            ->from($db->quoteName('#__hikashop_payment'))
            ->where($db->quoteName('payment_type') . ' = ' . $db->quote($this->name))
            ->setLimit(1);
        $db->setQuery($query);
        $params = $db->loadResult();

        if ($params) {
            $this->payment_params = hikashop_unserialize($params);
            $this->logDebug('PayU config loaded from DB');
        } else {
            $this->logDebug('Error: PayU configuration not found in DB.');
        }
    }

    /**
     * Generate a tamper-proof order token for PayU (extOrderId).
     * HMAC-SHA256 z kluczem podpisu — bez znajomości klucza nie da się sfałszować tokenu.
     *
     * @param   int  $order_id  The order ID
     *
     * @return  string
     *
     * @since   2.1.0
     */
    private function getOrderToken(int $order_id): string
    {
        $key = (string) ($this->payment_params->signature_key ?? '');

        return $order_id . '_' . hash_hmac('sha256', (string) $order_id, $key);
    }

    /**
     * Extract order ID from PayU token
     *
     * @param   string  $token  The token
     *
     * @return  int|null
     *
     * @since   2.1.0
     */
    private function getOrderIdFromToken(string $token): ?int
    {
        $parts = explode('_', $token);
        
        if (count($parts) === 2) {
            $order_id = (int) $parts[0];
            $expected_token = $this->getOrderToken($order_id);
            
            if ($token === $expected_token) {
                return $order_id;
            }
        }
        
        return null;
    }

    /**
     * Store PayU order ID in HikaShop order
     *
     * @param   int     $order_id       The order ID
     * @param   string  $payu_order_id  The PayU order ID
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function storePayuOrderId(int $order_id, string $payu_order_id): void
    {
        try {
            // Użyj API HikaShop
            $orderClass = hikashop_get('class.order');
            $order = $orderClass->get($order_id);
            
            if ($order) {
                // Pobierz aktualne params i dodaj payu_order_id
                $params = !empty($order->order_payment_params) ? $order->order_payment_params : new \stdClass();
                if (is_string($params)) {
                    $params = hikashop_unserialize($params);
                }
                if (!is_object($params)) {
                    $params = new \stdClass();
                }
                $params->payu_order_id = $payu_order_id;
                
                // Zapisz przez API
                $update = new \stdClass();
                $update->order_id = $order_id;
                $update->order_payment_params = $params;
                $orderClass->save($update);
                
                $this->logDebug('storePayuOrderId saved: ' . $payu_order_id . ' for order ' . $order_id);
            }
        } catch (\Exception $e) {
            $this->logError('Failed to store PayU order id: ' . $e->getMessage());
        }
    }
    
    /**
     * Log debug message
     *
     * @param   string  $message  The message
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function logDebug(string $message): void
    {
        if (!empty($this->payment_params->debug)) {
            $this->writeLog($message);
        }
    }

    /**
     * Log error message
     *
     * @param   string  $message  The message
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function logError(string $message): void
    {
        $this->writeLog('[ERROR] ' . $message);
    }

    /**
     * Write to log file
     *
     * @param   string  $message  The message
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function writeLog(string $message): void
    {
        try {
            $file = JPATH_ADMINISTRATOR . '/logs/payu_debug.log';
            $timestamp = date('Y-m-d H:i:s');
            
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0755, true);
            }
            
            file_put_contents($file, '[' . $timestamp . '] ' . $message . "\n", FILE_APPEND);
        } catch (\Exception $e) {
            Log::add('PayU plugin logging failed: ' . $e->getMessage(), Log::ERROR, 'com_hikashop');
        }
    }
}
