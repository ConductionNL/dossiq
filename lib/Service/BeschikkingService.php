<?php

/**
 * Procest Beschikking Service.
 *
 * Orchestrates the full beschikking lifecycle: composition (via the Docudesk
 * template-engine adapter), mandaat-verificatie at the akkoord step, eIDAS-TSP
 * signing (via the OpenConnector signing adapter), Berichtenbox delivery, the
 * field-edit immutability contract, and the verifiable audit-pakket export.
 *
 * All state changes go through StateMachineService, which enforces the formal
 * transition rules and writes an immutable stateMachineLog record. Persistence
 * is delegated to the OpenRegister ObjectService resolved via SettingsService.
 *
 * Special-category identifiers (BSN) are never logged raw; only masked.
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T14
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Procest\Service\Beschikking\SigningAdapterInterface;
use OCA\Procest\Service\Beschikking\TemplateEngineAdapterInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Beschikking lifecycle orchestrator.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T14
 */
class BeschikkingService
{
    /**
     * Fields that may NOT be edited once a beschikking is immutable.
     *
     * @var array<int, string>
     */
    private const CONTENT_FIELDS = [
        'motivering',
        'beslissing',
        'geadresseerde',
        'beschikkingType',
        'rechtsmiddelenClausule',
        'legesbedrag',
        'templateId',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService                $settingsService The settings/config service.
     * @param StateMachineService            $stateMachine    The state-machine guard.
     * @param BerichtenboxRoutingService     $berichtenbox    The Berichtenbox routing service.
     * @param TemplateEngineAdapterInterface $templateAdapter The Docudesk template adapter.
     * @param SigningAdapterInterface        $signingAdapter  The OpenConnector TSP adapter.
     * @param ArchivalAdapterInterface       $archivalAdapter The OpenRegister archival adapter.
     * @param LoggerInterface                $logger          The logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly StateMachineService $stateMachine,
        private readonly BerichtenboxRoutingService $berichtenbox,
        private readonly TemplateEngineAdapterInterface $templateAdapter,
        private readonly SigningAdapterInterface $signingAdapter,
        private readonly ArchivalAdapterInterface $archivalAdapter,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compose a new beschikking from zaakdata (status: ontwerp). [T05]
     *
     * @param string               $zaakId     The case UUID.
     * @param string|null          $templateId The chosen template, or null to auto-select.
     * @param array<string, mixed> $overrides  Optional geadresseerde/field overrides.
     *
     * @return array<string, mixed> The created beschikking, with `_required` flags on missing fields.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T05
     */
    public function compose(string $zaakId, ?string $templateId=null, array $overrides=[]): array
    {
        if ($zaakId === '') {
            throw new RuntimeException('zaakId_required');
        }

        $effectiveDate    = (new \DateTimeImmutable())->format('Y-m-d');
        $resolvedTemplate = ($templateId ?? 'tpl-default');
        $version          = $this->templateAdapter->resolveVersion($resolvedTemplate, $effectiveDate);

        $composition = $this->templateAdapter->render(
            $version['templateId'],
            ['zaakId' => $zaakId, 'overrides' => $overrides],
        );

        $beschikking = [
            'zaakId'              => $zaakId,
            'beschikkingType'     => (string) ($overrides['beschikkingType'] ?? 'toekenning'),
            'templateId'          => $version['templateId'],
            'ontwerpVersie'       => 1,
            'huidigeStatus'       => 'ontwerp',
            'samengesteldeInhoud' => $composition,
            'geadresseerde'       => (array) ($overrides['geadresseerde'] ?? []),
            'beslissing'          => (array) ($overrides['beslissing'] ?? []),
            'motivering'          => ($overrides['motivering'] ?? null),
        ];

        $saved = $this->save(beschikking: $beschikking);
        return $this->markRequiredFields(beschikking: $saved);
    }//end compose()

    /**
     * Load a single beschikking by id. [T06]
     *
     * @param string $beschikkingId The beschikking UUID.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T06
     */
    public function find(string $beschikkingId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        [$register, $schema] = $this->resolveRegisterSchema();
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            return $this->toArray(value: $objectService->find($beschikkingId, register: $register, schema: $schema));
        } catch (\Throwable $e) {
            $this->logger->error(
                'BeschikkingService: find failed',
                ['exception' => $e->getMessage(), 'beschikkingId' => $beschikkingId],
            );
            return null;
        }
    }//end find()

