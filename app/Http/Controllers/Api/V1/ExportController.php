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
                'generated_at' => $manifest['generated_at'],
                /** ファイルに含まれる施設のうち、最も新しい `updated_at`。差分の同期の起点に使える */
                'data_updated_at' => $manifest['data_updated_at'],
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
        $file = $this->exporter->file($filename);
        $path = $this->exporter->pathOf($filename);

        if ($manifest === null || $file === null || $path === null) {
            return response()->json(['message' => 'ファイルが見つかりません。'], 404);
        }

        $response = response()->download($path, $filename, ['Content-Type' => 'application/gzip']);
        $response->setEtag($file['sha256']);
        $response->setLastModified(Carbon::parse($manifest['generated_at']));
        $response->setPublic();
        $response->isNotModified($request);

        return $response;
    }
}
