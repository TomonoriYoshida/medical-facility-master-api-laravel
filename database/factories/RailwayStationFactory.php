<?php

namespace Database\Factories;

use App\Models\RailwayStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RailwayStation>
 */
class RailwayStationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->numerify('######');
        $name = fake()->unique()->lastName();

        return [
            'station_code' => $code,
            'group_code' => $code,
            'name' => $name,
            'name_normalized' => $name,
            'line_name' => '6号線三田線',
            'operator_name' => '東京都',
            'prefecture_code' => '13',
            'municipality_code' => '13105',
            'latitude' => fake()->latitude(35.70, 35.72),
            'longitude' => fake()->longitude(139.74, 139.76),
        ];
    }
}
