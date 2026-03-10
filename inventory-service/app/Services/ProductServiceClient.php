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
        $this->client = new Client([
            'timeout'         => 5.0,
            'connect_timeout' => 3.0,
        ]);
    }

    /**
     * Search products by attributes (name, code, category, q)
     */
    public function searchProducts(string $token, string $tenantId, array $params): array
    {
        try {
            // Use /search for text queries, /products for attribute filters
            $endpoint = isset($params['q']) ? '/products/search' : '/products';
            $queryParams = array_filter($params);

            $response = $this->client->get($this->baseUrl . $endpoint, [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/json',
                    'X-Tenant-ID'   => $tenantId,
                ],
                'query' => $queryParams,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            // Normalize response: /products returns paginated, /search returns {products:[...]}
            if (isset($body['data'])) {
                return ['success' => true, 'products' => $body['data']];
            }
            if (isset($body['products'])) {
                return ['success' => true, 'products' => $body['products']];
            }

            return ['success' => true, 'products' => []];
        } catch (RequestException $e) {
            Log::error('ProductServiceClient error', [
                'url'     => $this->baseUrl,
                'params'  => $params,
                'error'   => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage(), 'products' => []];
        }
    }

    /**
     * Get product by ID
     */
    public function getProduct(string $token, string $tenantId, string $productId): array
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/products/{$productId}", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/json',
                    'X-Tenant-ID'   => $tenantId,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ['success' => true, 'product' => $body['product'] ?? null];
        } catch (RequestException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'product' => null];
        }
    }
}
