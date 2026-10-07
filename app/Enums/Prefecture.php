<?php

namespace App\Enums;

// The 47 prefectures by JIS X 0401 code, the form prefecture_code is
// stored and filtered in.
//
// The docblock below is published as the API docs' schema description.

/**
 * 都道府県コード（JIS X 0401の2桁）
 */
enum Prefecture: string
{
    case Hokkaido = '01';
    case Aomori = '02';
    case Iwate = '03';
    case Miyagi = '04';
    case Akita = '05';
    case Yamagata = '06';
    case Fukushima = '07';
    case Ibaraki = '08';
    case Tochigi = '09';
    case Gunma = '10';
    case Saitama = '11';
    case Chiba = '12';
    case Tokyo = '13';
    case Kanagawa = '14';
    case Niigata = '15';
    case Toyama = '16';
    case Ishikawa = '17';
    case Fukui = '18';
    case Yamanashi = '19';
    case Nagano = '20';
    case Gifu = '21';
    case Shizuoka = '22';
    case Aichi = '23';
    case Mie = '24';
    case Shiga = '25';
    case Kyoto = '26';
    case Osaka = '27';
    case Hyogo = '28';
    case Nara = '29';
    case Wakayama = '30';
    case Tottori = '31';
    case Shimane = '32';
    case Okayama = '33';
    case Hiroshima = '34';
    case Yamaguchi = '35';
    case Tokushima = '36';
    case Kagawa = '37';
    case Ehime = '38';
    case Kochi = '39';
    case Fukuoka = '40';
    case Saga = '41';
    case Nagasaki = '42';
    case Kumamoto = '43';
    case Oita = '44';
    case Miyazaki = '45';
    case Kagoshima = '46';
    case Okinawa = '47';

    public function label(): string
    {
        return match ($this) {
            self::Hokkaido => '北海道',
            self::Aomori => '青森県',
            self::Iwate => '岩手県',
            self::Miyagi => '宮城県',
            self::Akita => '秋田県',
            self::Yamagata => '山形県',
            self::Fukushima => '福島県',
            self::Ibaraki => '茨城県',
            self::Tochigi => '栃木県',
            self::Gunma => '群馬県',
            self::Saitama => '埼玉県',
            self::Chiba => '千葉県',
            self::Tokyo => '東京都',
            self::Kanagawa => '神奈川県',
            self::Niigata => '新潟県',
            self::Toyama => '富山県',
            self::Ishikawa => '石川県',
            self::Fukui => '福井県',
            self::Yamanashi => '山梨県',
            self::Nagano => '長野県',
            self::Gifu => '岐阜県',
            self::Shizuoka => '静岡県',
            self::Aichi => '愛知県',
            self::Mie => '三重県',
            self::Shiga => '滋賀県',
            self::Kyoto => '京都府',
            self::Osaka => '大阪府',
            self::Hyogo => '兵庫県',
            self::Nara => '奈良県',
            self::Wakayama => '和歌山県',
            self::Tottori => '鳥取県',
            self::Shimane => '島根県',
            self::Okayama => '岡山県',
            self::Hiroshima => '広島県',
            self::Yamaguchi => '山口県',
            self::Tokushima => '徳島県',
            self::Kagawa => '香川県',
            self::Ehime => '愛媛県',
            self::Kochi => '高知県',
            self::Fukuoka => '福岡県',
            self::Saga => '佐賀県',
            self::Nagasaki => '長崎県',
            self::Kumamoto => '熊本県',
            self::Oita => '大分県',
            self::Miyazaki => '宮崎県',
            self::Kagoshima => '鹿児島県',
            self::Okinawa => '沖縄県',
        };
    }
}
