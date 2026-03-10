<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class InventoryServiceClient
{
    private Client $client;
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.inventory_service.url', 'http://inventory-service/api'), '/');
        $this->client = new Client(['timeout' => 5.0, 'connect_timeout' => 3.0]);
    }

    public function getInventoryForProduct(string $token, string $tenantId, string $productId): array
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/inventory/by-product/{$productId}", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ['success' => true, 'inventory' => $body['inventory'] ?? []];
        } catch (RequestException $e) {
            Log::error('InventoryServiceClient::getInventoryForProduct error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'inventory' => []];
        }
    }

    public function reserveStock(
        string $token, string $tenantId, string $inventoryId,
        int $quantity, string $reference, string $sagaId
    ): array {
        try {
            $response = $this->client->post("{$this->baseUrl}/inventory/{$inventoryId}/reserve", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'json'    => ['quantity' => $quantity, 'reference' => $reference, 'saga_id' => $sagaId],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ['success' => true, 'data' => $body];
        } catch (RequestException $e) {
            $body = null;
            if ($e->hasResponse()) {
                $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            }
            Log::error('InventoryServiceClient::reserveStock error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $body['error'] ?? $e->getMessage()];
        }
    }

    public function releaseStock(
        string $token, string $tenantId, string $inventoryId,
        int $quantity, string $reference, string $sagaId, string $reason = ''
    ): array {
        try {
            $response = $this->client->post("{$this->baseUrl}/inventory/{$inventoryId}/release", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'json'    => [
                    'quantity'  => $quantity,
                    'reference' => $reference,
                    'saga_id'   => $sagaId,
                    'reason'    => $reason,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ['success' => true, 'data' => $body];
        } catch (RequestException $e) {
            Log::error('InventoryServiceClient::releaseStock error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function adjustStock(
        string $token, string $tenantId, string $inventoryId,
        int $adjustment, string $type, string $reference
    ): array {
        try {
            $response = $this->client->post("{$this->baseUrl}/inventory/{$inventoryId}/adjust", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'json'    => ['adjustment' => $adjustment, 'type' => $type, 'reference' => $reference],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ['success' => true, 'data' => $body];
        } catch (RequestException $e) {
            Log::error('InventoryServiceClient::adjustStock error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
