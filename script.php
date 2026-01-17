<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.1.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface {

                private string $minimumJoomla = '5.0.0';
                private string $minimumPhp    = '8.1.0';

                /**
                 * Function called before the extension is installed/updated/uninstalled
                 *
                 * @param   string            $type    The type of change (install, update, discover_install, or uninstall)
                 * @param   InstallerAdapter  $parent  The adapter calling this method
                 *
                 * @return  boolean  True on success
                 */
                public function preflight(string $type, InstallerAdapter $parent): bool
                {
                    if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
                        Factory::getApplication()->enqueueMessage(
                            sprintf('Plugin PayU wymaga PHP %s lub nowszego. Posiadasz PHP %s.', $this->minimumPhp, PHP_VERSION),
                            'error'
                        );
                        return false;
                    }

                    if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
                        Factory::getApplication()->enqueueMessage(
                            sprintf('Plugin PayU wymaga Joomla %s lub nowszej. Posiadasz Joomla %s.', $this->minimumJoomla, JVERSION),
                            'error'
                        );
                        return false;
                    }

                    // Sprawdź czy HikaShop jest zainstalowany
                    if (!file_exists(JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php')) {
                        Factory::getApplication()->enqueueMessage(
                            'Plugin PayU wymaga zainstalowanego komponentu HikaShop.',
                            'error'
                        );
                        return false;
                    }

                    return true;
                }

                /**
                 * Function called after the extension is installed/updated/uninstalled
                 *
                 * @param   string            $type    The type of change
                 * @param   InstallerAdapter  $parent  The adapter calling this method
                 *
                 * @return  boolean  True on success
                 */
                public function postflight(string $type, InstallerAdapter $parent): bool
                {
                    if ($type === 'install' || $type === 'discover_install') {
                        // Włącz wtyczkę automatycznie
                        $this->enablePlugin();
                    }

                    return true;
                }

                /**
                 * Function called on install
                 *
                 * @param   InstallerAdapter  $parent  The adapter calling this method
                 *
                 * @return  boolean  True on success
                 */
                public function install(InstallerAdapter $parent): bool
                {
                    Factory::getApplication()->enqueueMessage(
                        'Plugin PayU dla HikaShop został zainstalowany pomyślnie. Skonfiguruj go w ustawieniach płatności HikaShop.',
                        'success'
                    );
                    return true;
                }

                /**
                 * Function called on update
                 *
                 * @param   InstallerAdapter  $parent  The adapter calling this method
                 *
                 * @return  boolean  True on success
                 */
                public function update(InstallerAdapter $parent): bool
                {
                    Factory::getApplication()->enqueueMessage(
                        'Plugin PayU dla HikaShop został zaktualizowany pomyślnie.',
                        'success'
                    );
                    return true;
                }

                /**
                 * Function called on uninstall
                 *
                 * @param   InstallerAdapter  $parent  The adapter calling this method
                 *
                 * @return  boolean  True on success
                 */
                public function uninstall(InstallerAdapter $parent): bool
                {
                    Factory::getApplication()->enqueueMessage(
                        'Plugin PayU dla HikaShop został odinstalowany.',
                        'message'
                    );
                    return true;
                }

                /**
                 * Włącza wtyczkę po instalacji
                 *
                 * @return  void
                 */
                private function enablePlugin(): void
                {
                    try {
                        $db = Factory::getContainer()->get(DatabaseInterface::class);

                        $query = $db->getQuery(true)
                            ->update($db->quoteName('#__extensions'))
                            ->set($db->quoteName('enabled') . ' = 1')
                            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                            ->where($db->quoteName('folder') . ' = ' . $db->quote('hikashoppayment'))
                            ->where($db->quoteName('element') . ' = ' . $db->quote('payu'));

                        $db->setQuery($query);
                        $db->execute();
                    } catch (\Exception $e) {
                        // Nie przerywaj instalacji w przypadku błędu
                    }
                }
            }
        );
    }
};
