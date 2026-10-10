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
 * Zapis do dziennika płatności HikaShopa.
 *
 * Do 2.3.1 wtyczka pisała do administrator/logs/payu_debug.log: zwykłego pliku
 * pod znaną nazwą, w katalogu, który bywa dostępny z sieci, razem z danymi
 * kupujących. Dziennik płatności HikaShopa leży w chronionym katalogu, a jego
 * położenie ustawia się w konfiguracji sklepu („Plik dziennika płatności”).
 *
 * Błędy i ostrzeżenia zapisujemy zawsze, bo bez nich nie da się ustalić, czemu
 * płatność nie doszła do skutku. Przebieg płatności tylko w trybie debugowania.
 *
 * @since  2.4.0
 */
final class Logger
{
    private const REDACTED = '***';

    /** @var list<string> */
    private array $secrets;

    /** @var callable|null */
    private $writer;

    /**
     * @param   string         $channel  Nazwa wtyczki, pod którą HikaShop trzyma wpisy
     * @param   bool           $debug    Czy zapisywać przebieg płatności
     * @param   list<string>   $secrets  Wartości do wymazania z każdego wpisu
     * @param   callable|null  $writer   Zapis do podmiany w testach
     */
    public function __construct(
        private readonly string $channel,
        private readonly bool $debug,
        array $secrets = [],
        ?callable $writer = null
    ) {
        // Krótkich ciągów nie wymazujemy: pocięłyby cały komunikat.
        $this->secrets = array_values(array_filter(
            $secrets,
            static fn ($secret): bool => \is_string($secret) && \strlen($secret) >= 6
        ));

        $this->writer = $writer;
    }

    /**
     * @param   array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('BŁĄD', $message, $context);
    }

    /**
     * @param   array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('UWAGA', $message, $context);
    }

    /**
     * @param   array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->write('INFO', $message, $context);
        }
    }

    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::REDACTED, $text);
        }

        return $text;
    }

    /**
     * @param   array<string, mixed>  $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $line = 'PayU [' . $level . '] ' . $message;

        if ($context !== []) {
            $parts = [];

            foreach ($context as $key => $value) {
                $parts[] = $key . '=' . $this->format($value);
            }

            $line .= ' | ' . implode(' ', $parts);
        }

        $line = $this->redact($line);

        if ($this->writer !== null) {
            ($this->writer)($line);

            return;
        }

        if (\function_exists('hikashop_writeToLog')) {
            hikashop_writeToLog($line, $this->channel);
        }
    }

    private function format(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'tak' : 'nie';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '?';
    }
}
