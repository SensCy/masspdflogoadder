<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_clientspreadsheet\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates uploaded CSV and XLSX spreadsheets.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validator {

    /** @var int Maximum row value errors to list individually. */
    private const MAX_VALUE_ERRORS = 10;

    /**
     * Validates an uploaded spreadsheet.
     *
     * @param \stored_file $file Uploaded draft file.
     * @param string[] $requiredcolumns Required header names.
     * @return \stdClass Validation result.
     */
    public function validate(\stored_file $file, array $requiredcolumns): \stdClass {
        $result = (object) [
            'valid' => false,
            'errors' => [],
            'headers' => [],
            'rowcount' => 0,
        ];

        $extension = \core_text::strtolower(pathinfo($file->get_filename(), \PATHINFO_EXTENSION));

        try {
            if ($extension === 'csv') {
                $rows = $this->read_csv($file);
            } else if ($extension === 'xlsx') {
                $rows = $this->read_xlsx($file);
            } else {
                $result->errors[] = get_string('unsupportedfiletype', 'clientspreadsheet');
                return $result;
            }
        } catch (\Throwable $exception) {
            $result->errors[] = get_string('filereaderror', 'clientspreadsheet', $exception->getMessage());
            return $result;
        }

        if (empty($rows)) {
            $result->errors[] = get_string('emptyspreadsheet', 'clientspreadsheet');
            return $result;
        }

        $headers = array_map([$this, 'clean_cell'], $rows[0]);
        $headers = $this->trim_trailing_empty_cells($headers);
        $result->headers = $headers;

        if (empty($headers)) {
            $result->errors[] = get_string('missingheaderrow', 'clientspreadsheet');
            return $result;
        }

        $normalisedheaders = [];
        $duplicates = [];
        foreach ($headers as $header) {
            $normalised = $this->normalise_header($header);
            if ($normalised === '') {
                continue;
            }

            if (isset($normalisedheaders[$normalised])) {
                $duplicates[] = $header;
            }
            $normalisedheaders[$normalised] = $header;
        }

        if (!empty($duplicates)) {
            $result->errors[] = get_string('duplicateheaders', 'clientspreadsheet', implode(', ', array_unique($duplicates)));
        }

        $missing = [];
        foreach ($requiredcolumns as $required) {
            if (!isset($normalisedheaders[$this->normalise_header($required)])) {
                $missing[] = $required;
            }
        }

        if (!empty($missing)) {
            $result->errors[] = get_string('missingrequiredcolumns', 'clientspreadsheet', implode(', ', $missing));
        }

        $datarows = array_slice($rows, 1);
        $datarows = array_values(array_filter($datarows, [$this, 'row_has_value']));
        $result->rowcount = count($datarows);

        if (empty($datarows)) {
            $result->errors[] = get_string('nodatarows', 'clientspreadsheet');
        }

        if (empty($missing)) {
            $valueerrors = $this->find_required_value_errors($headers, $datarows, $requiredcolumns);
            foreach ($valueerrors as $valueerror) {
                $result->errors[] = $valueerror;
            }
        }

        $result->valid = empty($result->errors);

        return $result;
    }

    /**
     * Reads a CSV file.
     *
     * @param \stored_file $file Uploaded file.
     * @return array
     */
    private function read_csv(\stored_file $file): array {
        $content = $file->get_content();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map([$this, 'clean_cell'], $row);
            if (empty($rows) && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }

            if ($this->row_has_value($row)) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Reads the first worksheet in an XLSX file.
     *
     * @param \stored_file $file Uploaded file.
     * @return array
     */
    private function read_xlsx(\stored_file $file): array {
        if (!class_exists('\ZipArchive')) {
            throw new \moodle_exception('ziparchivemissing', 'clientspreadsheet');
        }

        $tempdir = make_request_directory();
        $temppath = $tempdir . '/' . md5($file->get_contenthash() . $file->get_filename()) . '.xlsx';
        $file->copy_content_to($temppath);

        $zip = new \ZipArchive();
        if ($zip->open($temppath) !== true) {
            throw new \moodle_exception('xlsxopenfailed', 'clientspreadsheet');
        }

        $sharedstrings = $this->read_shared_strings($zip);
        $sheetpath = $this->get_first_sheet_path($zip);
        $sheetxml = $zip->getFromName($sheetpath);
        $zip->close();

        if ($sheetxml === false) {
            throw new \moodle_exception('worksheetmissing', 'clientspreadsheet');
        }

        $sheet = $this->load_xml($sheetxml);
        $rows = [];
        $rownodes = $sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');

        foreach ($rownodes as $rownode) {
            $row = [];
            $fallbackindex = 0;
            $cellnodes = $rownode->xpath('./*[local-name()="c"]');

            foreach ($cellnodes as $cellnode) {
                $reference = (string) $cellnode['r'];
                $index = $this->column_index_from_reference($reference);
                if ($index === null) {
                    $index = $fallbackindex;
                }
                $fallbackindex = $index + 1;

                $row[$index] = $this->read_xlsx_cell($cellnode, $sharedstrings);
            }

            if (!empty($row)) {
                ksort($row);
                $row = $this->fill_missing_cells($row);
            }

            if ($this->row_has_value($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Reads shared strings from an XLSX package.
     *
     * @param \ZipArchive $zip Open zip archive.
     * @return string[]
     */
    private function read_shared_strings(\ZipArchive $zip): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $sst = $this->load_xml($xml);
        $strings = [];
        $items = $sst->xpath('//*[local-name()="si"]');

        foreach ($items as $item) {
            $strings[] = $this->collect_text($item);
        }

        return $strings;
    }

    /**
     * Locates the first worksheet path.
     *
     * @param \ZipArchive $zip Open zip archive.
     * @return string
     */
    private function get_first_sheet_path(\ZipArchive $zip): string {
        $workbookxml = $zip->getFromName('xl/workbook.xml');
        $relsxml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookxml === false || $relsxml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = $this->load_xml($workbookxml);
        $sheets = $workbook->xpath('//*[local-name()="sheet"]');
        if (empty($sheets)) {
            return 'xl/worksheets/sheet1.xml';
        }

        $attributes = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipid = (string) $attributes['id'];
        if ($relationshipid === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        $rels = $this->load_xml($relsxml);
        $relationships = $rels->xpath('//*[local-name()="Relationship"]');

        foreach ($relationships as $relationship) {
            if ((string) $relationship['Id'] === $relationshipid) {
                return $this->normalise_zip_path((string) $relationship['Target']);
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * Reads an XLSX cell value.
     *
     * @param \SimpleXMLElement $cell Cell node.
     * @param string[] $sharedstrings Shared string table.
     * @return string
     */
    private function read_xlsx_cell(\SimpleXMLElement $cell, array $sharedstrings): string {
        $type = (string) $cell['t'];
        $valuenodes = $cell->xpath('./*[local-name()="v"]');

        if ($type === 's') {
            if (empty($valuenodes)) {
                return '';
            }

            $index = (int) (string) $valuenodes[0];
            return $this->clean_cell($sharedstrings[$index] ?? '');
        }

        if ($type === 'inlineStr') {
            $inline = $cell->xpath('./*[local-name()="is"]');
            return !empty($inline) ? $this->clean_cell($this->collect_text($inline[0])) : '';
        }

        return !empty($valuenodes) ? $this->clean_cell((string) $valuenodes[0]) : '';
    }

    /**
     * Loads XML safely.
     *
     * @param string $xml XML content.
     * @return \SimpleXMLElement
     */
    private function load_xml(string $xml): \SimpleXMLElement {
        $loaded = simplexml_load_string($xml, '\SimpleXMLElement', \LIBXML_NONET);

        if (!$loaded) {
            throw new \moodle_exception('invalidxml', 'clientspreadsheet');
        }

        return $loaded;
    }

    /**
     * Collects text nodes from a rich text XLSX node.
     *
     * @param \SimpleXMLElement $node XML node.
     * @return string
     */
    private function collect_text(\SimpleXMLElement $node): string {
        $texts = $node->xpath('.//*[local-name()="t"]');
        $value = '';

        foreach ($texts as $text) {
            $value .= (string) $text;
        }

        return $value;
    }

    /**
     * Converts an XLSX cell reference into a zero-based column index.
     *
     * @param string $reference Cell reference such as A1.
     * @return int|null
     */
    private function column_index_from_reference(string $reference): ?int {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return null;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    /**
     * Fills gaps in a sparse row array.
     *
     * @param array $row Sparse row keyed by column index.
     * @return array Dense row.
     */
    private function fill_missing_cells(array $row): array {
        if (empty($row)) {
            return [];
        }

        $filled = [];
        $max = max(array_keys($row));

        for ($i = 0; $i <= $max; $i++) {
            $filled[] = $row[$i] ?? '';
        }

        return $filled;
    }

    /**
     * Normalises a relationship target into an XLSX zip path.
     *
     * @param string $target Relationship target.
     * @return string
     */
    private function normalise_zip_path(string $target): string {
        $target = str_replace('\\', '/', $target);

        if (strpos($target, '/') === 0) {
            $path = ltrim($target, '/');
        } else if (strpos($target, 'xl/') === 0) {
            $path = $target;
        } else {
            $path = 'xl/' . $target;
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * Finds missing required values in data rows.
     *
     * @param string[] $headers Header row.
     * @param array $datarows Data rows.
     * @param string[] $requiredcolumns Required columns.
     * @return string[]
     */
    private function find_required_value_errors(array $headers, array $datarows, array $requiredcolumns): array {
        $positions = [];
        foreach ($headers as $index => $header) {
            $positions[$this->normalise_header($header)] = $index;
        }

        $errors = [];
        foreach ($datarows as $rowindex => $row) {
            foreach ($requiredcolumns as $required) {
                $normalised = $this->normalise_header($required);
                if (!isset($positions[$normalised])) {
                    continue;
                }

                $value = $row[$positions[$normalised]] ?? '';
                if (trim((string) $value) === '') {
                    $errors[] = get_string('requiredvalueempty', 'clientspreadsheet', (object) [
                        'row' => $rowindex + 2,
                        'column' => $required,
                    ]);
                    if (count($errors) >= self::MAX_VALUE_ERRORS) {
                        $errors[] = get_string('additionalvalueerrors', 'clientspreadsheet');
                        return $errors;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Cleans a spreadsheet cell value.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function clean_cell($value): string {
        return trim((string) $value);
    }

    /**
     * Normalises a header for comparisons.
     *
     * @param string $header Header text.
     * @return string
     */
    private function normalise_header(string $header): string {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = \core_text::strtolower(trim($header));

        return preg_replace('/[^a-z0-9]+/', '', $header);
    }

    /**
     * Removes empty cells from the end of a row.
     *
     * @param string[] $row Row values.
     * @return string[]
     */
    private function trim_trailing_empty_cells(array $row): array {
        while (!empty($row) && trim((string) end($row)) === '') {
            array_pop($row);
        }

        return array_values($row);
    }

    /**
     * Checks whether a row contains at least one value.
     *
     * @param array $row Row values.
     * @return bool
     */
    private function row_has_value(array $row): bool {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
}
