<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.0.0
 * @copyright   (C) 2026
 * @license     GNU/GPL
 */

namespace Pablop76\Plugin\HikashopPayment\Payu\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

\defined('_JEXEC') or die('Restricted access');

class Payu extends \hikashopPaymentPlugin
{
    public $accepted_currencies = array("PLN", "EUR", "USD");
    public $multiple = true;
    public $name = 'payu';

    public $pluginConfig = array(
        'pos_id' => array('POS_ID', 'input'),
        'signature_key' => array('SIGNATURE_KEY', 'input'),
        'oauth_client_id' => array('OAUTH_CLIENT_ID', 'input'),
        'oauth_client_secret' => array('OAUTH_CLIENT_SECRET', 'input'),
        'sandbox' => array('SANDBOX_MODE', 'boolean', 1),
        'debug' => array('DEBUG', 'boolean', 0),
        'return_url' => array('RETURN_URL', 'input'),
        'invalid_status' => array('INVALID_STATUS', 'orderstatus'),
        'verified_status' => array('VERIFIED_STATUS', 'orderstatus'),
        'check_status_on_return' => array('CHECK_STATUS_ON_RETURN_FOR_LOCALHOST', 'boolean', 1)
    );

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        
        // Load language file
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('plg_hikashoppayment_payu', JPATH_PLUGINS . '/hikashoppayment/payu');
    }

    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name = 'PayU';
        $element->payment_description = 'Payment via PayU Poland';
        $element->payment_params->sandbox = 1;
        $element->payment_params->debug = 0;
        $element->payment_params->invalid_status = 'cancelled';
        $element->payment_params->verified_status = 'confirmed';
        $element->payment_params->check_status_on_return = 1;
    }

    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        if (empty($this->payment_params->pos_id) || empty($this->payment_params->signature_key) || empty($this->payment_params->oauth_client_id) || empty($this->payment_params->oauth_client_secret)) {
            $this->app->enqueueMessage('PayU plugin is not fully configured!', 'error');
            $this->logError('PayU plugin misconfiguration: missing credentials');
            return false;
        }

        $this->initPayuSdk();

        // URL powrotu - jeśli check_status_on_return włączone (domyślnie TAK), użyj specjalnego endpointu
        $base_return_url = HIKASHOP_LIVE . "index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=" . $order->order_id;
        $check_status = isset($this->payment_params->check_status_on_return) ? $this->payment_params->check_status_on_return : 1;
        if ($check_status) {
            // Użyj notify z dodatkowym parametrem check_return=1 do sprawdzenia statusu przed powrotem
            $return_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=payu&tmpl=component&order_id=' . $order->order_id . '&check_return=1';
        } else {
            $return_url = !empty($this->payment_params->return_url) ? $this->payment_params->return_url : $base_return_url;
        }
        
        $this->logDebug('check_status_on_return: ' . $check_status);
        $this->logDebug('continueUrl set to: ' . $return_url);
        
        $currency = isset($order->cart->full_total->prices[0]->price_currency) ? $order->cart->full_total->prices[0]->price_currency : 'PLN';
        $amount = (int) round((isset($order->cart->full_total->prices[0]->price_value_with_tax) ? $order->cart->full_total->prices[0]->price_value_with_tax : 0) * 100);

        // Przygotowanie danych zamówienia
        $orderData = array(
            'continueUrl' => $return_url,
            'notifyUrl' => HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=payu&tmpl=component&order_id=' . $order->order_id,
            'customerIp' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1',
            'merchantPosId' => \OpenPayU_Configuration::getMerchantPosId(),
            'description' => 'Order #' . $order->order_number,
            'currencyCode' => $currency,
            'totalAmount' => $amount,
            'extOrderId' => $this->getOrderToken($order->order_id),
            'validityTime' => 86400
        );

        // Produkty
        $orderData['products'] = array();
        if (!empty($order->cart->products) && is_array($order->cart->products)) {
            foreach ($order->cart->products as $p) {
                $price = 0;
                if (!empty($p->order_product_price_with_tax)) {
                    $price = $p->order_product_price_with_tax;
                } elseif (!empty($p->order_product_price) && !empty($p->order_product_tax)) {
                    $price = $p->order_product_price + $p->order_product_tax;
                } elseif (!empty($p->product_price_with_tax)) {
                    $price = $p->product_price_with_tax;
                } elseif (!empty($p->product_price)) {
                    $price = $p->product_price;
                }

                $quantity = 1;
                if (!empty($p->order_product_quantity)) {
                    $quantity = (int)$p->order_product_quantity;
                } elseif (!empty($p->product_quantity)) {
                    $quantity = (int)$p->product_quantity;
                }

                $unitPrice = (int) round($price * 100);
                $productName = isset($p->order_product_name) ? $p->order_product_name : (isset($p->product_name) ? $p->product_name : 'Produkt');

                $orderData['products'][] = array(
                    'name' => $productName,
                    'unitPrice' => $unitPrice,
                    'quantity' => $quantity
                );

                if (!empty($this->payment_params->debug)) {
                    $this->logDebug("Product added: $productName | unitPrice: $unitPrice | quantity: $quantity");
                }
            }
        }

        // Adres billing
        $billing = null;
        if (!empty($order->cart->billing_address)) {
            $billing = $order->cart->billing_address;
        } elseif (!empty($order->addresses) && is_array($order->addresses)) {
            foreach ($order->addresses as $a) {
                if (isset($a->address_type) && $a->address_type == 'billing') {
                    $billing = $a;
                    break;
                }
            }
        }

        if ($billing) {
            $orderData['buyer'] = array(
                'email' => isset($billing->address_email) ? $billing->address_email : '',
                'phone' => isset($billing->address_phone) ? $billing->address_phone : '',
                'firstName' => isset($billing->address_firstname) ? $billing->address_firstname : '',
                'lastName' => isset($billing->address_lastname) ? $billing->address_lastname : ''
            );
        }

        try {
            $response = \OpenPayU_Order::create($orderData);

            if ($this->payment_params->debug) {
                $this->logDebug('PayU Order Request: ' . print_r($orderData, true));
                $this->logDebug('PayU Order Raw Response: ' . print_r($response, true));
            }

            $respData = is_object($response) && method_exists($response, 'getResponse') ? $response->getResponse() : $response;

            $payuOrderId = null;
            $redirectUri = null;

            if (is_object($respData)) {
                if (isset($respData->orderId)) $payuOrderId = $respData->orderId;
                if (isset($respData->redirectUri)) $redirectUri = $respData->redirectUri;
                if (isset($respData->response) && is_object($respData->response)) {
                    $payuOrderId = $respData->response->orderId ?? $payuOrderId;
                    $redirectUri = $respData->response->redirectUri ?? $redirectUri;
                }
            } elseif (is_array($respData)) {
                $payuOrderId = $respData['response']['orderId'] ?? $respData['orderId'] ?? null;
                $redirectUri = $respData['response']['redirectUri'] ?? $respData['redirectUri'] ?? null;
            }

            if (!empty($payuOrderId)) {
                $this->storePayuOrderId($order->order_id, $payuOrderId);
            }

            if (!empty($redirectUri)) {
                $this->app->redirect($redirectUri);
                return true;
            } else {
                $this->logError('No redirect URI received from PayU. Full response: ' . print_r($respData, true));
                throw new \Exception('No redirect URI received from PayU');
            }
        } catch (\Exception $e) {
            $error_msg = 'PayU order creation failed: ' . $e->getMessage();
            $this->app->enqueueMessage($error_msg, 'error');
            $this->logError($error_msg . ' | Stack: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Sprawdza status zamówienia w PayU i aktualizuje w HikaShop
     */
    public function checkAndUpdateOrderStatus($order_id, $retry = 0)
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
        } catch (\Exception $e) {
            $this->logError('PayU status check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function onPaymentNotification(&$statuses)
    {
        $this->logDebug('--- PayU Notification Triggered ---');

        $app = Factory::getApplication();
        $input = $app->input;
        
        // Sprawdź czy to powrót użytkownika z PayU (check_return=1)
        $checkReturn = $input->getInt('check_return', 0);
        $orderId = $input->getInt('order_id', 0);
        
        if ($checkReturn && $orderId) {
            $this->logDebug('User returned from PayU - checking status for order: ' . $orderId);
            
            $this->loadPayuParams();
            $this->initPayuSdk();
            
            // Sprawdź status w PayU
            $this->checkAndUpdateOrderStatus($orderId);
            
            // Przekieruj do strony "thank you" używając nagłówka HTTP
            $redirect_url = HIKASHOP_LIVE . "index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=" . $orderId;
            $this->logDebug('Redirecting to: ' . $redirect_url);
            header('Location: ' . $redirect_url);
            exit;
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
     * Inicjalizuje SDK PayU
     */
    private function initPayuSdk()
    {
        $vendor_path = __DIR__ . '/../../vendor/autoload.php';
        if (!file_exists($vendor_path)) {
            // Próbuj alternatywną ścieżkę
            $vendor_path = dirname(dirname(__DIR__)) . '/vendor/autoload.php';
        }
        
        if (!file_exists($vendor_path)) {
            throw new \Exception('PayU SDK not found at ' . $vendor_path);
        }
        
        require_once $vendor_path;

        if (!empty($this->payment_params->sandbox) && $this->payment_params->sandbox) {
            \OpenPayU_Configuration::setEnvironment('sandbox');
        } else {
            \OpenPayU_Configuration::setEnvironment('secure');
        }
        \OpenPayU_Configuration::setMerchantPosId(trim($this->payment_params->pos_id ?? ''));
        \OpenPayU_Configuration::setSignatureKey(trim($this->payment_params->signature_key ?? ''));
        \OpenPayU_Configuration::setOauthClientId(trim($this->payment_params->oauth_client_id ?? ''));
        \OpenPayU_Configuration::setOauthClientSecret(trim($this->payment_params->oauth_client_secret ?? ''));
    }

    /**
     * Ładuje parametry płatności z bazy danych
     */
    private function loadPayuParams()
    {
        if (!empty($this->payment_params) && is_object($this->payment_params) && !empty($this->payment_params->pos_id)) {
            return;
        }
        
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
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

    private function getOrderToken($order_id)
    {
        return $order_id . '_' . md5($order_id . $this->payment_params->signature_key);
    }

    private function getOrderIdFromToken($token)
    {
        $parts = explode('_', $token);
        if (count($parts) === 2) {
            $order_id = $parts[0];
            $expected_token = $this->getOrderToken($order_id);
            if ($token === $expected_token) {
                return (int)$order_id;
            }
        }
        return null;
    }

    private function storePayuOrderId($order_id, $payu_order_id)
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
    
    private function getStoredPayuOrderId($order_id)
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
     * Aktualizuje status zamówienia używając API HikaShop (wysyła email z powiadomieniem)
     */
    private function updateOrderStatus($order_id, $new_status)
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

    private function logDebug($message)
    {
        if (!empty($this->payment_params->debug)) {
            $this->writeLog($message);
        }
    }

    private function logError($message)
    {
        $this->writeLog('[ERROR] ' . $message);
    }

    private function writeLog($message)
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
