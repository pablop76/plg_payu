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
 * Nieudane wywołanie PayU: błąd sieci, odpowiedź z błędem albo nieczytelna.
 *
 * @since  2.4.0
 */
final class PayuApiException extends \RuntimeException
{
    /**
     * @param   string  $message     Opis dla dziennika
     * @param   int     $httpStatus  Kod HTTP odpowiedzi albo 0, gdy odpowiedzi nie było
     * @param   string  $statusCode  Kod błędu PayU (np. ERROR_ORDER_NOT_UNIQUE) albo pusty
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly string $statusCode = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getStatusCode(): string
    {
        return $this->statusCode;
    }

    /**
     * Czy PayU odrzuciło dane dostępowe (zły identyfikator lub hasło OAuth).
     */
    public function isAuthenticationFailure(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }
}
