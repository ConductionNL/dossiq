<?php

/**
 * Procest MandaatImportService.
 *
 * Imports a MandateringsBesluit + its Mandaten from a CSV payload
 * (header: mandaatNummer,omschrijving,rolNaam,plafondCents,subdelegatie,
 * wettelijkeGrondslag,decisionTypes). Resolves rolNaam to an
 * OrganisatieRol id; missing roles abort the import with a clear
 * error. Returns a diff vs the prior version of the same besluit.
 *
 * Excel (.xlsx) parsing is intentionally out of scope (PhpSpreadsheet
 * would pull in 4 MB of vendor code); the import controller may pre-
 * convert .xlsx to .csv via openconnector before posting.
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
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * CSV import of a MandateringsBesluit from a Decidesk export.
 */
class MandaatImportService
{
    use SearchesObjects;

    public const REQUIRED_COLUMNS = ['mandaatNummer', 'omschrijving', 'rolNaam', 'plafondCents'];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Import a MandateringsBesluit from CSV text.
     *
     * @param string $besluitNummer Besluit identifier.
     * @param string $besluitNaam   Besluit name.
     * @param string $decideskUuid  Source Decidesk besluit id.
     * @param string $csvContents   The CSV payload (RFC 4180; first row is header).
     *
     * @return array<string, mixed> {mandateringsBesluitId, totalMandaten, newCount, changedCount, removedCount, diff}
     *
     * @throws RuntimeException When the CSV is malformed or a rol cannot be resolved.
     *
     * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
     */
    public function importFromCsv(
        string $besluitNummer,
        string $besluitNaam,
        string $decideskUuid,
        string $csvContents
    ): array {
        $rows = $this->parseCsv(csv: $csvContents);
        if (count($rows) === 0) {
            throw new RuntimeException('CSV is empty or missing data rows');
        }

        // Resolve rol-name → rolId.
        $roleIndex = $this->loadRoleIndex();
        $resolved  = [];
        foreach ($rows as $idx => $row) {
            $rolNaam = (string) ($row['rolNaam'] ?? '');
            if ($rolNaam === '') {
                throw new RuntimeException('Row '.($idx + 1).' missing rolNaam');
            }

            if (isset($roleIndex[$rolNaam]) === false) {
                throw new RuntimeException('Unknown OrganisatieRol "'.$rolNaam.'" at row '.($idx + 1));
            }

            $resolved[] = $row + ['gemandateerdeRol' => $roleIndex[$rolNaam]];
        }

        // Create the besluit (concept).
        $besluit = $this->save(
            schemaConfigKey: 'mandaterings_besluit_schema',
            object: [
                'besluitNummer' => $besluitNummer,
                'besluitNaam'   => $besluitNaam,
                'status'        => 'concept',
                'decideskUuid'  => $decideskUuid,
            ]
        );

        // Find the prior besluit version (by besluitNummer) for diff.
        $prior         = $this->findPriorBesluit(besluitNummer: $besluitNummer);
        $priorMandaten = [];
        if ($prior !== null) {
            $priorMandaten = $this->findMandatenForBesluit(besluitId: (string) ($prior['id'] ?? ''));
        }

        // Create one mandaat per CSV row.
        $newCount       = 0;
        $changedCount   = 0;
        $unchangedCount = 0;
        $diff           = [];
        foreach ($resolved as $row) {
            $payload = [
                'mandaatNummer'       => (string) $row['mandaatNummer'],
                'mandateringsBesluit' => (string) $besluit['id'],
                'omschrijving'        => (string) ($row['omschrijving'] ?? ''),
                'gemandateerdeRol'    => (string) $row['gemandateerdeRol'],
                'wettelijkeGrondslag' => (string) ($row['wettelijkeGrondslag'] ?? ''),
                'voorwaarden'         => [
                    'plafondCents'  => (int) ($row['plafondCents'] ?? 0),
                    'subdelegatie'  => $this->parseBool(value: (string) ($row['subdelegatie'] ?? 'false')),
                    'decisionTypes' => $this->parseList(value: (string) ($row['decisionTypes'] ?? '')),
                ],
                'status'              => 'concept',
            ];

            $this->save(schemaConfigKey: 'mandaat_schema', object: $payload);

            $existing = null;
            foreach ($priorMandaten as $pm) {
                if ((string) ($pm['mandaatNummer'] ?? '') === (string) $row['mandaatNummer']) {
                    $existing = $pm;
                    break;
                }
            }

            if ($existing === null) {
                $newCount++;
                $diff[] = ['mandaatNummer' => (string) $row['mandaatNummer'], 'change' => 'NEW'];
                continue;
            }

            $changed       = false;
            $changedFields = [];
            foreach (['omschrijving', 'gemandateerdeRol', 'wettelijkeGrondslag'] as $f) {
                if ((string) ($existing[$f] ?? '') !== (string) $payload[$f]) {
                    $changed         = true;
                    $changedFields[] = $f;
                }
            }

            $exPlafond = (int) (($existing['voorwaarden'] ?? [])['plafondCents'] ?? 0);
            if ($exPlafond !== (int) $payload['voorwaarden']['plafondCents']) {
                $changed         = true;
                $changedFields[] = 'plafondCents';
            }

            if ($changed === true) {
                $changedCount++;
                $diff[] = [
                    'mandaatNummer' => (string) $row['mandaatNummer'],
                    'change'        => 'CHANGED',
                    'fields'        => $changedFields,
                ];
                continue;
            }

            $unchangedCount++;
            $diff[] = ['mandaatNummer' => (string) $row['mandaatNummer'], 'change' => 'UNCHANGED'];
        }//end foreach

        // REMOVED = in prior, not in new.
        $newNumbers   = array_map(static fn (array $r): string => (string) ($r['mandaatNummer'] ?? ''), $resolved);
        $removedCount = 0;
        foreach ($priorMandaten as $pm) {
            $num = (string) ($pm['mandaatNummer'] ?? '');
            if ($num !== '' && in_array($num, $newNumbers, true) === false) {
                $removedCount++;
                $diff[] = ['mandaatNummer' => $num, 'change' => 'REMOVED'];
            }
        }

        return [
            'mandateringsBesluitId' => (string) $besluit['id'],
            'totalMandaten'         => count($resolved),
            'newCount'              => $newCount,
            'changedCount'          => $changedCount,
            'removedCount'          => $removedCount,
            'unchangedCount'        => $unchangedCount,
            'diff'                  => $diff,
        ];
    }//end importFromCsv()

