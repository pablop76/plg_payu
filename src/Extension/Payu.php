<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.1.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 */

namespace Pablop76\Plugin\HikashopPayment\Payu\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die('Restricted access');

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
        'return_url'             => ['RETURN_URL', 'input'],
        'invalid_status'         => ['INVALID_STATUS', 'orderstatus'],
        'verified_status'        => ['VERIFIED_STATUS', 'orderstatus'],
        'check_status_on_return' => ['CHECK_STATUS_ON_RETURN_FOR_LOCALHOST', 'boolean', 1]
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
        
        // Load language file
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('plg_hikashoppayment_payu', JPATH_PLUGINS . '/hikashoppayment/payu');
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
        $element->payment_params->check_status_on_return = 1;
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

        $this->initPayuSdk();

        // Pobierz Itemid z konfiguracji HikaShop dla poprawnego routingu
        $url_itemid = '';
        if (function_exists('hikashop_config')) {
            $config = hikashop_config();
            $checkout_itemid = $config->get('checkout_itemid', 0);
            if (!empty($checkout_itemid)) {
                $url_itemid = '&Itemid=' . (int)$checkout_itemid;
            }
        }

        // URL powrotu - jeśli check_status_on_return włączone (domyślnie TAK), użyj specjalnego endpointu
        $base_return_url = HIKASHOP_LIVE . "index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=" . $order->order_id . $url_itemid;
        $check_status = $this->payment_params->check_status_on_return ?? 1;
        
        if ($check_status) {
            // Użyj notify z dodatkowym parametrem check_return=1 do sprawdzenia statusu przed powrotem
            // UWAGA: NIE używamy tmpl=component dla powrotu użytkownika - potrzebny pełny dokument HTML
            $return_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=payu&order_id=' . $order->order_id . '&check_return=1' . $url_itemid;
        } else {
            $return_url = !empty($this->payment_params->return_url) ? $this->payment_params->return_url : $base_return_url;
        }
        
        $this->logDebug('check_status_on_return: ' . $check_status);
        $this->logDebug('continueUrl set to: ' . $return_url);
        
        $currency = $order->cart->full_total->prices[0]->price_currency ?? 'PLN';
        $amount = (int) round(($order->cart->full_total->prices[0]->price_value_with_tax ?? 0) * 100);

        // Przygotowanie danych zamówienia
        $orderData = [
            'continueUrl'   => $return_url,
            'notifyUrl'     => HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=payu&tmpl=component&order_id=' . $order->order_id,
            'customerIp'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'merchantPosId' => \OpenPayU_Configuration::getMerchantPosId(),
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

        try {
            $response = \OpenPayU_Order::create($orderData);

            if (!empty($this->payment_params->debug)) {
                $this->logDebug('PayU Order Request: ' . print_r($orderData, true));
                $this->logDebug('PayU Order Raw Response: ' . print_r($response, true));
            }

            $respData = is_object($response) && method_exists($response, 'getResponse') ? $response->getResponse() : $response;

            [$payuOrderId, $redirectUri] = $this->extractPayuResponse($respData);

            if (!empty($payuOrderId)) {
                $this->storePayuOrderId($order->order_id, $payuOrderId);
            }

            if (!empty($redirectUri)) {
                $this->app->redirect($redirectUri);
                return true;
            }

            $this->logError('No redirect URI received from PayU. Full response: ' . print_r($respData, true));
            throw new \Exception('No redirect URI received from PayU');

        } catch (\Exception $e) {
            $error_msg = 'PayU order creation failed: ' . $e->getMessage();
            $this->app->enqueueMessage($error_msg, 'error');
            $this->logError($error_msg . ' | Stack: ' . $e->getTraceAsString());
            return false;
        }
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
     * Extract PayU order ID and redirect URI from response
     *
     * @param   mixed  $respData  The response data
     *
     * @return  array  [payuOrderId, redirectUri]
     *
     * @since   2.1.0
     */
    private function extractPayuResponse($respData): array
    {
        $payuOrderId = null;
        $redirectUri = null;

        if (is_object($respData)) {
            $payuOrderId = $respData->orderId ?? null;
            $redirectUri = $respData->redirectUri ?? null;
            
            if (isset($respData->response) && is_object($respData->response)) {
                $payuOrderId = $respData->response->orderId ?? $payuOrderId;
                $redirectUri = $respData->response->redirectUri ?? $redirectUri;
            }
        } elseif (is_array($respData)) {
            $payuOrderId = $respData['response']['orderId'] ?? $respData['orderId'] ?? null;
            $redirectUri = $respData['response']['redirectUri'] ?? $respData['redirectUri'] ?? null;
        }

        return [$payuOrderId, $redirectUri];
    }

    /**
     * Check and update order status from PayU
     *
     * @param   int  $order_id  The order ID
     * @param   int  $retry     Retry count
     *
     * @return  boolean
     *
     * @since   2.1.0
     */
    public function checkAndUpdateOrderStatus(int $order_id, int $retry = 0): bool
    {
        // Pobierz PayU order ID z order_payment_params
        $payuOrderId = $this->getStoredPayuOrderId($order_id);
        
        if (empty($payuOrderId)) {
            $this->logDebug('No PayU order ID found for order: ' . $order_id);
            return false;
        }
        
        $this->loadPayuParams();
        $this->initPayuSdk();
        
        try {
            $response = \OpenPayU_Order::retrieve($payuOrderId);
            $respData = is_object($response) && method_exists($response, 'getResponse') ? $response->getResponse() : $response;
            
            $this->logDebug('PayU Status Check Response: ' . print_r($respData, true));
            
            $status = null;
            if (is_object($respData) && isset($respData->orders) && is_array($respData->orders) && count($respData->orders) > 0) {
                $status = strtoupper($respData->orders[0]->status ?? '');
            }
            
            if (empty($status)) {
                $this->logDebug('Could not get status from PayU response');
                return false;
            }
            
            $this->logDebug('PayU Order Status for order ' . $order_id . ': ' . $status . ' (retry: ' . $retry . ')');
            
            return $this->processPayuStatus($order_id, $status, $retry);
            
        } catch (\Exception $e) {
            $this->logError('PayU status check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process PayU status and update order
     *
     * @param   int     $order_id  The order ID
     * @param   string  $status    The PayU status
     * @param   int     $retry     Retry count
     *
     * @return  boolean
     *
     * @since   2.1.0
     */
    private function processPayuStatus(int $order_id, string $status, int $retry = 0): bool
    {
        switch ($status) {
            case 'COMPLETED':
                $new_status = $this->payment_params->verified_status ?? 'confirmed';
                $this->logDebug('About to update order ' . $order_id . ' to status: ' . $new_status);
                $this->updateOrderStatus($order_id, $new_status);
                $this->logDebug('Order ' . $order_id . ' status changed to: ' . $new_status . ' (via status check)');
                return true;
                
            case 'CANCELED':
            case 'REJECTED':
            case 'EXPIRED':
                $new_status = $this->payment_params->invalid_status ?? 'cancelled';
                $this->updateOrderStatus($order_id, $new_status);
                $this->logDebug('Order ' . $order_id . ' status changed to: ' . $new_status . ' (via status check)');
                return true;
                
            case 'PENDING':
            case 'WAITING_FOR_CONFIRMATION':
            case 'NEW':
                // W sandbox status może być PENDING zaraz po płatności - poczekaj i spróbuj ponownie
                if ($retry < 3) {
                    $this->logDebug('Order ' . $order_id . ' status is ' . $status . ' - waiting 2 seconds and retrying...');
                    sleep(2);
                    return $this->checkAndUpdateOrderStatus($order_id, $retry + 1);
                }
                $this->logDebug('Order ' . $order_id . ' status is still ' . $status . ' after retries - no change');
                return false;
                
            default:
                $this->logDebug('Unknown PayU status: ' . $status . ' for order: ' . $order_id);
                return false;
        }
    }

    /**
     * Handle PayU payment notifications
     *
     * @param   array  $statuses  Order statuses
     *
     * @return  void
     *
     * @since   2.1.0
     */
    public function onPaymentNotification(&$statuses): void
    {
        // Najpierw załaduj parametry, żeby logDebug działało
        $this->loadPayuParams();
        
        $this->logDebug('--- PayU Notification Triggered ---');

        $app = Factory::getApplication();
        $input = $app->input;
        
        // Sprawdź czy to powrót użytkownika z PayU (check_return=1)
        $checkReturn = $input->getInt('check_return', 0);
        $orderId = $input->getInt('order_id', 0);
        
        $this->logDebug('check_return: ' . $checkReturn . ', order_id: ' . $orderId);
        
        if ($checkReturn && $orderId) {
            $this->logDebug('User returned from PayU - checking status for order: ' . $orderId);
            
            // Nie ładuj ponownie - już załadowano na początku
            $this->initPayuSdk();
            
            // Sprawdź status w PayU
            $this->checkAndUpdateOrderStatus($orderId);
            
            $this->logDebug('After checkAndUpdateOrderStatus - preparing redirect');
            
            // Wyczyść koszyk
            if (defined('HIKASHOP_COMPONENT')) {
                $cartClass = hikashop_get('class.cart');
                if ($cartClass) {
                    $cartClass->cleanCartFromSession();
                    $this->logDebug('Cart cleaned');
                }
            }
            
            // Pobierz Itemid z konfiguracji HikaShop
            $url_itemid = '';
            if (function_exists('hikashop_config')) {
                $config = hikashop_config();
                $checkout_itemid = $config->get('checkout_itemid', 0);
                if (!empty($checkout_itemid)) {
                    $url_itemid = '&Itemid=' . (int)$checkout_itemid;
                }
            }
            
            // Sprawdź czy jest ustawiony własny URL przekierowania
            $custom_url = trim($this->payment_params->return_url ?? '');
            
            if (!empty($custom_url)) {
                // Własny URL - dodaj komunikaty bo nie będzie ich HikaShop
                $app->enqueueMessage(Text::_('THANK_YOU_FOR_PURCHASE'), 'success');
                
                // Link do zamówienia tylko dla zalogowanych użytkowników
                $user = Factory::getApplication()->getIdentity();
                if ($user && !$user->guest) {
                    $order_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order&task=show&cid=' . $orderId . $url_itemid;
                    $app->enqueueMessage(Text::sprintf('YOU_CAN_NOW_ACCESS_YOUR_ORDER_HERE', $order_url), 'success');
                }
                
                $redirect_url = $custom_url;
                $this->logDebug('Using custom return_url: ' . $redirect_url);
            } else {
                // Domyślna strona HikaShop after_end
                $redirect_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . $orderId . $url_itemid;
            }
            
            $this->logDebug('Redirecting to: ' . $redirect_url);
            $app->redirect($redirect_url);
            return;
        }

        $this->loadPayuParams();
        $this->initPayuSdk();

        try {
            $body = trim(file_get_contents('php://input'));
            $this->logDebug('PayU Notification Body: ' . $body);

            if (empty($body)) {
                $this->logDebug('Empty notification body');
                header("HTTP/1.1 400 Bad Request");
                exit;
            }

            $response = \OpenPayU_Order::consumeNotification($body);
            $notification = $response->getResponse();

            $this->logDebug('PayU Notification Response: ' . print_r($notification, true));

            if (empty($notification->order->extOrderId)) {
                $this->logDebug('No extOrderId in notification');
                header("HTTP/1.1 400 Bad Request");
                exit;
            }

            $order_id = $this->getOrderIdFromToken($notification->order->extOrderId);

            if (empty($order_id)) {
                $this->logDebug('Invalid order token: ' . $notification->order->extOrderId);
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

            $status = strtoupper($notification->order->status ?? '');
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

            if (isset($notification->order->orderId)) {
                $this->storePayuOrderId($order_id, $notification->order->orderId);
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
     * Initialize PayU SDK
     *
     * @return  void
     *
     * @throws  \Exception
     *
     * @since   2.1.0
     */
    private function initPayuSdk(): void
    {
        $vendor_path = __DIR__ . '/../../vendor/autoload.php';
        
        if (!file_exists($vendor_path)) {
            $vendor_path = dirname(dirname(__DIR__)) . '/vendor/autoload.php';
        }
        
        if (!file_exists($vendor_path)) {
            throw new \Exception('PayU SDK not found at ' . $vendor_path);
        }
        
        require_once $vendor_path;

        $environment = !empty($this->payment_params->sandbox) ? 'sandbox' : 'secure';
        
        \OpenPayU_Configuration::setEnvironment($environment);
        \OpenPayU_Configuration::setMerchantPosId(trim($this->payment_params->pos_id ?? ''));
        \OpenPayU_Configuration::setSignatureKey(trim($this->payment_params->signature_key ?? ''));
        \OpenPayU_Configuration::setOauthClientId(trim($this->payment_params->oauth_client_id ?? ''));
        \OpenPayU_Configuration::setOauthClientSecret(trim($this->payment_params->oauth_client_secret ?? ''));
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
     * Generate order token for PayU
     *
     * @param   int  $order_id  The order ID
     *
     * @return  string
     *
     * @since   2.1.0
     */
    private function getOrderToken(int $order_id): string
    {
        return $order_id . '_' . md5($order_id . $this->payment_params->signature_key);
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
     * Get stored PayU order ID from HikaShop order
     *
     * @param   int  $order_id  The order ID
     *
     * @return  string|null
     *
     * @since   2.1.0
     */
    private function getStoredPayuOrderId(int $order_id): ?string
    {
        try {
            $orderClass = hikashop_get('class.order');
            $order = $orderClass->get($order_id);
            
            if ($order && !empty($order->order_payment_params)) {
                $params = $order->order_payment_params;
                
                if (is_string($params)) {
                    $params = hikashop_unserialize($params);
                }
                
                if (is_object($params) && !empty($params->payu_order_id)) {
                    return $params->payu_order_id;
                }
            }
        } catch (\Exception $e) {
            $this->logError('Failed to get PayU order id: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Update order status using HikaShop API (sends email notification)
     *
     * @param   int     $order_id    The order ID
     * @param   string  $new_status  The new status
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function updateOrderStatus(int $order_id, string $new_status): void
    {
        $this->logDebug('updateOrderStatus called for order ' . $order_id . ' with status ' . $new_status);
        
        try {
            // Użyj modifyOrder() zamiast bezpośredniego SQL - to wysyła email z powiadomieniem
            // Parametry: order_id, new_status, send_email_to_customer, send_email_to_admin
            $this->modifyOrder($order_id, $new_status, true, true);
            
            $this->logDebug('updateOrderStatus: Order ' . $order_id . ' updated to ' . $new_status . ' via modifyOrder (email sent)');
            
        } catch (\Exception $e) {
            $this->logError('updateOrderStatus failed: ' . $e->getMessage());
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
