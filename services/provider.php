<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.0.0
 * @copyright   (C) 2026
 * @license     GNU/GPL
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
// Register the namespace manually for HikaShop plugins
\JLoader::registerNamespace('Pablop76\\Plugin\\HikashopPayment\\Payu', JPATH_PLUGINS . '/hikashoppayment/payu/src', false, false, 'psr4');

use Pablop76\Plugin\HikashopPayment\Payu\Extension\Payu;

return new class implements ServiceProviderInterface
{
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $config = (array) PluginHelper::getPlugin('hikashoppayment', 'payu');
                
                $plugin = new Payu(
                    $dispatcher,
                    $config
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
