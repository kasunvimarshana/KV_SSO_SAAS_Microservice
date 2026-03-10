<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = '1';
    private string $token;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $payload = [
            'sub'         => '1',
            'tenant_id'   => $this->tenantId,
            'tenant_code' => 'testcorp',
            'role'        => 'admin',
            'email'       => 'admin@test.com',
            'name'        => 'Admin',
            'iat'         => time(),
            'exp'         => time() + 3600,
        ];

        $this->token = JWT::encode($payload, config('jwt.secret'), 'HS256');

        $this->category = Category::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Electronics', 'slug' => 'electronics',
            'is_active' => true,
        ]);
    }

    public function test_can_list_products(): void
    {
        Product::create([
            'tenant_id' => $this->tenantId, 'name' => 'Test Product',
            'code' => 'TEST-001', 'price' => 9.99, 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/products');
        $response->assertOk()->assertJsonStructure(['data', 'total']);
    }

    public function test_can_create_product(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/products', [
            'name'        => 'New Product',
            'code'        => 'NEW-001',
            'price'       => 29.99,
            'category_id' => $this->category->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('product.name', 'New Product')
            ->assertJsonPath('product.code', 'NEW-001');
    }

    public function test_product_code_unique_per_tenant(): void
    {
        Product::create([
            'tenant_id' => $this->tenantId, 'name' => 'Existing',
            'code' => 'DUPE-001', 'price' => 9.99, 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->postJson('/api/products', [
            'name' => 'Duplicate', 'code' => 'DUPE-001', 'price' => 5.00,
        ]);

        $response->assertStatus(409);
    }

    public function test_can_filter_by_name(): void
    {
        Product::create([
            'tenant_id' => $this->tenantId, 'name' => 'Laptop Pro',
            'code' => 'LAP-001', 'price' => 999.99, 'is_active' => true,
        ]);
        Product::create([
            'tenant_id' => $this->tenantId, 'name' => 'Mouse',
            'code' => 'MOU-001', 'price' => 19.99, 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/products?name=Laptop');
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Laptop Pro', $data[0]['name']);
    }

    public function test_can_search_products(): void
    {
        Product::create([
            'tenant_id' => $this->tenantId, 'name' => 'MacBook Pro',
            'code' => 'MAC-001', 'price' => 1999.99, 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/products/search?q=MacBook');

        $response->assertOk()
            ->assertJsonStructure(['products', 'count']);
        $this->assertEquals(1, $response->json('count'));
    }

    public function test_can_get_products_by_ids(): void
    {
        $p1 = Product::create([
            'tenant_id' => $this->tenantId, 'name' => 'P1',
            'code' => 'P1-001', 'price' => 10.00, 'is_active' => true,
        ]);
        $p2 = Product::create([
            'tenant_id' => $this->tenantId, 'name' => 'P2',
            'code' => 'P2-001', 'price' => 20.00, 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->getJson("/api/products/by-ids?ids={$p1->id},{$p2->id}");

        $response->assertOk();
        $this->assertCount(2, $response->json('products'));
    }

    public function test_tenant_isolation(): void
    {
        // Create product in different tenant
        Product::create([
            'tenant_id' => '999', 'name' => 'Other Tenant',
            'code' => 'OTH-001', 'price' => 5.00, 'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/products');
        $this->assertEquals(0, $response->json('total'));
    }
}
