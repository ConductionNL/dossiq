<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Workflow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project each `workflowTemplate` onto an OpenRegister flow.
 *
 * ADR-065: OpenRegister is the only home for a flow engine, and a leaf app that
 * keeps its own is an ADR-022 violation. dossiq's `workflowTemplate` is a state
 * machine — statuses joined by guarded transitions — which is one of the two
 * shapes that ADR names, and the one `symfony/workflow`'s StateMachine models
 * with `case.status` as the marking.
 *
 * WHY THIS IS NOT A REPAIR STEP, same as its sibling
 * {@see \OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator}: FlowService
 * refuses to create a flow without a signed-in owner, and a repair step running
 * under `occ upgrade` has none. It is an occ command a person runs.
 *
 * 🔴 THE FLOW ARRIVES DISABLED. A workflowTemplate that is live today drives
 * cases through `StatusTransitionService`; the projected flow is a second thing
 * that could drive them too. Creating it enabled would mean every status change
 * fires twice from the moment the migration runs. Adopting the flow stays a
 * deliberate act, which is also how the shipped `x-openregister-flows` arrive.
 *
 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
 */
class WorkflowTemplateFlowMigrator {

	use SearchesObjects;

	/**
	 * Provenance marker prefix written into the flow's notes.
	 *
	 * The migration resolves an existing flow by this marker rather than by
	 * name: a name is editable in the flow editor, and a re-run that matched on
	 * one would mint a second flow the moment somebody renamed the first.
	 *
	 * @var string
	 */
	private const MARKER_PREFIX = 'dossiq:workflowTemplate:';

	/**
	 * Flows read per page when scanning for existing markers.
	 *
	 * @var integer
	 */
	private const FLOW_PAGE = 100;

	/**
	 * Templates read per pass.
	 *
	 * @var integer
	 */
	private const BATCH_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Bridge to OpenRegister.
	 * @param ContainerInterface $container       Service container, for by-name resolution.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Project every workflowTemplate onto a flow, acting as the given user.
	 *
	 * @param IUser   $user   The user the created flows belong to.
	 * @param boolean $dryRun Report what would happen without writing.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
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

		if (method_exists($objectService, 'runAs') === false) {
			return $this->emptySummary(note: 'OpenRegister exposes no runAs(); the migration needs an owner for the flows it creates.');
		}

