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
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
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
 * WHY `flowRun` AND `flowNode` ARE WRITTEN ONTO THE PARAAF. Resuming needs to
 * name the node, not just the run: a run holds one awaiting slot per node and
 * cannot say which of them a signal answers. `askPerson` records those two on
 * its task for exactly this reason; `parafeeractie` gained the same two fields
 * so a paraaf can resume the run that asked for it.
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
	 * @param SettingsService $settingsService Register/schema configuration.
	 * @param IL10N           $l10n            Translations.
	 * @param LoggerInterface $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
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
			$this->ensureParaaf(items: $items, config: $config, context: $context);

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
	 * Write the parafeeractie once, and remember that it exists.
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
	private function ensureParaaf(array $items, array $config, array $context): void {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if (($resume instanceof FlowNodeResumeState) === false) {
			// Without a slot there is nowhere to record that the paraaf exists,
			// so every heartbeat would raise another one against the same
			// person. A step that cannot be made idempotent must not run.
			throw new RuntimeException('dossiq.askParaaf requires a node resume slot');
		}

		if ($resume->has(key: 'parafeeractieId') === true) {
			return;
		}

		$paraafId = $this->persist(
			paraaf: [
				'proposal' => $this->proposalIdFrom(items: $items),
				'step' => (int)($config['step'] ?? 0),
				'actor' => trim((string)($config['actor'] ?? '')),
				'actorType' => trim((string)($config['actorType'] ?? 'user')),
				'flowRun' => (string)($context[FlowRunContext::CONTEXT_RUN] ?? ''),
				'flowNode' => $resume->nodeId(),
			]
		);

		$resume->merge(
			values: [
				'parafeeractieId' => $paraafId,
				'askedAt' => (new DateTime())->format('c'),
				'question' => trim((string)($config['question'] ?? '')),
				// Read back by OpenRegister's resume guard, which refuses a
				// signal from anyone but this actor or their group.
				'assignee' => trim((string)($config['actor'] ?? '')),
			]
		);

		$this->logger->info(
			'Dossiq askParaaf: raised parafeeractie ' . $paraafId . ' and suspended the run',
			['app' => 'dossiq']
		);

	}//end ensureParaaf()

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
	 * Store the parafeeractie and return its id.
	 *
	 * @param array<string, mixed> $paraaf The paraaf to persist.
	 *
	 * @return string The created id.
	 *
	 * @throws RuntimeException When storage is unavailable or unconfigured.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function persist(array $paraaf): string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('openregister_unavailable');
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('parafeeractie_schema');
		if ($schema === '') {
			throw new RuntimeException('parafeeractie_schema_not_configured');
		}

		$created = $objectService->saveObject(object: $paraaf, register: $register, schema: $schema);
		$stored = $created;
		if (is_object($created) === true && method_exists($created, 'getObject') === true) {
			$stored = $created->getObject();
		}

		$id = '';
		if (is_array($stored) === true) {
			$id = (string)($stored['id'] ?? ($stored['uuid'] ?? ''));
		}

		if ($id === '') {
			throw new RuntimeException('parafeeractie_not_identifiable');
		}

		return $id;

	}//end persist()

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
