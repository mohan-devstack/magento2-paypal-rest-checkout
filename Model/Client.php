<?php
declare(strict_types=1);

namespace Dzinehub\PaypalRest\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;

class Client
{
    private const LIVE_BASE_URL    = 'https://api-m.paypal.com';
    private const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

    private const CONFIG_CLIENT_ID     = 'dz_paypalrest/api/client_id';
    private const CONFIG_CLIENT_SECRET = 'dz_paypalrest/api/client_secret';
    private const CONFIG_SANDBOX       = 'dz_paypalrest/api/sandbox';

    private const TIMEOUT = 30;

    /** Cached for the lifetime of this request to avoid repeated token calls. */
    private ?string $cachedToken = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Curl $httpClient
    ) {
        $this->httpClient->setTimeout(self::TIMEOUT);
    }

    /**
     * Returns a Bearer token, fetching a fresh one only when not already cached.
     *
     * @throws \RuntimeException
     */
    public function getAccessToken(): string
    {
        if ($this->cachedToken !== null) {
            return $this->cachedToken;
        }

        $clientId     = (string)$this->scopeConfig->getValue(self::CONFIG_CLIENT_ID, ScopeInterface::SCOPE_STORE);
        $clientSecret = $this->encryptor->decrypt(
            (string)$this->scopeConfig->getValue(self::CONFIG_CLIENT_SECRET, ScopeInterface::SCOPE_STORE)
        );

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException(
                'PayPal REST API credentials are not configured. '
                . 'Go to Dzinehub > PayPal REST Checkout > API Credentials in Magento Admin.'
            );
        }

        $this->httpClient->setHeaders([
            'Accept'          => 'application/json',
            'Accept-Language' => 'en_US',
            'Authorization'   => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type'    => 'application/x-www-form-urlencoded',
        ]);
        $this->httpClient->post($this->baseUrl() . '/v1/oauth2/token', 'grant_type=client_credentials');

        $status   = $this->httpClient->getStatus();
        $response = json_decode($this->httpClient->getBody(), true);

        if ($status !== 200 || empty($response['access_token'])) {
            throw new \RuntimeException(sprintf(
                'Failed to obtain PayPal access token (HTTP %d).',
                $status
            ));
        }

        $this->cachedToken = $response['access_token'];

        return $this->cachedToken;
    }

    /**
     * Authenticated GET. Returns [http_status, decoded_body_array].
     * Returns null body on 404.
     *
     * @return array{int, array<string,mixed>|null}
     * @throws \RuntimeException
     */
    public function get(string $path, string $accessToken): array
    {
        $this->httpClient->setHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
        ]);
        $this->httpClient->get($this->baseUrl() . $path);

        $status = $this->httpClient->getStatus();
        $body   = $this->httpClient->getBody();

        if ($status === 404) {
            return [404, null];
        }

        if ($status !== 200) {
            throw new \RuntimeException(sprintf(
                'PayPal API error %d for GET %s: %s',
                $status,
                $path,
                $body
            ));
        }

        return [$status, json_decode($body, true)];
    }

    /**
     * Authenticated JSON POST. Returns [http_status, decoded_body_array].
     *
     * @param array<string,mixed> $payload
     * @return array{int, array<string,mixed>}
     * @throws \RuntimeException
     */
    public function postJson(string $path, string $accessToken, array $payload): array
    {
        $this->httpClient->setHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
        ]);
        $this->httpClient->post(
            $this->baseUrl() . $path,
            empty($payload) ? '{}' : (string)json_encode($payload)
        );

        $status       = $this->httpClient->getStatus();
        $responseBody = $this->httpClient->getBody();

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'PayPal API error %d for POST %s: %s',
                $status,
                $path,
                $responseBody
            ));
        }

        return [$status, json_decode($responseBody, true) ?? []];
    }

    private function baseUrl(): string
    {
        return $this->scopeConfig->getValue(self::CONFIG_SANDBOX, ScopeInterface::SCOPE_STORE)
            ? self::SANDBOX_BASE_URL
            : self::LIVE_BASE_URL;
    }
}
