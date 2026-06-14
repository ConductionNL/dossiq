<?php

/**
 * Procest Besluit Materialisation Service
 *
 * Materialises the ZGW Besluit artifact on a case from a decidesk Decision
 * outcome. decidesk owns the *making* of the decision; this service records
 * the ZGW Besluit for the zaak dossier (Besluiten API), preserving the
 * Besluiten-API field shape exactly.
 *
 * Mapping (REQ-PDCD-003):
 *  - decidesk result (verleend/geweigerd/aangehouden) → ZGW Besluit.result
 *  - decidesk decidedAt                               → ZGW Besluit.datum
 *  - decidesk motivering / advice                     → ZGW Besluit.toelichting
 *  - decidesk signer / mandaathouder + method         → audit fields on the Besluit
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Materialises the ZGW Besluit from a decidesk Decision outcome.
 *
 * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md#req-pdcd-003
 */
class BesluitMaterialisationService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings / ObjectService resolver.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Write (or update) the ZGW Besluit on a case from a decidesk outcome.
     *
     * Preserves the Besluiten-API shape exactly; only the *origin* of the
     * values changes (decidesk outcome rather than the local besluit engine).
     *
     * @param string              $caseId    The case UUID.
     * @param string              $besluitId The existing ZGW Besluit UUID on the case (or empty for new).
     * @param array<string,mixed> $outcome   Normalised outcome from ContractDecisionDelegationService::consumeOutcome().
     *
     * @return array<string,mixed> The persisted Besluit record.
     *
     * @throws RuntimeException When the case cannot be loaded or the Besluit cannot be persisted.
     *
     * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md#req-pdcd-003
     */
    public function materialise(string $caseId, string $besluitId, array $outcome): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available; cannot materialise Besluit');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('besluit_schema') ?: 'besluit';

        // Build the ZGW Besluit payload from the decidesk outcome (REQ-PDCD-003).
        $besluit = $this->buildBesluitPayload(caseId: $caseId, outcome: $outcome);

        // Merge with existing Besluit when updating (non-empty UUID), otherwise create.
        if ($besluitId !== '') {
            $besluit['uuid'] = $besluitId;
        }

        try {
            $saved = $objectService->saveObject(
                object: $besluit,
                register: $register,
                schema: $schema,
                uuid: $besluitId !== '' ? $besluitId : null,
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'BesluitMaterialisationService: persist failed',
                ['caseId' => $caseId, 'error' => $e->getMessage()]
            );
            throw new RuntimeException('Besluit persist failed: '.$e->getMessage(), 0, $e);
        }

        if (is_array($saved) === false) {
            $saved = ['caseId' => $caseId, 'result' => $outcome['result']];
        }

        return $saved;
    }//end materialise()

    /**
     * Build the Besluiten-API payload from a decidesk outcome.
     *
     * @param string              $caseId  The case UUID.
     * @param array<string,mixed> $outcome Normalised outcome (result, decidedAt, motivering, signer, method).
     *
     * @return array<string,mixed> The Besluit payload ready for saveObject.
     *
     * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md#req-pdcd-003
     */
    public function buildBesluitPayload(string $caseId, array $outcome): array
    {
        $result     = (string) ($outcome['result'] ?? '');
        $decidedAt  = (string) ($outcome['decidedAt'] ?? date('c'));
        $motivering = (string) ($outcome['motivering'] ?? '');
        $signer     = (string) ($outcome['signer'] ?? '');
        $method     = (string) ($outcome['method'] ?? '');

        // ZGW Besluiten-API shape — datum, result, toelichting are the canonical fields.
        return [
            'zaakRef'             => $caseId,
            'result'              => $result,
            'datum'               => $decidedAt,
            'toelichting'         => $motivering,
            // Audit fields: decision-origin provenance for the zaak dossier.
            'mandaathouder'       => $signer,
            'besluitMethode'      => $method,
            'besluitBron'         => 'decidesk',
        ];
    }//end buildBesluitPayload()
}//end class
