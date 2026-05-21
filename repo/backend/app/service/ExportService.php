<?php
declare(strict_types=1);

namespace app\service;

use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use app\exception\AppException;

class ExportService
{
    private const EXPORT_DIR = '/app/public/exports/';

    private static function ensureDir(): void
    {
        if (!is_dir(self::EXPORT_DIR)) {
            mkdir(self::EXPORT_DIR, 0755, true);
        }
    }

    private static function watermarkText(string $username): string
    {
        return $username . ' — ' . date('m/d/Y g:i A');
    }

    /** Export widget data as watermarked PDF. Returns relative file path. */
    public static function exportPdf(array $widgetData, string $title, string $username): string
    {
        self::ensureDir();
        $filename = 'export_' . uniqid() . '.pdf';
        $fullPath = self::EXPORT_DIR . $filename;

        $watermark = htmlspecialchars(self::watermarkText($username));
        $rows = '';
        foreach ($widgetData as $label => $value) {
            $rows .= '<tr><td>' . htmlspecialchars((string)$label) . '</td>'
                   . '<td>' . htmlspecialchars((string)$value) . '</td></tr>';
        }

        $html = <<<HTML
<html><body>
<h2>{$title}</h2>
<p style="color:#aaa;font-size:9pt;">{$watermark}</p>
<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%">
<tr><th>Metric</th><th>Value</th></tr>
{$rows}
</table>
</body></html>
HTML;

        $mpdf = new Mpdf(['tempDir' => '/tmp/mpdf', 'mode' => 'utf-8', 'format' => 'A4']);
        $mpdf->SetWatermarkText($watermark);
        $mpdf->showWatermarkText = true;
        $mpdf->watermark_font    = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->WriteHTML($html);
        $mpdf->Output($fullPath, 'F');

        return 'exports/' . $filename;
    }

    /** Export widget data as watermarked XLSX. Returns relative file path. */
    public static function exportXlsx(array $widgetData, string $title, string $username): string
    {
        self::ensureDir();
        $filename = 'export_' . uniqid() . '.xlsx';
        $fullPath = self::EXPORT_DIR . $filename;

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($title, 0, 31));

        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', 'Exported by: ' . self::watermarkText($username));
        $sheet->setCellValue('A4', 'Metric');
        $sheet->setCellValue('B4', 'Value');

        $row = 5;
        foreach ($widgetData as $label => $value) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return 'exports/' . $filename;
    }

    /** Export widget data as watermarked PNG. Returns relative file path. */
    public static function exportPng(array $widgetData, string $title, string $username): string
    {
        self::ensureDir();
        $filename = 'export_' . uniqid() . '.png';
        $fullPath = self::EXPORT_DIR . $filename;

        $lineHeight = 24;
        $padding    = 20;
        $rows       = count($widgetData);
        $height     = $padding * 2 + 60 + ($rows * $lineHeight) + 30;
        $width      = 600;

        $img = imagecreatetruecolor($width, $height);
        $bg    = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 30, 30, 30);
        $gray  = imagecolorallocate($img, 150, 150, 150);
        $blue  = imagecolorallocate($img, 41, 98, 190);
        imagefill($img, 0, 0, $bg);

        // Title
        imagestring($img, 5, $padding, $padding, $title, $blue);
        // Watermark line
        imagestring($img, 2, $padding, $padding + 22, self::watermarkText($username), $gray);

        $y = $padding + 55;
        foreach ($widgetData as $label => $value) {
            imagestring($img, 3, $padding, $y, (string)$label . ': ' . (string)$value, $black);
            $y += $lineHeight;
        }

        // Diagonal watermark
        $wmColor = imagecolorallocatealpha($img, 180, 180, 180, 80);
        imagestringup($img, 2, (int)($width / 2), (int)($height / 2), $username, $wmColor);

        imagepng($img, $fullPath);
        imagedestroy($img);

        return 'exports/' . $filename;
    }
}
