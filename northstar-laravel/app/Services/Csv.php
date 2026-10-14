<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Csv
{
    /** @param  array<int, array<int, scalar|null>>  $rows */
    public static function fromRows(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public static function download(string $filename, string $csv): StreamedResponse
    {
        return response()->streamDownload(fn () => print ($csv), $filename, ['Content-Type' => 'text/csv']);
    }
}
