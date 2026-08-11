<?php

/**
 * Procest IngebrekestellingService.
 *
 * Handles AWB 4:17 ingebrekestelling registration: validates the notice
 * against the lapsed TermijnInstance, sets gevalideerd + geldigheidStatus,
 * and (on first valid notice) auto-creates a DwangsomBerekening with
 * startDatum = ontvangstDatum + 14 days grace.
 *
 * One-dwangsom guard: when TermijnInstance.relevantIngbrekes is already
 * set, subsequent notices are recorded but do NOT spawn a second
 * berekening (REQ-TERM-005).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * AWB 4:17 ingebrekestelling registration + DwangsomBerekening creation.
 */
class IngebrekestellingService
{
    public const TARIFF_AWB_PLAFOND = 144200;
    public const TARIFF_AWB_GRACE   = 14;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service.
     * @param DeadlineService $deadlineService DeadlineService.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly DeadlineService $deadlineService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register an ingebrekestelling against a TermijnInstance.
     *
     * @param string            $termijnInstanceId TermijnInstance id.
     * @param DateTimeImmutable $ontvangstDatum    Receipt date.
     * @param string            $kanaal            Receipt channel.
     * @param string            $documentLink      Document link.
     *
     * @return array<string, mixed> The ingebrekestelling row (with possibly null/created berekening).
     *
     * @throws RuntimeException When the instance is missing.
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
     */
    public function registerIngebrekestelling(
        string $termijnInstanceId,
        DateTimeImmutable $ontvangstDatum,
        string $kanaal,
        string $documentLink=''
    ): array {
        $instance = $this->deadlineService->getTermijnInstance($termijnInstanceId);
        if ($instance === null) {
            throw new RuntimeException('TermijnInstance not found: '.$termijnInstanceId);
        }

        $status   = (string) ($instance['status'] ?? '');
        $deadline = (string) ($instance['einddatumActueel'] ?? '');
        $receipt  = $ontvangstDatum->format('Y-m-d');

        $isValid = ($status === 'overschreden' && $deadline !== '' && $deadline < $receipt);

        $row = [
            'termijnInstance' => $termijnInstanceId,
            'ontvangstDatum'  => $receipt,
            'kanaal'          => $kanaal,
            'gevalideerd'     => $isValid,
            'documentLink'    => $documentLink,
        ];

        $row['geldigheidStatus'] = 'premaat';
        if ($isValid === true) {
            $row['geldigheidStatus'] = 'geldig';
        }

        $saved     = $this->saveSchema(schemaConfigKey: 'ingebrekestelling_schema', object: $row);
        $row['id'] = (string) ($saved['id'] ?? '');

        if ($isValid === false) {
            $this->logger->info(
                'Premature ingebrekestelling rejected',
                ['termijnInstance' => $termijnInstanceId, 'ontvangstDatum' => $receipt]
            );
            return $row;
        }

        // One-dwangsom guard: if an earlier valid notice already exists,
        // record the receipt but do NOT spawn a second berekening.
        $existing = (string) ($instance['relevantIngbrekes'] ?? '');
        if ($existing !== '') {
            $this->logger->info(
                'Additional ingebrekestelling recorded; first remains the dwangsom basis',
                ['termijnInstance' => $termijnInstanceId, 'firstNotice' => $existing]
            );
            return $row;
        }

        // First valid notice: link it and start a DwangsomBerekening.
        $row['dwangsomBerekening'] = $this->startDwangsomBerekening(
            termijnInstanceId: $termijnInstanceId,
            instance: $instance,
            ingebrekestellingId: (string) $row['id'],
            ontvangstDatum: $ontvangstDatum,
            kanaal: $kanaal,
            documentLink: $documentLink,
        );

        return $row;
    }//end registerIngebrekestelling()

