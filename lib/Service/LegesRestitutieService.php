<?php

/**
 * Procest Leges Restitutie Service
 *
 * Handles refunds (restituties) of paid/invoiced leges when an application is
 * withdrawn. The refund percentage is determined by the phase the case had
 * reached (phase staffel), the refund amount is computed from the original
 * calculation, a legesRestitutie is persisted, and a credit invoice is
 * requested from shillinq.
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-006
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Phase-staffel refund logic for leges.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LegesRestitutieService
{
    /**
     * Statuses from which a refund may be granted.
     *
     * @var array<int, string>
     */
    private const REFUNDABLE_STATUSES = ['gefactureerd', 'betaald'];

    /**
     * Default phase staffel: phase key => refund percentage.
     *
     * @var array<string, int>
     */
    private const PHASE_STAFFEL = [
        'aanvraag'          => 100,
        'start_behandeling' => 75,
        'in_behandeling'    => 75,
        'beschikking'       => 0,
        'afgehandeld'       => 0,
    ];

    /**
     * Constructor.
     *
     * @param SettingsService      $settingsService Settings + ObjectService access.
     * @param LegesShillinqService $shillinqService Credit invoice creation.
     * @param LoggerInterface      $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LegesShillinqService $shillinqService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Map a phase key to a refund percentage.
     *
     * @param string $fase The phase key.
     *
     * @return int Percentage between 0 and 100.
     */
    public function applyRestitutieStaffel(string $fase): int
    {
        $key = strtolower(trim($fase));
        return (self::PHASE_STAFFEL[$key] ?? 0);
    }//end applyRestitutieStaffel()

    /**
     * Create a refund for a calculation and submit a credit invoice.
     *
     * @param string $berekeningId   The legesBerekening UUID.
     * @param string $reason         The refund reason enum.
     * @param string $fase           The phase the case had reached.
     * @param string $besluitNemerId The user granting the refund.
     *
     * @return array<string, mixed> The persisted restitutie payload.
     *
     * @throws RuntimeException When unconfigured, the calculation is not refundable, or persistence fails.
     */
    public function createRestitutie(string $berekeningId, string $reason, string $fase, string $besluitNemerId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register         = $this->settingsService->getConfigValue('register');
        $berekeningSchema = $this->settingsService->getConfigValue('leges_berekening_schema');
        $restitutieSchema = $this->settingsService->getConfigValue('leges_restitutie_schema');
        if ($register === '' || $berekeningSchema === '' || $restitutieSchema === '') {
            throw new RuntimeException('Leges schemas are not configured');
        }

        $berekening = $this->loadBerekening(objectService: $objectService, register: $register, schema: $berekeningSchema, id: $berekeningId);

        $status = (string) ($berekening['status'] ?? '');
        if (in_array($status, self::REFUNDABLE_STATUSES, true) === false) {
            throw new RuntimeException('Berekening status "'.$status.'" is not refundable');
        }

        $percentage = $this->applyRestitutieStaffel(fase: $fase);
        $original   = (int) ($berekening['bedragInclBtw'] ?? 0);
        $bedrag     = (int) round($original * ($percentage / 100.0));

        $payload = [
            'berekeningId'         => $berekeningId,
            'restitutieReden'      => $reason,
            'fase'                 => $fase,
            'restitutiePercentage' => $percentage,
            'restitutieBedrag'     => $bedrag,
            'besluitNemerId'       => $besluitNemerId,
            'besluitDatum'         => (new DateTimeImmutable())->format('Y-m-d'),
        ];

        $creditId = '';
        if ($bedrag > 0) {
            $creditId = $this->submitCreditRequest(restitutie: $payload, berekening: $berekening);
            if ($creditId !== '') {
                $payload['creditfactuurId'] = $creditId;
            }
        }

        $saved   = $objectService->saveObject(object: $payload, register: $register, schema: $restitutieSchema);
        $savedId = $this->extractId(result: $saved);

        // Reflect the refund on the calculation status.
        if ($bedrag > 0) {
            $this->markBerekeningGerestitueerd(
                objectService: $objectService,
                register: $register,
                schema: $berekeningSchema,
                berekening: $berekening,
                berekeningId: $berekeningId
            );
        }

        $this->logger->info(
            'Procest leges: restitutie created',
            ['restitutieId' => $savedId, 'percentage' => $percentage, 'bedrag' => $bedrag]
        );

        $payload['id'] = $savedId;
        return $payload;
    }//end createRestitutie()

    /**
     * Submit a credit invoice request to shillinq for a refund.
     *
     * @param array<string, mixed> $restitutie The refund payload.
     * @param array<string, mixed> $berekening The original calculation.
     *
     * @return string The credit invoice id (empty when shillinq disabled).
     */
    public function submitCreditRequest(array $restitutie, array $berekening): string
    {
        if ($this->shillinqService->isEnabled() === false) {
            $this->logger->debug('Procest leges: shillinq disabled, skipping credit invoice');
            return '';
        }

        $original = (string) ($berekening['factuurId'] ?? '');
        if ($original === '') {
            $this->logger->warning('Procest leges: no original factuurId, cannot create credit invoice');
            return '';
        }

        try {
            return $this->shillinqService->createCreditInvoice(restitutie: $restitutie, originalFactuurId: $original);
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges: credit invoice failed: '.$e->getMessage());
            return '';
        }
    }//end submitCreditRequest()

    /**
     * Load a calculation by id.
     *
     * @param object $objectService OpenRegister ObjectService.
     * @param string $register      Register id.
     * @param string $schema        Schema id.
     * @param string $id            Calculation UUID.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When not found.
     */
    private function loadBerekening(object $objectService, string $register, string $schema, string $id): array
    {
        try {
            $obj = $objectService->find($id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            throw new RuntimeException('Berekening not found: '.$id, 0, $e);
        }

        $row = $this->toArray(value: $obj);
        if ($row === []) {
            throw new RuntimeException('Berekening not found: '.$id);
        }

        return $row;
    }//end loadBerekening()

    /**
     * Mark a calculation as gerestitueerd.
     *
     * @param object               $objectService OpenRegister ObjectService.
     * @param string               $register      Register id.
     * @param string               $schema        Schema id.
     * @param array<string, mixed> $berekening    The calculation.
     * @param string               $berekeningId  Calculation UUID.
     *
     * @return void
     */
    private function markBerekeningGerestitueerd(
        object $objectService,
        string $register,
        string $schema,
        array $berekening,
        string $berekeningId,
    ): void {
        try {
            $berekening['status'] = 'gerestitueerd';
            $objectService->saveObject(object: $berekening, register: $register, schema: $schema, uuid: (string) $berekeningId);
        } catch (\Throwable $e) {
            $this->logger->warning('Procest leges: could not update berekening status: '.$e->getMessage());
        }
    }//end markBerekeningGerestitueerd()

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
