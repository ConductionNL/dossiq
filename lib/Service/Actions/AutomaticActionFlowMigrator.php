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
use OCA\Dossiq\Kernel\Contracts\AutomaticActionFlowMigratorInterface;
use OCA\Dossiq\Service\Flow\FlowMigrationSummary;
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
class AutomaticActionFlowMigrator implements AutomaticActionFlowMigratorInterface {
	use ProjectsOntoFlows;


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
	 * @param ActionFlowSteps $flowSteps What each action becomes as flow steps, or why it cannot.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ActionFlowSteps $flowSteps,
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
	 * @spec openspec/changes/method-decomposition/tasks.md
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
		$refusal = $this->flowSteps->resolveSteps(projected: $projected);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->writeProjection(projected: $projected, run: $run);
	}//end migrateOne()





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
