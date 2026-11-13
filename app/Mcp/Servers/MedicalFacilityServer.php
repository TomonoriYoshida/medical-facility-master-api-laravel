<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\FindStationsTool;
use App\Mcp\Tools\GetFacilityTool;
use App\Mcp\Tools\SearchFacilitiesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('医療施設マスタ')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
日本全国の病院・診療所・歯科診療所・薬局を、場所・診療科・受付中の日時で探すためのサーバーです。

使い方:
- 「〇〇駅の近く」は find_stations で駅の位置を求め、search_facilities の latitude・longitude に渡します。
- 「いま開いている」「土曜の午後」などは、日本時間の具体的な日時を open_at に渡します（現在の日時が分からなければ利用者に確かめます）。
- 診療時間の詳細は get_facility で調べます。

答えるときの注意:
- データは各地方厚生局・厚生労働省などの公開データを加工したもので、臨時の休診や最近の変更は反映されません。受診の前に、電話や施設のホームページで確かめるよう添えてください。
- 施設の紹介にとどめ、病状の判断や治療の助言はしないでください。急を要する症状には、119番や救急相談（#7119 など）を案内してください。
- 結果を示すときは、attribution の notice と sources（出典）を添えてください。
TEXT)]
class MedicalFacilityServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        FindStationsTool::class,
        SearchFacilitiesTool::class,
        GetFacilityTool::class,
    ];
}
