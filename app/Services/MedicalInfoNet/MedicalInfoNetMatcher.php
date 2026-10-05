<?php

namespace App\Services\MedicalInfoNet;

use App\Models\MedicalFacility;
use App\Models\MedicalInfoNetLocation;
use App\Services\Address\FacilityMatchingKeys;
use stdClass;

/**
 * Finds a facility's counterpart among the 医療情報ネット's facilities, which
 * share no code with the bureaus' data. A facility is matched only when
 * exactly one 医療情報ネット facility of the same type and municipality has
 * its name (or, failing that, its address and a name containing or contained
 * in its own) -- counting the facilities it lists without coordinates, so
 * that a namesake with coordinates is not mistaken for one without.
 */
final class MedicalInfoNetMatcher
{
    public function __construct(private readonly FacilityMatchingKeys $keys) {}

    /**
     * Indexes medical_info_net_locations rows (as read by the query builder) for match().
     *
     * @param  iterable<stdClass>  $rows
     * @return array{0: array<string, list<array{id: int, address: string, name: string, position: array{float, float}|null}>>, 1: array<string, list<array{id: int, address: string, name: string, position: array{float, float}|null}>>}
     */
    public function index(iterable $rows): array
    {
        $byName = [];
        $byAddress = [];

        foreach ($rows as $row) {
            $scope = $row->institution_type.'|'.$row->municipality_code.'|';
            $entry = [
                'id' => (int) $row->id,
                'address' => (string) $row->address_key,
                'name' => (string) $row->name_key,
                'position' => $row->latitude === null || $row->longitude === null ? null : [(float) $row->latitude, (float) $row->longitude],
            ];
            $byName[$scope.$row->name_key][] = $entry;
            $byAddress[$scope.$row->address_key][] = $entry;
        }

        return [$byName, $byAddress];
    }

    /**
     * @param  array{institution_type: int, municipality_code: ?string, name: string, address: string}  $facility
     * @param  array{0: array<string, list<array{id: int, address: string, name: string, position: array{float, float}|null}>>, 1: array<string, list<array{id: int, address: string, name: string, position: array{float, float}|null}>>}  $index
     * @return array{id: int, address: string, name: string, position: array{float, float}|null}|null
     */
    public function match(array $facility, array $index): ?array
    {
        [$byName, $byAddress] = $index;
        $scope = $facility['institution_type'].'|'.$facility['municipality_code'].'|';
        $name = $this->keys->name($facility['name']);
        $address = $this->keys->address($facility['address']);

        $matches = $byName[$scope.$name] ?? [];

        if (count($matches) > 1) {
            $matches = array_values(array_filter($matches, fn (array $entry): bool => $entry['address'] === $address));
        }

        // Same address, and a name that is clearly the same facility: one
        // contains the other ("イムス札幌病院" / "札幌イムス札幌病院分院" do not;
        // "イムス札幌病院" / "イムス札幌病院附属" do).
        if ($matches === [] && mb_strlen($name) >= 3) {
            $matches = array_values(array_filter(
                $byAddress[$scope.$address] ?? [],
                fn (array $entry): bool => mb_strlen($entry['name']) >= 3
                    && (str_contains($entry['name'], $name) || str_contains($name, $entry['name'])),
            ));
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The one facility's counterpart, looked up among only the rows that
     * could match it (same type and municipality, and the same name or
     * address).
     */
    public function find(MedicalFacility $facility): ?MedicalInfoNetLocation
    {
        if ($facility->municipality_code === null) {
            return null;
        }

        $candidates = MedicalInfoNetLocation::query()
            ->where('municipality_code', $facility->municipality_code)
            ->where('institution_type', $facility->institution_type)
            ->where(fn ($query) => $query
                ->where('name_key', $this->keys->name($facility->name))
                ->orWhere('address_key', $this->keys->address($facility->address)))
            ->toBase()
            ->get();

        $match = $this->match([
            'institution_type' => $facility->institution_type->value,
            'municipality_code' => $facility->municipality_code,
            'name' => $facility->name,
            'address' => $facility->address,
        ], $this->index($candidates));

        return $match === null ? null : MedicalInfoNetLocation::query()->find($match['id']);
    }
}
