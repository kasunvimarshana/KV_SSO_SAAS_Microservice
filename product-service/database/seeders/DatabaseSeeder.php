<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = '1'; // Default tenant

        $electronics = Category::firstOrCreate(
            ['tenant_id' => $tenantId, 'slug' => 'electronics'],
            ['name' => 'Electronics', 'description' => 'Electronic products', 'is_active' => true]
        );

        $clothing = Category::firstOrCreate(
            ['tenant_id' => $tenantId, 'slug' => 'clothing'],
            ['name' => 'Clothing', 'description' => 'Clothing and apparel', 'is_active' => true]
        );

        $products = [
            ['name' => 'Laptop Pro X', 'code' => 'LAPTOP-001', 'category_id' => $electronics->id, 'price' => 1299.99, 'unit' => 'pcs'],
            ['name' => 'Wireless Mouse', 'code' => 'MOUSE-001', 'category_id' => $electronics->id, 'price' => 29.99, 'unit' => 'pcs'],
            ['name' => 'USB-C Hub', 'code' => 'HUB-001', 'category_id' => $electronics->id, 'price' => 49.99, 'unit' => 'pcs'],
            ['name' => 'Cotton T-Shirt', 'code' => 'SHIRT-001', 'category_id' => $clothing->id, 'price' => 19.99, 'unit' => 'pcs'],
            ['name' => 'Denim Jeans', 'code' => 'JEANS-001', 'category_id' => $clothing->id, 'price' => 59.99, 'unit' => 'pcs'],
        ];

        foreach ($products as $p) {
            Product::firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $p['code']],
                array_merge($p, ['tenant_id' => $tenantId, 'is_active' => true, 'attributes' => []])
            );
        }
    }
}
