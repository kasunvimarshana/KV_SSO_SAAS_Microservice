<?php

namespace Database\Seeders;

use App\Models\Inventory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = '1';

        $items = [
            ['product_id' => '1', 'warehouse' => 'main', 'quantity' => 150, 'reorder_level' => 20],
            ['product_id' => '2', 'warehouse' => 'main', 'quantity' => 300, 'reorder_level' => 50],
            ['product_id' => '3', 'warehouse' => 'main', 'quantity' => 75,  'reorder_level' => 10],
        ];

        foreach ($items as $item) {
            Inventory::firstOrCreate(
                ['tenant_id' => $tenantId, 'product_id' => $item['product_id'], 'warehouse' => $item['warehouse']],
                array_merge($item, ['tenant_id' => $tenantId, 'reserved' => 0, 'reorder_quantity' => 50])
            );
        }
    }
}
