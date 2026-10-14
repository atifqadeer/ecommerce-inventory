<?php

namespace App\Services\Statements;

/**
 * Turns an uploaded CSV/TSV report into rows, and finds the header row (reports such as Amazon's
 * date range report start with a few lines of description before the table).
 */
class StatementReader
{
    /** How many leading lines may precede the header row. */
    private const HEADER_SEARCH_ROWS = 30;

    /** @return array<int, array<int, string>> */
    public static function rows(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            // UTF-16 exports (some Excel saves) or Windows-1252 (e.g. "£" in Amazon UK reports).
            $content = str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")
                ? mb_convert_encoding($content, 'UTF-8', 'UTF-16')
                : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $delimiter = self::delimiter($content);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null] || ! array_filter($row, fn ($cell) => trim((string) $cell) !== '')) {
                continue; // blank line
            }
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $row);
        }
        fclose($handle);

        return $rows;
    }

    /** Lower-cased, single-spaced header name used to look up columns. */
    public static function key(string $header): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($header, " \t\n\r\0\x0B\"'")));
    }

    /**
     * Index of the first row that contains every one of the given header names.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string|array<int, string>>  $required  a header name, or a list of alternatives
     */
    public static function headerRow(array $rows, array $required): ?int
    {
        foreach (array_slice($rows, 0, self::HEADER_SEARCH_ROWS, true) as $index => $row) {
            $keys = array_map(self::key(...), $row);
            $matches = collect($required)->every(fn ($names) => array_intersect((array) $names, $keys) !== []);
            if ($matches) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Rows after the header as associative arrays keyed by normalised header name.
     *
     * @return array<int, array<string, string>>
     */
    public static function records(array $rows, int $headerRow): array
    {
        $keys = array_map(self::key(...), $rows[$headerRow]);
        $records = [];
        foreach (array_slice($rows, $headerRow + 1) as $row) {
            $record = [];
            foreach ($keys as $i => $key) {
                if ($key !== '' && ! isset($record[$key])) {
                    $record[$key] = $row[$i] ?? '';
                }
            }
            $records[] = $record;
        }

        return $records;
    }

    private static function delimiter(string $content): string
    {
        $sample = implode("\n", array_slice(preg_split('/\R/', $content, 40), 0, 40));
        $counts = ["\t" => substr_count($sample, "\t"), ',' => substr_count($sample, ','), ';' => substr_count($sample, ';')];
        arsort($counts);

        return array_key_first($counts);
    }
}
