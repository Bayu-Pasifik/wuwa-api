# Wuthering Waves Wiki API

REST API read-only untuk data hasil scraping `wutheringwaves.fandom.com` (9.231 artikel).
Semua response berformat JSON. Tidak perlu autentikasi.

Base URL (lokal): `http://127.0.0.1:8000/api`
Jalankan server: `php artisan serve`

## Ringkasan Route

| Method | Route | Fungsi |
|---|---|---|
| GET | `/api/{type}` | Daftar entri (berhalaman) |
| GET | `/api/{type}/{slug}` | Detail satu entri lengkap |
| GET | `/api/user` | Bawaan Laravel Sanctum (butuh token, tidak dipakai untuk data wiki) |

## Parameter `{type}`

| Nilai | Isi | Jumlah |
|---|---|---|
| `resonators` | Karakter Resonator | 58 |
| `weapons` | Senjata | 131 |
| `echoes` | Echo | 215 |
| `article` | Artikel lain (quest, lore, item, area, dll.) | 8.827 |
| `all` | Semua type di atas | 9.231 |

Nilai `type` yang tidak dikenal mengembalikan daftar kosong pada `index` dan 404 pada `show`.

---

## GET `/api/{type}`

Daftar entri, diurutkan berdasarkan `title`. Response ringkas (tanpa `infobox`, `sections`, `categories`, `images`).

### Query string

| Parameter | Tipe | Default | Keterangan |
|---|---|---|---|
| `search` | string | - | Cari judul (LIKE `%search%`) |
| `category` | string | - | Filter berdasarkan nama kategori wiki yang persis, contoh `Aero Enemies` |
| `per_page` | integer | 20 | Jumlah item per halaman, maksimal 100 |
| `page` | integer | 1 | Nomor halaman |

### Contoh

```
GET /api/resonators?search=jin&per_page=5
GET /api/all?category=5-Star%20Resonators&per_page=50
GET /api/echoes?page=2
```

### Response 200

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 379,
      "type": "echoes",
      "page_id": 25707,
      "title": "Abyssal Gladius/Echo",
      "slug": "Abyssal_Gladius/Echo",
      "url": "https://wutheringwaves.fandom.com/wiki/Abyssal_Gladius%2FEcho",
      "description": "Transform into Abyssal Gladius and deal Glacio DMG to enemies...",
      "image": "https://static.wikia.nocookie.net/wutheringwaves/images/3/34/Abyssal_Gladius_Icon.png/revision/latest"
    }
  ],
  "first_page_url": "http://127.0.0.1:8000/api/echoes?page=1",
  "from": 1,
  "last_page": 215,
  "last_page_url": "http://127.0.0.1:8000/api/echoes?page=215",
  "links": [],
  "next_page_url": "http://127.0.0.1:8000/api/echoes?page=2",
  "path": "http://127.0.0.1:8000/api/echoes",
  "per_page": 1,
  "prev_page_url": null,
  "to": 1,
  "total": 215
}
```

---

## GET `/api/{type}/{slug}`

Detail satu entri lengkap. `slug` adalah judul halaman dengan spasi diganti underscore, dan **boleh mengandung `/`** (sub-halaman), contoh `Jinhsi/Combat`.

### Contoh

```
GET /api/resonators/Jinhsi
GET /api/echoes/Abyssal_Gladius/Echo
GET /api/all/Jinhsi/Combat
```

Gunakan `all` sebagai `type` jika tipe entri tidak diketahui.
Jika `type` dan `slug` cocok dengan lebih dari satu baris, yang dikembalikan baris pertama.

### Response 200

| Field | Tipe | Keterangan |
|---|---|---|
| `id` | integer | ID database |
| `type` | string | `resonators`, `weapons`, `echoes`, atau `article` |
| `page_id` | integer | ID halaman di Fandom (unik) |
| `title` | string | Judul halaman |
| `slug` | string | Judul dengan underscore |
| `url` | string | Link halaman asli di wiki |
| `description` | string/null | Paragraf pembuka, dipotong 1.000 karakter |
| `image` | string/null | Gambar utama infobox |
| `infobox` | object | Pasangan key-value dari infobox (role, gender, birthday, dll.). `{}` jika tidak ada |
| `sections` | array | Isi halaman per heading, lihat di bawah |
| `categories` | array of string | Kategori wiki halaman ini |
| `images` | array of string | Nama file gambar di halaman |
| `scraped_at` | string (ISO 8601) | Waktu scraping |
| `created_at`, `updated_at` | string | Timestamp database |

Struktur elemen `sections`:

```json
{
  "heading": "Skill Costs",
  "level": 3,
  "text": "Teks paragraf dan list, dipisah \\n",
  "tables": [
    [
      ["Level", "Required Ascension", "Shell Credits"],
      ["1 → 2", "2✦", "1,500 Shell Credit[1,500]"]
    ]
  ]
}
```

- `level`: 2 untuk `h2`, 3 untuk `h3`. Teks sebelum heading pertama bernama `Intro`.
- `tables`: array tabel, tiap tabel adalah array baris, tiap baris adalah array sel (teks). Baris tiap tabel sudah disamakan lebarnya, sel gabungan (rowspan/colspan) diulang di setiap sel yang dicakup.

### Contoh response (dipersingkat)

```json
{
  "id": 1,
  "type": "resonators",
  "page_id": 585,
  "title": "Aalto",
  "slug": "Aalto",
  "url": "https://wutheringwaves.fandom.com/wiki/Aalto",
  "description": "G'day. What does our loyal client wish to inquire about today?",
  "image": "https://static.wikia.nocookie.net/wutheringwaves/images/6/65/Aalto_Card.jpg/revision/latest",
  "infobox": { "class": "Congenital", "gender": "Male", "birthday": "June 11th" },
  "sections": [ { "heading": "Trivia", "level": 2, "text": "...", "tables": [] } ],
  "categories": ["Resonators", "Aero DMG Amplification Resonators"],
  "images": ["Aalto_Card.jpg"],
  "scraped_at": "2026-11-04 12:00:00"
}
```

### Response 404

Entri tidak ditemukan.

```json
{ "message": "No query results for model [App\\Models\\WikiEntry]." }
```

---

## Catatan

- Pada URL, slug yang berisi karakter khusus (spasi, tanda kutip, `?`, `#`) harus di-URL-encode. Contoh: `"A_Study_of_Royan_Mythology"_(Excerpt)` menjadi `%22A_Study_of_Royan_Mythology%22_%28Excerpt%29`.
- Slug dan `type` peka huruf besar/kecil mengikuti judul di wiki (`Jinhsi`, bukan `jinhsi`) pada pencocokan `slug`; pencarian `search` tidak peka huruf besar/kecil.
- `Authorization` tidak diperlukan. Route `/api/user` berasal dari instalasi Sanctum dan tidak berisi data wiki.

## Memperbarui Data

```bash
# dari folder wuwa-api/
php scraper/wuwa-scraps.php 0 6 --update   # hanya halaman baru/berubah (bandingkan revid) -> ../output/pages.jsonl
php artisan wuwa:import --prune            # upsert ke MySQL + hapus halaman yang sudah hilang dari wiki
```

Opsi scraper:

- tanpa `--update`: hanya halaman baru (page_id belum ada); isi halaman lama tidak diperiksa.
- `--backfill-revid`: isi `revid` baris lama dari revisi wiki saat ini, tanpa scrape ulang (sekali saja untuk data lama).
- Scrape penuh dari nol: hapus `output/pages.jsonl`, lalu jalankan tanpa `--update`.