    /**
     * Link the first valid notice to its instance and open the DwangsomBerekening.
     *
     * @param string               $termijnInstanceId   TermijnInstance id.
     * @param array<string, mixed> $instance            TermijnInstance row.
     * @param string               $ingebrekestellingId Id of the saved ingebrekestelling.
     * @param DateTimeImmutable    $ontvangstDatum      Receipt date.
     * @param string               $kanaal              Receipt channel.
     * @param string               $documentLink        Document link.
     *
     * @return array<string, mixed> The created DwangsomBerekening row.
     */
    private function startDwangsomBerekening(
        string $termijnInstanceId,
        array $instance,
        string $ingebrekestellingId,
        DateTimeImmutable $ontvangstDatum,
        string $kanaal,
        string $documentLink
    ): array {
        $this->deadlineService->updateTermijnInstance(
            $termijnInstanceId,
            ['relevantIngbrekes' => $ingebrekestellingId]
        );

        $regime  = $this->resolveRegime(instance: $instance);
        $startAt = $ontvangstDatum->modify('+'.((int) $regime['grace']).' days')->format('Y-m-d');

        $regimeLabel = 'awb-default';
        if ($regime['custom'] === true) {
            $regimeLabel = 'afwijkend';
        }

        $berekening = $this->saveSchema(
            schemaConfigKey: 'dwangsom_berekening_schema',
            object: [
                'ingebrekestelling' => $ingebrekestellingId,
                'termijnInstance'   => $termijnInstanceId,
                'startDatum'        => $startAt,
                'huidigeDag'        => 0,
                'dagtarief'         => 0,
                'cumulatievBedrag'  => 0,
                'plafondBerekend'   => (int) $regime['plafond'],
                'plafondBereikt'    => false,
                'status'            => 'lopend',
                'regime'            => $regimeLabel,
            ]
        );

        $this->deadlineService->recordEvent(
            termijnInstanceId: $termijnInstanceId,
            type: 'ingebrekestelling-ontvangen',
            grondslag: 'AWB 4:17',
            motivering: 'Ingebrekestelling ontvangen via '.$kanaal,
            dagenImpact: 0,
            tijdstip: $ontvangstDatum,
            documentLink: $documentLink,
        );

        $this->deadlineService->recordEvent(
            termijnInstanceId: $termijnInstanceId,
            type: 'dwangsom-gestart',
            grondslag: 'AWB 4:17',
            motivering: 'Dwangsom-berekening gestart na grace period',
            dagenImpact: 0,
            tijdstip: $ontvangstDatum,
        );

        return $berekening;
    }//end startDwangsomBerekening()

    /**
     * Resolve the dwangsom regime (AWB-default or custom from definition).
     *
     * @param array<string, mixed> $instance TermijnInstance row.
     *
     * @return array{plafond:int,grace:int,custom:bool,dailyTariff?:int}
     */
    private function resolveRegime(array $instance): array
    {
        $defId = (string) ($instance['termijnDefinitie'] ?? '');
        if ($defId === '') {
            return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
        }

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('termijn_definitie_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
        }

        try {
            $def = $objectService->find($defId, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
        }

        if (is_array($def) === false) {
            return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
        }

        $regime = $def['afwijkendDwangsomRegime'] ?? null;
        if (is_array($regime) === false) {
            return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
        }

        return [
            'plafond'     => (int) ($regime['plafond'] ?? self::TARIFF_AWB_PLAFOND),
            'grace'       => (int) ($regime['grace'] ?? self::TARIFF_AWB_GRACE),
            'dailyTariff' => (int) ($regime['dailyTariff'] ?? 0),
            'custom'      => true,
        ];
    }//end resolveRegime()

    /**
     * Save to a configured schema.
     *
     * @param string               $schemaConfigKey Config key.
     * @param array<string, mixed> $object          Payload.
     *
     * @return array<string, mixed>
     */
    private function saveSchema(string $schemaConfigKey, array $object): array
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
            $this->logger->error(
                'IngebrekestellingService persist failed',
                ['schemaConfigKey' => $schemaConfigKey, 'error' => $e->getMessage()]
            );
            return $object;
        }
    }//end saveSchema()
}//end class
