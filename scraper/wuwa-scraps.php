<?php
/**
 * Wuthering Waves Wiki scraper - SEMUA artikel (namespace 0, non-redirect) via MediaWiki API.
 *
 * Usage : php wuwa-scraps.php [limit] [concurrency] [--update]
 * Output: ../../output/pages.jsonl  (1 halaman per baris, JSON; baris terakhir per page_id yang berlaku)
 *         ../../output/page_ids.json (daftar page_id yang masih ada di wiki, ditulis tiap run)
 * Resume: jalankan ulang, halaman yang sudah ada dilewati. Hapus file untuk mulai dari nol.
 * --update: bandingkan lastrevid wiki dengan revid tersimpan; scrape hanya halaman baru/berubah.
 */

const BASE = 'https://wutheringwaves.fandom.com';
const UA = 'Mozilla/5.0 (compatible; WuwaScraper/2.0)';
const TYPE_MAP = [ // kategori => type (prioritas dari atas)
    'Resonators' => 'resonators',
    'Weapons'    => 'weapons',
    'Echoes'     => 'echoes',
];

function http_get(string $url, int $tries = 5): ?string
{
    for ($i = 1; $i <= $tries; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => UA]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            return $body;
        }
        sleep($i * 2);
    }
    return null;
}

function api_url(array $p): string
{
    return BASE . '/api.php?' . http_build_query($p + ['format' => 'json']);
}

/**
 * Semua artikel (bukan redirect) di namespace 0 beserta lastrevid-nya (~19 request).
 *
 * @return array{0: array<int,string>, 1: array<int,int>} [pageid => judul, pageid => lastrevid]
 */
function all_pages(): array
{
    $titles = [];
    $revs = [];
    $cont = [];
    do {
        $body = http_get(api_url([
            'action' => 'query', 'generator' => 'allpages', 'gapnamespace' => 0,
            'gapfilterredir' => 'nonredirects', 'gaplimit' => 500, 'prop' => 'info',
        ] + $cont));
        $res = $body ? json_decode($body, true) : null;
        if (!$res || !isset($res['query']['pages'])) {
            fwrite(STDERR, "Gagal ambil daftar halaman\n");
            exit(1);
        }
        foreach ($res['query']['pages'] as $p) {
            $titles[$p['pageid']] = $p['title'];
            $revs[$p['pageid']] = (int) ($p['lastrevid'] ?? 0);
        }
        $cont = $res['continue'] ?? [];
        echo "\rDaftar halaman: " . count($titles);
    } while ($cont);
    echo "\n";
    return [$titles, $revs];
}

