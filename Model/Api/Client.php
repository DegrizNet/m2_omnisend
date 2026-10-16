<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Api;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Magelan\Omnisend\Model\Config;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Thin Omnisend REST v5 client. Every method throws ApiException on anything but a 2xx answer.
 */
class Client
{
    public const MODULE_VERSION = '1.0.3';
    private const TIMEOUT = 20;
    private const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly Config $config,
        private readonly ClientFactory $httpFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed> decoded response body (empty for 204)
     */
    public function request(string $method, string $path, int $storeId, ?array $body = null, array $query = []): array
    {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            throw new ApiException('No Omnisend API key for store ' . $storeId);
        }
        if (!preg_match('/^[\x21-\x7E]+$/', $apiKey)) {
            // Typically a key saved into core_config_data without encryption: Magento decrypts it into garbage.
            throw new ApiException('The Omnisend API key of store ' . $storeId . ' is not readable; save it again in the configuration.');
        }
        $url = $this->config->getApiUrl() . '/' . ltrim($path, '/');
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'Magelan_Omnisend/' . self::MODULE_VERSION . ' (Magento 2)',
            'X-INTEGRATION-NAME' => 'Magelan_Omnisend (Magento 2)',
            'X-INTEGRATION-VERSION' => self::MODULE_VERSION,
        ];
        if ($this->config->isLegacyApi()) {
            $headers['X-API-KEY'] = $apiKey;
        } else {
            $headers['Authorization'] = 'Omnisend-API-Key ' . $apiKey;
            $headers['Omnisend-Version'] = $this->config->getApiVersion();
        }
        $options = [
            RequestOptions::HEADERS => $headers,
            RequestOptions::TIMEOUT => self::TIMEOUT,
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT,
            RequestOptions::HTTP_ERRORS => false,
        ];
        if ($query) {
            $options[RequestOptions::QUERY] = $query;
        }
        if ($body !== null) {
            $options[RequestOptions::BODY] = $this->json->serialize(self::clean($body));
        }

        try {
            $response = $this->httpFactory->create()->request($method, $url, $options);
        } catch (GuzzleException $e) {
            $this->debug($method, $url, $options, 0, $e->getMessage());
            throw new ApiException('Omnisend request failed: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $raw = (string)$response->getBody();
        $this->debug($method, $url, $options, $status, $raw);

        if ($status < 200 || $status >= 300) {
            $retryAfter = $response->getHeaderLine('Retry-After') ?: $response->getHeaderLine('X-Rate-Limit-Reset');
            throw new ApiException(
                sprintf('Omnisend %s %s answered %d: %s', $method, $path, $status, mb_substr($raw, 0, 500)),
                $status,
                $raw,
                is_numeric($retryAfter) ? (int)$retryAfter : null
            );
        }
        if (trim($raw) === '') {
            return [];
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function post(string $path, int $storeId, array $body): array
    {
        return $this->request('POST', $path, $storeId, $body);
    }

    public function put(string $path, int $storeId, array $body): array
    {
        return $this->request('PUT', $path, $storeId, $body);
    }

    public function get(string $path, int $storeId, array $query = []): array
    {
        return $this->request('GET', $path, $storeId, null, $query);
    }

    public function delete(string $path, int $storeId): array
    {
        return $this->request('DELETE', $path, $storeId);
    }

    /**
     * Omnisend rejects null where it expects a string or an object, so nulls and empty
     * strings are dropped; empty lists are kept because some of them are meaningful.
     */
    public static function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $cleaned = self::clean($value);
                if (array_is_list($value)) {
                    $data[$key] = array_values($cleaned);
                } elseif ($cleaned === []) {
                    unset($data[$key]);
                } else {
                    $data[$key] = $cleaned;
                }
            } elseif ($value === null || $value === '') {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function debug(string $method, string $url, array $options, int $status, string $response): void
    {
        if (!$this->config->isDebug()) {
            return;
        }
        $this->logger->debug('Omnisend ' . $method . ' ' . $url, [
            'status' => $status,
            'request' => $options[RequestOptions::BODY] ?? null,
            'response' => mb_substr($response, 0, 2000),
        ]);
    }
}
