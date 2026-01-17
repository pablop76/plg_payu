<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.1.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 * 
 * Legacy entry point for Joomla 4/5/6 compatibility
 */

defined('_JEXEC') or die('Restricted access');

use Pablop76\Plugin\HikashopPayment\Payu\Extension\Payu;

// For HikaShop compatibility - create legacy class alias
if (!class_exists('plgHikashoppaymentPayu')) {
    class_alias(Payu::class, 'plgHikashoppaymentPayu');
}
