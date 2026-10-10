<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.4.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 */

declare(strict_types=1);

namespace Pablop76\Plugin\HikashopPayment\Payu\Client;

defined('_JEXEC') or die('Restricted access');

/**
 * Wywołania PayU, z których korzysta wtyczka.
 *
 * Osobny interfejs po to, żeby testy mogły podstawić atrapę zamiast łączyć się
 * z PayU.
 *
 * @since  2.4.0
 */
interface PayuClientInterface
{
    /**
     * Tworzy zamówienie w PayU.
     *
     * @param   array  $order  Ciało zamówienia bez merchantPosId
     *
     * @return  array{0: string, 1: string}  [identyfikator zamówienia PayU, adres strony płatności]
     *
     * @throws  PayuApiException
     */
    public function createOrder(array $order): array;

    /**
     * Odczytuje zamówienie z PayU.
     *
     * @return  array|null  Dane zamówienia (status, totalAmount, currencyCode…) albo null, gdy PayU go nie zna
     *
     * @throws  PayuApiException
     */
    public function retrieveOrder(string $orderId): ?array;

    /**
     * Anuluje zamówienie w PayU. Wpłata, która dotrze po anulowaniu, wraca do płacącego.
     *
     * @throws  PayuApiException
     */
    public function cancelOrder(string $orderId): void;

    /**
     * Sprawdza podpis powiadomienia (nagłówek OpenPayU-Signature).
     */
    public function verifyNotificationSignature(string $body, ?string $signatureHeader): bool;
}
