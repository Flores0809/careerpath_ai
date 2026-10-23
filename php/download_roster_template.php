<?php
// CareerPath AI - Sample LRN class-list file for the Approvals tab.
//   ?type=xlsx  Excel file with the LRN column already formatted as Text
//   ?type=csv   plain CSV with the same sample rows
// Administrators only.

require __DIR__ . '/auth.php';
$currentUser = require_role(['administrator']);

$rows = [
    ['LRN', 'Full name'],
    ['100000000001', 'Juan Dela Cruz'],
    ['100000000002', 'Maria Santos'],
];

$type = $_GET['type'] ?? 'xlsx';

if ($type === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lrn_class_list_template.csv"');
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) {
        fputcsv($out, $r, ',', '"', '\\');
    }
    fclose($out);
    exit;
}

if (!class_exists('ZipArchive')) {
    // No zip extension: serve the prebuilt copy of the same template.
    $static = __DIR__ . '/assets/lrn_class_list_template.xlsx';
    if (is_file($static)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="lrn_class_list_template.xlsx"');
        header('Content-Length: ' . filesize($static));
        readfile($static);
        exit;
    }
    http_response_code(500);
    echo 'This server cannot build Excel files. Use the CSV template instead.';
    exit;
}

$xmlEsc = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$sheetRows = '';
foreach ($rows as $i => $r) {
    $n = $i + 1;
    $style = $i === 0 ? 2 : 1; // header bold; data cells are Text
    $sheetRows .= '<row r="' . $n . '">';
    foreach ($r as $c => $val) {
        $col = chr(65 + $c);
        $sheetRows .= '<c r="' . $col . $n . '" t="inlineStr" s="' . $style . '"><is><t>' . $xmlEsc($val) . '</t></is></c>';
    }
    $sheetRows .= '</row>';
}

$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$files = [
    '[Content_Types].xml' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>',
    '_rels/.rels' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="LRN list" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>',
    'xl/styles.xml' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="' . $ns . '">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
        . '</cellXfs></styleSheet>',
    // Column A carries the Text style (style="1") so LRNs the admin types
    // or pastes into empty rows stay Text and keep all 12 digits.
    'xl/worksheets/sheet1.xml' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . $ns . '">'
        . '<cols><col min="1" max="1" width="18" style="1" customWidth="1"/><col min="2" max="2" width="38" customWidth="1"/></cols>'
        . '<sheetData>' . $sheetRows . '</sheetData></worksheet>',
];

$tmp = tempnam(sys_get_temp_dir(), 'lrn');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Could not build the template file.';
    exit;
}
foreach ($files as $path => $content) {
    $zip->addFromString($path, $content);
}
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="lrn_class_list_template.xlsx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
