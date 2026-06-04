<?php

/**
 * Procest Vaststelling Service.
 *
 * Final-settlement (vaststelling) handling under AWB 4:46. Owns the
 * settlement-form math: comparing werkelijke kosten against the granted
 * amount, the accountantsverklaring requirement check, final-bedrag
 * calculation, overpayment detection, and the automatic terugvordering
 * trigger (REQ-SUB-005). The math is pure and unit-tested; finalisation
 * delegates clawback-case creation to TerugvorderingService.
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

            $saved = $objectService->saveObject($register, $schema, $patch, $vaststellingId);
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

        return [
            'vaststelling'   => $saved,
            'terugvordering' => $clawback,
        ];
    }//end finalize()

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