function clean(string $s): string
{
    $s = preg_replace('/\[\d+\]/', '', $s);
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function node_text(DOMXPath $xp, DOMNode $n): string
{
    $text = clean($n->textContent);
    if ($text === '') { // fallback: alt gambar (ikon element dll)
        $alts = [];
        foreach ($xp->query('.//img', $n) as $img) {
            $a = clean($img->getAttribute('alt') ?: $img->getAttribute('data-image-name'));
            if ($a !== '') {
                $alts[] = $a;
            }
        }
        $text = implode(', ', $alts);
    }
    return $text;
}

function table_rows(DOMXPath $xp, DOMElement $table): array
{
    // grid dengan rowspan/colspan diekspansi agar kolom selalu sejajar
    $grid = [];
    $r = 0;
    foreach ($xp->query('.//tr', $table) as $tr) {
        // lewati tr milik tabel bersarang
        $owner = $tr;
        while (($owner = $owner->parentNode) && $owner->nodeName !== 'table');
        if ($owner !== $table) {
            continue;
        }
        $c = 0;
        foreach ($xp->query('./th|./td', $tr) as $cell) {
            while (isset($grid[$r][$c])) {
                $c++;
            }
            $text = node_text($xp, $cell);
            $rs = min(max(1, (int) $cell->getAttribute('rowspan')), 50);
            $cs = min(max(1, (int) $cell->getAttribute('colspan')), 50);
            for ($i = 0; $i < $rs; $i++) {
                for ($j = 0; $j < $cs; $j++) {
                    $grid[$r + $i][$c + $j] = $text;
                }
            }
            $c += $cs;
        }
        $r++;
    }
    $rows = [];
    foreach ($grid as $row) {
        ksort($row);
        $row = array_values($row);
        if (array_filter($row, fn($x) => $x !== '')) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** true jika node punya turunan blok; berhenti di temuan pertama (hindari XPath kuadratik). */
function has_block(DOMNode $n): bool
{
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMElement) {
            if (in_array($c->tagName, ['div', 'p', 'ul', 'ol', 'table', 'h2', 'h3'], true) || has_block($c)) {
                return true;
            }
        }
    }
    return false;
}

function build_page(array $p): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $p['text']['*']);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    // buang elemen noise
    foreach ($xp->query('//script|//style|//*[contains(@class,"navbox")]|//*[contains(@class,"mw-editsection")]|//*[@id="toc"]') as $n) {
        $n->parentNode->removeChild($n);
    }

    // infobox
    $info = [];
    foreach ($xp->query('//*[contains(@class,"pi-data") and @data-source]') as $node) {
        $val = $xp->query('.//*[contains(@class,"pi-data-value")]', $node)->item(0);
        if ($val) {
            $info[$node->getAttribute('data-source')] = node_text($xp, $val);
        }
    }

    // gambar utama
    $image = null;
    $img = $xp->query('//*[contains(@class,"pi-image")]//img')->item(0);
    if ($img) {
        $image = $img->getAttribute('data-src') ?: $img->getAttribute('src');
        $image = preg_replace('#/revision/latest.*$#', '/revision/latest', $image);
    }

    // sections berurutan: heading h2/h3 -> teks + tabel (urutan dokumen dipertahankan)
    $sections = [];
    $cur = ['heading' => 'Intro', 'level' => 2, 'text' => [], 'tables' => []];
    $root = $xp->query('//div[contains(@class,"mw-parser-output")]')->item(0) ?? $dom->documentElement;
    $walk = function (DOMNode $parent) use (&$walk, &$sections, &$cur, $xp) {
        foreach ($parent->childNodes as $n) {
            if (!($n instanceof DOMElement)) {
                continue;
            }
            $tag = $n->tagName;
            if (in_array($tag, ['h2', 'h3'], true)) {
                if ($cur['text'] || $cur['tables']) {
                    $sections[] = $cur;
                }
                $cur = ['heading' => clean($n->textContent), 'level' => (int) $tag[1], 'text' => [], 'tables' => []];
            } elseif ($tag === 'table' && !str_contains($n->getAttribute('class'), 'portable-infobox')) {
                $rows = table_rows($xp, $n);
                if ($rows) {
                    $cur['tables'][] = $rows;
                }
            } elseif (in_array($tag, ['p', 'ul', 'ol', 'dl', 'blockquote'], true)) {
                $t = clean($n->textContent);
                if ($t !== '') {
                    $cur['text'][] = $t;
                }
            } elseif (in_array($tag, ['div', 'section', 'article', 'span'], true)
                && !str_contains($n->getAttribute('class'), 'portable-infobox')) {
                if (!has_block($n)) {
                    $t = clean($n->textContent); // div/span daun berisi teks langsung
                    if ($t !== '') {
                        $cur['text'][] = $t;
                    }
                } else {
                    $walk($n);
                }
            }
        }
    };
    $walk($root);
    if ($cur['text'] || $cur['tables']) {
        $sections[] = $cur;
    }
    $seenText = [];
    $seenTable = [];
    foreach ($sections as &$s) {
        $lines = [];
        foreach ($s['text'] as $t) {
            if (mb_strlen($t) > 30) { // dedupe teks panjang (konten tab dobel)
                if (isset($seenText[$t])) {
                    continue;
                }
                $seenText[$t] = true;
            }
            $lines[] = $t;
        }
        $s['text'] = implode("\n", $lines);
        $tables = [];
        foreach ($s['tables'] as $tb) {
            $h = md5(json_encode($tb));
            if (!isset($seenTable[$h])) {
                $seenTable[$h] = true;
                $tables[] = $tb;
            }
        }
        $s['tables'] = $tables;
    }
    unset($s);

    $desc = null;
    foreach ($sections as $s) {
        foreach (explode("\n", $s['text']) as $line) {
            if (mb_strlen($line) > 40) {
                $desc = $line;
                break 2;
            }
        }
    }

    $cats = array_values(array_map(fn($c) => str_replace('_', ' ', $c['*']), $p['categories'] ?? []));
    $type = 'article';
    $isIndex = in_array($p['title'], ['Resonator', 'Weapon', 'Echo', 'Resonators', 'Weapons', 'Echoes'], true);
    foreach ($isIndex ? [] : TYPE_MAP as $cat => $t) {
        if (in_array($cat, $cats, true)) {
            $type = $t;
            break;
        }
    }

    return [
        'type'        => $type,
        'title'       => $p['title'],
        'page_id'     => $p['pageid'],
        'revid'       => $p['revid'] ?? null,
        'slug'       => str_replace(' ', '_', $p['title']),
        'url'         => BASE . '/wiki/' . rawurlencode(str_replace(' ', '_', $p['title'])),
        'description' => $desc,
        'image'       => $image,
        'infobox'     => $info,
        'sections'    => $sections,
        'categories'  => $cats,
        'images'      => $p['images'] ?? [],
        'scraped_at'  => date('c'),
    ];
}

