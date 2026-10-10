<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.4.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 */

declare(strict_types=1);

namespace Pablop76\Plugin\HikashopPayment\Payu\Payment;

defined('_JEXEC') or die('Restricted access');

/**
 * Dane płatności PayU zapisane przy zamówieniu HikaShopa.
 *
 * HikaShop trzyma je w polu order_payment_params, które bywa zwracane raz jako
 * obiekt, a raz jako ciąg po serialize. Zapis zawsze scala z tym, co w polu już
 * jest: korzystają z niego także inne wtyczki i sam HikaShop.
 *
 * @since  2.4.0
 */
final class OrderPaymentData
{
    /** Identyfikator zamówienia PayU bieżącej próby zapłaty (klucz obecny od 2.1.0). */
    public const PAYU_ORDER_ID = 'payu_order_id';

    /** Identyfikatory zamówień PayU wszystkich prób, od najstarszej. */
    public const PAYU_ORDERS = 'payu_orders';

    /** Próby, które wtyczka sama anulowała w PayU przed założeniem następnej. */
    public const CANCELLED = 'payu_cancelled';

    /** Metoda płatności HikaShopa, którą założono płatność (klucze i tryb są jej). */
    public const PAYMENT_ID = 'payu_payment_id';

    public const AMOUNT   = 'payu_amount';
    public const CURRENCY = 'payu_currency';

    /** Chwila potwierdzenia zapłaty przez wtyczkę i zamówienie PayU, które ją przyniosło. */
    public const PAID_AT    = 'payu_paid_at';
    public const PAID_ORDER = 'payu_paid_order';

    /** Chwila, w której sprzedawca dostał wiadomość o kłopocie z płatnością. */
    public const ALERT_AT = 'payu_alert_at';

    /** Ile prób pamiętamy przy zamówieniu. */
    public const ORDERS_LIMIT = 10;

    private function __construct()
    {
    }

    /**
     * Parametry płatności zamówienia jako obiekt.
     */
    public static function read(?object $order): object
    {
        if ($order === null || !isset($order->order_payment_params)) {
            return new \stdClass();
        }

        $params = $order->order_payment_params;

        if (\is_string($params) && $params !== '') {
            // Bez klas: w tym polu mają być dane, a nie obiekty do odtworzenia.
            $unserialized = @unserialize($params, ['allowed_classes' => [\stdClass::class]]);
            $params       = ($unserialized === false) ? new \stdClass() : $unserialized;
        }

        if (\is_array($params)) {
            $params = (object) $params;
        }

        return \is_object($params) ? $params : new \stdClass();
    }

    public static function getString(?object $order, string $key, string $default = ''): string
    {
        $params = self::read($order);
        $value  = $params->$key ?? $default;

        return \is_scalar($value) && $value !== '' ? (string) $value : $default;
    }

    public static function getInt(?object $order, string $key, int $default = 0): int
    {
        $params = self::read($order);
        $value  = $params->$key ?? $default;

        return \is_scalar($value) && $value !== '' ? (int) $value : $default;
    }

    /**
     * Lista identyfikatorów zapisana pod podanym kluczem.
     *
     * @return  list<string>
     */
    public static function getList(?object $order, string $key): array
    {
        $params = self::read($order);
        $value  = $params->$key ?? [];

        if (\is_object($value)) {
            $value = (array) $value;
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item): string => \is_scalar($item) ? (string) $item : '', $value),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * Zamówienia PayU wszystkich prób zapłaty, od najnowszej.
     *
     * Zamówienia sprzed 2.4.0 mają zapisany tylko bieżący identyfikator.
     *
     * @return  list<string>
     */
    public static function attemptsOf(?object $order): array
    {
        $proby   = self::getList($order, self::PAYU_ORDERS);
        $biezaca = self::getString($order, self::PAYU_ORDER_ID);

        if ($biezaca !== '') {
            $proby[] = $biezaca;
        }

        return array_values(array_unique(array_reverse($proby)));
    }

    /**
     * Dopisuje wartości do parametrów płatności zamówienia i zapisuje je.
     *
     * Parametry czytamy świeżo z bazy: między wczytaniem zamówienia a tym zapisem
     * mogło dojść powiadomienie z PayU i dopisać swoje pola.
     *
     * @param   array<string, mixed>  $values
     */
    public static function store(int $orderId, array $values): bool
    {
        if ($orderId <= 0 || $values === [] || !\function_exists('hikashop_get')) {
            return false;
        }

        $orderClass = hikashop_get('class.order');

        if (!\is_object($orderClass)) {
            return false;
        }

        $params = self::read($orderClass->get($orderId));

        foreach ($values as $key => $value) {
            $params->$key = $value;
        }

        $update                       = new \stdClass();
        $update->order_id             = $orderId;
        $update->order_payment_params = $params;

        return (bool) $orderClass->save($update);
    }

    /**
     * Zapisuje nową próbę zapłaty jako bieżącą.
     */
    public static function startAttempt(int $orderId, string $payuOrderId, int $paymentId, int $amount, string $currency): bool
    {
        $zapisane = self::fresh($orderId);
        $proby    = array_reverse(self::attemptsOf($zapisane));
        $proby[]  = $payuOrderId;
        $proby    = \array_slice(array_values(array_unique($proby)), -self::ORDERS_LIMIT);

        return self::store($orderId, [
            self::PAYU_ORDER_ID => $payuOrderId,
            self::PAYU_ORDERS   => $proby,
            self::PAYMENT_ID    => $paymentId,
            self::AMOUNT        => $amount,
            self::CURRENCY      => $currency,
        ]);
    }

    /**
     * Zapisuje, że wtyczka sama anuluje tę próbę w PayU.
     */
    public static function markCancelled(int $orderId, string $payuOrderId): bool
    {
        $anulowane   = self::getList(self::fresh($orderId), self::CANCELLED);
        $anulowane[] = $payuOrderId;

        return self::store($orderId, [
            self::CANCELLED => \array_slice(array_values(array_unique($anulowane)), -self::ORDERS_LIMIT),
        ]);
    }

    /**
     * Cofa znacznik anulowania, gdy PayU anulowania nie przyjęło.
     */
    public static function unmarkCancelled(int $orderId, string $payuOrderId): bool
    {
        $anulowane = array_values(array_filter(
            self::getList(self::fresh($orderId), self::CANCELLED),
            static fn (string $id): bool => $id !== $payuOrderId
        ));

        return self::store($orderId, [self::CANCELLED => $anulowane]);
    }

    /**
     * Zamówienie odczytane świeżo z bazy.
     */
    private static function fresh(int $orderId): ?object
    {
        if ($orderId <= 0 || !\function_exists('hikashop_get')) {
            return null;
        }

        $orderClass = hikashop_get('class.order');
        $order      = \is_object($orderClass) ? $orderClass->get($orderId) : null;

        return \is_object($order) ? $order : null;
    }
}
