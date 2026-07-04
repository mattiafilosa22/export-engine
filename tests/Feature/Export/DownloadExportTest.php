<?php

namespace Tests\Feature\Export;

use App\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DownloadExportTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT_TYPE_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function test_it_downloads_the_generated_xlsx_file(): void
    {
        Storage::fake('local');
        $export = Export::factory()->completed()->create();
        Storage::disk('local')->put($export->file_path, 'binary-xlsx-content');

        $response = $this->get("/api/v1/exports/{$export->uuid}/download");

        $response->assertStatus(200);
        $this->assertStringContainsString(
            self::CONTENT_TYPE_XLSX,
            $response->headers->get('content-type')
        );
        $this->assertStringContainsString(
            "export-{$export->uuid}.xlsx",
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_it_returns_409_when_the_export_is_not_completed(): void
    {
        $export = Export::factory()->create(['status' => Export::STATUS_PENDING]);

        $response = $this->getJson("/api/v1/exports/{$export->uuid}/download");

        $response->assertStatus(409);
    }

    public function test_it_returns_404_when_the_file_is_missing_on_disk(): void
    {
        Storage::fake('local');
        $export = Export::factory()->completed()->create();

        $response = $this->getJson("/api/v1/exports/{$export->uuid}/download");

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_an_unknown_export(): void
    {
        $response = $this->getJson('/api/v1/exports/' . Str::uuid() . '/download');

        $response->assertStatus(404);
    }
}
