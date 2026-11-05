<?php

namespace App\Console\Commands;

use App\Models\WikiEntry;
use Illuminate\Console\Command;

class ImportWuwa extends Command
{
    protected $signature = 'wuwa:import {--file= : path pages.jsonl hasil scraper}';

    protected $description = 'Import output/pages.jsonl (wuwa-scraps.php) ke MySQL';

    /** Samakan lebar baris tiap tabel dengan sel kosong di ujung. */
    private static function padTables(array $sections): array
    {
        foreach ($sections as &$sec) {
            foreach ($sec["tables"] ?? [] as $k => $rows) {
                $w = max(array_map("count", $rows));
                foreach ($rows as $ri => $row) {
                    $rows[$ri] = array_pad($row, $w, "");
                }
                $sec["tables"][$k] = $rows;
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
                    'type', 'title', 'slug', 'url', 'description', 'image',
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
            $i["sections"] = self::padTables($i["sections"] ?? []);
            $now = now();
            $batch[] = [
                'type' => $i['type'],
                'page_id' => $i['page_id'],
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

        return self::SUCCESS;
    }
}
