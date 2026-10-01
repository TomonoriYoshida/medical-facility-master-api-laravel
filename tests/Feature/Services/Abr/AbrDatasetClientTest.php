<?php

namespace Tests\Feature\Services\Abr;

use App\Enums\AbrDataset;
use App\Services\Abr\AbrDatasetClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakesAbrDatasets;
use Tests\TestCase;

class AbrDatasetClientTest extends TestCase
{
    use FakesAbrDatasets;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_downloads_a_file_and_reads_its_rows(): void
    {
        $this->fakeAbr(['mt_town/pref/mt_town_pref13.csv.zip' => [$this->abrTown('131016', '0001001', '内幸町', chome: '1')]]);

        $client = new AbrDatasetClient;
        $rows = iterator_to_array($client->rows((string) $client->fetch(AbrDataset::Town, '13')));

        $this->assertCount(1, $rows);
        $this->assertSame('内幸町', $rows[0]['oaza_cho']);
    }

    public function test_an_unchanged_file_is_revalidated_instead_of_downloaded_again(): void
    {
        $this->fakeAbr(['mt_town/pref/mt_town_pref13.csv.zip' => [$this->abrTown('131016', '0001001', '内幸町')]]);

        $first = (new AbrDatasetClient)->fetch(AbrDataset::Town, '13');
        $second = (new AbrDatasetClient)->fetch(AbrDataset::Town, '13');

        $this->assertSame($first, $second);
        Http::assertSent(fn ($request): bool => $request->header('If-None-Match') !== []);
        $this->assertCount(1, iterator_to_array((new AbrDatasetClient)->rows((string) $second)));
    }

    public function test_a_file_is_fetched_once_per_process(): void
    {
        $this->fakeAbr(['mt_town/pref/mt_town_pref13.csv.zip' => [$this->abrTown('131016', '0001001', '内幸町')]]);

        $client = new AbrDatasetClient;
        $client->fetch(AbrDataset::Town, '13');
        $client->fetch(AbrDataset::Town, '13');

        $this->assertSame(1, $this->abrRequests['mt_town/pref/mt_town_pref13.csv.zip']);
    }

    public function test_an_unpublished_file_is_null(): void
    {
        $this->fakeAbr([]);

        $this->assertNull((new AbrDatasetClient)->fetch(AbrDataset::Parcel, '131016'));
    }

    public function test_a_server_error_fails_loudly(): void
    {
        Http::fake(fn () => Http::response('', 500));

        $this->expectException(RuntimeException::class);

        (new AbrDatasetClient)->fetch(AbrDataset::Town, '13');
    }
}
