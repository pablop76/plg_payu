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
 * Identyfikator zamówienia po stronie sklepu wysyłany do PayU (extOrderId).
 *
 * PayU nie pozwala założyć drugiego zamówienia z tym samym extOrderId. Sprawdzone
 * na sandboksie 10.10.2026: dopóki pierwsze zamówienie PayU jest otwarte, powtórzone
 * żądanie oddaje je z powrotem (ten sam orderId, ze starym adresem powrotu); po jego
 * anulowaniu albo wygaśnięciu PayU odpowiada ERROR_ORDER_NOT_UNIQUE. Tym samym błędem
 * odpowiada też od razu, gdy żądanie niesie kupującego z pustym adresem e-mail.
 *
 * Do 2.3.1 identyfikator zależał wyłącznie od numeru zamówienia, a wtyczka wysyłała
 * pusty e-mail kupującego, więc „Zapłać teraz” po nieudanej albo przerwanej płatności
 * kończyło się błędem. Teraz każda próba zapłaty dostaje własny identyfikator.
 *
 * Postać: <numer zamówienia>_<losowy dopisek>_<podpis>. Podpis to HMAC-SHA256
 * kluczem podpisu punktu płatności, więc identyfikatora nie da się ułożyć dla
 * cudzego zamówienia bez znajomości klucza.
 *
 * @since  2.4.0
 */
final class ExtOrderId
{
    private function __construct()
    {
    }

    /**
     * Nowy identyfikator dla kolejnej próby zapłaty.
     *
     * @param   int          $orderId  Numer zamówienia HikaShopa
     * @param   string       $key      Klucz podpisu punktu płatności
     * @param   string|null  $nonce    Dopisek do podmiany w testach
     */
    public static function generate(int $orderId, string $key, ?string $nonce = null): string
    {
        $nonce ??= bin2hex(random_bytes(6));

        return $orderId . '_' . $nonce . '_' . self::sign($orderId . '|' . $nonce, $key);
    }

    /**
     * Numer zamówienia zapisany w identyfikatorze, BEZ sprawdzania podpisu.
     *
     * Służy wyłącznie do odnalezienia zamówienia, żeby wiedzieć, którym kluczem
     * sprawdzać podpis. O zaufaniu decyduje dopiero isValid().
     */
    public static function orderIdOf(string $extOrderId): int
    {
        return preg_match('/^(\d{1,10})_/', $extOrderId, $trafienie) === 1 ? (int) $trafienie[1] : 0;
    }

    /**
     * Czy identyfikator został wystawiony przez ten sklep.
     *
     * Przyjmuje też postać sprzed 2.4.0 (<numer>_<podpis>): płatności rozpoczęte
     * przed aktualizacją wtyczki muszą dać się rozliczyć po niej.
     */
    public static function isValid(string $extOrderId, string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $czesci = explode('_', $extOrderId);

        if (count($czesci) === 3 && ctype_digit($czesci[0]) && preg_match('/^[a-f0-9]{6,32}$/', $czesci[1]) === 1) {
            return hash_equals(self::sign($czesci[0] . '|' . $czesci[1], $key), $czesci[2]);
        }

        if (count($czesci) === 2 && ctype_digit($czesci[0])) {
            return hash_equals(hash_hmac('sha256', $czesci[0], $key), $czesci[1]);
        }

        return false;
    }

    private static function sign(string $data, string $key): string
    {
        return hash_hmac('sha256', $data, $key);
    }
}
