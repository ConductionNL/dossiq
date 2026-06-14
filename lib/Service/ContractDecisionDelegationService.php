<?php

/**
 * Procest Contract Decision Delegation Service
 *
 * Delegates contract approval / renewal / besluit decisions to decidesk via
 * the OpenRegister ADR-019 integration registry. procest keeps ZGW case
 * management; decidesk owns the deciding. This service:
 *
 * - Raises a decidesk Decision for any contract approval/renewal/sign-off.
 * - Consumes the decidesk Decision outcome.
 * - FAILS CLOSED when the decidesk leaf is unavailable (never auto-approves).
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

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises and consumes decidesk Decisions for contract / besluit decisions.
 *
 * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md
 */
class ContractDecisionDelegationService
{
    /**
     * The decidesk integration leaf identifier in the OR registry (ADR-019).
     */
    public const DECIDESK_INTEGRATION_LEAF = 'decidesk';

    /**
     * Decision types supported by the contract delegation surface.
     */
    public const DECISION_TYPE_CONTRACT_RENEWAL   = 'contract-renewal';
    public const DECISION_TYPE_REPORT_ADOPTION    = 'report-adoption';
    public const DECISION_TYPE_BEZWAAR            = 'bezwaar-beslissing';

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager App manager.
     * @param ContainerInterface $container  Service container.
     * @param LoggerInterface    $logger     Logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Raise a decidesk Decision for a contract approval / renewal / sign-off.
     *
     * Resolves the `decidesk` integration leaf from the OR integration registry
     * (ADR-019) and creates a decidesk Decision. FAILS CLOSED: when the leaf is
     * unavailable or the request fails this method throws — it never silently
     * returns a null / auto-approves (mirrors hydra-gate-unsafe-auth-resolver).
     *
     * @param string              $caseRef        The ZGW case reference (UUID) that owns this decision.
     * @param string              $contractRef     The contract object UUID.
     * @param string              $decisionType    Decision type slug (e.g. self::DECISION_TYPE_CONTRACT_RENEWAL).
     * @param array<string,mixed> $subject         Subject fields: sourceApp, subjectRegister, subjectSchema, subjectId, subjectLabel.
     * @param array<string,mixed> $mandateContext  Mandate context: requestedBy, mandateRole, mandateScope.
     *
     * @return string The decidesk decisionRef (UUID) to persist on the case.
     *
     * @throws RuntimeException When the decidesk leaf is unavailable or the Decision could not be created.
     *
     * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md#req-pdcd-001
     * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md#req-pdcd-002
     */
    public function raiseContractDecision(
        string $caseRef,
        string $contractRef,
        string $decisionType,
        array $subject,
        array $mandateContext,
    ): string {
        $integrationService = $this->resolveIntegrationService();

        // REQ-PDCD-002: fail closed — when the leaf is unavailable surface a
        // clear error; NEVER fall back to a procest-local auto-approve.
        if ($integrationService === null) {
            $this->logger->error(
                'ContractDecisionDelegationService: decidesk integration leaf unavailable; failing closed',
                ['caseRef' => $caseRef, 'contractRef' => $contractRef, 'decisionType' => $decisionType]
            );
            throw new RuntimeException('Decision service unavailable: decidesk integration leaf not registered or unavailable. Contract decision cannot proceed.');
        }

        $payload = [
            'decisionType'        => $decisionType,
            'sourceApp'           => 'procest',
            'subjectRegister'     => (string) ($subject['subjectRegister'] ?? ''),
            'subjectSchema'       => (string) ($subject['subjectSchema'] ?? ''),
            'subjectId'           => (string) ($subject['subjectId'] ?? $contractRef),
            'subjectLabel'        => (string) ($subject['subjectLabel'] ?? ''),
            'externalReference'   => $caseRef,
            'outcomeCallbackUrl'  => '',
            'mandateContext'      => $mandateContext,
        ];

        try {
            $result = $integrationService->createDecision(payload: $payload);
        } catch (Throwable $e) {
            $this->logger->error(
                'ContractDecisionDelegationService: createDecision failed',
                ['caseRef' => $caseRef, 'error' => $e->getMessage()]
            );
            // REQ-PDCD-002: re-throw to fail closed; caller must not proceed.
            throw new RuntimeException('Decision service error: '.$e->getMessage(), 0, $e);
        }

        $decisionRef = (string) ($result['id'] ?? $result['uuid'] ?? '');
        if ($decisionRef === '') {
            throw new RuntimeException('Decision service returned an empty decisionRef; failing closed.');
        }

        $this->logger->info(
            'ContractDecisionDelegationService: decidesk Decision raised',
            ['caseRef' => $caseRef, 'contractRef' => $contractRef, 'decisionRef' => $decisionRef]
        );

        return $decisionRef;
    }//end raiseContractDecision()

    /**
     * Consume the outcome of a decidesk Decision.
     *
     * Reads the decided Decision (result, decidedAt, motivering,
     * signer/mandaathouder, method) from decidesk via the integration registry.
     * Returns a normalised outcome array for use by BesluitMaterialisationService.
     *
     * @param string $decisionRef The decidesk Decision UUID.
     *
     * @return array{result:string, decidedAt:string, motivering:string, signer:string, method:string, raw:array<string,mixed>}
     *
     * @throws RuntimeException When the decidesk leaf is unavailable or the outcome cannot be read.
     *
     * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md#req-pdcd-003
     */
    public function consumeOutcome(string $decisionRef): array
    {
        $integrationService = $this->resolveIntegrationService();

        if ($integrationService === null) {
            throw new RuntimeException('Decision service unavailable: cannot consume outcome for '.$decisionRef);
        }

        try {
            $raw = $integrationService->getDecisionOutcome(decisionRef: $decisionRef);
        } catch (Throwable $e) {
            throw new RuntimeException('Decision service error consuming outcome: '.$e->getMessage(), 0, $e);
        }

        if (is_array($raw) === false) {
            throw new RuntimeException('Decision service returned non-array outcome for '.$decisionRef);
        }

        return [
            'result'     => (string) ($raw['result'] ?? ''),
            'decidedAt'  => (string) ($raw['decidedAt'] ?? $raw['decided_at'] ?? ''),
            'motivering' => (string) ($raw['motivering'] ?? $raw['motivation'] ?? ''),
            'signer'     => (string) ($raw['signer'] ?? $raw['mandaathouder'] ?? ''),
            'method'     => (string) ($raw['method'] ?? ''),
            'raw'        => $raw,
        ];
    }//end consumeOutcome()

    /**
     * Resolve the decidesk integration service from the OR registry (ADR-019).
     *
     * Returns null when OpenRegister is not installed or the decidesk leaf is
     * not configured — the caller MUST treat null as "fail closed".
     *
     * @return object|null The resolved integration leaf service, or null.
     */
    private function resolveIntegrationService(): ?object
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return null;
        }

        try {
            // ADR-019: resolve the integration registry and look up the decidesk leaf.
            $registry = $this->container->get('OCA\\OpenRegister\\Service\\IntegrationService');
            return $registry->getLeaf(name: self::DECIDESK_INTEGRATION_LEAF);
        } catch (Throwable $e) {
            $this->logger->warning(
                'ContractDecisionDelegationService: could not resolve decidesk integration leaf',
                ['error' => $e->getMessage()]
            );
            return null;
        }
    }//end resolveIntegrationService()
}//end class
