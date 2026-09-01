<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Parafeer
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ProjectsOntoFlows;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project each endorsement route onto an OpenRegister flow.
 *
 * An approval route is not an entity. It is a sequence of manual steps that
 * must be taken before a decision can be attached to a case, which is a flow
 * with a person at each step. Modelling it as its own schema in two apps at
 * once (dossiq's `parafeerroute`, decidiq's `ApprovalRoute`) gave the fleet two
 * half-engines and no editor for either; the flow store already has the
 * engine, the editor and the run history.
 *
 * Each step becomes an {@see \OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode}:
 * it pauses the run until its actor answers, reads `decision` off the resume
 * payload, and treats a rejection as an outcome to route on rather than a
 * fault. That is exactly what a parafering step is.
 *
 * WHY THIS IS NOT A REPAIR STEP, same as its sibling
 * {@see \OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator}: FlowService
 * refuses to create a flow without a signed-in owner, and a repair step running
 * under `occ upgrade` has none. It is an occ command a person runs.
 *
 * 🔴 THE FLOW ARRIVES DISABLED, for the same reason its sibling does. The route
 * still drives parafering through BesluitvormingParafeerService. A projection
 * that ran as well would ask every approver twice.
 *
 * @spec openspec/changes/approval-routes-are-flows/specs/approval-routes-are-flows/spec.md
 */
class EndorsementRouteFlowMigrator {

	use SearchesObjects;
	use ProjectsOntoFlows;

