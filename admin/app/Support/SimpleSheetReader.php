<?php

namespace App\Support;

/**
 * Tiny, dependency-free reader for the two spreadsheet formats we accept
 * for bulk member import: CSV (and tab/semicolon variants) and XLSX.
 *
 * We deliberately avoid PhpSpreadsheet/OpenSpout here — member imports are
 * small, simple, text-only sheets, and a self-contained reader keeps the
 * composer tree untouched. XLSX is just a zip of XML, which PHP's bundled
 * ext-zip + SimpleXML handle fine for the first worksheet.
 *
 * Returns a list of rows, each row a 0-indexed array of trimmed strings.
 * The first row is the header. Fully-empty rows are dropped.
 */
class SimpleSheetReader
{
    /**
     * @return array<int, array<int, string>>
     */
    public static function read(string $path, ?string $ext = null): array
    {
        $ext = strtolower($ext ?: pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'csv', 'txt', 'tsv' => self::readCsv($path),
            'xlsx'              => self::readXlsx($path),
            default             => throw new \RuntimeException("Unsupported file type: .{$ext}. Upload a .csv or .xlsx file."),
        };
    }

    /** @return array<int, array<int, string>> */
    private static function readCsv(string $path): array
    {
        $rows = [];
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the uploaded file.');
        }

        // Sniff the delimiter from the first non-empty line (Excel exports
        // semicolons in some locales; .tsv uses tabs).
        $delimiter = self::sniffDelimiter($path);

        $first = true;
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($cells === [null] || $cells === false) {
                continue; // blank line
            }
            if ($first) {
                $first = false;
                // Strip a UTF-8 BOM that Excel often prepends to the first cell.
                if (isset($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
                }
            }
            $cells = array_map(fn ($c) => trim((string) ($c ?? '')), $cells);
            if (self::isEmptyRow($cells)) {
                continue;
            }
            $rows[] = $cells;
        }

        fclose($handle);
        return $rows;
    }

    private static function sniffDelimiter(string $path): string
    {
        $line = '';
        $handle = @fopen($path, 'r');
        if ($handle !== false) {
            $line = (string) fgets($handle);
            fclose($handle);
        }
        foreach ([",", ";", "\t"] as $candidate) {
            if (str_contains($line, $candidate)) {
                return $candidate;
            }
        }
        return ',';
    }

    /** @return array<int, array<int, string>> */
    private static function readXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not read the .xlsx file — it may be corrupt.');
        }

        // Shared strings table (most text cells reference this by index).
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $xml = @simplexml_load_string($ssXml);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    $shared[] = self::richText($si);
                }
            }
        }

        // First worksheet. sheet1.xml covers the common case; otherwise fall
        // back to the first worksheet entry in the archive.
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheetXml = $zip->getFromName($name);
                    break;
                }
            }
        }
        $zip->close();

        if (!$sheetXml) {
            return [];
        }

        $xml = @simplexml_load_string($sheetXml);
        if ($xml === false || !isset($xml->sheetData)) {
            return [];
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $colIdx = self::columnIndex((string) $c['r']);
                $type   = (string) $c['t'];

                if ($type === 's') {
                    $idx = (int) $c->v;
                    $value = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = self::richText($c->is);
                } else {
                    $value = (string) $c->v;
                }

                $cells[$colIdx] = trim($value);
            }

            if (empty($cells)) {
                continue;
            }

            // Densify into a contiguous 0-based row (fill column gaps).
            $max = max(array_keys($cells));
            $dense = [];
            for ($i = 0; $i <= $max; $i++) {
                $dense[] = $cells[$i] ?? '';
            }

            if (self::isEmptyRow($dense)) {
                continue;
            }
            $rows[] = $dense;
        }

        return $rows;
    }

    /** Flatten a shared-string / inline-string node, including rich runs. */
    private static function richText(\SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }
        return $text;
    }

    /** "B7" → 1, "AA1" → 26 (0-based column index). */
    private static function columnIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return 0;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /** @param array<int, string> $cells */
    private static function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }
        return true;
    }
}
