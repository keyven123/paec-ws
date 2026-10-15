<?php

namespace App\Services;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Writes a single-sheet Excel workbook (.xlsx) with a fixed set of cell styles.
 *
 * Cells are plain values (string, int, float, DateTimeInterface or null) or
 * [value, style] pairs that override the row style.
 */
class XlsxWriter
{
    public const STYLE_DEFAULT = 0;

    public const STYLE_TITLE = 1;

    public const STYLE_NOTE = 2;

    public const STYLE_HEADER = 3;

    public const STYLE_MONEY = 4;

    public const STYLE_DATE = 5;

    public const STYLE_DATETIME = 6;

    public const STYLE_TOTAL = 7;

    public const STYLE_TOTAL_MONEY = 8;

    /** @var list<string> */
    private array $rows = [];

    /** @var list<float> */
    private array $columnWidths = [];

    private int $frozenRows = 0;

    private ?int $filterRow = null;

    private int $columnCount = 0;

    public function __construct(private readonly string $sheetName)
    {
    }

    /**
     * @param  list<float>  $widths
     */
    public function setColumnWidths(array $widths): static
    {
        $this->columnWidths = $widths;

        return $this;
    }

    /** Keeps the first $rows rows visible while scrolling. */
    public function freezeRows(int $rows): static
    {
        $this->frozenRows = $rows;

        return $this;
    }

    /**
     * @param  list<mixed>  $cells
     * @return int The 1-based row number.
     */
    public function addRow(array $cells, int $style = self::STYLE_DEFAULT): int
    {
        $rowNumber = count($this->rows) + 1;
        $this->columnCount = max($this->columnCount, count($cells));

        $xml = '';
        foreach (array_values($cells) as $index => $cell) {
            [$value, $cellStyle] = is_array($cell) ? $cell : [$cell, $style];
            $xml .= $this->cellXml(self::columnLetter($index) . $rowNumber, $value, $cellStyle);
        }

        $this->rows[] = '<row r="' . $rowNumber . '">' . $xml . '</row>';

        return $rowNumber;
    }

    /** Adds Excel filter buttons to this header row, covering every row below it. */
    public function filterFromRow(int $row): static
    {
        $this->filterRow = $row;

        return $this;
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true
            || throw new RuntimeException("Unable to create {$path}.");

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml());
        $zip->close();
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    public static function columnLetter(int $index): string
    {
        $letter = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letter = chr(65 + ($index - 1) % 26) . $letter;
        }

        return $letter;
    }

    private function cellXml(string $reference, mixed $value, int $style): string
    {
        $attributes = 'r="' . $reference . '"' . ($style !== self::STYLE_DEFAULT ? ' s="' . $style . '"' : '');

        if ($value === null || $value === '') {
            return $style !== self::STYLE_DEFAULT ? '<c ' . $attributes . '/>' : '';
        }

        if ($value instanceof DateTimeInterface) {
            // Excel stores dates as days since 1899-12-30, in the time zone of the given date.
            $value = ($value->getTimestamp() + $value->getOffset()) / 86400 + 25569;
        }

        if (is_int($value) || is_float($value)) {
            return '<c ' . $attributes . '><v>' . $value . '</v></c>';
        }

        return '<c ' . $attributes . ' t="inlineStr"><is><t xml:space="preserve">'
            . $this->escape((string) $value) . '</t></is></c>';
    }

    private function escape(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function safeSheetName(): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $this->sheetName));

        return mb_substr($name !== '' ? $name : 'Sheet1', 0, 31);
    }

    private function filterRange(): ?string
    {
        if ($this->filterRow === null || $this->columnCount === 0) {
            return null;
        }

        $lastColumn = self::columnLetter($this->columnCount - 1);

        return '$A$' . $this->filterRow . ':$' . $lastColumn . '$' . max($this->filterRow, count($this->rows));
    }

    private function sheetXml(): string
    {
        $view = '<sheetView workbookViewId="0"/>';
        if ($this->frozenRows > 0) {
            $topLeft = 'A' . ($this->frozenRows + 1);
            $view = '<sheetView workbookViewId="0"><pane ySplit="' . $this->frozenRows . '" topLeftCell="' . $topLeft
                . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="' . $topLeft
                . '" sqref="' . $topLeft . '"/></sheetView>';
        }

        $cols = '';
        foreach ($this->columnWidths as $index => $width) {
            $column = $index + 1;
            $cols .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
        }

        $filterRange = $this->filterRange();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews>' . $view . '</sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . implode('', $this->rows) . '</sheetData>'
            . ($filterRange !== null ? '<autoFilter ref="' . str_replace('$', '', $filterRange) . '"/>' : '')
            . '</worksheet>';
    }

    private function workbookXml(): string
    {
        $sheetName = $this->escape($this->safeSheetName());
        $filterRange = $this->filterRange();
        $definedNames = $filterRange !== null
            ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''
                . str_replace("'", "''", $sheetName) . '\'!' . $filterRange . '</definedName></definedNames>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets>'
            . $definedNames
            . '</workbook>';
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** The cellXfs order matches the STYLE_* constants. */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="mmm d, yyyy"/>'
            . '<numFmt numFmtId="166" formatCode="mmm d, yyyy h:mm AM/PM"/>'
            . '</numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF5B21B6"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF7C3AED"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3E8FF"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
