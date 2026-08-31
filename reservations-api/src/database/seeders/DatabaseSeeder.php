<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Product::query()->firstOrCreate(
            ['name' => 'Widget'],
            ['stock' => 100],
        );

        Product::query()->firstOrCreate(
            ['name' => 'Gadget'],
            ['stock' => 10],
        );

        Product::query()->firstOrCreate(
            ['name' => 'Out of stock item'],
            ['stock' => 0],
        );
    }
}
