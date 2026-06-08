<?php

/**
 * Procest Leges Seed Data Service
 *
 * Seeds example leges tariff tables, tariffs, variants and discounts from
 * lib/Settings/leges_seed_data.json into OpenRegister. The seed is idempotent:
 * a tariff table is skipped when one with the same naam already exists.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Seeds example leges data into OpenRegister.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LegesSeedDataService
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
     * Seed the leges example data.
     *
     * @return array<string, mixed> Result with 'success' and either 'message' or the per-kind counts.
     */
    public function seed(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return ['success' => false, 'message' => 'OpenRegister is not available'];
        }

        $register      = $this->settingsService->getConfigValue('register');
        $tabelSchema   = $this->settingsService->getConfigValue('leges_tarief_tabel_schema');
        $tariefSchema  = $this->settingsService->getConfigValue('leges_tarief_schema');
        $variantSchema = $this->settingsService->getConfigValue('leges_variant_schema');
        $kortingSchema = $this->settingsService->getConfigValue('leges_korting_schema');
        if ($register === '' || $tabelSchema === '' || $tariefSchema === '') {
            return ['success' => false, 'message' => 'Leges schemas not configured'];
        }

        $seedPath = __DIR__.'/../Settings/leges_seed_data.json';
        if (file_exists($seedPath) === false) {
            return ['success' => false, 'message' => 'Seed file not found'];
        }

        $data = json_decode((string) file_get_contents($seedPath), true);
        if (is_array($data) === false) {
            return ['success' => false, 'message' => 'Invalid seed JSON'];
        }

        $existingNames = $this->existingTableNames(objectService: $objectService, register: $register, schema: $tabelSchema);

        $counts = ['tabellen' => 0, 'tarieven' => 0, 'varianten' => 0, 'kortingen' => 0, 'skipped' => 0];

        foreach (($data['tariefTabellen'] ?? []) as $tabelSeed) {
            if (in_array((string) ($tabelSeed['naam'] ?? ''), $existingNames, true) === true) {
                $counts['skipped']++;
                continue;
            }

            $this->seedTable(
                objectService: $objectService,
                register: $register,
                schemas: [
                    'tabel'   => $tabelSchema,
                    'tarief'  => $tariefSchema,
                    'variant' => $variantSchema,
                    'korting' => $kortingSchema,
                ],
                tabelSeed: $tabelSeed,
                counts: $counts
            );
        }

        $this->logger->info('Procest leges: seed complete', $counts);

        return array_merge(['success' => true], $counts);
    }//end seed()

    /**
     * Seed one tariff table with its tariffs, variants and discounts.
     *
     * @param object                $objectService OpenRegister ObjectService.
     * @param string                $register      Register id.
     * @param array<string, string> $schemas       Schema ids keyed by kind.
     * @param array<string, mixed>  $tabelSeed     The table seed.
     * @param array<string, int>    $counts        Running counts (by reference).
     *
     * @return void
     */
    private function seedTable(object $objectService, string $register, array $schemas, array $tabelSeed, array &$counts): void
    {
        $tabelPayload = [
            'naam'            => (string) ($tabelSeed['naam'] ?? ''),
            'geldigVanaf'     => (string) ($tabelSeed['geldigVanaf'] ?? ''),
            'geldigTotEnMet'  => ($tabelSeed['geldigTotEnMet'] ?? null),
            'vastgesteldDoor' => (string) ($tabelSeed['vastgesteldDoor'] ?? ''),
            'vastgesteldOp'   => ($tabelSeed['vastgesteldOp'] ?? null),
            'status'          => (string) ($tabelSeed['status'] ?? 'concept'),
        ];

        $tabelId = $this->extractId(result: $objectService->saveObject(object: $tabelPayload, register: $register, schema: $schemas['tabel']));
        $counts['tabellen']++;

        foreach (($tabelSeed['tarieven'] ?? []) as $tariefSeed) {
            $tariefPayload = [
                'tariefTabelId'     => $tabelId,
                'tariefNummer'      => (string) ($tariefSeed['tariefNummer'] ?? ''),
                'omschrijving'      => (string) ($tariefSeed['omschrijving'] ?? ''),
                'bedrag'            => (int) ($tariefSeed['bedrag'] ?? 0),
                'grondslag'         => (string) ($tariefSeed['grondslag'] ?? 'vast'),
                'eenheid'           => (string) ($tariefSeed['eenheid'] ?? 'per_stuk'),
                'grondslagVeld'     => (string) ($tariefSeed['grondslagVeld'] ?? ''),
                'percentage'        => ($tariefSeed['percentage'] ?? null),
                'btwTarief'         => (int) ($tariefSeed['btwTarief'] ?? 0),
                'grootboekrekening' => (string) ($tariefSeed['grootboekrekening'] ?? ''),
                'kostendrager'      => (string) ($tariefSeed['kostendrager'] ?? ''),
                'productCode'       => (string) ($tariefSeed['productCode'] ?? ''),
            ];

            $tariefId = $this->extractId(result: $objectService->saveObject(object: $tariefPayload, register: $register, schema: $schemas['tarief']));
            $counts['tarieven']++;

            $this->seedChildren(
                objectService: $objectService,
                register: $register,
                schemas: $schemas,
                tariefSeed: $tariefSeed,
                tariefId: $tariefId,
                counts: $counts
            );
        }//end foreach
    }//end seedTable()

    /**
     * Seed the variants and discounts of a single tariff.
     *
     * @param object                $objectService OpenRegister ObjectService.
     * @param string                $register      Register id.
     * @param array<string, string> $schemas       Schema ids keyed by kind.
     * @param array<string, mixed>  $tariefSeed    The tariff seed.
     * @param string                $tariefId      The created tariff id.
     * @param array<string, int>    $counts        Running counts (by reference).
     *
     * @return void
     */
    private function seedChildren(object $objectService, string $register, array $schemas, array $tariefSeed, string $tariefId, array &$counts): void
    {
        if ($schemas['variant'] !== '') {
            foreach (($tariefSeed['varianten'] ?? []) as $variantSeed) {
                $objectService->saveObject(
                    object: [
                        'tariefId'       => $tariefId,
                        'variantNaam'    => (string) ($variantSeed['variantNaam'] ?? ''),
                        'condities'      => (array) ($variantSeed['condities'] ?? []),
                        'bedragOpslag'   => ($variantSeed['bedragOpslag'] ?? null),
                        'bedragOverride' => ($variantSeed['bedragOverride'] ?? null),
                    ],
                    register: $register,
                    schema: $schemas['variant']
                );
                $counts['varianten']++;
            }
        }

        if ($schemas['korting'] !== '') {
            foreach (($tariefSeed['kortingen'] ?? []) as $kortingSeed) {
                $objectService->saveObject(
                    object: [
                        'naam'                => (string) ($kortingSeed['naam'] ?? ''),
                        'tariefIds'           => [$tariefId],
                        'kortingsType'        => (string) ($kortingSeed['kortingsType'] ?? 'percentage'),
                        'kortingsWaarde'      => (float) ($kortingSeed['kortingsWaarde'] ?? 0),
                        'condities'           => (array) ($kortingSeed['condities'] ?? []),
                        'wettelijkeGrondslag' => (string) ($kortingSeed['wettelijkeGrondslag'] ?? ''),
                        'vereistMinimaCheck'  => (bool) ($kortingSeed['vereistMinimaCheck'] ?? false),
                        'geldigVanaf'         => (string) ($kortingSeed['geldigVanaf'] ?? ''),
                        'geldigTotEnMet'      => ($kortingSeed['geldigTotEnMet'] ?? null),
                    ],
                    register: $register,
                    schema: $schemas['korting']
                );
                $counts['kortingen']++;
            }
        }
    }//end seedChildren()

    /**
     * Collect existing tariff table names for idempotency.
     *
     * @param object $objectService OpenRegister ObjectService.
     * @param string $register      Register id.
     * @param string $schema        Tariff table schema id.
     *
     * @return array<int, string>
     */
    private function existingTableNames(object $objectService, string $register, string $schema): array
    {
        try {
            $records = $objectService->findAll($register, $schema, ['filters' => []]);
        } catch (\Throwable $e) {
            return [];
        }

        $names = [];
        foreach ((array) $records as $record) {
            $row = [];
            if (is_array($record) === true) {
                $row = $record;
            } else if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
                $serialized = $record->jsonSerialize();
                if (is_array($serialized) === true) {
                    $row = $serialized;
                }
            }

            if (isset($row['naam']) === true) {
                $names[] = (string) $row['naam'];
            }
        }

        return $names;
    }//end existingTableNames()

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

        if (is_array($result) === true) {
            return (string) ($result['id'] ?? ($result['uuid'] ?? ''));
        }

        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $row = $result->jsonSerialize();
            if (is_array($row) === true) {
                return (string) ($row['id'] ?? ($row['uuid'] ?? ''));
            }
        }

        return '';
    }//end extractId()
}//end class
