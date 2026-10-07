<?php
declare(strict_types=1);

function study_interest_xlsx_available(): bool
{
    return class_exists('ZipArchive');
}

function study_interest_xlsx_column(int $number): string
{
    $column = '';
    while ($number > 0) {
        $number--;
        $column = chr(65 + ($number % 26)) . $column;
        $number = intdiv($number, 26);
    }
    return $column;
}

function study_interest_xlsx_xml(string $value): string
{
    $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    return htmlspecialchars(is_string($clean) ? $clean : '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function study_interest_xlsx_write(string $path, string $sheetName, array $headers, array $rows, array $numericColumns = [], array $widths = []): void
{
    if (!study_interest_xlsx_available()) throw new RuntimeException('Excel export is unavailable because ZipArchive is not installed.');
    if ($headers === [] || count($headers) > 100) throw new InvalidArgumentException('Excel export columns are invalid.');
    $lastColumn = study_interest_xlsx_column(count($headers));
    $columnXml = '';
    foreach ($headers as $index => $_header) {
        $number = $index + 1;
        $width = max(8, min(60, (float)($widths[$index] ?? 22)));
        $columnXml .= '<col min="' . $number . '" max="' . $number . '" width="' . $width . '" customWidth="1"/>';
    }
    $rowXml = '<row r="1" ht="25" customHeight="1">';
    foreach (array_values($headers) as $index => $header) {
        $ref = study_interest_xlsx_column($index + 1) . '1';
        $rowXml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . study_interest_xlsx_xml((string)$header) . '</t></is></c>';
    }
    $rowXml .= '</row>';
    foreach (array_values($rows) as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        $style = $excelRow % 2 === 0 ? 2 : 3;
        $rowXml .= '<row r="' . $excelRow . '">';
        foreach (array_values($headers) as $columnIndex => $_header) {
            $ref = study_interest_xlsx_column($columnIndex + 1) . $excelRow;
            $value = $row[$columnIndex] ?? '';
            if (in_array($columnIndex, $numericColumns, true) && $value !== '' && is_numeric($value)) {
                $rowXml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . study_interest_xlsx_xml((string)$value) . '</v></c>';
            } else {
                $text = str_replace("\r", '', (string)$value);
                $rowXml .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . study_interest_xlsx_xml($text) . '</t></is></c>';
            }
        }
        $rowXml .= '</row>';
    }
    $lastRow = count($rows) + 1;
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/><cols>' . $columnXml . '</cols><sheetData>' . $rowXml . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . max(1, $lastRow) . '"/><pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.2" footer="0.2"/>'
        . '</worksheet>';
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF10162F"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="hair"><color rgb="FFD5DAE3"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Excel workbook could not be created.');
    try {
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="' . study_interest_xlsx_xml(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . study_interest_xlsx_xml($sheetName) . '</dc:title><dc:creator>Study Interest Explorer</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
    } finally {
        $zip->close();
    }
    if (!is_file($path) || filesize($path) === 0) throw new RuntimeException('Excel workbook is empty.');
}
