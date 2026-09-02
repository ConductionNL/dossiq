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
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Ask someone for a paraaf, and wait for it.
 *
 * WHY THIS IS NOT `dossiq.askPerson`. That node raises a generic TASK, and a
 * task cannot hold what a paraaf legally is. `parafeeractie` carries
 * `onBehalfOf` and `mandate` — who signed on whose behalf, under which mandate,
 * which is an administrative-law record and not a UI detail — plus `advice`,
 * `actorType` and `step`. A task has none of them.
 *
 * Projecting approval routes onto askPerson nodes and enabling them would
 * therefore have put generic tasks in approvers' queues, left the parafering
 * screens empty because they read `parafeeractie`, and stopped recording the
 * mandate chain. That is a loss of record dressed as an engine change, so the
 * flow gets a node that speaks the domain instead.
 *
 * 🔴 THE NODE DOES NOT CREATE THE PARAFEERACTIE. It records who is being
 * asked and waits.
 *
 * It used to create one up front, as a standing request. That cannot work and
 * never could: `parafeeractie` declares `action` among its required
 * properties, OpenRegister runs hard validation by default, and there is no
 * enum value meaning "not yet signed". A paraaf raised with no action is
 * rejected on save, so the node could not raise anything on a real instance —
 * the unit tests passed only because their fake accepted whatever it was
 * handed. openspec/specs/parafering-actions says the same thing from the other
 * side: the schema SHALL enforce voorstel, step, actor, action.
 *
 * Which is the model telling us what a parafeeractie IS. It is the record of a
 * sign-off somebody gave, not a request for one. Writing a blank one to stand
 * for "awaiting" would put an unsigned signature in an administrative-law
 * record, and no enum value should be invented to let it.
 *
 * So the request lives where a request belongs: in the run's own awaiting
 * slot, which already carries the assignee OpenRegister's resume guard
 * consults. The approver signs through the ordinary parafering surfaces, which
 * create the parafeeractie WITH its action, and the run is resumed from there.
 *
 * `flowRun` and `flowNode` stay on the schema, and stay necessary: a run holds
 * one awaiting slot per node and cannot say which of them a paraaf answers, so
 * whatever resumes the run has to name the node, not just the run.
 *
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 */
class DossiqAskParaafNode implements IFlowNode {

	/**
	 * How long to sleep between heartbeats while waiting for a paraaf.
	 *
	 * @var integer
	 */
	private const DEFAULT_HEARTBEAT_MINUTES = 15;