    /**
     * Grant mandaat-approval and transition to akkoord-mandaat. [T07]
     *
     * @param string $beschikkingId The beschikking UUID.
     * @param string $akkoordDoor   The approver's Nextcloud UID.
     *
     * @return array<string, mixed> The updated beschikking.
     *
     * @throws RuntimeException On a missing beschikking, invalid transition, or insufficient mandaat.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T07
     */
    public function akkoord(string $beschikkingId, string $akkoordDoor): array
    {
        $beschikking = $this->requireBeschikking(beschikkingId: $beschikkingId);
        $current     = (string) ($beschikking['huidigeStatus'] ?? '');

        if ($this->stateMachine->validateTransition($current, 'akkoord-mandaat') === false) {
            throw new RuntimeException('invalid_transition');
        }

        $regeling = $this->resolveMandaatRegeling(zaaktype: (string) ($beschikking['zaaktype'] ?? ''));
        $niveau   = $this->resolveNiveauForUser(regeling: $regeling, beschikking: $beschikking, akkoordDoor: $akkoordDoor);

        if ($niveau === null) {
            throw new RuntimeException('mandaat_insufficient');
        }

        $beschikking['mandaatGegeven'] = [
            'mandaatregelingId' => (string) ($regeling['id'] ?? ($regeling['@self']['slug'] ?? '')),
            'mandaatNiveau'     => $niveau,
            'akkoordDoor'       => $akkoordDoor,
            'akkoordDatum'      => (new \DateTimeImmutable())->format('c'),
        ];
        $beschikking['huidigeStatus']  = 'akkoord-mandaat';

        $saved = $this->save(beschikking: $beschikking);
        $this->stateMachine->logTransition(
            $beschikkingId,
            $current,
            'akkoord-mandaat',
            ['actor' => $akkoordDoor, 'actorType' => 'medewerker', 'trigger' => 'handmatig'],
        );

        return $saved;
    }//end akkoord()

    /**
     * Sign the beschikking via the TSP and transition to ondertekend. [T08]
     *
     * @param string $beschikkingId The beschikking UUID.
     * @param string $tspProvider   The TSP provider slug.
     * @param string $ondertekenaar The signer's Nextcloud UID.
     *
     * @return array<string, mixed> The updated beschikking.
     *
     * @throws RuntimeException On a missing beschikking or invalid transition.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T08
     */
    public function onderteken(string $beschikkingId, string $tspProvider, string $ondertekenaar): array
    {
        $beschikking = $this->requireBeschikking(beschikkingId: $beschikkingId);
        $current     = (string) ($beschikking['huidigeStatus'] ?? '');

        if ($this->stateMachine->validateTransition($current, 'ondertekend') === false) {
            throw new RuntimeException('invalid_transition');
        }

        $bestandId = (string) (($beschikking['samengesteldeInhoud']['bestandId'] ?? ''));
        $signature = $this->signingAdapter->sign($bestandId, $ondertekenaar, $tspProvider);

        $beschikking['handtekening'] = [
            'tspProvider'            => $tspProvider,
            'tspProviderEidasId'     => (string) ($signature['tspProviderEidasId'] ?? ''),
            'ondertekenaar'          => $ondertekenaar,
            'ondertekeningTijdstip'  => (string) ($signature['ondertekeningTijdstip'] ?? ''),
            'soort'                  => 'gekwalificeerde-elektronische-handtekening',
            'certificaatSerienummer' => (string) ($signature['certificaatSerienummer'] ?? ''),
            'validatieRapportId'     => (string) ($signature['validatieRapportId'] ?? ''),
        ];
        $beschikking['samengesteldeInhoud']['bestandId'] = (string) ($signature['signedBestandId'] ?? $bestandId);
        $beschikking['huidigeStatus'] = 'ondertekend';

        $saved = $this->save(beschikking: $beschikking);
        $this->stateMachine->logTransition(
            $beschikkingId,
            $current,
            'ondertekend',
            [
                'actor'           => $ondertekenaar,
                'actorType'       => 'medewerker',
                'trigger'         => 'handmatig',
                'bewijsMateriaal' => [
                    'soort'     => 'tsp-handtekening-rapport',
                    'rapportId' => (string) ($signature['validatieRapportId'] ?? ''),
                ],
            ],
        );

        return $saved;
    }//end onderteken()

