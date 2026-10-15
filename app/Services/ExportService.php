<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    /** All rows are exported; callers must supply an authorized, unpaginated report. */
    public function spreadsheet(array $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Laporan PAM');
        $columnCount = count($report['columns']);
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, $columnCount));
        foreach ([$report['identity']['name'] ?? 'PAM RW.10', $report['title'], $this->filterText($report['filters'] ?? []), 'Dicetak: '.now()->format('d/m/Y H:i').' WIB'] as $index => $line) {
            $sheet->setCellValueExplicit('A'.($index + 1), $line, DataType::TYPE_STRING);
            $sheet->mergeCells('A'.($index + 1).':'.$lastColumn.($index + 1));
        }
        $rowNumber = 6;
        foreach ($report['columns'] as $columnIndex => $column) {
            $sheet->setCellValueExplicit([$columnIndex + 1, $rowNumber], $column['label'], DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($columnIndex + 1)->setWidth(in_array($column['type'], ['money', 'number', 'decimal', 'balance', 'reading', 'age']) ? 18 : 25);
        }
        $sheet->getStyle('A6:'.$lastColumn.'6')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A6:'.$lastColumn.'6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF145A48');
        foreach ($report['rows'] as $row) {
            $rowNumber++;
            $this->writeRow($sheet, $rowNumber, $report['columns'], $row);
        }
        $sheet->setAutoFilter('A6:'.$lastColumn.max(6, $rowNumber));
        $sheet->freezePane('A7');
        foreach (($report['rtTotals'] ?? []) as $rt => $totals) {
            $rowNumber += 2;
            $sheet->setCellValueExplicit([1, $rowNumber], 'Subtotal '.$rt, DataType::TYPE_STRING);
            $this->writeRow($sheet, $rowNumber, $report['columns'], $totals, true);
        }
        $rowNumber += 2;
        $sheet->setCellValueExplicit([1, $rowNumber], 'TOTAL KESELURUHAN', DataType::TYPE_STRING);
        $this->writeRow($sheet, $rowNumber, $report['columns'], $report['totals'] ?? [], true);
        $sheet->getStyle('A'.$rowNumber.':'.$lastColumn.$rowNumber)->getFont()->setBold(true);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 6);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $book->getProperties()->setTitle($report['title'])->setCreator($report['identity']['name'] ?? 'PAM RW.10');

        return $book;
    }

    private function writeRow($sheet, int $rowNumber, array $columns, array $row, bool $onlyNumeric = false): void
    {
        foreach ($columns as $index => $column) {
            if (! array_key_exists($column['key'], $row)) {
                continue;
            }
            $value = $row[$column['key']];
            if (in_array($column['type'], ['money', 'number', 'decimal', 'balance', 'reading', 'age']) && is_numeric($value)) {
                $sheet->setCellValueExplicit([$index + 1, $rowNumber], $value, DataType::TYPE_NUMERIC);
                $sheet->getStyle([$index + 1, $rowNumber])->getNumberFormat()->setFormatCode(in_array($column['type'], ['decimal', 'reading']) ? '#,##0.000' : '#,##0');
            } elseif (! $onlyNumeric) {
                // Explicit strings keep =, +, -, @, tabs, and leading zeros as inert text.
                $sheet->setCellValueExplicit([$index + 1, $rowNumber], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
    }

    public function xlsx(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $book = $this->spreadsheet($report);
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(array $report, string $filename)
    {
        return response($this->pdfBytes(view('exports.report', compact('report'))->render(), 'a4', 'landscape'))
            ->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$filename.'.pdf"');
    }

    public function pdfBytes(string $html, string|array $paper = 'a4', string $orientation = 'portrait'): string
    {
        $cacheDirectory = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($cacheDirectory, 0750);
        if (! is_dir($cacheDirectory) || ! is_writable($cacheDirectory)) {
            throw new RuntimeException('Direktori sementara PDF tidak dapat ditulis.');
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontCache', $cacheDirectory);
        $options->set('tempDir', $cacheDirectory);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper($paper, $orientation);
        $pdf->render();
        $pdf->getCanvas()->page_text(24, $pdf->getCanvas()->get_height() - 22, 'PAM • Halaman {PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.35, 0.35, 0.35]);

        return $pdf->output();
    }

    public function filterText(array $filters): string
    {
        $parts = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === [] || in_array($key, ['format', 'page', '_token'])) {
                continue;
            }
            $parts[] = str_replace('_', ' ', $key).': '.(is_array($value) ? implode(', ', $value) : $value);
        }

        return $parts ? implode(' | ', $parts) : 'Seluruh wilayah dan periode sesuai hak akses';
    }
}
