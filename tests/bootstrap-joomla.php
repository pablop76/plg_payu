<?php
/**
 * Rozruch Joomli i HikaShopa dla testów wtyczki PayU.
 *
 * Testy chodzą po prawdziwej instalacji (domyślnie lokalna kopia sklepu),
 * z wtyczką ZAINSTALOWANĄ w tej Joomli. Przed testami trzeba więc zbudować
 * paczkę (php -d extension=zip build.php) i zainstalować ją w tej witrynie.
 * Rozruch sprawdza, czy zainstalowana wersja zgadza się z repozytorium.
 *
 * Ścieżkę do Joomli można podać zmienną środowiskową JOOMLA_PATH.
 *
 * Używamy aplikacji witryny, a nie konsolowej: HikaShop korzysta
 * z setUserState(), którego aplikacja konsolowa nie ma.
 */

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

if (!defined('JPATH_BASE')) {
    define('JPATH_BASE', getenv('JOOMLA_PATH') ?: 'D:/laragon/www/haskap');
}

if (!is_file(JPATH_BASE . '/includes/defines.php')) {
    fwrite(STDERR, 'Nie znaleziono Joomli w ' . JPATH_BASE . PHP_EOL);
    fwrite(STDERR, 'Ustaw zmienną JOOMLA_PATH na katalog instalacji.' . PHP_EOL);
    exit(2);
}

// Poza przeglądarką nie ma nagłówka Host, a Joomla buduje z niego adresy.
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'sklep.test';
$_SERVER['HTTPS']       = 'on';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.7';

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(Joomla\Session\Session::class, 'session.cli')
    ->alias(Joomla\Session\SessionInterface::class, 'session.cli');

$app = $container->get(Joomla\CMS\Application\SiteApplication::class);
Joomla\CMS\Factory::$application = $app;

// Język aplikacji ustawia się dopiero w execute(), którego tu nie wołamy.
$app->loadLanguage(
    Joomla\CMS\Language\Language::getInstance(
        $container->get('config')->get('language', 'en-GB')
    )
);

// Bez execute() aplikacja i Factory miałyby dwa różne obiekty języka:
// wtyczka wczytałaby tłumaczenia do jednego, a Text::_() czytałby z drugiego.
Joomla\CMS\Factory::$language = $app->getLanguage();

// HikaShop używa starych nazw klas (JFactory, JText). W przeglądarce rejestruje
// je wtyczka zgodności wstecznej, która poza przeglądarką się nie uruchamia.
foreach (['compat', 'compat6'] as $compat) {
    $classmap = JPATH_PLUGINS . '/behaviour/' . $compat . '/src/classmap/classmap.php';

    if (is_file($classmap)) {
        require_once $classmap;
        break;
    }
}

// Mapę przestrzeni nazw rozszerzeń aplikacja tworzy dopiero w execute().
$app->createExtensionNamespaceMap();

$helperHikaShop = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

if (!is_file($helperHikaShop)) {
    fwrite(STDERR, 'Nie znaleziono HikaShopa w ' . JPATH_BASE . PHP_EOL);
    exit(2);
}

require_once $helperHikaShop;

// Zainstalowana wtyczka musi być tą z repozytorium, inaczej testy sprawdzałyby stary kod.
$manifestRepo        = simplexml_load_file(__DIR__ . '/../payu.xml');
$manifestInstalacji  = JPATH_PLUGINS . '/hikashoppayment/payu/payu.xml';
$wersjaRepo          = trim((string) $manifestRepo->version);
$wersjaInstalacji    = is_file($manifestInstalacji) ? trim((string) simplexml_load_file($manifestInstalacji)->version) : '';
$klasaRepo           = __DIR__ . '/../src/Extension/Payu.php';
$klasaInstalacji     = JPATH_PLUGINS . '/hikashoppayment/payu/src/Extension/Payu.php';

$bezCr = static fn (string $plik): string => is_file($plik) ? str_replace("\r\n", "\n", (string) file_get_contents($plik)) : '';

if ($wersjaInstalacji !== $wersjaRepo || $bezCr($klasaRepo) !== $bezCr($klasaInstalacji)) {
    fwrite(STDERR, 'Wtyczka zainstalowana w ' . JPATH_BASE . ' różni się od repozytorium' . PHP_EOL);
    fwrite(STDERR, '(zainstalowana ' . ($wersjaInstalacji ?: 'brak') . ', repozytorium ' . $wersjaRepo . ').' . PHP_EOL);
    fwrite(STDERR, 'Zbuduj paczkę (php -d extension=zip build.php) i zainstaluj ją w tej witrynie.' . PHP_EOL);
    exit(2);
}

/**
 * Połączenie z bazą witryny.
 */
function polaczenieZBaza(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = new JConfig();
    $pdo = new PDO(
        "mysql:host={$cfg->host};dbname={$cfg->db};charset=utf8mb4",
        $cfg->user,
        $cfg->password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function przedrostekTabel(): string
{
    return (new JConfig())->dbprefix;
}
