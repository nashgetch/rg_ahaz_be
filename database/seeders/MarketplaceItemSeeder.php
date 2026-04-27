<?php

namespace Database\Seeders;

use App\Models\MarketplaceItem;
use Illuminate\Database\Seeder;

class MarketplaceItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            [
                'code' => 'AIRTIME-5',
                'name' => 'ETB 5 Airtime Credit',
                'category' => 'airtime_credit',
                'description' => 'Instant ETB 5 airtime top-up reward.',
                'token_cost' => 500,
                'etb_value' => 5.00,
                'metadata' => ['operator' => 'ethio_telecom', 'delivery' => 'instant'],
            ],
            [
                'code' => 'AIRTIME-10',
                'name' => 'ETB 10 Airtime Credit',
                'category' => 'airtime_credit',
                'description' => 'Instant ETB 10 airtime top-up reward.',
                'token_cost' => 1000,
                'etb_value' => 10.00,
                'metadata' => ['operator' => 'ethio_telecom', 'delivery' => 'instant'],
            ],
            [
                'code' => 'AIRTIME-PACK-20',
                'name' => 'ETB 20 Airtime Package',
                'category' => 'airtime_package',
                'description' => 'Best-value airtime package voucher.',
                'token_cost' => 2000,
                'etb_value' => 20.00,
                'metadata' => ['operator' => 'ethio_telecom', 'delivery' => 'voucher'],
            ],
            [
                'code' => 'AIRTIME-PACK-50',
                'name' => 'ETB 50 Airtime Package',
                'category' => 'airtime_package',
                'description' => 'Premium airtime package voucher.',
                'token_cost' => 5000,
                'etb_value' => 50.00,
                'metadata' => ['operator' => 'ethio_telecom', 'delivery' => 'voucher'],
            ],
        ];

        foreach ($items as $item) {
            MarketplaceItem::query()->updateOrCreate(
                ['code' => $item['code']],
                array_merge($item, ['is_active' => true]),
            );
        }
    }
}
