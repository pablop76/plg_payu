<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.4.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 */

declare(strict_types=1);

namespace Pablop76\Plugin\HikashopPayment\Payu\Client;

use Joomla\CMS\Http\HttpFactory;

defined('_JEXEC') or die('Restricted access');

/**
 * Lekki klient REST API PayU (v2.1) oparty o natywny klient HTTP Joomli.
 *
 * Obsługuje:
 *  - OAuth (grant client_credentials),
 *  - OrderCreate (odpowiedź 302 z ciałem JSON — nie podążamy za przekierowaniem),
 *  - OrderRetrieve i anulowanie zamówienia,
 *  - weryfikację podpisu notyfikacji (nagłówek OpenPayU-Signature).
 *
 * @since  2.3.0
 */
final class PayuRestClient implements PayuClientInterface
{
    /** Najdłuższy czas oczekiwania na odpowiedź PayU, w sekundach. */
    private const TIMEOUT = 20;

    private string $serviceUrl;
    private string $oauthUrl;
    private string $posId;
    private string $signatureKey;
    private string $clientId;
    private string $clientSecret;
    private ?string $accessToken = null;

    /** @var object|null klient HTTP podstawiany w testach */
    private ?object $http;

    /**
     * @param   bool         $sandbox       Tryb testowy (Sandbox)
     * @param   string       $posId         Identyfikator POS
     * @param   string       $signatureKey  Klucz podpisu (weryfikacja notyfikacji)
     * @param   string       $clientId      OAuth Client ID
     * @param   string       $clientSecret  OAuth Client Secret
     * @param   object|null  $http          Klient HTTP do podmiany w testach
     */
    public function __construct(bool $sandbox, string $posId, string $signatureKey, string $clientId, string $clientSecret, ?object $http = null)
    {
        $base = $sandbox ? 'https://secure.snd.payu.com' : 'https://secure.payu.com';

        $this->serviceUrl   = $base . '/api/v2_1/';
        $this->oauthUrl     = $base . '/pl/standard/user/oauth/authorize';
        $this->posId        = $posId;
        $this->signatureKey = $signatureKey;
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->http         = $http;
    }

