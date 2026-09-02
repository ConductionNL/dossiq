<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use DateTime;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Ask decidiq for a decision, and wait for it.
 *
 * DECISIONS ARE DECIDIQ'S. This node raises one and suspends; it does not
 * decide, and it does not project an outcome of its own. What comes back is
 * whatever decidiq concluded, delivered by its `DecisionConcludedEvent` and
 * routed to this run by {@see \OCA\Dossiq\Listener\DecisionConcludedListener}.
 *
 * 🔴 IT FAILS CLOSED. When decidiq is unavailable the step FAILS and the run
 * stops at the decision. The alternative — carry on and let a later step assume
 * approval — produces a case decided by nobody, which is the one outcome a
 * decision step exists to prevent. The delegation service already fails closed
 * for exactly this reason; this node must not soften it by catching.
 *
 * THE REF IS THE CORRELATION KEY. The `decisionRef` decidiq returns is written
 * into this node's resume slot, and the listener matches on it. Matching on the
 * CASE instead would wake the run whenever any of that case's decisions
 * concluded — and a case has several in its life, so the run would advance on
 * an answer to a different question.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */
class DossiqRequestDecisionNode implements IFlowNode {

    /**
     * Minutes between heartbeats when the config names none.
     *
     * Longer than the task node's: a decision involves a person being convened,
     * not a form being filled, so a lost-signal safety net that fires every
     * half hour is noise.
     *
     * @var integer
     */
    private const DEFAULT_HEARTBEAT_MINUTES = 120;

    /**
     * The shortest heartbeat this node will honour.
     *
     * @var integer
     */
    private const MIN_HEARTBEAT_MINUTES = 15;


