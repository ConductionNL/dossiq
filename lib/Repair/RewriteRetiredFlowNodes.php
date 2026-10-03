<?php

/**
 * Rewrites stored dossiq flows that still name a retired node type.
 *
 * Removing a node from the catalogue does not remove it from the flows people
 * saved. This step walks every flow dossiq owns and applies
 * {@see \OCA\Dossiq\Service\Flow\RetiredNodeMap}: a step with a replacement is
 * renamed or translated into the steps that replace it, a step without one is
 * removed and its edges are bridged, and a step whose configuration cannot be
 * carried over faithfully is left in place and logged as a warning. Every
 * change is logged with the flow and the step it touched.
 *
 * A PUBLISHED FLOW IS REWRITTEN THROUGH A NEW VERSION. OpenRegister runs the
 * published version's graph, not the flow's head, and refuses a definition
 * change on anything but a draft. So a published flow gets a draft, the draft
 * is rewritten, and the draft is published. A flow that is not published only
 * has its head rewritten, which is what a later publish will carry.
 *
 * It also reports stored `automaticAction` records of a retired action type.
 * Those are data somebody entered, so they are logged and left alone.
 *
 * Idempotent: a flow with no retired step is not touched, so a second run
 * changes nothing. A step left in place is reported again on every run, which
 * is the point: it still needs a person.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeRewriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the retired-node table to stored flows and reports retired actions.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
class RewriteRetiredFlowNodes implements IRepairStep {

	use SearchesObjects;

	/**
	 * OpenRegister's flow mapper, resolved by name.
	 *
	 * @var string
	 */
	private const FLOW_MAPPER = 'OCA\\OpenRegister\\Db\\FlowMapper';

	/**
	 * OpenRegister's flow version service, resolved by name.
	 *
	 * @var string
	 */
	private const VERSION_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowVersionService';

	/**
	 * The lifecycle state whose graph the engine runs.
	 *
	 * @var string
	 */
	private const PUBLISHED = 'published';

	/**
	 * Flows read per page.
	 *
	 * @var integer
	 */
	private const PAGE = 100;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface  $container       Resolves OpenRegister's flow classes, which may be absent.
	 * @param SettingsService     $settingsService Register, schema and object service.
	 * @param RetiredNodeMap      $map             The retired-node table.
	 * @param RetiredNodeRewriter $rewriter        Applies the table to one graph.
	 * @param LoggerInterface     $logger          Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly RetiredNodeMap $map,
		private readonly RetiredNodeRewriter $rewriter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function getName(): string {
		return 'Rewrite Dossiq flows that still use a retired step type';
	}//end getName()

	/**
	 * Rewrite the flows, then report the retired actions.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function run(IOutput $output): void {
		$this->rewriteFlows(output: $output);
		$this->reportRetiredActions(output: $output);
	}//end run()

	/**
	 * Walk every flow dossiq owns and rewrite the ones that name a retired type.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 */
	private function rewriteFlows(IOutput $output): void {
		try {
			$mapper = $this->container->get(self::FLOW_MAPPER);
			$versions = $this->container->get(self::VERSION_SERVICE);
		} catch (Throwable $e) {
			$output->info('Dossiq: OpenRegister flows are not available; no stored flow to rewrite.');
			return;
		}

		$rewritten = 0;
		$offset = 0;
		do {
			try {
				$page = (array)$mapper->findAllFlows(app: Application::APP_ID, limit: self::PAGE, offset: $offset);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: could not read the stored flows to rewrite retired steps',
					['app' => Application::APP_ID, 'exception' => $e->getMessage()]
				);
				return;
			}

			foreach ($page as $flow) {
				if (is_object($flow) === true && $this->rewriteFlow(flow: $flow, mapper: $mapper, versions: $versions, output: $output) === true) {
					$rewritten++;
				}
			}

			$offset += self::PAGE;
			$full = (count($page) === self::PAGE);
		} while ($full === true);

		$output->info('Dossiq: rewrote ' . $rewritten . ' flow(s) that used a retired step type.');
	}//end rewriteFlows()

	/**
	 * Rewrite one flow, when it names a retired type.
	 *
	 * @param object  $flow     The stored flow.
	 * @param object  $mapper   OpenRegister's FlowMapper.
	 * @param object  $versions OpenRegister's FlowVersionService.
	 * @param IOutput $output   Output sink.
	 *
	 * @return boolean Whether the flow was rewritten.
	 */
	private function rewriteFlow(object $flow, object $mapper, object $versions, IOutput $output): bool {
		$result = $this->rewriter->rewrite(
			nodes: (array)($flow->getNodes() ?? []),
			edges: (array)($flow->getEdges() ?? [])
		);
		if ($result['changes'] === []) {
			return false;
		}

		$label = '"' . (string)$flow->getName() . '" (' . (string)$flow->getUuid() . ')';
		foreach ($result['changes'] as $change) {
			$this->report(change: $change, label: $label, output: $output);
		}

		// A flow whose only retired steps could not be carried over has
		// nothing to save: the graph is what it was, and the warnings above
		// are the whole outcome. Saving it anyway would mint a version that
		// differs from the last in nothing but its number.
		$unmappable = array_filter($result['changes'], static fn (array $change): bool => $change['outcome'] === 'unmappable');
		if (count($unmappable) === count($result['changes'])) {
			return false;
		}

		try {
			$published = ((string)$flow->getLifecycleStatus() === self::PUBLISHED);
			if ($published === true) {
				$versions->createDraft($flow);
			}

			$flow->setNodes($result['nodes']);
			$flow->setEdges($result['edges']);
			$mapper->update($flow);

			if ($published === true) {
				$versions->publish($flow);
			}
		} catch (Throwable $e) {
			$message = 'Dossiq: could not save the rewritten flow ' . $label . '; edit it in the flow editor. ' . $e->getMessage();
			$output->warning($message);
			$this->logger->warning($message, ['app' => Application::APP_ID, 'flow' => (string)$flow->getUuid()]);
			return false;
		}

		return true;
	}//end rewriteFlow()

	/**
	 * Say what happened to one step, in the output and in the log.
	 *
	 * @param array{step: string, type: string, outcome: string, replacement: string|null, reason: string, orphaned: bool} $change The change row.
	 * @param string  $label  The flow's name and uuid.
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 */
	private function report(array $change, string $label, IOutput $output): void {
		$context = ['app' => Application::APP_ID, 'flow' => $label, 'step' => $change['step'], 'type' => $change['type']];

		if ($change['outcome'] === 'unmappable') {
			$message = 'Dossiq: flow ' . $label . ', step "' . $change['step'] . '" could not be carried over: '
				. $change['type'] . ' is retired (' . $change['reason'] . '). Rebuild it in the flow editor; until then the run stops at it.';
			$output->warning($message);
			$this->logger->warning($message, $context);
			return;
		}

		if ($change['outcome'] === 'replaced') {
			$message = 'Dossiq: flow ' . $label . ', step "' . $change['step'] . '": ' . $change['type']
				. ' is now ' . (string)$change['replacement'] . ' (' . $change['reason'] . ').';
			$output->info($message);
			$this->logger->info($message, $context);
			return;
		}

		$message = 'Dossiq: flow ' . $label . ', step "' . $change['step'] . '" removed: ' . $change['type']
			. ' is retired and has no replacement (' . $change['reason'] . ').';
		if ($change['orphaned'] === true) {
			$message .= ' The step had no next step, so the steps before it now end there; check the flow.';
		}

		$output->warning($message);
		$this->logger->warning($message, $context);
	}//end report()

	/**
	 * Log every stored `automaticAction` of a retired type, and change none of them.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 */
	private function reportRetiredActions(IOutput $output): void {
		$types = $this->map->retiredActionTypes();
		if ($types === [] || $this->settingsService->isOpenRegisterAvailable() === false) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'automatic_action_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		foreach ($types as $type => $reason) {
			try {
				$rows = $this->runAsSystemIfAvailable(
					objectService: $objectService,
					operation: fn (): array => $this->searchObjectsAsArraysUnscoped(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						filters: ['type' => $type, '_limit' => 1000]
					)
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: could not read the stored automatic actions of a retired type',
					['app' => Application::APP_ID, 'type' => $type, 'exception' => $e->getMessage()]
				);
				continue;
			}

			foreach ((array)$rows as $row) {
				$identifier = (string)($row['slug'] ?? ($row['id'] ?? ($row['uuid'] ?? '?')));
				$message = 'Dossiq: automatic action "' . $identifier . '" has type ' . $type
					. ', which is retired (' . $reason . '). It is kept, but it will not run.';
				$output->warning($message);
				$this->logger->warning($message, ['app' => Application::APP_ID, 'action' => $identifier]);
			}
		}//end foreach
	}//end reportRetiredActions()
}//end class
