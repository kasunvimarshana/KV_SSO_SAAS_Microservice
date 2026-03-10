<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class ProductServiceClient
{
    private Client $client;
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.product_service.url', 'http://product-service/api'), '/');
        $this->client = new Client(['timeout' => 5.0, 'connect_timeout' => 3.0]);
    }

    public function searchProducts(string $token, string $tenantId, array $params): array
    {
        try {
            $endpoint = isset($params['q']) ? '/products/search' : '/products';
            $response = $this->client->get($this->baseUrl . $endpoint, [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'query'   => array_filter($params),
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['data'])) return ['success' => true, 'products' => $body['data']];
            if (isset($body['products'])) return ['success' => true, 'products' => $body['products']];

            return ['success' => true, 'products' => []];
        } catch (RequestException $e) {
            Log::error('ProductServiceClient::searchProducts error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'products' => []];
        }
    }

    public function getProductsByIds(string $token, string $tenantId, array $ids): array
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/products/by-ids", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'query'   => ['ids' => implode(',', $ids)],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ['success' => true, 'products' => $body['products'] ?? []];
        } catch (RequestException $e) {
            Log::error('ProductServiceClient::getProductsByIds error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'products' => []];
        }
    }
}
