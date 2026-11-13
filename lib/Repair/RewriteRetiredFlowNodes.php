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

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\RetiredNodeRewriterInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
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
	 * @param SettingsServiceInterface     $settingsService Register, schema and object service.
	 * @param RetiredNodeMap      $map             The retired-node table.
	 * @param RetiredNodeRewriterInterface $rewriter        Applies the table to one graph.
	 * @param LoggerInterface     $logger          Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsServiceInterface $settingsService,
		private readonly RetiredNodeMap $map,
		private readonly RetiredNodeRewriterInterface $rewriter,
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
		$run = $this->flowRewriteRun(output: $output);
		if ($run === null) {
			return;
		}

		$rewritten = $this->rewriteStoredFlows(run: $run);
		if ($rewritten !== null) {
			$output->info('Dossiq: rewrote ' . $rewritten . ' flow(s) that used a retired step type.');
		}
	}//end rewriteFlows()

	/**
	 * OpenRegister's flow classes for this run, or null when they are not there.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return FlowRewriteRun|null The run.
	 */
	private function flowRewriteRun(IOutput $output): ?FlowRewriteRun {
		try {
			$mapper = $this->container->get(self::FLOW_MAPPER);
			$versions = $this->container->get(self::VERSION_SERVICE);
		} catch (Throwable $e) {
			$output->info('Dossiq: OpenRegister flows are not available; no stored flow to rewrite.');
			return null;
		}

		return new FlowRewriteRun(mapper: $mapper, versions: $versions, output: $output);
	}//end flowRewriteRun()

	/**
	 * Rewrite the flows page by page; a page that cannot be read stops the walk.
	 *
	 * @param FlowRewriteRun $run The run.
	 *
	 * @return int|null How many flows were rewritten, or null when a page could not be read.
	 */
	private function rewriteStoredFlows(FlowRewriteRun $run): ?int {
		$rewritten = 0;
		$offset = 0;
		do {
			$page = $this->flowPage(run: $run, offset: $offset);
			if ($page === null) {
				return null;
			}

			$rewritten += $this->rewritePage(run: $run, page: $page);
			$offset += self::PAGE;
			$full = (count($page) === self::PAGE);
		} while ($full === true);

		return $rewritten;
	}//end rewriteStoredFlows()

	/**
	 * One page of the flows dossiq owns, or null when it cannot be read.
	 *
	 * @param FlowRewriteRun $run    The run.
	 * @param int            $offset Where the page starts.
	 *
	 * @return array<int, mixed>|null The page.
	 */
	private function flowPage(FlowRewriteRun $run, int $offset): ?array {
		try {
			return (array)$run->mapper->findAllFlows(app: AppIdentity::APP_ID, limit: self::PAGE, offset: $offset);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read the stored flows to rewrite retired steps',
				['app' => AppIdentity::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end flowPage()

	/**
	 * Rewrite the flows on one page.
	 *
	 * @param FlowRewriteRun    $run  The run.
	 * @param array<int, mixed> $page The page.
	 *
	 * @return int How many of them were rewritten.
	 */
	private function rewritePage(FlowRewriteRun $run, array $page): int {
		$rewritten = 0;
		foreach ($page as $flow) {
			if (is_object($flow) === true && $this->rewriteFlow(flow: $flow, run: $run) === true) {
				$rewritten++;
			}
		}

		return $rewritten;
	}//end rewritePage()

	/**
	 * Rewrite one flow, when it names a retired type.
	 *
	 * @param object         $flow The stored flow.
	 * @param FlowRewriteRun $run  The run.
	 *
	 * @return boolean Whether the flow was rewritten.
	 */
	private function rewriteFlow(object $flow, FlowRewriteRun $run): bool {
		$rewrite = $this->rewriteOf(flow: $flow);
		if ($rewrite === null) {
			return false;
		}

		foreach ($rewrite->changes as $change) {
			$this->report(change: $change, label: $rewrite->label(), output: $run->output);
		}

		// A flow whose only retired steps could not be carried over has
		// nothing to save: the graph is what it was, and the warnings above
		// are the whole outcome. Saving it anyway would mint a version that
		// differs from the last in nothing but its number.
		if ($rewrite->savesNothing() === true) {
			return false;
		}

		return $this->saveRewrite(rewrite: $rewrite, run: $run);
	}//end rewriteFlow()

	/**
	 * What the rewrite makes of a flow, or null when it names no retired type.
	 *
	 * @param object $flow The stored flow.
	 *
	 * @return FlowRewrite|null The rewrite.
	 */
	private function rewriteOf(object $flow): ?FlowRewrite {
		$result = $this->rewriter->rewrite(
			nodes: (array)($flow->getNodes() ?? []),
			edges: (array)($flow->getEdges() ?? [])
		);
		if ($result['changes'] === []) {
			return null;
		}

		return new FlowRewrite(flow: $flow, nodes: $result['nodes'], edges: $result['edges'], changes: $result['changes']);
	}//end rewriteOf()

	/**
	 * Save a rewritten flow; a save that fails is reported and the walk goes on.
	 *
	 * @param FlowRewrite    $rewrite The rewrite.
	 * @param FlowRewriteRun $run     The run.
	 *
	 * @return boolean Whether the flow was saved.
	 */
	private function saveRewrite(FlowRewrite $rewrite, FlowRewriteRun $run): bool {
		try {
			$this->storeRewrite(rewrite: $rewrite, run: $run);
		} catch (Throwable $e) {
			$message = 'Dossiq: could not save the rewritten flow ' . $rewrite->label() . '; edit it in the flow editor. ' . $e->getMessage();
			$run->output->warning($message);
			$this->logger->warning($message, ['app' => AppIdentity::APP_ID, 'flow' => (string)$rewrite->flow->getUuid()]);
			return false;
		}

		return true;
	}//end saveRewrite()

	/**
	 * Write the rewritten graph: through a new version for a published flow, in place otherwise.
	 *
	 * @param FlowRewrite    $rewrite The rewrite.
	 * @param FlowRewriteRun $run     The run.
	 *
	 * @return void
	 */
	private function storeRewrite(FlowRewrite $rewrite, FlowRewriteRun $run): void {
		$flow = $rewrite->flow;
		$published = ((string)$flow->getLifecycleStatus() === self::PUBLISHED);
		if ($published === true) {
			$run->versions->createDraft($flow);
		}

		$flow->setNodes($rewrite->nodes);
		$flow->setEdges($rewrite->edges);
		$run->mapper->update($flow);

		if ($published === true) {
			$run->versions->publish($flow);
		}
	}//end storeRewrite()

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
		$context = ['app' => AppIdentity::APP_ID, 'flow' => $label, 'step' => $change['step'], 'type' => $change['type']];

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

		$this->retiredActionReport(output: $output)?->report(types: $types);
	}//end reportRetiredActions()

	/**
	 * The report over the stored automatic actions, or null when they cannot be read.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return RetiredActionReport|null The report.
	 */
	private function retiredActionReport(IOutput $output): ?RetiredActionReport {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'automatic_action_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return new RetiredActionReport(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			output: $output,
			logger: $this->logger,
		);
	}//end retiredActionReport()
}//end class