// ---- main ----
$update = in_array('--update', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$limit = (int) ($args[0] ?? 0);
$conc  = max(1, (int) ($args[1] ?? 5));
$outDir = __DIR__ . '/../../output';
@mkdir($outDir, 0777, true);
$file = "$outDir/pages.jsonl";

$done = []; // page_id => revid tersimpan (baris terakhir menang)
if (is_file($file)) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if ($r) {
            $done[$r['page_id']] = (int) ($r['revid'] ?? 0);
        }
    }
}

[$titles, $revs] = all_pages();
file_put_contents("$outDir/page_ids.json", json_encode(array_keys($titles)));

if (in_array('--backfill-revid', $argv, true)) {
    // isi revid baris lama dari lastrevid wiki saat ini, tanpa scrape ulang
    $tmp = "$file.tmp";
    $in = fopen($file, 'rb');
    $w = fopen($tmp, 'wb');
    $filled = 0;
    while (($line = fgets($in)) !== false) {
        $r = json_decode($line, true);
        if ($r && empty($r['revid']) && !empty($revs[$r['page_id']])) {
            $r['revid'] = $revs[$r['page_id']];
            $line = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $filled++;
        }
        fwrite($w, $line);
    }
    fclose($in);
    fclose($w);
    rename($tmp, $file);
    echo "revid diisi untuk $filled baris di $file\n";
    exit(0);
}

$queue = [];
$gone = 0;
foreach ($titles as $id => $t) {
    if (!isset($done[$id])) {
        $queue[$id] = $t; // halaman baru
    } elseif ($update && $done[$id] !== $revs[$id]) {
        $queue[$id] = $t; // halaman berubah (revid tersimpan 0 = belum pernah dicatat)
    }
}
foreach ($done as $id => $_) {
    $gone += isset($titles[$id]) ? 0 : 1;
}
if ($limit > 0) {
    $queue = array_slice($queue, 0, $limit, true);
}
$total = count($queue);
echo "Total artikel: " . count($titles) . " | sudah ada: " . count($done)
    . " | hilang dari wiki: $gone | akan di-scrape: $total\n";

$out = fopen($file, 'ab');
$mh = curl_multi_init();
$active = [];   // (int)handle => [id, title, tries, ch]
$retries = [];  // id => jumlah retry
$ok = 0;
$fail = [];

$start = function () use (&$queue, &$active, $mh, &$retries) {
    $id = array_key_first($queue);
    $title = $queue[$id];
    unset($queue[$id]);
    $ch = curl_init(api_url([
        'action' => 'parse', 'pageid' => $id, 'prop' => 'text|categories|images|revid',
        'disableeditsection' => 1, 'redirects' => 1,
    ]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 90, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => UA]);
    curl_multi_add_handle($mh, $ch);
    $active[(int) $ch] = [$id, $title, $ch];
};

while ($queue && count($active) < $conc) {
    $start();
}
while ($active) {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 1.0);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        [$id, $title] = $active[(int) $ch];
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($active[(int) $ch]);

        $res = ($code === 200 && $body) ? json_decode($body, true) : null;
        if ($res && isset($res['parse']['text']['*'])) {
            $res['parse']['pageid'] = $id;
            fwrite($out, json_encode(build_page($res['parse']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $ok++;
        } elseif (isset($res['error']) && $code === 200) {
            $fail[$id] = $title . ' (' . ($res['error']['code'] ?? 'error') . ')';
        } else {
            $retries[$id] = ($retries[$id] ?? 0) + 1;
            if ($retries[$id] <= 4) {
                $queue[$id] = $title; // coba lagi nanti
                usleep(500000 * $retries[$id]);
            } else {
                $fail[$id] = "$title (HTTP $code)";
            }
        }
        $n = $ok + count($fail);
        if ($n % 25 === 0) {
            echo "\r[$n/$total] ok=$ok gagal=" . count($fail) . "   ";
        }
        if ($queue) {
            $start();
        }
    }
}
fclose($out);
curl_multi_close($mh);

echo "\nSelesai. ok=$ok gagal=" . count($fail) . " -> $file\n";
if ($fail) {
    file_put_contents("$outDir/failed.txt", implode("\n", $fail));
    echo "Daftar gagal: output/failed.txt (jalankan ulang untuk retry)\n";
}