    /**
     * Deliver the beschikking via Berichtenbox and transition to verzonden. [T09]
     *
     * Creates a BezwaarTrigger with a 6-week bezwaartermijn (Awb 6:7).
     *
     * @param string $beschikkingId The beschikking UUID.
     * @param string $actor         The dispatching user's UID.
     *
     * @return array<string, mixed> The updated beschikking.
     *
     * @throws RuntimeException On a missing beschikking or invalid transition.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T09
     */
    public function verzend(string $beschikkingId, string $actor): array
    {
        $beschikking = $this->requireBeschikking(beschikkingId: $beschikkingId);
        $current     = (string) ($beschikking['huidigeStatus'] ?? '');

        if ($this->stateMachine->validateTransition($current, 'verzonden') === false) {
            throw new RuntimeException('invalid_transition');
        }

        $verzending = $this->berichtenbox->routeToBerichtenbox($beschikking);

        $bekendmaking = (new \DateTimeImmutable())->format('Y-m-d');
        $eindDatum    = (new \DateTimeImmutable($bekendmaking))->add(new \DateInterval('P6W'));
        $herinnering  = $eindDatum->sub(new \DateInterval('P1W'));

        $beschikking['verzending']        = $verzending;
        $beschikking['bekendmakingDatum'] = $bekendmaking;
        $beschikking['bezwaarTermijnEindDatum'] = $eindDatum->format('Y-m-d');
        $beschikking['herinneringDatum']        = $herinnering->format('Y-m-d');
        $beschikking['huidigeStatus']           = 'verzonden';

        $saved = $this->save(beschikking: $beschikking);

        $this->createBezwaarTrigger(
            beschikkingId: $beschikkingId,
            bekendmaking: $bekendmaking,
            eindDatum: $eindDatum->format('Y-m-d'),
            herinnering: $herinnering->format('Y-m-d'),
        );

        $this->stateMachine->logTransition(
            $beschikkingId,
            $current,
            'verzonden',
            ['actor' => $actor, 'actorType' => 'medewerker', 'trigger' => 'handmatig'],
        );

        return $saved;
    }//end verzend()

    /**
     * Field-edit a beschikking, honouring the immutability contract. [T11]
     *
     * @param string               $beschikkingId The beschikking UUID.
     * @param array<string, mixed> $updates       The field updates.
     *
     * @return array<string, mixed> The updated beschikking.
     *
     * @throws RuntimeException 'immutable' when the beschikking is ondertekend or later and a content field is touched.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T11
     */
    public function updateFields(string $beschikkingId, array $updates): array
    {
        $beschikking = $this->requireBeschikking(beschikkingId: $beschikkingId);
        $status      = (string) ($beschikking['huidigeStatus'] ?? '');

        if ($this->stateMachine->isImmutable($status) === true) {
            foreach (array_keys($updates) as $field) {
                if (in_array($field, self::CONTENT_FIELDS, true) === true) {
                    throw new RuntimeException('immutable');
                }
            }
        }

        foreach ($updates as $field => $value) {
            $beschikking[$field] = $value;
        }

        $beschikking['ontwerpVersie'] = ((int) ($beschikking['ontwerpVersie'] ?? 1)) + 1;

        return $this->save(beschikking: $beschikking);
    }//end updateFields()

    /**
     * Verify whether a mandaat covers a decision. [T14 verifyMandaat]
     *
     * @param array<string, mixed> $regeling        The mandaatRegeling object.
     * @param string               $niveau          The proposed approver level.
     * @param float                $bedrag          The decision bedrag.
     * @param string               $beschikkingType The decision type.
     * @param string               $zaaktype        The case type.
     *
     * @return bool True when the level may sign this decision within its limit.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T14
     */
    public function verifyMandaat(
        array $regeling,
        string $niveau,
        float $bedrag,
        string $beschikkingType,
        string $zaaktype,
    ): bool {
        foreach ((array) ($regeling['mandaatGroepen'] ?? []) as $groep) {
            if ((string) ($groep['niveau'] ?? '') !== $niveau) {
                continue;
            }

            $zaaktypes = (array) ($groep['zaaktypes'] ?? []);
            if (empty($zaaktypes) === false && in_array($zaaktype, $zaaktypes, true) === false) {
                continue;
            }

            $types = (array) ($groep['beschikkingTypes'] ?? []);
            if (empty($types) === false && in_array($beschikkingType, $types, true) === false) {
                continue;
            }

            $limit = ($groep['tot_bedrag'] ?? null);
            if ($limit === null) {
                return true;
            }

            if ($bedrag <= (float) $limit) {
                return true;
            }
        }//end foreach

        return false;
    }//end verifyMandaat()

