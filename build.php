<?php
/**
 * Buduje paczkę instalacyjną wtyczki.
 *
 * Uruchomienie:
 *   php -d extension=zip build.php
 *
 * Do paczki trafia dokładnie to, co wymienia manifest w <files> (oraz sam
 * manifest i ewentualny <scriptfile>), więc testy, README i ten skrypt
 * zostają poza nią. Numer wersji w nazwie pliku pochodzi z manifestu.
 */

const MANIFEST       = __DIR__ . "/payu.xml";
const KATALOG_PACZEK = __DIR__ . '/build';
const NAZWA          = 'plg_payu';

/** Pliki tekstowe zapisujemy z końcami wierszy LF, niezależnie od systemu. */
const TEKSTOWE = ['php', 'xml', 'ini', 'html', 'css', 'js', 'txt', 'md'];

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, 'Brak rozszerzenia zip w PHP. Uruchom: php -d extension=zip build.php' . PHP_EOL);
    exit(1);
}

$manifest = is_file(MANIFEST) ? simplexml_load_file(MANIFEST) : false;

if ($manifest === false) {
    fwrite(STDERR, 'Nie udało się wczytać manifestu ' . MANIFEST . PHP_EOL);
    exit(1);
}

$wersja = trim((string) $manifest->version);

if ($wersja === '') {
    fwrite(STDERR, 'Manifest nie podaje numeru wersji.' . PHP_EOL);
    exit(1);
}

$pliki = [basename(MANIFEST)];

if (trim((string) $manifest->scriptfile) !== '') {
    $pliki[] = trim((string) $manifest->scriptfile);
}

foreach ($manifest->files->filename as $plik) {
    $pliki[] = trim((string) $plik);
}

foreach ($manifest->files->folder as $katalog) {
    $katalog  = trim((string) $katalog);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/' . $katalog, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $sciezka => $info) {
        if ($info->isFile()) {
            $pliki[] = str_replace('\\', '/', substr($sciezka, strlen(__DIR__) + 1));
        }
    }
}

$pliki = array_values(array_unique($pliki));
sort($pliki);

if (!is_dir(KATALOG_PACZEK) && !mkdir(KATALOG_PACZEK, 0775, true) && !is_dir(KATALOG_PACZEK)) {
    fwrite(STDERR, 'Nie udało się utworzyć katalogu ' . KATALOG_PACZEK . PHP_EOL);
    exit(1);
}

$paczka = KATALOG_PACZEK . '/' . NAZWA . '-' . $wersja . '.zip';

if (is_file($paczka) && !unlink($paczka)) {
    fwrite(STDERR, 'Nie udało się usunąć poprzedniej paczki ' . $paczka . PHP_EOL);
    exit(1);
}

$zip = new ZipArchive();

if ($zip->open($paczka, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, 'Nie udało się otworzyć archiwum do zapisu.' . PHP_EOL);
    exit(1);
}

foreach ($pliki as $plik) {
    $zrodlo = __DIR__ . '/' . $plik;

    if (!is_file($zrodlo)) {
        fwrite(STDERR, 'Manifest wymienia plik, którego nie ma: ' . $plik . PHP_EOL);
        exit(1);
    }

    $tresc = file_get_contents($zrodlo);

    if (in_array(strtolower(pathinfo($plik, PATHINFO_EXTENSION)), TEKSTOWE, true)) {
        $tresc = str_replace("\r\n", "\n", $tresc);
    }

    // Nazwy w archiwum zawsze z ukośnikiem w przód, inaczej Joomla na Linuksie
    // rozpakuje pliki o nazwach z odwrotnym ukośnikiem zamiast katalogów.
    $zip->addFromString($plik, $tresc);
}

$zip->close();

echo 'Paczka: ' . $paczka . PHP_EOL;
echo 'Wersja: ' . $wersja . ', plików: ' . count($pliki) . PHP_EOL;
echo 'SHA-256: ' . hash_file('sha256', $paczka) . PHP_EOL;