		return $objectService->runAs(
			$user,
			fn (): array => $this->migrateAll(flowService: $flowService, dryRun: $dryRun)
		);

	}//end migrate()

	/**
	 * Resolve OpenRegister's FlowService by name, or null when absent.
	 *
	 * By name and not by type-hint: dossiq must install and boot on an instance
	 * without OpenRegister, where the class does not exist to hint against.
	 *
	 * @return object|null The FlowService, or null.
	 */
	private function flowService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\Flow\FlowService');
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: FlowService could not be resolved',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);

			return null;
		}

	}//end flowService()

	/**
	 * A summary describing a run that could not start.
	 *
	 * @param string $note Why nothing happened.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function emptySummary(string $note): array {
		return [
			'total' => 0,
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed' => 0,
			'rows' => [],
			'note' => $note,
		];

	}//end emptySummary()

	/**
	 * Walk every template and project it, inside the acting user's context.
	 *
	 * @param object  $flowService OpenRegister's FlowService.
	 * @param boolean $dryRun      Report only.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function migrateAll(object $flowService, bool $dryRun): array {
		$templates = $this->fetchTemplates();
		$existing = $this->existingByMarker(flowService: $flowService);

		$summary = $this->emptySummary(note: '');
		$summary['total'] = count($templates);
		unset($summary['note']);

		foreach ($templates as $template) {
			$row = $this->migrateOne(
				template: $template,
				existing: $existing,
				flowService: $flowService,
				dryRun: $dryRun,
			);
			$summary[$row['outcome']] = ($summary[$row['outcome']] + 1);
			$summary['rows'][] = $row;
		}

		return $summary;

	}//end migrateAll()

	/**
	 * Project one template, returning the row that describes what happened.
	 *
	 * @param array<string, mixed>  $template    The stored workflowTemplate.
	 * @param array<string, string> $existing    Marker to flow uuid.
	 * @param object                $flowService OpenRegister's FlowService.
	 * @param boolean               $dryRun      Report only.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function migrateOne(array $template, array $existing, object $flowService, bool $dryRun): array {
		$id = (string)($template['id'] ?? ($template['@self']['id'] ?? ''));
		$marker = (self::MARKER_PREFIX . $id);

		if ($id === '') {
			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => 'the template has no id'];
		}

		$graph = $this->graphOf(template: $template);
		if ($graph === null) {
			// A template with no transitions is not a state machine; projecting
			// it would produce a flow with nodes and no way between them, which
			// looks like a migration that worked.
			return ['outcome' => 'skipped', 'marker' => $marker, 'detail' => 'no usable transitions'];
		}

		if ($dryRun === true) {
			return [
				'outcome' => $this->outcomeFor(uuid: ($existing[$marker] ?? null)),
				'marker' => $marker,
				'detail' => sprintf('%d node(s), %d edge(s)', count($graph['nodes']), count($graph['edges'])),
			];
		}

		return $this->writeFlow(
			flowService: $flowService,
			document: $this->flowDocument(template: $template, graph: $graph, marker: $marker),
			marker: $marker,
			uuid: ($existing[$marker] ?? null),
		);

	}//end migrateOne()

	/**
	 * Build the flow's nodes and edges from the template's state machine.
	 *
	 * Each STATUS becomes a `dossiq.setStatus` node and each TRANSITION becomes
	 * an edge between two of them. That is the honest projection: the template's
	 * statuses are what a case moves between, and its transitions are the moves.
	 *
	 * Statuses are carried by NAME, never by id. A statusType uuid is minted per
	 * installation, so a flow naming one is portable nowhere — which is exactly
	 * why `dossiq.setStatus` takes a name and resolves it inside the case's own
	 * case type.
	 *
	 * @param array<string, mixed> $template The stored template.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}|null
	 *         The graph, or null when the template has no usable transitions.
	 */
	private function graphOf(array $template): ?array {
		$transitions = $this->decodeList(value: ($template['transitions'] ?? null));
		if ($transitions === []) {
			return null;
		}

		$statuses = [];
		$edges = [];
		foreach ($transitions as $index => $transition) {
			$from = trim((string)($transition['fromStatus'] ?? ''));
			$to = trim((string)($transition['toStatus'] ?? ''));

			// A wildcard source has no node to leave from. The seeder accepts
			// `fromStatus: '*'` and no shipped template uses it, so it is
			// skipped rather than guessed at — a drawn edge with no source is
			// worse than an absent one.
			if ($to === '' || $from === '' || $from === '*') {
				continue;
			}

			$statuses[$from] = true;
			$statuses[$to] = true;
			$edges[] = [
				'id' => ('t-' . ($index + 1)),
				'from' => [$this->nodeId(status: $from)],
				'to' => [$this->nodeId(status: $to)],
				'label' => (string)($transition['label'] ?? ($transition['slug'] ?? '')),
			];
		}

		if ($edges === []) {
			return null;
		}

		$nodes = [];
		foreach (array_keys($statuses) as $status) {
			$nodes[] = [
				'id' => $this->nodeId(status: (string)$status),
				'type' => 'dossiq.setStatus',
				'config' => ['status' => (string)$status],
			];
		}

		return ['nodes' => $nodes, 'edges' => $edges];

	}//end graphOf()

	/**
	 * A stable node id for a status name.
	 *
	 * @param string $status The status name.
	 *
	 * @return string The node id.
	 */
	private function nodeId(string $status): string {
		$slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $status) ?? '');

		return ('status-' . trim($slug, '-'));

	}//end nodeId()

	/**
	 * Decode a field the schema stores as a JSON-encoded string.
	 *
	 * `steps` and `transitions` are declared as strings holding JSON, which is
	 * ADR-065's named cost of this model: they are opaque to OpenRegister. Rows
	 * written before that were stored as native arrays, so both are accepted.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, array<string, mixed>> The decoded list.
	 */
	private function decodeList(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_array($entry) === true) {
				$out[] = $entry;
			}
		}

		return $out;

	}//end decodeList()

	/**
	 * The flow document to store.
	 *
	 * @param array<string, mixed> $template The stored template.
	 * @param array{nodes: array, edges: array} $graph The projected graph.
	 * @param string $marker The provenance marker.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function flowDocument(array $template, array $graph, string $marker): array {
		return [
			'name' => (string)($template['title'] ?? 'Workflow'),
			'description' => $this->description(template: $template),
			'app' => Application::APP_ID,
			// DISABLED. The template still drives cases through
			// StatusTransitionService; an enabled projection would move the
			// same case a second time on every status change.
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
	 * @param array<string, mixed> $template The stored template.
	 *
	 * @return string The description.
	 */
	private function description(array $template): string {
		$own = trim((string)($template['description'] ?? ''));
		$provenance = sprintf(
			'Projected from the Dossiq workflow definition "%s" (version %s). '
			. 'It arrives DISABLED: the definition still drives cases, and enabling '
			. 'this without retiring it would move every case twice.',
			(string)($template['title'] ?? ''),
			(string)($template['version'] ?? '1')
		);

		if ($own === '') {
			return $provenance;
		}

		return ($own . ' — ' . $provenance);

	}//end description()

	/**
	 * Read the stored templates.
	 *
	 * @return array<int, array<string, mixed>> The templates.
	 */
	private function fetchTemplates(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'workflow_template_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::BATCH_LIMIT],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not list workflow templates',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);

			return [];
		}

	}//end fetchTemplates()

	/**
	 * Map every already-projected flow by its provenance marker.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 *
	 * @return array<string, string> Marker to flow uuid.
	 */
	private function existingByMarker(object $flowService): array {
		$map = [];
		$offset = 0;

		while (true) {
			$page = $flowService->findAll(Application::APP_ID, null, null, self::FLOW_PAGE, $offset);
			if (is_array($page) === false || $page === []) {
				return $map;
			}

			foreach ($page as $flow) {
				$notes = (string)($flow->getNotes() ?? '');
				if (str_starts_with($notes, self::MARKER_PREFIX) === true) {
					$map[$notes] = (string)$flow->getUuid();
				}
			}

			if (count($page) < self::FLOW_PAGE) {
				return $map;
			}

			$offset += self::FLOW_PAGE;
		}

	}//end existingByMarker()

	/**
	 * Write one flow, never letting a single failure abort the rest.
	 *
	 * @param object               $flowService OpenRegister's FlowService.
	 * @param array<string, mixed> $document    The flow document.
	 * @param string               $marker      The provenance marker.
	 * @param string|null          $uuid        The existing flow uuid, or null to create.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function writeFlow(object $flowService, array $document, string $marker, ?string $uuid): array {
		try {
			$flow = $flowService->save($document, $uuid);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not project a workflow definition onto a flow',
				['app' => Application::APP_ID, 'marker' => $marker, 'exception' => $e->getMessage()]
			);

			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => $e->getMessage()];
		}

		return [
			'outcome' => $this->outcomeFor(uuid: $uuid),
			'marker' => $marker,
			'detail' => ('flow ' . (string)$flow->getUuid()),
		];

	}//end writeFlow()

	/**
	 * Whether writing against this uuid counts as a create or an update.
	 *
	 * @param string|null $uuid The existing flow uuid, or null.
	 *
	 * @return string Either `created` or `updated`.
	 */
	private function outcomeFor(?string $uuid): string {
		if ($uuid === null) {
			return 'created';
		}

		return 'updated';

	}//end outcomeFor()

}//end class