	/**
	 * Provenance marker prefix written into the flow's notes.
	 *
	 * Resolved by marker rather than by name, because a name is editable in the
	 * flow editor and a re-run matching on one would mint a second flow the
	 * moment somebody renamed the first.
	 *
	 * @var string
	 */
	public const MARKER_PREFIX = 'dossiq:endorsementRoute:';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Register/schema configuration.
	 * @param ContainerInterface $container       Resolves OpenRegister's FlowService at runtime.
	 * @param LoggerInterface    $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Project every stored route onto a flow.
	 *
	 * @param IUser   $user   The owner the flows are created under.
	 * @param boolean $dryRun Whether to report without writing.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/approval-routes-are-flows/specs/approval-routes-are-flows/spec.md
	 */
	public function migrate(IUser $user, bool $dryRun): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $this->emptySummary(note: 'OpenRegister is not available.');
		}

		$flowService = $this->flowService();
		if ($flowService === null) {
			return $this->emptySummary(note: 'OpenRegister exposes no FlowService on this instance.');
		}

		// The whole migration runs AS the given user, because a flow inherits
		// its owner and organisation from whoever created it, permanently.
		// Running unscoped would hand every projected route to nobody.
		if (method_exists($objectService, 'runAs') === false) {
			return $this->emptySummary(note: 'OpenRegister exposes no runAs(); the migration needs an owner for the flows it creates.');
		}

		return $objectService->runAs(
			$user,
			fn (): array => $this->migrateAll(flowService: $flowService, dryRun: $dryRun)
		);

	}//end migrate()


	/**
	 * An empty summary carrying the reason nothing happened.
	 *
	 * @param string $note Why the run did nothing.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function emptySummary(string $note): array {
		return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'routes' => [], 'note' => $note];

	}//end emptySummary()

	/**
	 * Project every stored route, returning the summary.
	 *
	 * @param object  $flowService OpenRegister's FlowService.
	 * @param boolean $dryRun      Report only.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function migrateAll(object $flowService, bool $dryRun): array {
		$routes = $this->fetchRoutes();
		$existing = $this->existingByMarker(flowService: $flowService);

		// Counts and rows are kept apart deliberately. Incrementing
		// `$summary[$row['outcome']]` against a mixed-value array reads as
		// "add 1 to whatever is at that key", and nothing stops the key being
		// `rows` — psalm said so, and it was right: an outcome named `rows`
		// would append to the row list instead of counting.
		$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
		$rows = [];

		foreach ($routes as $route) {
			$row = $this->migrateOne(
				route: $route,
				existing: $existing,
				flowService: $flowService,
				dryRun: $dryRun,
			);

			$outcome = $row['outcome'];
			if (array_key_exists($outcome, $counts) === true) {
				$counts[$outcome] = ($counts[$outcome] + 1);
			}

			$rows[] = $row;
		}

		return ($counts + ['total' => count($routes), 'rows' => $rows]);

	}//end migrateAll()

	/**
	 * Project one route, returning the row that describes what happened.
	 *
	 * @param array<string, mixed>  $route       The stored route.
	 * @param array<string, string> $existing    Marker to flow uuid.
	 * @param object                $flowService OpenRegister's FlowService.
	 * @param boolean               $dryRun      Report only.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function migrateOne(array $route, array $existing, object $flowService, bool $dryRun): array {
		$id = (string)($route['id'] ?? ($route['@self']['id'] ?? ''));
		$marker = (self::MARKER_PREFIX . $id);

		if ($id === '') {
			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => 'the route has no id'];
		}

		$graph = $this->graphOf(route: $route);
		if ($graph === null) {
			// A route with no steps asks nobody for anything. Projecting it
			// would produce a flow that completes the moment it starts, which
			// reads as an approval that was granted rather than never sought.
			return ['outcome' => 'skipped', 'marker' => $marker, 'detail' => 'no usable steps, or a step with no actor'];
		}

		if ($dryRun === true) {
			return [
				'outcome' => $this->outcomeFor(uuid: ($existing[$marker] ?? null)),
				'marker' => $marker,
				'detail' => sprintf('%d step(s)', (count($graph['nodes']) - 1)),
			];
		}

		return $this->writeFlow(
			flowService: $flowService,
			document: $this->flowDocument(route: $route, graph: $graph, marker: $marker),
			marker: $marker,
			uuid: ($existing[$marker] ?? null),
		);

	}//end migrateOne()

	/**
	 * Build the flow's nodes and edges from the route's steps.
	 *
	 * Each step becomes a `dossiq.askParaaf` node.
	 *
	 * NOT `dossiq.askPerson`, which was this migrator's first answer and was
	 * wrong. askPerson raises a generic TASK, and a task cannot hold what a
	 * paraaf legally is: `parafeeractie` carries `onBehalfOf` and `mandate` —
	 * who signed on whose behalf, under which mandate — plus `advice`,
	 * `actorType` and `step`. Enabling a projection built on askPerson would
	 * have put generic tasks in approvers' queues, left the parafering screens
	 * empty because they read `parafeeractie`, and stopped recording the
	 * mandate chain. That is a loss of record dressed as an engine change.
	 *
	 * NOT `openregister.awaitSignal` either: a raw await waits for an answer
	 * nobody was asked for, so the step never reaches anybody's queue.
	 *
	 * The steps are chained in `order`, because an approval route is a
	 * sequence: step two is not asked until step one has answered.
	 *
	 * The chain ends at `dossiq.requestDecision`, because that is what an
	 * approval route is FOR. The steps are the manual sign-offs; the decision
	 * is what may be attached to the case once they are done.
	 *
	 * @param array<string, mixed> $route The stored route.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}|null
	 *         The graph, or null when the route has no usable steps.
	 */
	private function graphOf(array $route): ?array {
		$steps = $this->decodeList(value: ($route['steps'] ?? null));
		if ($steps === []) {
			return null;
		}

		usort(
			$steps,
			static fn (array $a, array $b): int => (((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)))
		);

		$nodes = [];
		$edges = [];
		$previous = null;
		foreach ($steps as $index => $step) {
			$position = ($index + 1);
			$id = ('step-' . $position);

			$assignee = trim((string)($step['actor'] ?? ''));
			if ($assignee === '') {
				// `dossiq.askParaaf` refuses an empty actor, and rightly: a
				// sign-off with nobody to give it is not a step. Refusing the
				// whole route is the honest outcome, because projecting the
				// rest would silently drop an approval somebody expects.
				return null;
			}

			$nodes[] = [
				'id' => $id,
				'type' => 'dossiq.askParaaf',
				'config' => [
					'question' => $this->question(step: $step, position: $position),
					'actor' => $assignee,
					'actorType' => trim((string)($step['actorType'] ?? 'user')),
					// The route's own step number, not the position in the
					// chain: a parafeeractie's `step` is read back by the
					// parafering screens and must mean what the route meant.
					'step' => (int)($step['order'] ?? $position),
					'signalKey' => ('step' . $position),
				],
			];

			if ($previous !== null) {
				$edges[] = ['id' => ('e-' . $position), 'from' => [$previous], 'to' => [$id], 'label' => ''];
			}

			$previous = $id;
		}//end foreach

		$nodes[] = [
			'id' => 'decision',
			'type' => 'dossiq.requestDecision',
			'config' => [
				'question' => sprintf('Decision for %s', (string)($route['name'] ?? 'this case')),
				'signalKey' => 'decision',
			],
		];
		$edges[] = ['id' => 'e-decision', 'from' => [$previous], 'to' => ['decision'], 'label' => ''];

		return ['nodes' => $nodes, 'edges' => $edges];

	}//end graphOf()

	/**
	 * What the approver is actually being asked.
	 *
	 * @param array<string, mixed> $step     The step.
	 * @param integer              $position Its position in the sequence.
	 *
	 * @return string The question.
	 */
	private function question(array $step, int $position): string {
		$label = trim((string)($step['label'] ?? ''));
		if ($label !== '') {
			return $label;
		}

		$type = trim((string)($step['type'] ?? ''));
		if ($type !== '') {
			return ucfirst($type);
		}

		return ('Step ' . $position);

	}//end question()

	/**
	 * The flow document for one route.
	 *
	 * @param array<string, mixed> $route  The stored route.
	 * @param array<string, mixed> $graph  Its nodes and edges.
	 * @param string               $marker The provenance marker.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function flowDocument(array $route, array $graph, string $marker): array {
		return [
			'name' => (string)($route['name'] ?? 'Approval route'),
			'description' => $this->description(route: $route),
			'app' => Application::APP_ID,
			// DISABLED, like its sibling. The route still drives parafering
			// through BesluitvormingParafeerService; a projection that ran too
			// would ask every approver twice.
			'enabled' => false,
			'trigger' => 'manual',
			'notes' => $marker,
			'nodes' => $graph['nodes'],
			'edges' => $graph['edges'],
		];

	}//end flowDocument()

	/**
	 * The flow's description, carrying the provenance a reader needs.
	 *
	 * @param array<string, mixed> $route The stored route.
	 *
	 * @return string The description.
	 */
	private function description(array $route): string {
		$own = trim((string)($route['description'] ?? ''));
		$provenance = sprintf(
			'Projected from the Dossiq approval route "%s". It arrives disabled: '
			. 'the route still drives parafering, and enabling this without '
			. 'retiring it would ask every approver twice.',
			(string)($route['name'] ?? '')
		);

		if ($own === '') {
			return $provenance;
		}

		return ($own . '. ' . $provenance);

	}//end description()

	/**
	 * Read the stored routes.
	 *
	 * @return array<int, array<string, mixed>> The routes.
	 */
	private function fetchRoutes(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('parafeerroute_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: []
		);

	}//end fetchRoutes()

}//end class
