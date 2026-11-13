<?php

/**
 * Dossiq automaticAction → OpenRegister flow migrator.
 *
 * Projects each stored `automaticAction` object onto an OpenRegister flow whose
 * single action node is the one Dossiq contributes for that action's type. This
 * is what makes the configuration executable: `automaticAction` objects have
 * never been fired by anything — `SideEffectDispatcher` runs the SEPARATE
 * `Service\Transitions` vocabulary — so the admin surface over them described a
 * capability with no runtime behind it.
 *
 * WHY THIS IS NOT A REPAIR STEP. `FlowService` refuses to create a flow without
 * a signed-in owner AND an active organisation, throwing rather than storing an
 * orphan that could never be seen, run or edited again. An upgrade runs as
 * nobody, and `runAsSystem()` elevates RBAC without supplying either. So the
 * migration is an explicit `occ` command run as a named user, whose identity and
 * organisation the created flows inherit.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Flow\FlowMigrationSummary;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ProjectsOntoFlows;
use OCP\IAppConfig;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns stored automaticAction objects into runnable OpenRegister flows.
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
class AutomaticActionFlowMigrator {
	use ProjectsOntoFlows;

	/**
	 * The flow-node id space Dossiq's configured-action catalogue registered under.
	 *
	 * Every node in it has since gone to the app that owns what it did, so an
	 * action type is looked up in the retired-node table as this prefix plus
	 * the type, and becomes whatever that table says replaces it.
	 */
	private const NODE_PREFIX = 'dossiq.action.';

	/**
	 * Prefix of the `notes` marker that ties a flow back to its source action.
	 *
	 * Idempotency hangs on this. It is stored on the flow rather than kept in a
	 * side table so a re-run rediscovers the link from the flow itself, the same
	 * property that let the Codeberg migration be rebuilt after its state was
	 * lost.
	 */
	private const MARKER_PREFIX = 'dossiq:automaticAction:';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister.
	 * @param ContainerInterface $container Service container, for by-name resolution.
	 * @param IAppConfig $appConfig App configuration.
	 * @param LoggerInterface $logger Logger.
	 * @param RetiredNodeMap $map What each retired action type became.
	 * @param RetiredNodeTranslator $translator Turns an action's config into its replacement's.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly RetiredNodeMap $map,
		private readonly RetiredNodeTranslator $translator,
	) {
	}//end __construct()

	/**
	 * Migrate every automaticAction to a flow, acting as the given user.
	 *
	 * @param IUser $user The user the created flows belong to.
	 * @param bool $dryRun Report what would happen without writing.
	 *
	 * @return array<string, mixed> The summary: total, created, updated, skipped, failed, rows.
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	public function migrate(IUser $user, bool $dryRun): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return FlowMigrationSummary::empty(note: 'OpenRegister is not available.');
		}

		$flowService = $this->flowService();
		if ($flowService === null) {
			return FlowMigrationSummary::empty(note: 'OpenRegister exposes no FlowService on this instance.');
		}

		return $objectService->runAs(
			$user,
			fn (): array => $this->migrateAll(flowService: $flowService, dryRun: $dryRun)
		);
	}//end migrate()

	/**
	 * Resolve OpenRegister's FlowService by name, or null when absent.
	 *
	 * By name and not by type-hint: Dossiq must install and boot on an instance
	 * without OpenRegister, where the class does not exist to hint against.
	 * Kept over the ProjectsOntoFlows copy because this command logs the miss
	 * at debug level, not as a warning.
	 *
	 * @return object|null The FlowService, or null.
	 */
	private function flowService(): ?object {
		try {
			return $this->container->get(self::FLOW_SERVICE);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: FlowService could not be resolved',
				['app' => AppIdentity::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end flowService()

	/**
	 * Walk every action and project it, inside the acting user's context.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param bool $dryRun Report only.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function migrateAll(object $flowService, bool $dryRun): array {
		$actions = $this->fetchActions();
		$run = new ActionMigrationRun(
			flowService: $flowService,
			existing: $this->existingByMarker(flowService: $flowService),
		);
		$run->dryRun = $dryRun;

		$summary = $this->startSummary(total: count($actions));
		foreach ($actions as $action) {
			$row = $this->migrateOne(action: $action, run: $run);
			$summary[$row['outcome']] = ($summary[$row['outcome']] + 1);
			$summary['rows'][] = $row;
		}

		return $summary;
	}//end migrateAll()

	/**
	 * The summary a run starts from, before any action is tallied.
	 *
	 * @param int $total How many actions the run walks.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function startSummary(int $total): array {
		$summary = FlowMigrationSummary::empty(note: '');
		$summary['total'] = $total;
		unset($summary['note']);

		return $summary;
	}//end startSummary()

	/**
	 * Project one action, returning the row that describes what happened.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 * @param ActionMigrationRun $run The pass this action is part of.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function migrateOne(array $action, ActionMigrationRun $run): array {
		$marker = $this->marker(action: $action);
		if ($marker === null) {
			return [
				'outcome' => 'failed',
				'marker' => '(unidentifiable)',
				'detail' => 'the action carries no tenantId/slug pair, which the schema requires',
			];
		}

		$projected = new ProjectedAction(action: $action, marker: $marker);
		$refusal = $this->resolveSteps(projected: $projected);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->writeProjection(projected: $projected, run: $run);
	}//end migrateOne()

	/**
	 * Resolve the steps an action becomes, or the row that skips it.
	 *
	 * Skipped when the action has no faithful equivalent, or when a step names
	 * a node nothing implements: a flow around it would never run.
	 *
	 * @param ProjectedAction $projected The identified action; receives its steps.
	 *
	 * @return array{outcome: string, marker: string, detail: string}|null The skip row, or null to go on.
	 */
	private function resolveSteps(ProjectedAction $projected): ?array {
		$type = $projected->type();
		try {
			$projected->steps = $this->steps(type: $type, config: $this->config(action: $projected->action));
		} catch (UnmappableStep $e) {
			return $this->skippedRow(
				marker: $projected->marker,
				detail: '"' . $type . '" cannot become a flow step: ' . $e->getMessage(),
			);
		}

		return $this->missingNodeRefusal(projected: $projected);
	}//end resolveSteps()

	/**
	 * The row that skips an action one of whose steps no node implements.
	 *
	 * @param ProjectedAction $projected The action with its resolved steps.
	 *
	 * @return array{outcome: string, marker: string, detail: string}|null The skip row, or null when every node exists.
	 */
	private function missingNodeRefusal(ProjectedAction $projected): ?array {
		$missing = $this->firstMissingNode(steps: $projected->steps);
		if ($missing === null) {
			return null;
		}

		return $this->skippedRow(
			marker: $projected->marker,
			detail: 'no node implements "' . $missing . '" (for "' . $projected->type() . '"); a flow around it would never run',
		);
	}//end missingNodeRefusal()

	/**
	 * The first step whose node no registry entry answers to.
	 *
	 * @param array<int, array{type: string, config: array<string, mixed>}> $steps The steps.
	 *
	 * @return string|null The missing node id, or null when every node exists.
	 */
	private function firstMissingNode(array $steps): ?string {
		foreach ($steps as $step) {
			if ($this->nodeExists(nodeType: $step['type']) === false) {
				return $step['type'];
			}
		}

		return null;
	}//end firstMissingNode()

	/**
	 * A row reporting an action that was left alone.
	 *
	 * @param string $marker The action's provenance marker.
	 * @param string $detail Why it was skipped.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function skippedRow(string $marker, string $detail): array {
		return [
			'outcome' => 'skipped',
			'marker' => $marker,
			'detail' => $detail,
		];
	}//end skippedRow()

	/**
	 * Write the action's flow, or report what a dry run would have done.
	 *
	 * @param ProjectedAction $projected The action with its steps.
	 * @param ActionMigrationRun $run The pass this action is part of.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function writeProjection(ProjectedAction $projected, ActionMigrationRun $run): array {
		$uuid = ($run->existing[$projected->marker] ?? null);
		if ($run->dryRun === true) {
			return $this->dryRunRow(marker: $projected->marker, uuid: $uuid);
		}

		return $this->writeFlow(
			flowService: $run->flowService,
			document: $this->flowDocument(projected: $projected),
			marker: $projected->marker,
			uuid: $uuid,
		);
	}//end writeProjection()

	/**
	 * The row a dry run reports for an action it would have written.
	 *
	 * @param string      $marker The action's provenance marker.
	 * @param string|null $uuid   The existing flow uuid, or null.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function dryRunRow(string $marker, ?string $uuid): array {
		return [
			'outcome' => $this->outcomeFor(uuid: $uuid),
			'marker' => $marker,
			'detail' => 'dry run — no write',
		];
	}//end dryRunRow()

	/**
	 * The flow document an action with its steps becomes.
	 *
	 * @param ProjectedAction $projected The action with its steps.
	 *
	 * @return array<string, mixed> The flow document.
	 */
	private function flowDocument(ProjectedAction $projected): array {
		return (new AutomaticActionFlowDocument())->build(
			action: $projected->action,
			marker: $projected->marker,
			steps: $projected->steps,
		);
	}//end flowDocument()

	/**
	 * Write (or rewrite) the flow, converting a throw into a failed row.
	 *
	 * One unusable action must never abort the rest of the migration. Kept over
	 * the ProjectsOntoFlows copy because the log line names automatic actions.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param array<string, mixed> $document The flow document.
	 * @param string $marker The provenance marker.
	 * @param string|null $uuid The existing flow uuid, or null to create.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function writeFlow(object $flowService, array $document, string $marker, ?string $uuid): array {
		try {
			$flow = $flowService->save($document, $uuid);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not migrate an automaticAction to a flow',
				['app' => AppIdentity::APP_ID, 'marker' => $marker, 'exception' => $e->getMessage()]
			);
			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => $e->getMessage()];
		}

		return [
			'outcome' => $this->outcomeFor(uuid: $uuid),
			'marker' => $marker,
			'detail' => 'flow ' . (string)$flow->getUuid(),
		];
	}//end writeFlow()

	/**
	 * Whether OpenRegister's node registry knows this node id.
	 *
	 * Checked BEFORE writing, because a flow whose action node does not resolve
	 * is the exact failure this programme already paid for once: `spawnCase` sat
	 * in shipped data naming a handler nothing implemented, and every transition
	 * that ran it reported success while doing nothing.
	 *
	 * @param string $nodeType The fully-qualified node id.
	 *
	 * @return bool True when a node answers to it.
	 */
	private function nodeExists(string $nodeType): bool {
		try {
			$registry = $this->container->get('OCA\OpenRegister\Service\Flow\FlowNodeRegistry');
			$registry->get($nodeType);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: no flow node answers to this id',
				['app' => AppIdentity::APP_ID, 'node' => $nodeType, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end nodeExists()

	/**
	 * The steps one action becomes.
	 *
	 * @param string               $type   The action type.
	 * @param array<string, mixed> $config The action's decoded config.
	 *
	 * @return array<int, array{type: string, config: array<string, mixed>}> The steps.
	 *
	 * @throws UnmappableStep When the action has no faithful equivalent.
	 */
	private function steps(string $type, array $config): array {
		$nodeType = self::NODE_PREFIX . $type;
		$row = $this->map->rowFor(type: $nodeType);
		if ($row === null) {
			return [['type' => $nodeType, 'config' => $config]];
		}

		return $this->translator->stepsFor(row: $row, config: $config);
	}//end steps()

	/**
	 * Decode the action's handler config.
	 *
	 * Stored as a JSON string by the schema. A value that does not decode to an
	 * array yields an empty config rather than a fatal: the flow is still worth
	 * creating so an admin can see and repair it in the flow editor.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 *
	 * @return array<string, mixed> The handler config.
	 */
	private function config(array $action): array {
		$raw = ($action['config'] ?? '');
		if (is_array($raw) === true) {
			return $raw;
		}

		$decoded = json_decode((string)$raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end config()

	/**
	 * The provenance marker for one action, or null when it cannot be identified.
	 *
	 * Built from `tenantId` + `slug`, both of which the schema marks required and
	 * which are together tenant-unique. Read WITHOUT a default: a missing one
	 * means the row is malformed, and defaulting would silently collapse several
	 * actions onto a single marker — one flow overwriting the next.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 *
	 * @return string|null The marker, or null.
	 */
	private function marker(array $action): ?string {
		if (isset($action['tenantId'], $action['slug']) === false) {
			return null;
		}

		$tenantId = (string)$action['tenantId'];
		$slug = (string)$action['slug'];
		if ($tenantId === '' || $slug === '') {
			return null;
		}

		return self::MARKER_PREFIX . $tenantId . ':' . $slug;
	}//end marker()

	/**
	 * Read every stored automaticAction object.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 */
	private function fetchActions(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$filters = $this->actionFilters();
		if ($filters === null) {
			return [];
		}

		$results = $objectService->findAll(['filters' => $filters]);
		if (is_array($results) === false) {
			return [];
		}

		return $this->actionRows(results: $results);
	}//end fetchActions()

	/**
	 * The register/schema filter the actions live under, or null when unconfigured.
	 *
	 * @return array{register: string, schema: string}|null The filters.
	 */
	private function actionFilters(): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'automatic_action_schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end actionFilters()

	/**
	 * Turn the search results into plain action arrays.
	 *
	 * @param array<int, mixed> $results The objects the search returned.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 */
	private function actionRows(array $results): array {
		$out = [];
		foreach ($results as $entry) {
			if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
				$entry = $entry->jsonSerialize();
			}

			$out[] = (array)$entry;
		}

		return $out;
	}//end actionRows()
}//end class
