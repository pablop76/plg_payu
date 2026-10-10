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
 * Kwoty w postaci, której używa PayU.
 *
 * PayU podaje i przyjmuje kwoty w setnych częściach waluty (1000 to 10,00),
 * także dla walut bez groszy. Przeliczenie jest w jednym miejscu, żeby kwota
 * wysłana przy tworzeniu płatności i kwota porównywana po zapłacie zawsze
 * wychodziły tak samo.
 *
 * @since  2.4.0
 */
final class Amount
{
    private function __construct()
    {
    }

    /**
     * @param   mixed  $value  Kwota w walucie (np. 19.99 albo "19.99000")
     */
    public static function toMinor($value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