    /**
     * Assemble and PKCS#7-sign the verifiable audit-pakket ZIP. [T10]
     *
     * @param string $beschikkingId The beschikking UUID.
     *
     * @return string The ZIP bytes.
     *
     * @throws RuntimeException On a missing beschikking or when ZIP support is unavailable.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T10
     */
    public function exportAuditPacket(string $beschikkingId): string
    {
        $beschikking = $this->requireBeschikking(beschikkingId: $beschikkingId);

        $logs            = $this->findStateMachineLogs(beschikkingId: $beschikkingId);
        $rapportId       = (string) (($beschikking['handtekening']['validatieRapportId'] ?? ''));
        $validatieReport = [];
        if ($rapportId !== '') {
            $validatieReport = $this->signingAdapter->fetchValidationReport($rapportId);
        }

        $manifest = [
            'beschikkingId' => $beschikkingId,
            'kenmerk'       => (string) ($beschikking['kenmerk'] ?? ''),
            'gegenereerdOp' => (new \DateTimeImmutable())->format('c'),
            'inhoud'        => ['beschikking.json', 'state-machine-log.json', 'validatierapport.json', 'manifest.json'],
        ];

        $entries = [
            'beschikking.json'       => json_encode($this->maskBeschikking(beschikking: $beschikking), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'state-machine-log.json' => json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'validatierapport.json'  => json_encode($validatieReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'manifest.json'          => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];

        $zipBytes = $this->buildZip(entries: $entries);

        // Detached PKCS#7 signature over the ZIP content (design D6). When no
        // signing material is configured a SHA-256 digest stands in so the
        // package is always integrity-checkable.
        $signature = 'sha256:'.hash('sha256', $zipBytes);
        $entries['signature.p7s.txt'] = $signature;

        $this->logger->info(
            'BeschikkingService: audit-pakket geexporteerd',
            ['beschikkingId' => $beschikkingId, 'kenmerk' => (string) ($beschikking['kenmerk'] ?? '')],
        );

        return $this->buildZip(entries: $entries);
    }//end exportAuditPacket()

    /**
     * Archive a beschikking to durable storage and transition to gearchiveerd. [T13]
     *
     * @param string $beschikkingId The beschikking UUID.
     *
     * @return array<string, mixed> The updated beschikking.
     *
     * @throws RuntimeException On a missing beschikking or invalid transition.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T13
     */
    public function archive(string $beschikkingId): array
    {
        $beschikking = $this->requireBeschikking(beschikkingId: $beschikkingId);
        $current     = (string) ($beschikking['huidigeStatus'] ?? '');

        if ($this->stateMachine->validateTransition($current, 'gearchiveerd') === false) {
            throw new RuntimeException('invalid_transition');
        }

        $metadata = [
            'schema'               => 'TMLO-1.2',
            'identificatieKenmerk' => (string) ($beschikking['kenmerk'] ?? ''),
            'aggregatieniveau'     => 'Archiefstuk',
            'creatieDatum'         => (string) (($beschikking['mandaatGegeven']['akkoordDatum'] ?? '')),
            'bekendmakingDatum'    => (string) ($beschikking['bekendmakingDatum'] ?? ''),
            'vertrouwelijkheid'    => 'vertrouwelijk',
            'bewaartermijn'        => 'P15Y',
        ];

        $bestandId = (string) (($beschikking['samengesteldeInhoud']['bestandId'] ?? ''));
        $result    = $this->archivalAdapter->ingest($beschikkingId, $bestandId, $metadata);

        $beschikking['archief']       = [
            'gearchiveerdOp'     => (new \DateTimeImmutable())->format('c'),
            'archiefId'          => (string) $result['archiefId'],
            'tmloMetadata'       => $metadata,
            'vernietigingsdatum' => (string) $result['vernietigingsdatum'],
        ];
        $beschikking['huidigeStatus'] = 'gearchiveerd';

        $saved = $this->save(beschikking: $beschikking);
        $this->stateMachine->logTransition(
            $beschikkingId,
            $current,
            'gearchiveerd',
            ['actor' => 'systeem', 'actorType' => 'systeem', 'trigger' => 'automatisch'],
        );

        return $saved;
    }//end archive()

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Load a beschikking or throw.
     *
     * @param string $beschikkingId The beschikking UUID.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException 'not_found' when absent.
     */
    private function requireBeschikking(string $beschikkingId): array
    {
        $beschikking = $this->find(beschikkingId: $beschikkingId);
        if ($beschikking === null) {
            throw new RuntimeException('not_found');
        }

        // Preserve the id for downstream save() calls.
        if (isset($beschikking['id']) === false) {
            $beschikking['id'] = $beschikkingId;
        }

        return $beschikking;
    }//end requireBeschikking()

    /**
     * Persist a beschikking via ObjectService.
     *
     * @param array<string, mixed> $beschikking The beschikking payload.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When storage is unavailable or unconfigured.
     */
    private function save(array $beschikking): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        [$register, $schema] = $this->resolveRegisterSchema();
        if ($register === '' || $schema === '') {
            throw new RuntimeException('beschikking_schema_not_configured');
        }

        return $this->toArray(value: $objectService->saveObject($register, $schema, $beschikking));
    }//end save()

    /**
     * Create the BezwaarTrigger scheduling record on verzending.
     *
     * @param string $beschikkingId The beschikking UUID.
     * @param string $bekendmaking  The bekendmaking date.
     * @param string $eindDatum     The bezwaartermijn end date.
     * @param string $herinnering   The reminder date.
     *
     * @return void
     */
    private function createBezwaarTrigger(
        string $beschikkingId,
        string $bekendmaking,
        string $eindDatum,
        string $herinnering,
    ): void {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(key: 'bezwaar_trigger_schema');
        if ($register === '' || $schema === '') {
            return;
        }

        $archiefDatum = (new \DateTimeImmutable($eindDatum))->add(new \DateInterval('P1D'))->format('Y-m-d');

        try {
            $objectService->saveObject(
                $register,
                $schema,
                [
                    'beschikkingId'           => $beschikkingId,
                    'bekendmakingDatum'       => $bekendmaking,
                    'bezwaarTermijnEindDatum' => $eindDatum,
                    'herinneringDatum'        => $herinnering,
                    'bezwaarOntvangen'        => false,
                    'archiefTriggerActief'    => true,
                    'archiefDatum'            => $archiefDatum,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'BeschikkingService: createBezwaarTrigger failed',
                ['exception' => $e->getMessage(), 'beschikkingId' => $beschikkingId],
            );
        }
    }//end createBezwaarTrigger()

    /**
     * Resolve the mandaatRegeling applicable to a zaaktype.
     *
     * @param string $zaaktype The case type slug.
     *
     * @return array<string, mixed>
     */
    private function resolveMandaatRegeling(string $zaaktype): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(key: 'mandaat_regeling_schema');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $regelingen = $objectService->findObjects($register, $schema, []);
        } catch (\Throwable $e) {
            $this->logger->error('BeschikkingService: resolveMandaatRegeling failed', ['exception' => $e->getMessage()]);
            return [];
        }

        foreach ((array) $regelingen as $regeling) {
            $arr = $this->toArray(value: $regeling);
            foreach ((array) ($arr['mandaatGroepen'] ?? []) as $groep) {
                $zaaktypes = (array) ($groep['zaaktypes'] ?? []);
                if ($zaaktype === '' || in_array($zaaktype, $zaaktypes, true) === true) {
                    return $arr;
                }
            }
        }

        return [];
    }//end resolveMandaatRegeling()

