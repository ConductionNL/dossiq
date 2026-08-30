<?php

/**
 * Dossiq Workflow Step Authorization Resolver.
 *
 * Bridges dossiq's role-routing definitions to OpenRegister's declarative
 * per-transition authorization gate (OR PR #153, ADR-022). For a workflow
 * step or transition it reads the assignee/allowed `roleType` UUID references,
 * loads each `roleType` object, and returns the literal Nextcloud group ids
 * from their `ncGroupId` field. `WorkflowDefinitionService::publish()` writes
 * the resolved group ids into each transition's `authorization` list so OR
 * enforces "only members of group X may perform this transition" server-side —
 * replacing dossiq's bespoke in-app role lookup with the canonical OR RBAC
 * group identifier.
 *
 * A roleType with a null/empty `ncGroupId` resolves to no group id (open to
 * all authenticated users), matching the pre-migration behaviour for
 * unmapped roles.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve workflow step/transition roles to NC group ids for OR RBAC.
 *
 * @spec openspec/changes/migrate-role-routing-to-or-rbac/tasks.md#P-2.1
 */
class WorkflowStepAuthorizationResolver {

	/**
	 * Per-publish cache of roleType UUID => ncGroupId|null, so a workflow with
	 * many transitions referencing the same role loads each roleType once.
	 *
	 * @var array<string, string|null>
	 */
	private array $groupIdCache = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister ObjectService + config.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the literal NC group ids that gate a step or transition.
	 *
	 * Reads role references in precedence order: an explicit `routingRule`
	 * (`roleType` + `roleTypes` + `fallback`), then legacy `assigneeRole`, then
	 * legacy `allowedRoles`. Each referenced `roleType` UUID is resolved to its
	 * `ncGroupId`. Null/empty group ids are dropped.
	 *
	 * @param array<string, mixed> $entry The step or transition payload.
	 *
	 * @return array<int, string> Distinct, non-empty NC group ids (may be empty when no role maps to a group).
	 *
	 * @spec openspec/changes/migrate-role-routing-to-or-rbac/tasks.md#P-2.1
	 */
	public function resolveGroupIds(array $entry): array {
		$roleTypeIds = $this->collectRoleTypeIds(entry: $entry);
		if ($roleTypeIds === []) {
			return [];
		}

		$groupIds = [];
		foreach ($roleTypeIds as $roleTypeId) {
			$groupId = $this->ncGroupIdFor(roleTypeId: $roleTypeId);
			if ($groupId !== null && $groupId !== '') {
				$groupIds[$groupId] = true;
			}
		}

		return array_keys($groupIds);
	}//end resolveGroupIds()

	/**
	 * Collect every roleType UUID referenced by a step/transition.
	 *
	 * @param array<string, mixed> $entry The step or transition payload.
	 *
	 * @return array<int, string> Distinct roleType UUIDs.
	 */
	private function collectRoleTypeIds(array $entry): array {
		$ids = [];

		$rule = ($entry['routingRule'] ?? null);
		if (is_array($rule) === true) {
			$single = (string)($rule['roleType'] ?? '');
			if ($single !== '') {
				$ids[$single] = true;
			}

			foreach ((array)($rule['roleTypes'] ?? []) as $roleType) {
				$roleType = (string)$roleType;
				if ($roleType !== '') {
					$ids[$roleType] = true;
				}
			}

			$fallback = (string)($rule['fallback'] ?? '');
			if ($fallback !== '') {
				$ids[$fallback] = true;
			}
		}//end if

		$assignee = (string)($entry['assigneeRole'] ?? '');
		if ($assignee !== '') {
			$ids[$assignee] = true;
		}

		foreach ((array)($entry['allowedRoles'] ?? []) as $allowed) {
			$allowed = (string)$allowed;
			if ($allowed !== '') {
				$ids[$allowed] = true;
			}
		}

		return array_keys($ids);
	}//end collectRoleTypeIds()

	/**
	 * Load a roleType's ncGroupId, caching the result for this publish pass.
	 *
	 * @param string $roleTypeId The roleType UUID.
	 *
	 * @return string|null The NC group id, or null when unmapped/unresolvable.
	 */
	private function ncGroupIdFor(string $roleTypeId): ?string {
		if (array_key_exists($roleTypeId, $this->groupIdCache) === true) {
			return $this->groupIdCache[$roleTypeId];
		}

		$this->groupIdCache[$roleTypeId] = null;

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$roleTypeSchema = $this->settingsService->getConfigValue(key: 'role_type_schema');
		if ($register === '' || $roleTypeSchema === '') {
			return null;
		}

		try {
			$roleType = $this->toArray(value: $objectService->find($roleTypeId, register: $register, schema: $roleTypeSchema));
		} catch (Throwable $e) {
			$this->logger->warning(
				'WorkflowStepAuthorizationResolver: roleType lookup failed',
				['roleType' => $roleTypeId, 'exception' => $e->getMessage()],
			);
			return null;
		}

		$groupId = trim((string)($roleType['ncGroupId'] ?? ''));
		if ($groupId === '') {
			return null;
		}

		$this->groupIdCache[$roleTypeId] = $groupId;
		return $groupId;
	}//end ncGroupIdFor()

	/**
	 * Coerce an ObjectService return value to a plain array.
	 *
	 * @param mixed $value The record (entity or array).
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
