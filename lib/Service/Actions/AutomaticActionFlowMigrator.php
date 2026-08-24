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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
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
	/**
	 * The flow-node id space Dossiq's configured-action catalogue registers under.
	 *
	 * Deliberately NOT `dossiq.*`, which is the live transition vocabulary the
	 * SideEffectDispatcher fires. Both spaces ship a `sendEmail` implemented by
	 * different classes with different config keys, so crossing them would run
	 * the wrong handler against the right config.
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
	 * How many flows to page through when rebuilding the marker map.
	 */
	private const FLOW_PAGE = 100;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister.
	 * @param ContainerInterface $container Service container, for by-name resolution.
	 * @param IAppConfig $appConfig App configuration.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
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
			return $this->emptySummary(note: 'OpenRegister is not available.');
		}

		$flowService = $this->flowService();
		if ($flowService === null) {
			return $this->emptySummary(note: 'OpenRegister exposes no FlowService on this instance.');
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
	 * Walk every action and project it, inside the acting user's context.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param bool $dryRun Report only.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function migrateAll(object $flowService, bool $dryRun): array {
		$actions = $this->fetchActions();
		$existing = $this->existingByMarker(flowService: $flowService);

		$summary = $this->emptySummary(note: '');
		$summary['total'] = count($actions);
		unset($summary['note']);

		foreach ($actions as $action) {
			$row = $this->migrateOne(
				action: $action,
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
	 * Project one action, returning the row that describes what happened.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 * @param array<string, string> $existing Marker → flow uuid.
	 * @param object $flowService OpenRegister's FlowService.
	 * @param bool $dryRun Report only.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 */
	private function migrateOne(array $action, array $existing, object $flowService, bool $dryRun): array {
		$marker = $this->marker(action: $action);
		if ($marker === null) {
			return [
				'outcome' => 'failed',
				'marker' => '(unidentifiable)',
				'detail' => 'the action carries no tenantId/slug pair, which the schema requires',
			];
		}

		$type = (string)($action['type'] ?? '');
		$nodeType = self::NODE_PREFIX . $type;
		if ($this->nodeExists(nodeType: $nodeType) === false) {
			return [
				'outcome' => 'skipped',
				'marker' => $marker,
				'detail' => 'no node implements "' . $type . '"; a flow around it would never run',
			];
		}

		$uuid = ($existing[$marker] ?? null);
		if ($dryRun === true) {
			return [
				'outcome' => $this->outcomeFor(uuid: $uuid),
				'marker' => $marker,
				'detail' => 'dry run — no write',
			];
		}

		return $this->writeFlow(
			flowService: $flowService,
			document: $this->flowDocument(action: $action, marker: $marker, nodeType: $nodeType),
			marker: $marker,
			uuid: $uuid,
		);
	}//end migrateOne()

	/**
	 * Write (or rewrite) the flow, converting a throw into a failed row.
	 *
	 * One unusable action must never abort the rest of the migration.
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
				['app' => Application::APP_ID, 'marker' => $marker, 'exception' => $e->getMessage()]
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
	 * Whether writing against this uuid counts as a create or an update.
	 *
	 * A method rather than a ternary at both call sites: phpcs.xml forbids
	 * inline IF, and the summary keys are the two outcomes' names, so getting
	 * this wrong would mis-tally the run rather than fail it.
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
				['app' => Application::APP_ID, 'node' => $nodeType, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end nodeExists()

	/**
	 * The flow document one action becomes.
	 *
	 * Three nodes, because a flow OpenRegister will run needs an entry and an
	 * exit: a manual trigger, the action itself, and an end. `enabled` is true —
	 * the stored configuration said what it wanted and had never been honoured.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 * @param string $marker The provenance marker.
	 * @param string $nodeType The action node id.
	 *
	 * @return array<string, mixed> The flow document.
	 */
	private function flowDocument(array $action, string $marker, string $nodeType): array {
		return [
			'name' => (string)($action['title'] ?? $action['slug']),
			'description' => $this->description(action: $action),
			'app' => Application::APP_ID,
			'enabled' => true,
			'trigger' => 'manual',
			'notes' => $marker,
			'nodes' => [
				['id' => 'trigger', 'type' => 'openregister.trigger-manual'],
				['id' => 'action', 'type' => $nodeType, 'config' => $this->config(action: $action)],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			'edges' => [
				['id' => 'trigger-action', 'from' => ['trigger'], 'to' => ['action']],
				['id' => 'action-end', 'from' => ['action'], 'to' => ['end']],
			],
		];
	}//end flowDocument()

	/**
	 * The flow's description, carrying the provenance a reader needs.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 *
	 * @return string The description.
	 */
	private function description(array $action): string {
		$own = (string)($action['description'] ?? '');
		$provenance = 'Migrated from the Dossiq automatic action "' . (string)($action['slug'] ?? '') . '".';
		if ($own === '') {
			return $provenance;
		}

		return $own . ' — ' . $provenance;
	}//end description()

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
	 * Map the markers of already-migrated flows to their uuids.
	 *
	 * Paged: an instance with more than one page of Dossiq flows would otherwise
	 * look empty past the first, and every action beyond it would be created a
	 * second time on the next run.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 *
	 * @return array<string, string> Marker → flow uuid.
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
	 * Read every stored automaticAction object.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 */
	private function fetchActions(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'automatic_action_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$results = $objectService->findAll(['filters' => ['register' => $register, 'schema' => $schema]]);
		if (is_array($results) === false) {
			return [];
		}

		$out = [];
		foreach ($results as $entry) {
			if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
				$entry = $entry->jsonSerialize();
			}

			$out[] = (array)$entry;
		}

		return $out;
	}//end fetchActions()
}//end class