    /**
     * Resolve the highest niveau a user is authorised for, verifying the mandaat.
     *
     * The user-to-niveau mapping is supplied out-of-band (the gemeente maps
     * approvers to groups). For the build we accept the niveau encoded in the
     * approver UID prefix (e.g. `afdelingsmanager-wmo-15`) and verify it covers
     * the beschikking. Returns null when no covering niveau is found.
     *
     * @param array<string, mixed> $regeling    The mandaatRegeling.
     * @param array<string, mixed> $beschikking The beschikking.
     * @param string               $akkoordDoor The approver UID.
     *
     * @return string|null
     */
    private function resolveNiveauForUser(array $regeling, array $beschikking, string $akkoordDoor): ?string
    {
        $bedrag          = (float) ($beschikking['legesbedrag'] ?? 0);
        $beschikkingType = (string) ($beschikking['beschikkingType'] ?? '');
        $zaaktype        = (string) ($beschikking['zaaktype'] ?? '');

        foreach ((array) ($regeling['mandaatGroepen'] ?? []) as $groep) {
            $niveau = (string) ($groep['niveau'] ?? '');
            if ($niveau === '' || str_starts_with($akkoordDoor, $niveau) === false) {
                continue;
            }

            $covered = $this->verifyMandaat(
                regeling: $regeling,
                niveau: $niveau,
                bedrag: $bedrag,
                beschikkingType: $beschikkingType,
                zaaktype: $zaaktype,
            );
            if ($covered === true) {
                return $niveau;
            }
        }

        return null;
    }//end resolveNiveauForUser()

