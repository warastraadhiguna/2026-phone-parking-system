<?php

namespace App\Domain\Reporting\Internal;

use App\Domain\Reporting\Enums\ExportFormat;
use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Streams report rows into a CSV, XLSX or PDF file.
 *
 * - CSV/XLSX: any size, row by row.
 * - PDF: limited to MAX_PDF_ROWS; larger reports must use CSV/XLSX.
 * - CSV cells that a spreadsheet would treat as a formula (=, +, -, @, tab, CR) are prefixed
 *   with an apostrophe (CSV injection), and numbers stay numbers.
 */
final class ExportWriter
{
    public const MAX_PDF_ROWS = 2000;

    /**
     * @param  array<string, string>  $columns
     * @param  iterable<array<string, scalar|null>>  $rows
     * @return int rows written
     */
    public function write(ExportFormat $format, string $path, string $title, array $columns, iterable $rows): int
    {
        return match ($format) {
            ExportFormat::CSV => $this->csv($path, $columns, $rows),
            ExportFormat::XLSX => $this->xlsx($path, $columns, $rows),
            ExportFormat::PDF => $this->pdf($path, $title, $columns, $rows),
        };
    }

    /**
     * @param  array<string, string>  $columns
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    private function csv(string $path, array $columns, iterable $rows): int
    {
        $out = fopen($path, 'wb') ?: throw new RuntimeException('Cannot open export file.');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet software reads Indonesian text correctly
        fputcsv($out, array_values($columns), escape: '');
        $n = 0;
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($key) => self::safeCell($row[$key] ?? null), array_keys($columns)), escape: '');
            $n++;
        }
        fclose($out);

        return $n;
    }

    /**
     * @param  array<string, string>  $columns
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    private function xlsx(string $path, array $columns, iterable $rows): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_values($columns)));
        $n = 0;
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($key) => $row[$key] ?? null, array_keys($columns))));
            $n++;
        }
        $writer->close();

        return $n;
    }

    /**
     * @param  array<string, string>  $columns
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    private function pdf(string $path, string $title, array $columns, iterable $rows): int
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '';
        $n = 0;
        foreach ($rows as $row) {
            if (++$n > self::MAX_PDF_ROWS) {
                throw new RuntimeException('Laporan terlalu besar untuk PDF (maks. '.self::MAX_PDF_ROWS.' baris). Gunakan CSV atau XLSX.');
            }
            $body .= '<tr>'.implode('', array_map(fn ($key) => '<td>'.$e($row[$key] ?? '').'</td>', array_keys($columns))).'</tr>';
        }
        $head = implode('', array_map(fn ($label) => '<th>'.$e($label).'</th>', $columns));
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:8px}table{border-collapse:collapse;width:100%}'
            .'th,td{border:1px solid #999;padding:2px 3px;text-align:left}th{background:#eee}h1{font-size:12px}</style></head><body>'
            .'<h1>'.$e($title).'</h1><table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></body></html>';

        $options = new Options;
        $options->setIsRemoteEnabled(false); // never fetch external resources
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', count($columns) > 6 ? 'landscape' : 'portrait');
        $dompdf->render();
        file_put_contents($path, (string) $dompdf->output());

        return $n;
    }

    public static function safeCell(mixed $value): string|int|float|null
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'ya' : 'tidak';
        }
        $s = (string) $value;
        if ($s !== '' && preg_match('/^-?\d+(\.\d+)?$/', $s) !== 1 && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$s;
        }

        return $s;
    }
}