    /**
     * Pobiera token dostępu OAuth (grant client_credentials).
     * Token jest cache'owany w obrębie pojedynczego żądania.
     *
     * @return  string
     *
     * @throws  PayuApiException
     */
    public function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], '', '&');

        [$code, $data] = $this->send('POST', $this->oauthUrl, $body, ['Content-Type' => 'application/x-www-form-urlencoded']);

        if ($code !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? ('HTTP ' . $code);

            // Błędne dane OAuth PayU zgłasza kodem 401; traktujemy tak każdą odmowę tokenu.
            throw new PayuApiException('PayU OAuth failed: ' . $msg, $code === 200 ? 401 : $code, (string) ($data['error'] ?? ''));
        }

        return $this->accessToken = (string) $data['access_token'];
    }

    /**
     * Tworzy zamówienie w PayU (OrderCreateRequest).
     *
     * @param   array  $order  Ciało zamówienia (bez merchantPosId — ustawiany tutaj)
     *
     * @return  array  [orderId, redirectUri]
     *
     * @throws  PayuApiException
     */
    public function createOrder(array $order): array
    {
        $order['merchantPosId'] = $this->posId;

        $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        [$code, $data] = $this->authorizedRequest('POST', 'orders', $json);

        // PayU dla poprawnego OrderCreate zwraca 302 z ciałem JSON (redirectUri, orderId).
        if (!in_array($code, [200, 201, 301, 302], true) || empty($data['redirectUri']) || empty($data['orderId'])) {
            throw $this->statusError($data, $code);
        }

        return [(string) $data['orderId'], (string) $data['redirectUri']];
    }

    /**
     * Pobiera informacje o zamówieniu (OrderRetrieveRequest).
     *
     * @param   string  $orderId  Identyfikator zamówienia PayU
     *
     * @return  array|null  Pierwszy element listy orders (status, totalAmount, currencyCode…)
     *                      albo null, gdy PayU takiego zamówienia nie zna
     *
     * @throws  PayuApiException
     */
    public function retrieveOrder(string $orderId): ?array
    {
        [$code, $data] = $this->authorizedRequest('GET', 'orders/' . rawurlencode($orderId));

        if ($code === 404) {
            return null;
        }

        if ($code !== 200 || empty($data['orders'][0]) || !is_array($data['orders'][0])) {
            throw $this->statusError($data, $code);
        }

        return $data['orders'][0];
    }

    /**
     * Anuluje zamówienie w PayU (DELETE orders/{orderId}).
     *
     * PayU odpowiada 200 także dla zamówienia już anulowanego. Nieznane zamówienie
     * (404) traktujemy jak anulowane: nie ma czego opłacić.
     *
     * @throws  PayuApiException
     */
    public function cancelOrder(string $orderId): void
    {
        [$code, $data] = $this->authorizedRequest('DELETE', 'orders/' . rawurlencode($orderId));

        if ($code === 404) {
            return;
        }

        if ($code !== 200) {
            throw $this->statusError($data, $code);
        }
    }

    /**
     * Weryfikuje podpis notyfikacji PayU względem klucza podpisu.
     *
     * @param   string       $body             Surowe ciało żądania (php://input)
     * @param   string|null  $signatureHeader  Wartość nagłówka OpenPayU-Signature
     *
     * @return  bool
     */
    public function verifyNotificationSignature(string $body, ?string $signatureHeader): bool
    {
        if (empty($signatureHeader) || $this->signatureKey === '') {
            return false;
        }

        $parsed = [];

        foreach (explode(';', rtrim($signatureHeader, ';')) as $pair) {
            $kv = explode('=', $pair, 2);

            if (count($kv) === 2) {
                $parsed[trim($kv[0])] = trim($kv[1]);
            }
        }

        if (empty($parsed['signature']) || empty($parsed['algorithm'])) {
            return false;
        }

        $algorithm = strtoupper($parsed['algorithm']);
        $concat    = $body . $this->signatureKey;

        if ($algorithm === 'MD5') {
            $hash = md5($concat);
        } elseif (in_array($algorithm, ['SHA', 'SHA1', 'SHA-1'], true)) {
            $hash = sha1($concat);
        } else {
            $hash = hash('sha256', $concat);
        }

        return hash_equals($hash, strtolower($parsed['signature']));
    }

    /**
     * Odczytuje nagłówek podpisu z bieżącego żądania HTTP.
     *
     * @return  string|null
     */
    public static function readIncomingSignatureHeader(): ?string
    {
        foreach (['HTTP_OPENPAYU_SIGNATURE', 'HTTP_X_OPENPAYU_SIGNATURE'] as $key) {
            if (!empty($_SERVER[$key])) {
                return (string) $_SERVER[$key];
            }
        }

        return null;
    }

    /**
     * Wykonuje autoryzowane żądanie REST (Bearer). Nie podąża za przekierowaniem,
     * dzięki czemu zachowujemy ciało odpowiedzi 302 z OrderCreate.
     *
     * @param   string       $method  POST|GET|DELETE
     * @param   string       $path    Ścieżka względem serviceUrl (np. "orders")
     * @param   string|null  $json    Ciało JSON (dla POST)
     *
     * @return  array  [kod HTTP, tablica z JSON]
     *
     * @throws  PayuApiException
     */
    private function authorizedRequest(string $method, string $path, ?string $json = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type'  => 'application/json',
        ];

        return $this->send($method, $this->serviceUrl . $path, $json, $headers);
    }

    /**
     * Wysyła żądanie i zamienia błąd sieci na wyjątek wtyczki.
     *
     * Limit czasu jest konieczny: bez niego zawieszone połączenie z PayU trzymałoby
     * klienta na białej stronie do końca limitu wykonania PHP.
     *
     * @return  array  [kod HTTP, tablica z JSON]
     *
     * @throws  PayuApiException
     */
    private function send(string $method, string $url, ?string $body, array $headers): array
    {
        $http = $this->http ?? HttpFactory::getHttp(['follow_location' => false]);

        try {
            $response = match ($method) {
                'POST'   => $http->post($url, (string) $body, $headers, self::TIMEOUT),
                'DELETE' => $http->delete($url, $headers, self::TIMEOUT),
                default  => $http->get($url, $headers, self::TIMEOUT),
            };
        } catch (\Throwable $blad) {
            throw new PayuApiException('PayU nie odpowiada: ' . $blad->getMessage(), 0, '', $blad);
        }

        return $this->decode($response);
    }

    /**
     * Normalizuje odpowiedź klienta HTTP (PSR-7 lub starszą) do [kod, tablica JSON].
     *
     * @param   object  $response  Obiekt odpowiedzi Joomla\CMS\Http
     *
     * @return  array
     */
    private function decode($response): array
    {
        $code = method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode()
            : (int) ($response->code ?? 0);

        $raw = method_exists($response, 'getBody')
            ? (string) $response->getBody()
            : (string) ($response->body ?? '');

        $data = json_decode($raw, true);

        return [$code, is_array($data) ? $data : []];
    }

    /**
     * Buduje wyjątek z sekcji "status" odpowiedzi PayU
     * (np. "ERROR_VALUE_INVALID - Invalid continueUrl").
     *
     * @param   array  $data  Zdekodowana odpowiedź
     * @param   int    $code  Kod HTTP
     */
    private function statusError(array $data, int $code): PayuApiException
    {
        $status     = is_array($data['status'] ?? null) ? $data['status'] : [];
        $statusCode = (string) ($status['statusCode'] ?? '');
        $part       = $statusCode !== '' ? $statusCode : ('HTTP ' . $code);
        $desc       = (string) ($status['codeLiteral'] ?? $status['statusDesc'] ?? '');

        return new PayuApiException(trim($part . ($desc !== '' ? ' - ' . $desc : '')), $code, $statusCode);
    }
}
