<?php

namespace App\Console\Commands;

use App\Jobs\GenerateExportJob;
use App\Models\Event;
use App\Models\Export;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Benchmarks a streamed events export and reports peak memory, to prove the
 * generation stays at ~constant memory regardless of row count (DoD Slice 5).
 * Uses the detail/keyset path (the flat-memory one).
 */
class ExportBenchmarkCommand extends Command
{
    protected $signature = 'gamindo:export-benchmark
        {--rows=500000 : Number of event rows to export}
        {--keep : Keep the generated data and file instead of cleaning up}';

    protected $description = 'Benchmark a streamed events export and report peak memory (flat-memory proof).';

    public function handle(): int
    {
        $rows = max(1, (int) $this->option('rows'));

        $this->info("Seeding {$rows} events…");
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->bulkInsert((int) $version->id, (int) $player->id, $rows);

        $export = Export::create([
            'version_id' => $version->id,
            'params' => [],
            'format' => Export::FORMAT_XLSX,
            'status' => Export::STATUS_PENDING,
        ]);

        $this->info('Generating export (keyset stream)…');
        $start = microtime(true);
        $this->getLaravel()->call([new GenerateExportJob($export->uuid), 'handle']);
        $duration = microtime(true) - $start;

        $export->refresh();

        $this->line('');
        $this->info(sprintf(
            'rows=%d  peak_mem=%.1f MB  duration=%.2fs  file=%.2f MB  status=%s',
            (int) $export->total_rows,
            memory_get_peak_usage(true) / 1048576,
            $duration,
            (int) $export->file_size / 1048576,
            (string) $export->status
        ));

        if (! $this->option('keep')) {
            $this->cleanup($version, $export);
            $this->comment('Cleaned up benchmark data.');
        }

        return self::SUCCESS;
    }

    private function cleanup(Version $version, Export $export): void
    {
        DB::table('events')->where('version_id', $version->id)->delete();
        DB::table('players')->where('version_id', $version->id)->delete();
        $export->delete();
        DB::table('versions')->where('id', $version->id)->delete();
    }
}
