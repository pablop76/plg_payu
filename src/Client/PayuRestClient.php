<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.3.1
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
 * Zastępuje bibliotekę openpayu_php. Obsługuje:
 *  - OAuth (grant client_credentials),
 *  - OrderCreate (odpowiedź 302 z ciałem JSON — nie podążamy za przekierowaniem),
 *  - OrderRetrieve,
 *  - weryfikację podpisu notyfikacji (nagłówek OpenPayU-Signature).
 *
 * API i model danych pozostają identyczne jak w starym SDK — zmienia się tylko
 * warstwa transportowa (Joomla\CMS\Http zamiast wbudowanego cURL biblioteki PayU).
 *
 * @since  2.3.0
 */
final class PayuRestClient
{
    private string $serviceUrl;
    private string $oauthUrl;
    private string $posId;
    private string $signatureKey;
    private string $clientId;
    private string $clientSecret;
    private ?string $accessToken = null;

    /**
     * @param   bool    $sandbox       Tryb testowy (Sandbox)
     * @param   string  $posId         Identyfikator POS
     * @param   string  $signatureKey  Klucz podpisu (weryfikacja notyfikacji)
     * @param   string  $clientId      OAuth Client ID
     * @param   string  $clientSecret  OAuth Client Secret
     */
    public function __construct(bool $sandbox, string $posId, string $signatureKey, string $clientId, string $clientSecret)
    {
        $base = $sandbox ? 'https://secure.snd.payu.com' : 'https://secure.payu.com';

        $this->serviceUrl   = $base . '/api/v2_1/';
        $this->oauthUrl     = $base . '/pl/standard/user/oauth/authorize';
        $this->posId        = $posId;
        $this->signatureKey = $signatureKey;
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Pobiera token dostępu OAuth (grant client_credentials).
     * Token jest cache'owany w obrębie pojedynczego żądania.
     *
     * @return  string
     *
     * @throws  \RuntimeException
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

        $response = HttpFactory::getHttp()->post(
            $this->oauthUrl,
            $body,
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );

        [$code, $data] = $this->decode($response);

        if ($code !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? ('HTTP ' . $code);
            throw new \RuntimeException('PayU OAuth failed: ' . $msg);
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
     * @throws  \RuntimeException
     */
    public function createOrder(array $order): array
    {
        $order['merchantPosId'] = $this->posId;

        $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        [$code, $data] = $this->authorizedRequest('POST', 'orders', $json);

        // PayU dla poprawnego OrderCreate zwraca 302 z ciałem JSON (redirectUri, orderId).
        if (!in_array($code, [200, 201, 301, 302], true) || empty($data['redirectUri'])) {
            throw new \RuntimeException($this->formatStatusError($data, $code));
        }

        return [$data['orderId'] ?? null, (string) $data['redirectUri']];
    }

    /**
     * Pobiera informacje o zamówieniu (OrderRetrieveRequest).
     *
     * @param   string  $orderId  Identyfikator zamówienia PayU
     *
     * @return  array  Pierwszy element listy orders (z polami status, orderId, ...)
     *
     * @throws  \RuntimeException
     */
    public function retrieveOrder(string $orderId): array
    {
        [$code, $data] = $this->authorizedRequest('GET', 'orders/' . rawurlencode($orderId));

        if ($code !== 200 || empty($data['orders'][0])) {
            throw new \RuntimeException($this->formatStatusError($data, $code));
        }

        return (array) $data['orders'][0];
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
        if (empty($signatureHeader)) {
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

        return hash_equals($hash, $parsed['signature']);
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
     * @param   string       $method  POST|GET
     * @param   string       $path    Ścieżka względem serviceUrl (np. "orders")
     * @param   string|null  $json    Ciało JSON (dla POST)
     *
     * @return  array  [kod HTTP, tablica z JSON]
     *
     * @throws  \RuntimeException
     */
    private function authorizedRequest(string $method, string $path, ?string $json = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type'  => 'application/json',
        ];

        $http = HttpFactory::getHttp(['follow_location' => false]);

        $response = ($method === 'POST')
            ? $http->post($this->serviceUrl . $path, (string) $json, $headers)
            : $http->get($this->serviceUrl . $path, $headers);

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
     * Buduje czytelny komunikat błędu z sekcji "status" odpowiedzi PayU
     * (np. "ERROR_VALUE_INVALID - Invalid continueUrl").
     *
     * @param   array  $data  Zdekodowana odpowiedź
     * @param   int    $code  Kod HTTP
     *
     * @return  string
     */
    private function formatStatusError(array $data, int $code): string
    {
        $status = $data['status'] ?? [];
        $part   = $status['statusCode'] ?? ('HTTP ' . $code);
        $desc   = $status['codeLiteral'] ?? $status['statusDesc'] ?? '';

        return trim($part . ($desc !== '' ? ' - ' . $desc : ''));
    }
}
