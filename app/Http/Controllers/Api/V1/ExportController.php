<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Services\Export\FacilityExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    use ProvidesAttribution;

    public function __construct(
        private readonly FacilityExporter $exporter,
    ) {}

    /**
     * 一括ダウンロードのファイル一覧
     *
     * 取り扱う範囲の全施設（廃止を含む）を、都道府県ごとと全体（`all`）の CSV / JSON Lines（gzip 圧縮）で提供します。
     * 毎日の取込の後に、データが変わっていれば作り直します。JSON Lines の各行は施設詳細と同じ形です。
     * CSV はコードと名前を別の列にし、診療科目は `|` 区切り、`designation_history` と `bed_counts` は JSON 文字列です。
     * `sha256` でダウンロードしたファイルを検証できます。以降の差分は、一覧APIの `updated_since` で取得してください。
     */
    public function index(): JsonResponse
    {
        $manifest = $this->exporter->manifest();

        if ($manifest === null) {
            return response()->json(['message' => '一括ダウンロードのファイルはまだ作成されていません。'], 404);
        }

        return response()->json([
            'data' => [
                /**
                 * ファイルを作った日時
                 *
                 * @var string
                 *
                 * @format date-time
                 *
                 * @example 2026-10-05T22:16:01.654097Z
                 */
                'generated_at' => $manifest['generated_at'],
                /**
                 * ファイルに含まれる施設のうち、最も新しい `updated_at`。差分の同期の起点に使える
                 *
                 * @var string
                 *
                 * @format date-time
                 *
                 * @example 2026-10-05T06:56:45.000000Z
                 */
                'data_updated_at' => $manifest['data_updated_at'],
                /**
                 * ファイルの一覧。`format` は `csv` か `jsonl`、`prefecture` は都道府県（全体のファイルは null）、
                 * `records` は施設の数、`size` はバイト数、`sha256` はファイルのハッシュ値、`url` はダウンロードのURL
                 *
                 * @var list<array{name: string, format: 'csv'|'jsonl', prefecture: array{code: string, label: string}|null, records: int, size: int, sha256: string, url: string}>
                 *
                 * @example [{"name": "medical-facilities-01.csv.gz", "format": "csv", "prefecture": {"code": "01", "label": "北海道"}, "records": 8130, "size": 428199, "sha256": "e45239903993bda22a9bcbe4d5aabf64bc26cf436c06eea5583e054c90377934", "url": "https://168-110-42-30.sslip.io/api/v1/exports/medical-facilities-01.csv.gz"}, {"name": "medical-facilities-all.jsonl.gz", "format": "jsonl", "prefecture": null, "records": 224517, "size": 16248504, "sha256": "a0c6681a6d420e2dce225445426c087c68672bf02515b365fd0dec0c98d8ef01", "url": "https://168-110-42-30.sslip.io/api/v1/exports/medical-facilities-all.jsonl.gz"}]
                 */
                'files' => array_map(fn (array $file): array => [
                    ...$file,
                    'url' => route('api.v1.exports.show', ['filename' => $file['name']]),
                ], $manifest['files']),
            ],
            'meta' => ['attribution' => $this->attribution()],
        ]);
    }

    /**
     * 一括ダウンロード
     *
     * ファイル一覧の `url` からダウンロードします。`ETag`（SHA-256）と `If-None-Match` で、変わっていなければ 304 を返します。
     */
    public function show(Request $request, string $filename): BinaryFileResponse|JsonResponse
    {
        $manifest = $this->exporter->manifest();
        $file = $manifest === null ? null : $this->exporter->file($manifest, $filename);

        if ($manifest === null || $file === null) {
            return response()->json(['message' => 'ファイルが見つかりません。'], 404);
        }

        $path = $this->exporter->pathOf($manifest, $filename);

        $response = response()->download($path, $filename, ['Content-Type' => 'application/gzip']);
        $response->setEtag($file['sha256']);
        $response->setLastModified(Carbon::parse($manifest['generated_at']));
        $response->setPublic();
        $response->isNotModified($request);

        return $response;
    }
}
