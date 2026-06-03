<?php

/**
 * Procest Leges Verordening Import Service
 *
 * Imports an annual legesverordening from a decidesk raadsbesluit: fetches the
 * besluit metadata, parses the attached tariff table (CSV or XLSX), validates
 * the rows, computes a diff against the current tariff table, and creates a new
 * legesTariefTabel version (status `concept`) with its legesTarief rows.
 *
 * XLSX is parsed natively (a zipped set of XML parts) without a third-party
 * spreadsheet library; XML is loaded XXE-safe (no external entity loading on
 * PHP 8 + libxml >= 2.9).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-001
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-009
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

/**
 * Imports legesverordeningen from decidesk raadsbesluiten.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class LegesVerordingImportService
{
    /**
     * Allowed VAT rates for a tariff row.
     *
     * @var array<int, int>
     */
    private const ALLOWED_BTW = [0, 9, 21];

    /**
     * Allowed grondslag (calculation basis) values.
     *
     * @var array<int, string>
     */
    private const ALLOWED_GRONDSLAG = ['vast', 'oppervlakte', 'bouwsom', 'staffel', 'formule'];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings + ObjectService access.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Parse a raw tariff table (CSV or XLSX bytes) into tariff rows.
     *
     * @param string $bytes  The raw file bytes.
     * @param string $format Either 'csv' or 'xlsx'.
     *
     * @return array<int, array<string, mixed>> Parsed (unvalidated) tariff rows.
     *
     * @throws RuntimeException When the format is unsupported or unparseable.
     */
    public function parseRawTable(string $bytes, string $format='csv'): array
    {
        return match (strtolower($format)) {
            'csv' => $this->parseCsv(bytes: $bytes),
            'xlsx' => $this->parseXlsx(bytes: $bytes),
            default => throw new RuntimeException('Unsupported tariff table format: '.$format),
        };
    }//end parseRawTable()

    /**
     * Validate parsed tariff rows.
     *
     * @param array<int, array<string, mixed>> $rows The parsed rows.
     *
     * @return array{valid: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function validateTariffs(array $rows): array
    {
        $valid  = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateRow(row: $row, index: $index);
            if ($rowErrors === []) {
                $valid[] = $this->normaliseRow(row: $row);
                continue;
            }

            $errors = array_merge($errors, $rowErrors);
        }

        return ['valid' => $valid, 'errors' => $errors];
    }//end validateTariffs()

    /**
     * Compute a diff of new tariff rows against an existing tariff table.
     *
     * @param array<int, array<string, mixed>> $newRows      The new tariff rows.
     * @param array<int, array<string, mixed>> $existingRows The current tariff rows.
     *
     * @return array{new: array<int, array<string, mixed>>, changed: array<int, array<string, mixed>>, deleted: array<int, array<string, mixed>>}
     */
    public function diff(array $newRows, array $existingRows): array
    {
        $existingByNr = [];
        foreach ($existingRows as $row) {
            $existingByNr[(string) ($row['tariefNummer'] ?? '')] = $row;
        }

        $new     = [];
        $changed = [];
        $seen    = [];

        foreach ($newRows as $row) {
            $nummer        = (string) ($row['tariefNummer'] ?? '');
            $seen[$nummer] = true;
            if (isset($existingByNr[$nummer]) === false) {
                $new[] = $row;
                continue;
            }

            if ((int) ($existingByNr[$nummer]['bedrag'] ?? 0) !== (int) ($row['bedrag'] ?? 0)) {
                $changed[] = $row;
            }
        }

        $deleted = [];
        foreach ($existingByNr as $nummer => $row) {
            if (isset($seen[$nummer]) === false) {
                $deleted[] = $row;
            }
        }

        return ['new' => $new, 'changed' => $changed, 'deleted' => $deleted];
    }//end diff()

    /**
     * Import a verordening: validate rows and create a concept tariff table.
     *
     * @param array<string, mixed>             $metaData Table metadata (naam, geldigVanaf, vastgesteldDoor, vastgesteldOp).
     * @param array<int, array<string, mixed>> $rows     Parsed tariff rows.
     *
     * @return array<string, mixed> Result with the created table id, counts and diff.
     *
     * @throws RuntimeException When OpenRegister is unavailable/unconfigured or validation fails hard.
     */
    public function createTariefTabelVersion(array $metaData, array $rows): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register     = $this->settingsService->getConfigValue('register');
        $tabelSchema  = $this->settingsService->getConfigValue('leges_tarief_tabel_schema');
        $tariefSchema = $this->settingsService->getConfigValue('leges_tarief_schema');
        if ($register === '' || $tabelSchema === '' || $tariefSchema === '') {
            throw new RuntimeException('Leges schemas are not configured');
        }

        if (empty($metaData['naam']) === true || empty($metaData['geldigVanaf']) === true) {
            throw new RuntimeException('Verordening naam and geldigVanaf are required');
        }

        $validation = $this->validateTariffs(rows: $rows);
        if ($validation['valid'] === []) {
            throw new RuntimeException('No valid tariff rows: '.implode('; ', $validation['errors']));
        }

        $existing = $this->loadCurrentTariffRows(
            objectService: $objectService,
            register: $register,
            tariefSchema: $tariefSchema
        );
        $diff     = $this->diff(newRows: $validation['valid'], existingRows: $existing);

        $tabelPayload = [
            'naam'            => (string) $metaData['naam'],
            'geldigVanaf'     => (string) $metaData['geldigVanaf'],
            'geldigTotEnMet'  => ($metaData['geldigTotEnMet'] ?? null),
            'vastgesteldDoor' => (string) ($metaData['vastgesteldDoor'] ?? ''),
            'vastgesteldOp'   => ($metaData['vastgesteldOp'] ?? null),
            'status'          => 'concept',
        ];

        $tabel   = $objectService->saveObject($register, $tabelSchema, $tabelPayload);
        $tabelId = $this->extractId(result: $tabel);

        $created = 0;
        foreach ($validation['valid'] as $tariefRow) {
            $tariefRow['tariefTabelId'] = $tabelId;
            $objectService->saveObject($register, $tariefSchema, $tariefRow);
            $created++;
        }

        $this->logger->info(
            'Procest leges: imported verordening "'.$metaData['naam'].'" as concept',
            ['tabelId' => $tabelId, 'tarieven' => $created]
        );

        return [
            'tariefTabelId' => $tabelId,
            'status'        => 'concept',
            'tarieven'      => $created,
            'errors'        => $validation['errors'],
            'diff'          => [
                'new'     => count($diff['new']),
                'changed' => count($diff['changed']),
                'deleted' => count($diff['deleted']),
            ],
        ];
    }//end createTariefTabelVersion()

    /**
     * Validate a single tariff row, returning a list of error strings.
     *
     * @param array<string, mixed> $row   The row.
     * @param int                  $index The zero-based row index.
     *
     * @return array<int, string>
     */
    private function validateRow(array $row, int $index): array
    {
        $errors = [];
        $line   = ($index + 1);

        foreach (['tariefNummer', 'omschrijving', 'grondslag', 'eenheid'] as $field) {
            if (empty($row[$field]) === true) {
                $errors[] = 'Rij '.$line.': veld "'.$field.'" ontbreekt';
            }
        }

        if (isset($row['bedrag']) === false || is_numeric($row['bedrag']) === false) {
            $errors[] = 'Rij '.$line.': bedrag is geen getal';
        }

        $btw = (int) ($row['btwTarief'] ?? -1);
        if (in_array($btw, self::ALLOWED_BTW, true) === false) {
            $errors[] = 'Rij '.$line.': BTW-tarief moet 0, 9 of 21 zijn';
        }

        $grondslag = (string) ($row['grondslag'] ?? '');
        if ($grondslag !== '' && in_array($grondslag, self::ALLOWED_GRONDSLAG, true) === false) {
            $errors[] = 'Rij '.$line.': onbekende grondslag "'.$grondslag.'"';
        }

        if (empty($row['grootboekrekening']) === true) {
            $errors[] = 'Rij '.$line.': grootboekrekening ontbreekt';
        }

        return $errors;
    }//end validateRow()

    /**
     * Normalise a validated row to the legesTarief shape (bedrag as int cents).
     *
     * @param array<string, mixed> $row The validated row.
     *
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        $percentage = null;
        if (isset($row['percentage']) === true && $row['percentage'] !== '') {
            $percentage = (float) $row['percentage'];
        }

        return [
            'tariefNummer'      => (string) $row['tariefNummer'],
            'omschrijving'      => (string) $row['omschrijving'],
            'bedrag'            => (int) round((float) $row['bedrag']),
            'grondslag'         => (string) $row['grondslag'],
            'eenheid'           => (string) $row['eenheid'],
            'grondslagVeld'     => (string) ($row['grondslagVeld'] ?? ''),
            'percentage'        => $percentage,
            'btwTarief'         => (int) $row['btwTarief'],
            'grootboekrekening' => (string) $row['grootboekrekening'],
            'kostendrager'      => (string) ($row['kostendrager'] ?? ''),
            'productCode'       => (string) ($row['productCode'] ?? ''),
            'zaaktype'          => (string) ($row['zaaktype'] ?? ''),
        ];
    }//end normaliseRow()

    /**
     * Parse a CSV tariff table into rows keyed by header column.
     *
     * @param string $bytes The CSV bytes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $bytes): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($bytes));
        if ($lines === false || count($lines) < 2) {
            return [];
        }

        $delimiter = ',';
        if (str_contains($lines[0], ';') === true) {
            $delimiter = ';';
        }

        $header = str_getcsv($lines[0], $delimiter);
        $header = array_map(static fn ($col): string => trim((string) $col), $header);

        $rows  = [];
        $count = count($lines);
        for ($i = 1; $i < $count; $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }

            $cols = str_getcsv($lines[$i], $delimiter);
            $row  = [];
            foreach ($header as $idx => $name) {
                $row[$name] = ($cols[$idx] ?? '');
            }

            $rows[] = $row;
        }

        return $rows;
    }//end parseCsv()

    /**
     * Parse an XLSX tariff table (first worksheet) into rows keyed by header.
     *
     * @param string $bytes The XLSX bytes.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException When the archive cannot be read.
     */
    private function parseXlsx(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'leges_xlsx_');
        if ($tmp === false) {
            throw new RuntimeException('Could not allocate a temp file for XLSX parsing');
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('XLSX is not a valid archive');
            }

            $shared = $this->readSharedStrings(zip: $zip);
            $sheet  = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            if ($sheet === false) {
                throw new RuntimeException('XLSX has no first worksheet');
            }

            return $this->rowsFromSheetXml(sheetXml: $sheet, shared: $shared);
        } finally {
            if (file_exists($tmp) === true) {
                unlink($tmp);
            }
        }//end try
    }//end parseXlsx()

    /**
     * Read the shared strings table from an open XLSX archive.
     *
     * @param ZipArchive $zip The open archive.
     *
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = $this->safeXml(xml: $xml);
        if ($doc === null) {
            return [];
        }

        $strings = [];
        foreach ($doc->si as $si) {
            $strings[] = (string) $si->t;
        }

        return $strings;
    }//end readSharedStrings()

    /**
     * Convert a worksheet XML into header-keyed rows.
     *
     * @param string             $sheetXml The worksheet XML.
     * @param array<int, string> $shared   The shared strings table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromSheetXml(string $sheetXml, array $shared): array
    {
        $doc = $this->safeXml(xml: $sheetXml);
        if ($doc === null || isset($doc->sheetData) === false) {
            return [];
        }

        $matrix = [];
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref    = (string) $c['r'];
                $colIdx = $this->columnIndex(ref: $ref);
                $value  = (string) $c->v;
                if ((string) $c['t'] === 's') {
                    $value = ($shared[(int) $value] ?? '');
                }

                $cells[$colIdx] = $value;
            }

            $matrix[] = $cells;
        }

        if (count($matrix) < 2) {
            return [];
        }

        ksort($matrix[0]);
        $header = array_values(array_map(static fn ($col): string => trim((string) $col), $matrix[0]));

        $rows  = [];
        $count = count($matrix);
        for ($i = 1; $i < $count; $i++) {
            ksort($matrix[$i]);
            $cells = array_values($matrix[$i]);
            $row   = [];
            foreach ($header as $idx => $name) {
                $row[$name] = ($cells[$idx] ?? '');
            }

            $rows[] = $row;
        }

        return $rows;
    }//end rowsFromSheetXml()

    /**
     * Convert an A1-style cell reference to a zero-based column index.
     *
     * @param string $ref The cell reference (e.g. "B3").
     *
     * @return int
     */
    private function columnIndex(string $ref): int
    {
        $letters = preg_replace('/[0-9]/', '', $ref) ?? '';
        $index   = 0;
        $length  = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = (($index * 26) + (ord($letters[$i]) - 64));
        }

        return max(0, ($index - 1));
    }//end columnIndex()

    /**
     * Load XML XXE-safe.
     *
     * `LIBXML_NONET` forbids network access; on PHP 8 with libxml >= 2.9 external
     * entities are not loaded by default, and `LIBXML_NOENT` is deliberately NOT
     * passed (it would enable entity substitution). No deprecated
     * libxml_disable_entity_loader() call is needed.
     *
     * @param string $xml The XML string.
     *
     * @return SimpleXMLElement|null
     */
    private function safeXml(string $xml): ?SimpleXMLElement
    {
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

        if ($doc === false) {
            return null;
        }

        return $doc;
    }//end safeXml()

    /**
     * Load the current tariff rows to diff a new import against.
     *
     * @param object $objectService OpenRegister ObjectService.
     * @param string $register      Register id.
     * @param string $tariefSchema  Tariff schema id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadCurrentTariffRows(object $objectService, string $register, string $tariefSchema): array
    {
        try {
            $records = $objectService->findAll($register, $tariefSchema, ['filters' => []]);
        } catch (Throwable $e) {
            $this->logger->warning('Procest leges: could not load existing tariffs: '.$e->getMessage());
            return [];
        }

        $rows = [];
        foreach ((array) $records as $record) {
            $row = $this->toArray(value: $record);
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }//end loadCurrentTariffRows()

    /**
     * Normalise an OR record to an array.
     *
     * @param mixed $value The record.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return [];
    }//end toArray()

    /**
     * Extract the id/uuid from a saved OR object.
     *
     * @param mixed $result The save result.
     *
     * @return string
     */
    private function extractId(mixed $result): string
    {
        if (is_object($result) === true && method_exists($result, 'getUuid') === true) {
            return (string) $result->getUuid();
        }

        $row = $this->toArray(value: $result);
        return (string) ($row['id'] ?? ($row['uuid'] ?? ''));
    }//end extractId()
}//end class