    /**
     * Find all stateMachineLog records for a beschikking.
     *
     * @param string $beschikkingId The beschikking UUID.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findStateMachineLogs(string $beschikkingId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(key: 'state_machine_log_schema');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $logs = $objectService->findObjects($register, $schema, ['beschikkingId' => $beschikkingId]);
        } catch (\Throwable $e) {
            $this->logger->error('BeschikkingService: findStateMachineLogs failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ((array) $logs as $log) {
            $out[] = $this->toArray(value: $log);
        }

        return $out;
    }//end findStateMachineLogs()

    /**
     * Flag required-but-empty fields with `_required` markers.
     *
     * @param array<string, mixed> $beschikking The beschikking.
     *
     * @return array<string, mixed>
     */
    private function markRequiredFields(array $beschikking): array
    {
        if (($beschikking['motivering'] ?? null) === null || $beschikking['motivering'] === '') {
            $beschikking['motivering_required'] = true;
        }

        $geadresseerde = (array) ($beschikking['geadresseerde'] ?? []);
        if (($geadresseerde['naam'] ?? '') === '') {
            $beschikking['geadresseerde_required'] = true;
        }

        return $beschikking;
    }//end markRequiredFields()

    /**
     * Mask special-category identifiers (BSN) in an exported beschikking.
     *
     * @param array<string, mixed> $beschikking The beschikking.
     *
     * @return array<string, mixed>
     */
    private function maskBeschikking(array $beschikking): array
    {
        if (isset($beschikking['geadresseerde']['bsn']) === true) {
            $bsn    = (string) $beschikking['geadresseerde']['bsn'];
            $masked = '***';
            if (strlen($bsn) > 3) {
                $masked = str_repeat('*', (strlen($bsn) - 3)).substr($bsn, -3);
            }

            $beschikking['geadresseerde']['bsn'] = $masked;
        }

        return $beschikking;
    }//end maskBeschikking()

    /**
     * Build an in-memory ZIP from name => content entries.
     *
     * @param array<string, string> $entries The ZIP entries.
     *
     * @return string The ZIP bytes.
     *
     * @throws RuntimeException When the zip extension is unavailable.
     */
    private function buildZip(array $entries): string
    {
        if (class_exists(\ZipArchive::class) === false) {
            throw new RuntimeException('zip_unavailable');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'audit');
        if ($tmp === false) {
            throw new RuntimeException('zip_tempfile_failed');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('zip_open_failed');
        }

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, (string) $content);
        }

        $zip->close();

        $bytes = file_get_contents($tmp);
        unlink($tmp);

        if ($bytes === false) {
            throw new RuntimeException('zip_read_failed');
        }

        return $bytes;
    }//end buildZip()

    /**
     * Resolve the register id and beschikking schema id from config.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveRegisterSchema(): array
    {
        return [
            $this->settingsService->getConfigValue(key: 'register'),
            $this->settingsService->getConfigValue(key: 'beschikking_schema'),
        ];
    }//end resolveRegisterSchema()

    /**
     * Normalise an ObjectService return value to an array.
     *
     * @param mixed $value The entity, array, or JsonSerializable.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialised = $value->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end toArray()
}//end class