    /**
     * Approve a concept besluit: flip besluit → vastgesteld + every mandaat → active,
     * and mark the prior besluit (if any) → vervallen.
     *
     * @param string $besluitId Besluit id.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
     */
    public function approveImport(string $besluitId): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $bSchema       = (string) $this->settingsService->getConfigValue('mandaterings_besluit_schema');
        $mSchema       = (string) $this->settingsService->getConfigValue('mandaat_schema');
        if ($objectService === null || $register === '' || $bSchema === '' || $mSchema === '') {
            throw new RuntimeException('Mandaat services not configured');
        }

        $besluit = $objectService->find($besluitId, register: $register, schema: $bSchema);
        if (is_array($besluit) === false) {
            throw new RuntimeException('Besluit not found: '.$besluitId);
        }

        if (($besluit['status'] ?? '') !== 'concept') {
            throw new RuntimeException('Besluit is not in concept status');
        }

        $now = (new DateTimeImmutable())->format('Y-m-d');
        $besluit['status']           = 'vastgesteld';
        $besluit['inWerkingtreding'] = ($besluit['inWerkingtreding'] ?? $now);
        $besluit = $objectService->saveObject($register, $bSchema, $besluit);

        // Flip mandaten to active.
        try {
            $mandaten = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $mSchema,
                filters: ['mandateringsBesluit' => $besluitId]
            );
        } catch (\Throwable $e) {
            $mandaten = [];
        }

        foreach ($mandaten as $m) {
            $m['status'] = 'active';
            if (isset($m['validFrom']) === false || $m['validFrom'] === '') {
                $m['validFrom'] = $now;
            }

            $objectService->saveObject($register, $mSchema, $m);
        }

        // Expire prior besluit.
        $prior = $this->findPriorBesluit(besluitNummer: (string) $besluit['besluitNummer'], excludeId: $besluitId);
        if ($prior !== null) {
            $prior['status']      = 'vervallen';
            $prior['vervalDatum'] = $now;
            $objectService->saveObject($register, $bSchema, $prior);
        }

        return $besluit;
    }//end approveImport()

    /**
     * Parse RFC-4180-ish CSV with first row = header.
     *
     * @param string $csv CSV.
     *
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if ($lines === false || count($lines) < 2) {
            return [];
        }

        $header  = str_getcsv($lines[0]);
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if (count($missing) > 0) {
            throw new RuntimeException('Missing required CSV columns: '.implode(', ', $missing));
        }

        $rows      = [];
        $lineCount = count($lines);
        for ($i = 1; $i < $lineCount; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line);
            $rows[] = array_combine($header, array_pad($values, count($header), ''));
        }

        return $rows;
    }//end parseCsv()

    /**
     * Build a rolNaam to rolId index from OrganisatieRol objects.
     *
     * @return array<string, string> rolNaam → rolId
     */
    private function loadRoleIndex(): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('organisatie_rol_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $rolNaam = (string) ($row['rolNaam'] ?? '');
            if ($rolNaam !== '') {
                $out[$rolNaam] = (string) ($row['id'] ?? '');
            }
        }

        return $out;
    }//end loadRoleIndex()

    /**
     * Find the prior vastgesteld besluit for a besluit number.
     *
     * @param string      $besluitNummer Number.
     * @param string|null $excludeId     Optional id to exclude.
     *
     * @return array<string, mixed>|null
     */
    private function findPriorBesluit(string $besluitNummer, ?string $excludeId=null): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('mandaterings_besluit_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['besluitNummer' => $besluitNummer]
            );
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($rows as $row) {
            if ($excludeId !== null && (string) ($row['id'] ?? '') === $excludeId) {
                continue;
            }

            if (($row['status'] ?? '') === 'vastgesteld') {
                return $row;
            }
        }

        return null;
    }//end findPriorBesluit()

    /**
     * Find the mandaten linked to a besluit.
     *
     * @param string $besluitId Besluit id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findMandatenForBesluit(string $besluitId): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('mandaat_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            return (array) $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['mandateringsBesluit' => $besluitId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }//end findMandatenForBesluit()

    /**
     * Persist a single object for the configured schema.
     *
     * @param string               $schemaConfigKey Config key.
     * @param array<string, mixed> $object          Payload.
     *
     * @return array<string, mixed>
     */
    private function save(string $schemaConfigKey, array $object): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue($schemaConfigKey);
        if ($objectService === null || $register === '' || $schema === '') {
            return $object;
        }

        try {
            $saved = $objectService->saveObject($register, $schema, $object);
            if (is_array($saved) === true) {
                return $saved;
            }

            return $object;
        } catch (\Throwable $e) {
            $this->logger->error('Mandaat import persist failed', ['key' => $schemaConfigKey, 'error' => $e->getMessage()]);
            return $object;
        }
    }//end save()

    /**
     * Parse a boolean from CSV text.
     *
     * @param string $value Boolean text.
     *
     * @return bool
     */
    private function parseBool(string $value): bool
    {
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'true', 'ja', 'yes', 'y'], true);
    }//end parseBool()

    /**
     * Parse a semicolon-separated list from CSV text.
     *
     * @param string $value Comma-separated list.
     *
     * @return array<int, string>
     */
    private function parseList(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(';', $value))));
    }//end parseList()
}//end class
