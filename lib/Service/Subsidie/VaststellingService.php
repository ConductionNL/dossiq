<?php

/**
 * Procest Vaststelling Service.
 *
 * Final-settlement (vaststelling) handling under AWB 4:46. Owns the
 * settlement-form math: comparing werkelijke kosten against the granted
 * amount, the accountantsverklaring requirement check, final-bedrag
 * calculation, overpayment detection, and the automatic terugvordering
 * trigger (REQ-SUB-005). The math is pure and unit-tested; finalisation
 * delegates clawback-case creation to TerugvorderingService and,
 * best-effort, auto-populates the linked case's `kosten` with the settled
 * amount (subsidie-settlement-case-costs) via the same ObjectService write
 * path, walking subsidieUitvoering -> subsidieAanvraag -> case.
 *
 * @category Service
 * @package  OCA\Procest\Service\Subsidie
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Subsidie;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Settlement math and terugvordering trigger.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class VaststellingService
{
    /**
     * `case.kosten` entry `type` for a subsidie vaststelling settlement
     * amount (subsidie-settlement-case-costs). English snake_case to match
     * the existing `leges_income`/`handling_cost` type-discriminator
     * convention in {@see \OCA\Procest\Service\Iv3ReportService}; counted
     * toward `totalCosts` there.
     */
    public const KOSTEN_TYPE_SUBSIDY_DISBURSEMENT = 'subsidy_disbursement';

    /**
     * `case.kosten` entry `source` marker identifying an entry appended by
     * this service — paired with `vaststellingId` for idempotency (a
     * re-finalize of the same vaststelling must not duplicate the entry).
     */
    public const KOSTEN_SOURCE = 'subsidie_vaststelling';

    /**
     * Constructor.
     *
     * @param SettingsService       $settingsService Schema/register bridge.
     * @param TerugvorderingService $terugvordering  Clawback factory.
     * @param LoggerInterface       $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly TerugvorderingService $terugvordering,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether an accountantsverklaring is mandatory for a granted amount.
     *
     * @param float $verleendBedrag The granted amount.
     * @param float $drempel        The regeling threshold.
     *
     * @return bool True when an accountant declaration is required.
     *
     * @spec openspec/changes/subsidieverlening-keten/specs.md
     */
    public function accountantsverklaringVereist(float $verleendBedrag, float $drempel): bool
    {
        return $verleendBedrag > $drempel;
    }//end accountantsverklaringVereist()

    /**
     * Compute the final vaststelling amount: capped at the granted amount,
     * never above the actual costs, never negative.
     *
     * @param float $verleendBedrag   The granted amount.
     * @param float $werkelijkeKosten The total actual costs.
     *
     * @return float The final settled amount.
     *
     * @spec openspec/changes/subsidieverlening-keten/specs.md
     */
    public function computeVastgesteldBedrag(float $verleendBedrag, float $werkelijkeKosten): float
    {
        $bedrag = min($verleendBedrag, $werkelijkeKosten);
        return round(max(0.0, $bedrag), 2);
    }//end computeVastgesteldBedrag()

    /**
     * Compute the overpayment to be reclaimed: positive when the disbursed
     * advances exceed the final settled amount (REQ-SUB-005).
     *
     * @param float $totaalVoorschotten The cumulative disbursed advances.
     * @param float $vastgesteldBedrag  The final settled amount.
     *
     * @return float The overpayment (0.0 when none).
     *
     * @spec openspec/changes/subsidieverlening-keten/specs.md
     */
    public function computeOverpayment(float $totaalVoorschotten, float $vastgesteldBedrag): float
    {
        $diff = ($totaalVoorschotten - $vastgesteldBedrag);
        if ($diff < 0.01) {
            return 0.0;
        }

        return round($diff, 2);
    }//end computeOverpayment()

    /**
     * Whether a terugvordering must be triggered for these figures.
     *
     * @param float $totaalVoorschotten The cumulative disbursed advances.
     * @param float $vastgesteldBedrag  The final settled amount.
     *
     * @return bool True when a clawback is required.
     *
     * @spec openspec/changes/subsidieverlening-keten/specs.md
     */
    public function triggerTerugvordering(float $totaalVoorschotten, float $vastgesteldBedrag): bool
    {
        return $this->computeOverpayment(totaalVoorschotten: $totaalVoorschotten, vastgesteldBedrag: $vastgesteldBedrag) > 0.0;
    }//end triggerTerugvordering()

    /**
     * Finalise a settlement: persist the vastgesteld bedrag and, when the
     * advances exceed it, open a clawback case for the difference. The
     * clawback case itself is created in "concept" awaiting manager
     * approval — this method never publishes it.
     *
     * @param string $vaststellingId     The settlement id.
     * @param float  $verleendBedrag     The granted amount.
     * @param float  $werkelijkeKosten   The total actual costs.
     * @param float  $totaalVoorschotten The cumulative disbursed advances.
     *
     * @return array<string, mixed> The finalisation result with optional clawback.
     *
     * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
     *
     * @spec openspec/specs/subsidie-settlement-case-costs/spec.md
     */
    public function finalize(
        string $vaststellingId,
        float $verleendBedrag,
        float $werkelijkeKosten,
        float $totaalVoorschotten,
    ): array {
        [$objectService, $register, $schema] = $this->resolve();

        $vastgesteld = $this->computeVastgesteldBedrag(verleendBedrag: $verleendBedrag, werkelijkeKosten: $werkelijkeKosten);
        $overpayment = $this->computeOverpayment(totaalVoorschotten: $totaalVoorschotten, vastgesteldBedrag: $vastgesteld);
        $trigger     = ($overpayment > 0.0);

        $patch = [
            'vastgesteldBedrag'                 => $vastgesteld,
            'triggerTerugvordering'             => $trigger,
            'vaststellingsbeschikkingGenerated' => true,
            'status'                            => 'vastgesteld',
        ];

        try {
            $current = $objectService->find($vaststellingId, register: $register, schema: $schema);
            if (is_array($current) === false) {
                throw new OCSBadRequestException('Vaststelling niet gevonden');
            }

            $saved = $objectService->saveObject(object: $patch, register: $register, schema: $schema, uuid: (string) $vaststellingId);
        } catch (OCSBadRequestException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Procest subsidie: vaststelling finalize failed: '.$e->getMessage());
            throw new OCSBadRequestException('Kon vaststelling niet vaststellen');
        }

        $clawback     = null;
        $uitvoeringId = (string) ($current['subsidieuitvoering'] ?? '');
        if ($trigger === true && $uitvoeringId !== '') {
            $clawback = $this->terugvordering->createClawbackCase(uitvoeringId: $uitvoeringId, bedrag: $overpayment);
        }

        // Subsidie-settlement-case-costs: auto-populate the linked case's
        // kosten with the settled amount. Best-effort — never fails the
        // vaststelling itself (no linked case, missing chain link, or a
        // transport error all degrade to a logged warning).
        $this->appendKostenToLinkedCase(uitvoeringId: $uitvoeringId, vaststellingId: $vaststellingId, bedrag: $vastgesteld);

        return [
            'vaststelling'   => $saved,
            'terugvordering' => $clawback,
        ];
    }//end finalize()

    /**
     * Append the settled amount to the case linked via
     * subsidieUitvoering -> subsidieAanvraag -> case, through the SAME
     * `ObjectService::saveObject()` write path every other case field
     * mutation in this app uses. Fail-soft on every branch: an unresolvable
     * link (no execution id, execution/application/case not found, no case
     * linked to the application) or any `Throwable` is logged and skipped —
     * this is enrichment, not a hard dependency of settling.
     *
     * Idempotent: a re-finalize of the same vaststelling is detected via
     * the `source` + `vaststellingId` markers already present on an
     * existing entry and skipped, so the amount is never duplicated.
     *
     * @param string $uitvoeringId   The execution id (may be empty).
     * @param string $vaststellingId The settlement id (idempotency key).
     * @param float  $bedrag         The settled amount to record.
     *
     * @return void
     */
    private function appendKostenToLinkedCase(string $uitvoeringId, string $vaststellingId, float $bedrag): void
    {
        if ($uitvoeringId === '' || $bedrag <= 0.0) {
            return;
        }

        try {
            $context = $this->resolveKostenContext();
            if ($context === null) {
                return;
            }

            [$objectService, $register, $uitvoeringSchema, $aanvraagSchema, $caseSchema] = $context;

            $caseId = $this->resolveLinkedCaseId(
                objectService: $objectService,
                register: $register,
                uitvoeringId: $uitvoeringId,
                uitvoeringSchema: $uitvoeringSchema,
                aanvraagSchema: $aanvraagSchema
            );
            if ($caseId === null) {
                return;
            }

            $case = $objectService->find($caseId, register: $register, schema: $caseSchema);
            if (is_array($case) === false) {
                return;
            }

            $kosten = $this->decodeKosten(raw: $case['kosten'] ?? null);
            if ($this->hasExistingEntry(kosten: $kosten, vaststellingId: $vaststellingId) === true) {
                // Already recorded for this vaststelling — do not duplicate.
                return;
            }

            $kosten[] = [
                'bedrag'         => round($bedrag, 2),
                'type'           => self::KOSTEN_TYPE_SUBSIDY_DISBURSEMENT,
                'datum'          => (new DateTimeImmutable())->format('Y-m-d'),
                'source'         => self::KOSTEN_SOURCE,
                'vaststellingId' => $vaststellingId,
            ];

            $objectService->saveObject(
                object: ['kosten' => json_encode($kosten)],
                register: $register,
                schema: $caseSchema,
                uuid: $caseId
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest subsidie: could not append vaststelling kosten to linked case: '.$e->getMessage()
            );
        }//end try
    }//end appendKostenToLinkedCase()

    /**
     * Resolve the ObjectService plus every register/schema id the kosten
     * append needs, or null when any is unavailable/unconfigured (the
     * fail-soft equivalent of {@see resolve()} — this path must never throw).
     *
     * @return array{0: object, 1: string, 2: string, 3: string, 4: string}|null
     */
    private function resolveKostenContext(): ?array
    {
        $objectService    = $this->settingsService->getObjectService();
        $register         = $this->settingsService->getConfigValue('register');
        $uitvoeringSchema = $this->settingsService->getConfigValue('subsidie_uitvoering_schema');
        $aanvraagSchema   = $this->settingsService->getConfigValue('subsidie_aanvraag_schema');
        $caseSchema       = $this->settingsService->getConfigValue('case_schema');
        if ($objectService === null || $register === '' || $uitvoeringSchema === '' || $aanvraagSchema === '' || $caseSchema === '') {
            return null;
        }

        return [$objectService, $register, $uitvoeringSchema, $aanvraagSchema, $caseSchema];
    }//end resolveKostenContext()

    /**
     * Walk subsidieUitvoering -> subsidieAanvraag -> case to resolve the
     * linked case id, or null when any hop is missing.
     *
     * @param object $objectService    Resolved ObjectService.
     * @param string $register         Register id.
     * @param string $uitvoeringId     The execution id.
     * @param string $uitvoeringSchema The subsidieUitvoering schema id.
     * @param string $aanvraagSchema   The subsidieAanvraag schema id.
     *
     * @return string|null The linked case id, or null when unresolvable.
     */
    private function resolveLinkedCaseId(
        object $objectService,
        string $register,
        string $uitvoeringId,
        string $uitvoeringSchema,
        string $aanvraagSchema
    ): ?string {
        $uitvoering = $objectService->find($uitvoeringId, register: $register, schema: $uitvoeringSchema);
        if (is_array($uitvoering) === false) {
            return null;
        }

        $aanvraagId = (string) ($uitvoering['subsidieaanvraag'] ?? '');
        if ($aanvraagId === '') {
            return null;
        }

        $aanvraag = $objectService->find($aanvraagId, register: $register, schema: $aanvraagSchema);
        if (is_array($aanvraag) === false) {
            return null;
        }

        $caseId = (string) ($aanvraag['case'] ?? '');
        if ($caseId === '') {
            return null;
        }

        return $caseId;
    }//end resolveLinkedCaseId()

    /**
     * Whether the decoded kosten list already carries an entry for this
     * vaststelling (idempotency guard).
     *
     * @param array<int, mixed> $kosten         Decoded kosten entries.
     * @param string            $vaststellingId The settlement id to look for.
     *
     * @return bool
     */
    private function hasExistingEntry(array $kosten, string $vaststellingId): bool
    {
        foreach ($kosten as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $source = (string) ($entry['source'] ?? '');
            $id     = (string) ($entry['vaststellingId'] ?? '');
            if ($source === self::KOSTEN_SOURCE && $id === $vaststellingId) {
                return true;
            }
        }

        return false;
    }//end hasExistingEntry()

    /**
     * Decode a case's raw `kosten` field (array or JSON-encoded string) into
     * a plain list, defaulting to an empty list for any other shape.
     * Mirrors {@see \OCA\Procest\Service\Iv3ReportService::decodeKosten()}.
     *
     * @param mixed $raw The raw `kosten` field value.
     *
     * @return array<int, mixed>
     */
    private function decodeKosten(mixed $raw): array
    {
        if (is_array($raw) === true) {
            return $raw;
        }

        if (is_string($raw) === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end decodeKosten()

    /**
     * Resolve the ObjectService and register/schema ids.
     *
     * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
     *
     * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
     */
    private function resolve(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new OCSBadRequestException('OpenRegister is niet beschikbaar');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('subsidie_vaststelling_schema');
        if ($register === '' || $schema === '') {
            throw new OCSBadRequestException('Vaststelling-schema is niet geconfigureerd');
        }

        return [$objectService, $register, $schema];
    }//end resolve()
}//end class