    /**
     * Constructor.
     *
     * @param ContractDecisionDelegationService $delegation Raises the decision in decidiq.
     * @param IL10N                             $l10n       The localisation service.
     * @param LoggerInterface                   $logger     The logger.
     *
     * @return void
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function __construct(
        private readonly ContractDecisionDelegationService $delegation,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * This node's catalogue id.
     *
     * @return string The namespaced node id.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getId(): string {
        return 'dossiq.requestDecision';

    }//end getId()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Request a decision');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getDescription(): string {
        return $this->l10n->t('Ask Decidiq to decide, and pause the case until it has.');

    }//end getDescription()


    /**
     * The node's icon.
     *
     * @return string The icon name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getIcon(): string {
        return 'gavel';

    }//end getIcon()


    /**
     * Where this node may be offered.
     *
     * @param integer $scope The Nextcloud workflow scope.
     *
     * @return boolean True when available in this scope.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function isAvailableForScope(int $scope): bool {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()


    /**
     * Refuse a decision request with no question.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When the question is missing.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function validateConfig(array $config): void {
        if (trim((string) ($config['question'] ?? '')) === '') {
            throw new UnexpectedValueException(
                $this->l10n->t('Say what is being decided, or the decision cannot be presented.')
            );
        }

    }//end validateConfig()


    /**
     * Raise the decision on the first pass; pass the outcome on when it arrives.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items, each carrying the outcome.
     *
     * @throws FlowSuspension While the decision is outstanding.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function execute(array $items, array $config, array $context): array {
        $this->validateConfig(config: $config);

        $outcome = $this->outcomeFrom(context: $context);

        if ($outcome === null) {
            $this->ensureDecision(items: $items, config: $config, context: $context);

            throw new FlowSuspension(
                resumeAt: $this->heartbeatAt(config: $config),
                reason: sprintf(
                    'waiting for a decision: %s',
                    trim((string) ($config['question'] ?? 'a decision'))
                )
            );
        }

        $key = trim((string) ($config['signalKey'] ?? ''));
        if ($key === '') {
            $key = 'decisionOutcome';
        }

        $out = [];
        foreach ($items as $item) {
            if (is_array($item) === true) {
                $json         = (array) ($item['json'] ?? []);
                $json[$key]   = $outcome;
                $item['json'] = $json;
            }

            $out[] = $item;
        }

        return $out;

    }//end execute()


    /**
     * Raise the decision once, and remember its ref.
     *
     * @param array $items   The input items; the first carries the case.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return void
     */
    private function ensureDecision(array $items, array $config, array $context): void {
        $resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
        if (($resume instanceof FlowNodeResumeState) === false) {
            // No slot means no way to record the ref, so every heartbeat would
            // raise ANOTHER decision in decidiq — convening people repeatedly
            // for a question already asked.
            throw new RuntimeException('dossiq.requestDecision requires a node resume slot');
        }

        if ($resume->has(key: 'decisionRef') === true) {
            return;
        }

        $case  = [];
        $first = ($items[0] ?? null);
        if (is_array($first) === true) {
            $case = (array) ($first['json'] ?? []);
        }

        $caseId = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
        if ($caseId === '') {
            throw new RuntimeException('dossiq.requestDecision had no case to decide on');
        }

        $decisionType = trim((string) ($config['decisionType'] ?? ''));
        if ($decisionType === '') {
            $decisionType = ContractDecisionDelegationService::DECISION_TYPE_ADVICE;
        }

        try {
            // The raise runs under the flow run's `runAs` identity: the
            // engine's RegistryStepDispatcher executes every contributed node
            // inside `ObjectService::runAs()` (openregister#3332), so the
            // whole dispatch — including decidiq's synchronous listener write
            // through the object store — is scoped to the run's acting user
            // without a local wrap.
            $ref = $this->delegation->raiseDecision(
                decisionType: $decisionType,
                externalReference: $caseId,
                subject: [
                    'subjectRegister' => 'dossiq',
                    'subjectSchema'   => 'case',
                    'subjectId'       => $caseId,
                    'subjectLabel'    => (string) ($case['title'] ?? ''),
                ],
                context: [
                    'question' => trim((string) ($config['question'] ?? '')),
                    'advisor'  => trim((string) ($config['advisor'] ?? '')),
                ],
            );
        } catch (Throwable $e) {
            // NOT swallowed. The delegation fails closed when decidiq is
            // absent, and softening that here would let the run proceed past a
            // decision nobody made. Re-thrown so the step fails and the run
            // stops on it.
            $this->logger->error(
                'Dossiq requestDecision: could not raise the decision; the run stops here',
                ['case' => $caseId, 'error' => $e->getMessage()]
            );

            throw new RuntimeException('decision_could_not_be_raised: ' . $e->getMessage(), 0, $e);
        }//end try

        if (trim($ref) === '') {
            throw new RuntimeException('decision_raised_without_a_reference');
        }

        $resume->merge(
            values: [
                'decisionRef' => $ref,
                'askedAt'     => (new DateTime())->format('c'),
                'question'    => trim((string) ($config['question'] ?? '')),
            ]
        );

        $this->logger->info(
            'Dossiq requestDecision: raised decision ' . $ref . ' and suspended the run',
            ['case' => $caseId, 'node' => $resume->nodeId()]
        );

    }//end ensureDecision()


    /**
     * The outcome carried by a resume, or null when none has arrived.
     *
     * @param array $context Run-level metadata.
     *
     * @return array|null The signal payload, or null.
     */
    private function outcomeFrom(array $context): ?array {
        $signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
        if (is_array($signal) === false) {
            return null;
        }

        if (trim((string) ($signal['decision'] ?? '')) === '') {
            return null;
        }

        return $signal;

    }//end outcomeFrom()


    /**
     * When to wake and re-check, absent an outcome.
     *
     * @param array $config The step configuration.
     *
     * @return DateTime The next heartbeat.
     */
    private function heartbeatAt(array $config): DateTime {
        $minutes = (int) ($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
        if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
            $minutes = self::MIN_HEARTBEAT_MINUTES;
        }

        return (new DateTime())->modify('+' . $minutes . ' minutes');

    }//end heartbeatAt()


}//end class