	/**
	 * Constructor.
	 *
	 * @param IL10N           $l10n   Translations.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The namespaced node id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function getId(): string {
		return 'dossiq.askParaaf';

	}//end getId()

	/**
	 * The node's display name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Ask for a paraaf');

	}//end getDisplayName()

	/**
	 * What the node does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Raise a parafeeractie against an actor and wait for their sign-off. Records who signed, '
			. 'on whose behalf and under which mandate.'
		);

	}//end getDescription()

	/**
	 * The node's icon.
	 *
	 * @return string The icon name.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function getIcon(): string {
		return 'SignatureFreehand';

	}//end getIcon()

	/**
	 * Whether the node is offered in the given scope.
	 *
	 * @param integer $scope The flow scope.
	 *
	 * @return boolean Always true.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The scope is part of
	 * IFlowNode's contract. A paraaf step is valid in every scope, so this node
	 * has nothing to ask of it, and dropping the parameter would break the
	 * interface.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return true;

	}//end isAvailableForScope()

	/**
	 * Refuse a configuration that cannot ask anybody anything.
	 *
	 * @param array<string, mixed> $config The node config.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no actor or no question is named.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['question'] ?? '')) === '') {
			throw new RuntimeException('dossiq.askParaaf needs a question');
		}

		if (trim((string)($config['actor'] ?? '')) === '') {
			// A sign-off with nobody to give it is not a step. Refusing here is
			// what stops a route projecting into a flow that waits forever.
			throw new RuntimeException('dossiq.askParaaf needs an actor');
		}

	}//end validateConfig()

	/**
	 * Raise the paraaf and suspend, or carry the answer forward.
	 *
	 * @param array<int, mixed>    $items   The input items.
	 * @param array<string, mixed> $config  The node config.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return array<int, mixed> The items, carrying the answer.
	 *
	 * @throws FlowSuspension While the paraaf is outstanding.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->validateConfig(config: $config);

		$signal = $this->answerFrom(context: $context);
		if ($signal === null) {
			$this->recordTheAsk(items: $items, config: $config, context: $context);

			throw new FlowSuspension(
				resumeAt: $this->heartbeatAt(config: $config),
				reason: sprintf('waiting for a paraaf: %s', trim((string)($config['question'] ?? '')))
			);
		}

		$key = trim((string)($config['signalKey'] ?? ''));
		if ($key === '') {
			$key = 'paraaf';
		}

		// Onto every item rather than into the run: the steps after this route
		// per item, and a switch cannot branch on something only the run holds.
		$out = [];
		foreach ($items as $item) {
			if (is_array($item) === true) {
				$json = (array)($item['json'] ?? []);
				$json[$key] = $signal;
				$item['json'] = $json;
			}

			$out[] = $item;
		}

		return $out;

	}//end execute()

	/**
	 * The signal, if one has arrived carrying a decision.
	 *
	 * A resume with no `decision` is a nudge, not an answer, so the node
	 * suspends again rather than treating a duplicate POST as a sign-off.
	 *
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return array<string, mixed>|null The signal.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function answerFrom(array $context): ?array {
		$signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
		if (is_array($signal) === false) {
			return null;
		}

		if (trim((string)($signal['decision'] ?? '')) === '') {
			return null;
		}

		return $signal;

	}//end answerFrom()

	/**
	 * Record who is being asked, so the run can say it and the guard can check it.
	 *
	 * This writes nothing outside the run. The parafeeractie is created by the
	 * person who signs, carrying the action they took; see the class docblock
	 * for why a blank one must not stand in for "awaiting".
	 *
	 * @param array<int, mixed>    $items   The input items.
	 * @param array<string, mixed> $config  The node config.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When there is no resume slot or no voorstel.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function recordTheAsk(array $items, array $config, array $context): void {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if (($resume instanceof FlowNodeResumeState) === false) {
			// The slot is where the assignee lives, and the assignee is what
			// OpenRegister's resume guard consults before letting a signal
			// through. A step that cannot record who may answer it must not
			// run: without it, anyone could sign for anyone.
			throw new RuntimeException('dossiq.askParaaf requires a node resume slot');
		}

		// Refuses a run with no voorstel here rather than later: a paraaf is a
		// sign-off ON something, and a step that cannot name what it is asking
		// about has nothing to ask.
		$proposalId = $this->proposalIdFrom(items: $items);

		if ($resume->has(key: 'askedAt') === true) {
			// Already asked. The heartbeat is a safety net for a lost signal,
			// not a reason to restate the question.
			return;
		}

		$resume->merge(
			values: [
				'proposal' => $proposalId,
				'step' => (int)($config['step'] ?? 0),
				'askedAt' => (new DateTime())->format('c'),
				'question' => trim((string)($config['question'] ?? '')),
				'actorType' => trim((string)($config['actorType'] ?? 'user')),
				// Read back by OpenRegister's resume guard, which refuses a
				// signal from anyone but this actor or their group.
				'assignee' => trim((string)($config['actor'] ?? '')),
			]
		);

		$this->logger->info(
			'Dossiq askParaaf: awaiting a paraaf on voorstel ' . $proposalId,
			['app' => 'dossiq', 'actor' => trim((string)($config['actor'] ?? ''))]
		);

	}//end recordTheAsk()

	/**
	 * The voorstel the paraaf belongs to.
	 *
	 * @param array<int, mixed> $items The input items.
	 *
	 * @return string The voorstel id.
	 *
	 * @throws RuntimeException When the run carries no voorstel.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function proposalIdFrom(array $items): string {
		$first = ($items[0] ?? null);
		$subject = [];
		if (is_array($first) === true) {
			$subject = (array)($first['json'] ?? []);
		}

		$id = (string)($subject['id'] ?? ($subject['uuid'] ?? ''));
		if ($id === '') {
			throw new RuntimeException('dossiq.askParaaf had no voorstel to attach a paraaf to');
		}

		return $id;

	}//end proposalIdFrom()

	/**
	 * When to wake and re-ask whether the paraaf has arrived.
	 *
	 * The heartbeat is the safety net: a delivered signal wakes the run in one
	 * worker pass, a lost one costs a heartbeat instead of costing the flow.
	 *
	 * @param array<string, mixed> $config The node config.
	 *
	 * @return DateTime When to wake.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function heartbeatAt(array $config): DateTime {
		$minutes = (int)($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
		if ($minutes < 1) {
			$minutes = self::DEFAULT_HEARTBEAT_MINUTES;
		}

		return (new DateTime())->modify('+' . $minutes . ' minutes');

	}//end heartbeatAt()

}//end class
