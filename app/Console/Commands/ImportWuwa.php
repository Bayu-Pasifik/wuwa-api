<?php

namespace App\Console\Commands;

use App\Models\WikiEntry;
use Illuminate\Console\Command;

class ImportWuwa extends Command
{
    protected $signature = 'wuwa:import
        {--file= : path pages.jsonl hasil scraper}
        {--prune : hapus baris yang page_id-nya sudah tidak ada di wiki (butuh page_ids.json di folder yang sama)}';

    protected $description = 'Import output/pages.jsonl (wuwa-scraps.php) ke MySQL';

    /** Samakan lebar baris tiap tabel dengan sel kosong di ujung. */
    private static function padTables(array $sections): array
    {
        foreach ($sections as &$sec) {
            foreach ($sec['tables'] ?? [] as $k => $rows) {
                $w = max(array_map('count', $rows));
                foreach ($rows as $ri => $row) {
                    $rows[$ri] = array_pad($row, $w, '');
                }
                $sec['tables'][$k] = $rows;
            }
        }

        return $sections;
    }

    public function handle(): int
    {
        $file = $this->option('file') ?: base_path('../output/pages.jsonl');
        if (! is_file($file)) {
            $this->error("File tidak ada: $file");

            return self::FAILURE;
        }

        $batch = [];
        $n = 0;
        $flush = function () use (&$batch) {
            if ($batch) {
                WikiEntry::upsert($batch, ['page_id'], [
                    'type', 'revid', 'title', 'slug', 'url', 'description', 'image',
                    'infobox', 'sections', 'categories', 'images', 'scraped_at', 'updated_at',
                ]);
                $batch = [];
            }
        };

        $h = fopen($file, 'r');
        while (($line = fgets($h)) !== false) {
            $i = json_decode($line, true);
            if (! $i) {
                continue;
            }
            $i['sections'] = self::padTables($i['sections'] ?? []);
            $now = now();
            $batch[] = [
                'type' => $i['type'],
                'page_id' => $i['page_id'],
                'revid' => $i['revid'] ?? null,
                'title' => $i['title'],
                'slug' => $i['slug'],
                'url' => $i['url'],
                'description' => isset($i['description']) ? mb_substr($i['description'], 0, 1000) : null,
                'image' => $i['image'] ?? null,
                'infobox' => json_encode($i['infobox'] ?? [], JSON_UNESCAPED_UNICODE),
                'sections' => json_encode($i['sections'] ?? [], JSON_UNESCAPED_UNICODE),
                'categories' => json_encode($i['categories'] ?? [], JSON_UNESCAPED_UNICODE),
                'images' => json_encode($i['images'] ?? [], JSON_UNESCAPED_UNICODE),
                'scraped_at' => isset($i['scraped_at']) ? date('Y-m-d H:i:s', strtotime($i['scraped_at'])) : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $n++;
            if (count($batch) >= 100) {
                $flush();
                $this->output->write("\r$n");
            }
        }
        $flush();
        fclose($h);
        $this->newLine();
        $this->info("$n halaman diimport");

        if ($this->option('prune')) {
            return $this->prune(dirname($file).'/page_ids.json');
        }

        return self::SUCCESS;
    }

    /** Hapus baris yang page_id-nya tidak ada lagi di daftar halaman wiki terbaru. */
    private function prune(string $idsFile): int
    {
        $ids = is_file($idsFile) ? json_decode(file_get_contents($idsFile), true) : null;
        if (! is_array($ids) || $ids === []) {
            $this->error("Daftar page_id tidak valid, prune dilewati: $idsFile");

            return self::FAILURE;
        }

        $live = array_flip($ids);
        $stale = WikiEntry::query()->pluck('page_id')->filter(fn ($id) => ! isset($live[$id]));
        foreach ($stale->chunk(500) as $chunk) {
            WikiEntry::whereIn('page_id', $chunk->all())->delete();
        }
        $this->info($stale->count().' halaman dihapus (sudah tidak ada di wiki)');

        return self::SUCCESS;
    }
}
