<?php

/**
 * Procest Leges Verordening Service
 *
 * Read and lifecycle operations on leges tariff tables: list all tables,
 * edit tariff amounts while a table is in `concept`, and approve a concept
 * table (status `concept` -> `vastgesteld`), automatically closing the prior
 * vastgestelde table when their periods overlap (REQ-LEGES-001-B).
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

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lifecycle service for leges tariff tables.
 */
class LegesVerordeningService
{
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
     * List all tariff tables.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTariefTabellen(): array
    {
        [$objectService, $register] = $this->context();
        $schema = $this->settingsService->getConfigValue('leges_tarief_tabel_schema');

        return $this->findAllRows(objectService: $objectService, register: $register, schema: $schema, filters: []);
    }//end listTariefTabellen()

    /**
     * Update tariff amounts on a concept tariff table.
     *
     * @param string                           $tariefTabelId The tariff table UUID.
     * @param array<int, array<string, mixed>> $tarieven      Rows {id, bedrag}.
     *
     * @return array{updated: int}
     *
     * @throws RuntimeException When the table is not in concept.
     */
    public function updateConceptTarieven(string $tariefTabelId, array $tarieven): array
    {
        [$objectService, $register] = $this->context();
        $tabel = $this->loadTabel(objectService: $objectService, register: $register, id: $tariefTabelId);

        if ((string) ($tabel['status'] ?? '') !== 'concept') {
            throw new RuntimeException('Alleen concept-verordeningen kunnen worden gewijzigd');
        }

        $tariefSchema = $this->settingsService->getConfigValue('leges_tarief_schema');
        $updated      = 0;
        foreach ($tarieven as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($row['bedrag']) === false) {
                continue;
            }

            try {
                $current           = $this->toArray(value: $objectService->find($id, register: $register, schema: $tariefSchema));
                $current['bedrag'] = (int) round((float) $row['bedrag']);
                $objectService->saveObject($register, $tariefSchema, $current, $id);
                $updated++;
            } catch (\Throwable $e) {
                $this->logger->warning('Procest leges: could not update tarief '.$id.': '.$e->getMessage());
            }
        }

        return ['updated' => $updated];
    }//end updateConceptTarieven()

    /**
     * Approve a concept tariff table.
     *
     * @param string $tariefTabelId The tariff table UUID.
     *
     * @return array{tariefTabelId: string, status: string, closedPrevious: string|null}
     *
     * @throws RuntimeException When the table is not in concept.
     */
    public function approve(string $tariefTabelId): array
    {
        [$objectService, $register] = $this->context();
        $schema = $this->settingsService->getConfigValue('leges_tarief_tabel_schema');
        $tabel  = $this->loadTabel(objectService: $objectService, register: $register, id: $tariefTabelId);

        if ((string) ($tabel['status'] ?? '') !== 'concept') {
            throw new RuntimeException('Alleen concept-verordeningen kunnen worden vastgesteld');
        }

        $closedPrevious = $this->closeOverlappingTable(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            geldigVanaf: (string) ($tabel['geldigVanaf'] ?? '')
        );

        $tabel['status'] = 'vastgesteld';
        $objectService->saveObject($register, $schema, $tabel, $tariefTabelId);

        $this->logger->info('Procest leges: verordening vastgesteld', ['tariefTabelId' => $tariefTabelId]);

        return [
            'tariefTabelId'  => $tariefTabelId,
            'status'         => 'vastgesteld',
            'closedPrevious' => $closedPrevious,
        ];
    }//end approve()

    /**
     * Close the prior vastgestelde table by setting its geldigTotEnMet to the
     * day before the new table takes effect (REQ-LEGES-001-B).
     *
     * @param object $objectService OpenRegister ObjectService.
     * @param string $register      Register id.
     * @param string $schema        Tariff table schema id.
     * @param string $geldigVanaf   The new table effective date.
     *
     * @return string|null The id of the table that was closed, or null.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function closeOverlappingTable(object $objectService, string $register, string $schema, string $geldigVanaf): ?string
    {
        if ($geldigVanaf === '') {
            return null;
        }

        $rows = $this->findAllRows(objectService: $objectService, register: $register, schema: $schema, filters: ['status' => 'vastgesteld']);

        $previous = null;
        foreach ($rows as $row) {
            $rowVanaf = (string) ($row['geldigVanaf'] ?? '');
            if ($rowVanaf >= $geldigVanaf) {
                continue;
            }

            $rowTot = (string) ($row['geldigTotEnMet'] ?? '');
            if ($rowTot !== '' && $rowTot < $geldigVanaf) {
                continue;
            }

            if ($previous === null || $rowVanaf > (string) ($previous['geldigVanaf'] ?? '')) {
                $previous = $row;
            }
        }

        if ($previous === null) {
            return null;
        }

        try {
            $previous['geldigTotEnMet'] = (new DateTimeImmutable($geldigVanaf))->modify('-1 day')->format('Y-m-d');
            $previousId = (string) ($previous['id'] ?? '');
            $objectService->saveObject($register, $schema, $previous, $previousId);
            return $previousId;
        } catch (\Throwable $e) {
            $this->logger->warning('Procest leges: could not close previous table: '.$e->getMessage());
            return null;
        }
    }//end closeOverlappingTable()

    /**
     * Resolve the ObjectService + register, asserting configuration.
     *
     * @return array{0: object, 1: string}
     *
     * @throws RuntimeException When unavailable or unconfigured.
     */
    private function context(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        if ($register === '' || $this->settingsService->getConfigValue('leges_tarief_tabel_schema') === '') {
            throw new RuntimeException('Leges schemas are not configured');
        }

        return [$objectService, $register];
    }//end context()

    /**
     * Load a tariff table by id.
     *
     * @param object $objectService OpenRegister ObjectService.
     * @param string $register      Register id.
     * @param string $id            Table UUID.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When not found.
     */
    private function loadTabel(object $objectService, string $register, string $id): array
    {
        $schema = $this->settingsService->getConfigValue('leges_tarief_tabel_schema');

        try {
            $obj = $objectService->find($id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            throw new RuntimeException('Verordening niet gevonden: '.$id, 0, $e);
        }

        $row = $this->toArray(value: $obj);
        if ($row === []) {
            throw new RuntimeException('Verordening niet gevonden: '.$id);
        }

        return $row;
    }//end loadTabel()

    /**
     * Find rows via OpenRegister findAll, normalised to arrays.
     *
     * @param object               $objectService OpenRegister ObjectService.
     * @param string               $register      Register id.
     * @param string               $schema        Schema id.
     * @param array<string, mixed> $filters       Equality filters.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findAllRows(object $objectService, string $register, string $schema, array $filters): array
    {
        if ($schema === '') {
            return [];
        }

        try {
            $records = $objectService->findAll($register, $schema, ['filters' => $filters]);
        } catch (\Throwable $e) {
            $this->logger->warning('Procest leges: findAll failed: '.$e->getMessage());
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
    }//end findAllRows()

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
}//end class
