<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 市区町村マスタ。5桁の全国地方公共団体コード（JIS X 0402。デジタル庁
     * アドレス・ベース・レジストリの6桁コードから検査数字を除いたもの）を
     * 主キーにし、施設の住所からの市区町村判定と人口統計との突合に使う。
     */
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->char('code', 5)->primary();
            $table->char('prefecture_code', 2)->index();
            // 郡＋市＋区の結合表記（例: 札幌市中央区、磯城郡三宅町）
            $table->string('name');
            $table->string('name_kana');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('municipalities');
    }
};
