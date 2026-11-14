<?php
/**
 * CoolerCityXlsxWriter - Pure PHP Lightweight XLSX Workbook Generator
 * Menghasilkan file Microsoft Excel (.xlsx) OpenXML standar resmi
 * Tanpa memerlukan Composer / library pihak ketiga eksternal
 * 100% Kompatibel di XAMPP lokal maupun shared hosting (InfinityFree, cPanel, dll.)
 */

class CoolerCityXlsxWriter {
    private $sheets = [];

    /**
     * Tambah Sheet baru ke Workbook
     * @param string $name Nama tab sheet (maks 31 karakter)
     * @param array $cols Lebar kolom ['A' => 25, 'B' => 15, ...]
     * @param array $rows Data baris [rowNum => [colLetter => ['val' => ..., 's' => styleId, 'type' => 's'|'n']]]
     * @param array $merges Daftar merge cell ['A1:D1', 'A2:D2', ...]
     */
    public function addSheet($name, $cols, $rows, $merges = []) {
        // Nama sheet di Excel tidak boleh melebihi 31 karakter dan tidak boleh ada karakter khusus \ / ? * [ ] :
        $cleanName = substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $name), 0, 31);
        $this->sheets[] = [
            'name'   => $cleanName,
            'cols'   => $cols,
            'rows'   => $rows,
            'merges' => $merges
        ];
    }

    /**
     * Tulis dan stream file XLSX langsung ke output browser untuk diunduh
     * @param string $filename Nama file yang diunduh (misal: Laporan.xlsx)
     */
    public function download($filename) {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $this->saveToFile($tempFile);

        // Matikan error display agar tidak mencemari binary output
        @ini_set('display_errors', '0');
        error_reporting(0);

        // Bersihkan SEMUA level output buffer (penting untuk shared hosting InfinityFree
        // yang sering inject script/tracking ke output PHP)
        if (ob_get_length() !== false) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempFile));

        // Pastikan tidak ada output apapun sebelum binary data
        flush();
        readfile($tempFile);
        @unlink($tempFile);
        exit;
    }

    /**
     * Simpan file XLSX ke path tertentu di server
     */
    public function saveToFile($filePath) {
        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Tidak dapat membuat file arsip XLSX di: $filePath");
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
        $contentTypes .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
        $contentTypes .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' . "\n";
        foreach ($this->sheets as $idx => $s) {
            $sheetId = $idx + 1;
            $contentTypes .= '  <Override PartName="/xl/worksheets/sheet' . $sheetId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . "\n";
        }
        $contentTypes .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n";
        $rels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' . "\n";
        $rels .= '  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' . "\n";
        $rels .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // 3. docProps/core.xml
        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $core .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $core .= '  <dc:title>Laporan Inventory Cooler City</dc:title>' . "\n";
        $core .= '  <dc:subject>Sistem Inventory Manajemen Bahan Baku</dc:subject>' . "\n";
        $core .= '  <dc:creator>Sistem Inventory Cooler City</dc:creator>' . "\n";
        $core .= '  <cp:lastModifiedBy>Cooler City Management</cp:lastModifiedBy>' . "\n";
        $core .= '  <dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>' . "\n";
        $core .= '  <dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>' . "\n";
        $core .= '</cp:coreProperties>';
        $zip->addFromString('docProps/core.xml', $core);

        // 4. docProps/app.xml
        $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $app .= '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' . "\n";
        $app .= '  <Application>Microsoft Excel</Application>' . "\n";
        $app .= '  <DocSecurity>0</DocSecurity>' . "\n";
        $app .= '  <ScaleCrop>false</ScaleCrop>' . "\n";
        $app .= '  <Company>Cooler City - Ice Cream &amp; Tea</Company>' . "\n";
        $app .= '</Properties>';
        $zip->addFromString('docProps/app.xml', $app);

        // 5. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        foreach ($this->sheets as $idx => $s) {
            $sheetId = $idx + 1;
            $wbRels .= '  <Relationship Id="rId' . $sheetId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetId . '.xml"/>' . "\n";
        }
        $wbRels .= '  <Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' . "\n";
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 6. xl/workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $wb .= '  <sheets>' . "\n";
        foreach ($this->sheets as $idx => $s) {
            $sheetId = $idx + 1;
            $nameEsc = htmlspecialchars($s['name'], ENT_XML1, 'UTF-8');
            $wb .= '    <sheet name="' . $nameEsc . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>' . "\n";
        }
        $wb .= '  </sheets>' . "\n";
        $wb .= '</workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        // 7. xl/styles.xml
        $styles = $this->buildStylesXml();
        $zip->addFromString('xl/styles.xml', $styles);

        // 8. xl/worksheets/sheet{N}.xml
        foreach ($this->sheets as $idx => $s) {
            $sheetId = $idx + 1;
            $sheetXml = $this->buildSheetXml($s);
            $zip->addFromString('xl/worksheets/sheet' . $sheetId . '.xml', $sheetXml);
        }

        $zip->close();
        return true;
    }

    /**
     * Membangun stylesheet OpenXML dengan palet warna resmi Cooler City
     */
    private function buildStylesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n" .
        '  <numFmts count="2">' . "\n" .
        '    <numFmt numFmtId="164" formatCode="#,##0"/>' . "\n" .
        '    <numFmt numFmtId="165" formatCode="&quot;Rp &quot;#,##0"/>' . "\n" .
        '  </numFmts>' . "\n" .
        '  <fonts count="9">' . "\n" .
        '    <font><name val="Segoe UI"/><sz val="10"/><color rgb="FF0F172A"/></font>' . "\n" . // 0: Default
        '    <font><b/><name val="Segoe UI"/><sz val="15"/><color rgb="FF00677D"/></font>' . "\n" . // 1: Main Title Cyan Bold
        '    <font><i/><name val="Segoe UI"/><sz val="9.5"/><color rgb="FF64748B"/></font>' . "\n" . // 2: Subtitle
        '    <font><b/><name val="Segoe UI"/><sz val="10"/><color rgb="FFFFFFFF"/></font>' . "\n" . // 3: Table Header White Bold
        '    <font><b/><name val="Segoe UI"/><sz val="10"/><color rgb="FF0F172A"/></font>' . "\n" . // 4: Bold Dark
        '    <font><b/><name val="Segoe UI"/><sz val="10"/><color rgb="FFBA1A1A"/></font>' . "\n" . // 5: Status Red Bold (Habis)
        '    <font><b/><name val="Segoe UI"/><sz val="10"/><color rgb="FF854D64"/></font>' . "\n" . // 6: Status Pink Bold (Menipis)
        '    <font><b/><name val="Segoe UI"/><sz val="10"/><color rgb="FF15803D"/></font>' . "\n" . // 7: Status Green Bold (Aman)
        '    <font><b/><name val="Segoe UI"/><sz val="11"/><color rgb="FF00677D"/></font>' . "\n" . // 8: Subheader Bold
        '  </fonts>' . "\n" .
        '  <fills count="9">' . "\n" .
        '    <fill><patternFill patternType="none"/></fill>' . "\n" . // 0: none
        '    <fill><patternFill patternType="gray125"/></fill>' . "\n" . // 1: gray125
        '    <fill><patternFill patternType="solid"><fgColor rgb="FF00B4D8"/></patternFill></fill>' . "\n" . // 2: Cyan Header #00B4D8
        '    <fill><patternFill patternType="solid"><fgColor rgb="FF00677D"/></patternFill></fill>' . "\n" . // 3: Dark Teal Header #00677D
        '    <fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>' . "\n" . // 4: Zebra Light #F8FAFC
        '    <fill><patternFill patternType="solid"><fgColor rgb="FFFFDAD6"/></patternFill></fill>' . "\n" . // 5: Soft Red (Habis)
        '    <fill><patternFill patternType="solid"><fgColor rgb="FFFFD9E5"/></patternFill></fill>' . "\n" . // 6: Soft Pink (Menipis)
        '    <fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/></patternFill></fill>' . "\n" . // 7: Soft Ice Blue (Section)
        '    <fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/></patternFill></fill>' . "\n" . // 8: Soft Green (Aman)
        '  </fills>' . "\n" .
        '  <borders count="2">' . "\n" .
        '    <border><left/><right/><top/><bottom/><diagonal/></border>' . "\n" . // 0: None
        '    <border>' . "\n" . // 1: Thin border all around
        '      <left style="thin"><color rgb="FFCBD5E1"/></left>' . "\n" .
        '      <right style="thin"><color rgb="FFCBD5E1"/></right>' . "\n" .
        '      <top style="thin"><color rgb="FFCBD5E1"/></top>' . "\n" .
        '      <bottom style="thin"><color rgb="FFCBD5E1"/></bottom>' . "\n" .
        '    </border>' . "\n" .
        '  </borders>' . "\n" .
        '  <cellStyleXfs count="1">' . "\n" .
        '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . "\n" .
        '  </cellStyleXfs>' . "\n" .
        '  <cellXfs count="21">' . "\n" .
        '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n" . // 0: Default
        '    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' . "\n" . // 1: Title 15pt
        '    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' . "\n" . // 2: Subtitle
        '    <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' . "\n" . // 3: Table Header Cyan #00B4D8
        '    <xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' . "\n" . // 4: Table Header Dark Teal #00677D
        '    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' . "\n" . // 5: Cell Text Left
        '    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 6: Cell Text Center
        '    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 7: Cell Text Right
        '    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 8: Cell Number Integer
        '    <xf numFmtId="165" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 9: Cell Currency Rp
        '    <xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' . "\n" . // 10: Section Subheader Bar
        '    <xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 11: Status Habis (Red)
        '    <xf numFmtId="0" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 12: Status Menipis (Pink)
        '    <xf numFmtId="0" fontId="7" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 13: Status Aman (Green)
        '    <xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' . "\n" . // 14: Zebra Left
        '    <xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 15: Zebra Center
        '    <xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 16: Zebra Right
        '    <xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 17: Zebra Number
        '    <xf numFmtId="165" fontId="4" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 18: Zebra Currency
        '    <xf numFmtId="165" fontId="4" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 19: Total Row Currency
        '    <xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 20: Total Row Label
        '  </cellXfs>' . "\n" .
        '</styleSheet>';
    }

    /**
     * Membangun dokumen XML untuk worksheet individual
     */
    private function buildSheetXml($sheet) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";

        // Pengaturan lebar kolom
        if (!empty($sheet['cols'])) {
            $xml .= '  <cols>' . "\n";
            $colIndex = 1;
            foreach ($sheet['cols'] as $colLetter => $width) {
                $xml .= '    <col min="' . $colIndex . '" max="' . $colIndex . '" width="' . $width . '" customWidth="1"/>' . "\n";
                $colIndex++;
            }
            $xml .= '  </cols>' . "\n";
        }

        // Data sel baris
        $xml .= '  <sheetData>' . "\n";
        foreach ($sheet['rows'] as $rowNum => $cols) {
            $xml .= '    <row r="' . $rowNum . '">' . "\n";
            foreach ($cols as $colLetter => $cell) {
                $ref  = $colLetter . $rowNum;
                $s    = $cell['s'] ?? 0;
                $val  = $cell['val'] ?? '';
                $type = $cell['type'] ?? 's';

                if ($type === 'n' && is_numeric($val)) {
                    $xml .= '      <c r="' . $ref . '" s="' . $s . '"><v>' . $val . '</v></c>' . "\n";
                } else {
                    $escVal = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
                    $xml .= '      <c r="' . $ref . '" t="inlineStr" s="' . $s . '"><is><t>' . $escVal . '</t></is></c>' . "\n";
                }
            }
            $xml .= '    </row>' . "\n";
        }
        $xml .= '  </sheetData>' . "\n";

        // Penggabungan sel (Merge Cells)
        if (!empty($sheet['merges'])) {
            $xml .= '  <mergeCells count="' . count($sheet['merges']) . '">' . "\n";
            foreach ($sheet['merges'] as $range) {
                $xml .= '    <mergeCell ref="' . $range . '"/>' . "\n";
            }
            $xml .= '  </mergeCells>' . "\n";
        }

        $xml .= '</worksheet>';
        return $xml;
    }
}

