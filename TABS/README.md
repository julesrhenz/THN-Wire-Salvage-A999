# WireSalvage AI (PHP)

Master Wire & Recommendation Station — Streamlit port to PHP + SQLite.

## Requirements

- PHP 8.1+
- Extensions: `pdo_sqlite`, `mbstring`, `zip`, `xml`, `gd`
- Composer (optional, for Excel uploads)

## Install

```bash
# 1) Create writable data directory
mkdir -p data/uploads
chmod -R 775 data

# 2) (Optional) Enable Excel uploads
composer require phpoffice/phpspreadsheet
```

Then add this to the top of `index.php` (already included in the shipped version):

```php
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
```

## Run

```bash
php -S localhost:8000
# open http://localhost:8000
```

## Verify PhpSpreadsheet

```bash
php -r "require 'vendor/autoload.php'; var_dump(class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory'));"
# expected: bool(true)
```

## Uploads

- `.csv` — always supported
- `.xls` / `.xlsx` — requires PhpSpreadsheet

Column map (0-based):
`[2]=Lot, [3]=Part, [5]=Circuit, [10]=WireKind, [11]=MaterialCode,
[12]=Size, [13]=Color, [14]=Length, [26]=Machine, [27]=Seq`