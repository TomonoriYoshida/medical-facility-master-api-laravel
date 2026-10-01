<?php

namespace App\Enums;

/**
 * The Address Base Registry (アドレス・ベース・レジストリ) datasets used for
 * geocoding. Each is published as one zipped CSV per prefecture, except
 * 地番, which is split per municipality.
 */
enum AbrDataset: string
{
    case Town = 'mt_town';
    case TownPosition = 'mt_town_pos';
    case Block = 'mt_rsdtdsp_blk';
    case BlockPosition = 'mt_rsdtdsp_blk_pos';
    case Residence = 'mt_rsdtdsp_rsdt';
    case ResidencePosition = 'mt_rsdtdsp_rsdt_pos';
    case Parcel = 'mt_parcel';
    case ParcelPosition = 'mt_parcel_pos';

    public function isPerMunicipality(): bool
    {
        return $this === self::Parcel || $this === self::ParcelPosition;
    }

    /**
     * Path of the file below the download base URL, e.g.
     * "mt_town/pref/mt_town_pref13.csv.zip" or
     * "mt_parcel/city/mt_parcel_city131016.csv.zip".
     *
     * @param  string  $area  2-digit prefecture code, or the 6-digit 全国地方公共団体コード (with check digit) for a per-municipality dataset
     */
    public function path(string $area): string
    {
        $unit = $this->isPerMunicipality() ? 'city' : 'pref';

        return "{$this->value}/{$unit}/{$this->value}_{$unit}{$area}.csv.zip";
    }
}
