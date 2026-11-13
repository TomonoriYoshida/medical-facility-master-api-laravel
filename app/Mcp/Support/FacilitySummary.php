<?php

namespace App\Mcp\Support;

/**
 * The fields of an API facility (MedicalFacilityResource) that a model
 * needs to answer with, as plain labels rather than codes.
 */
final class FacilitySummary
{
    /**
     * @param  array<string, mixed>  $facility
     * @return array<string, mixed>
     */
    public static function from(array $facility): array
    {
        return [
            'id' => $facility['id'] ?? null,
            'name' => $facility['name'] ?? null,
            'type' => $facility['institution_type']['label'] ?? null,
            'departments' => array_column($facility['department_categories'] ?? [], 'label'),
            'address' => self::address($facility),
            'phone_number' => $facility['phone_number'] ?? null,
            'website_url' => $facility['website_url'] ?? null,
            'latitude' => $facility['location']['latitude'] ?? null,
            'longitude' => $facility['location']['longitude'] ?? null,
        ];
    }

    /**
     * The address with its prefecture: some bureaus write it in the address
     * and some leave it out (the address is kept as published).
     *
     * @param  array<string, mixed>  $facility
     */
    private static function address(array $facility): string
    {
        $prefecture = (string) ($facility['prefecture']['label'] ?? '');
        $address = (string) ($facility['address'] ?? '');

        return str_starts_with($address, $prefecture) ? $address : $prefecture.$address;
    }
}
