<?php

/**
 * Dossiq Workflow Definition Service
 *
 * Lifecycle and consumer service for workflowTemplate objects (aka
 * "workflow definitions"). Pure CRUD over workflowTemplate is delegated to
 * the manifest renderer / OpenRegister auto-routing; this service owns the
 * domain logic that CRUD cannot express:
 *
 *   - createDraft()          — create a new draft definition from a
 *                              fully-resolved payload (used by seed-data
 *                              and catalog-import flows; mutations to
 *                              workflowTemplate MUST go through this
 *                              service to respect the immutability
 *                              invariant of published rows).
 *   - publish()              — flip a draft to published, deprecate the
 *                              previously active version, pin the
 *                              caseType.workflowDefinition reference.
 *   - deprecate()            — flip a published version to deprecated and
 *                              clear isActive (refuses if the caseType has
 *                              no other published version while open cases
 *                              remain).
 *   - cloneDefinition()      — produce a new draft from an existing
 *                              published or deprecated version with
 *                              version + 1.
 *   - getActiveDefinitionFor — read-only consumer entrypoint used by
 *                              status-transition-engine and
 *                              role-based-step-routing.
 *   - getDefinition          — read-only by UUID.
 *   - getDefinitionForCase   — resolves through case.workflowTemplate +
 *                              case.workflowVersion.
 *   - listVersions           — admin UI listing.
 *
 * Three collaborators carry the concerns that are not lifecycle transitions:
 * {@see Workflow\WorkflowDefinitionRepository} owns every OpenRegister
 * read/write, {@see Workflow\WorkflowLifecycleGuard} owns the preconditions a
 * publish or deprecate must satisfy, and
 * {@see Workflow\TransitionAuthorizationStamper} owns the publish-time
 * freezing of role routing into literal NC group ids.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Workflow\TransitionAuthorizationStamper;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle + consumer service for workflowTemplate objects.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class WorkflowDefinitionService {

	/**
	 * Lifecycle states. Mirrors the enum on the workflowTemplate schema.
	 *
	 * Re-exported from WorkflowLifecycleGuard, which owns the lifecycle
	 * semantics, so existing `WorkflowDefinitionService::STATUS_*` callers
	 * keep reading the single source of truth.
	 */
	public const STATUS_DRAFT = WorkflowLifecycleGuard::STATUS_DRAFT;
	public const STATUS_PUBLISHED = WorkflowLifecycleGuard::STATUS_PUBLISHED;
	public const STATUS_DEPRECATED = WorkflowLifecycleGuard::STATUS_DEPRECATED;

	/**
	 * Constructor.
	 *
	 * @param WorkflowDefinitionRepository $repository The OpenRegister persistence layer
	 * @param WorkflowLifecycleGuard $guard Publish/deprecate preconditions
	 * @param TransitionAuthorizationStamper $stamper Publish-time role → group
	 *                                                freezing
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly WorkflowDefinitionRepository $repository,
		private readonly WorkflowLifecycleGuard $guard,
		private readonly TransitionAuthorizationStamper $stamper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the latest published+active definition for a caseType, or
	 * null when none exists. Read-only consumer entrypoint used by
	 * status-transition-engine and role-based-step-routing.
	 *
	 * @param string $caseTypeId The caseType UUID
	 *
	 * @return array<string, mixed>|null The definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getActiveDefinitionFor(string $caseTypeId): ?array {
		if ($caseTypeId === '') {
			return null;
		}

		$versions = $this->repository->listVersionsForCaseType(caseTypeId: $caseTypeId);

		foreach ($versions as $candidate) {
			if ($this->guard->statusOf(row: $candidate) !== self::STATUS_PUBLISHED) {
				continue;
			}

			if ((bool)($candidate['isActive'] ?? false) === true) {
				return $candidate;
			}
		}

		return null;
	}//end getActiveDefinitionFor()

	/**
	 * Read a single definition by UUID. Returns null when not found.
	 *
	 * @param string $id The definition UUID
	 *
	 * @return array<string, mixed>|null The definition or null
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function getDefinition(string $id): ?array {
		return $this->repository->findById(id: $id);
	}//end getDefinition()

	/**
	 * Resolve the definition pinned to a case via case.workflowTemplate +
	 * case.workflowVersion. Falls back to the active definition for the
	 * case's caseType when no pin is set.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed>|null The definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getDefinitionForCase(string $caseId): ?array {
		$case = $this->repository->findCase(caseId: $caseId);
		if ($case === null) {
			return null;
		}

		$templateId = (string)($case['workflowTemplate'] ?? '');
		if ($templateId !== '') {
			return $this->repository->findById(id: $templateId);
		}

		$caseTypeId = (string)($case['caseType'] ?? '');
		if ($caseTypeId === '') {
			return null;
		}

		return $this->getActiveDefinitionFor(caseTypeId: $caseTypeId);
	}//end getDefinitionForCase()

	/**
	 * List every version of the definition for a given caseType, ordered
	 * by version descending. Used by the admin UI.
	 *
	 * @param string $caseTypeId The caseType UUID
	 *
	 * @return array<int, array<string, mixed>> The versions
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function listVersions(string $caseTypeId): array {
		return $this->repository->listVersionsForCaseType(caseTypeId: $caseTypeId);
	}//end listVersions()

	/**
	 * Publish a draft definition. Atomically:
	 *   - sets target lifecycleStatus=published, isActive=true, isDraft=false
	 *   - moves any previously active version of the same caseType to
	 *     lifecycleStatus=deprecated, isActive=false
	 *   - updates caseType.workflowDefinition to point at the new active id
	 *
	 * Returns the updated definition or null on error (errors logged).
	 *
	 * @param string $id The definition UUID to publish
	 *
	 * @return array<string, mixed>|null Updated definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function publish(string $id): ?array {
		$current = $this->repository->findById(id: $id);
		if ($current === null) {
			$this->logger->warning(
				'Dossiq: publish() — definition not found',
				['app' => Application::APP_ID, 'id' => $id]
			);
			return null;
		}

		$transitions = $this->decodeArray(raw: ($current['transitions'] ?? ''));
		if ($this->guard->isPublishableDraft(current: $current, transitions: $transitions, id: $id) === false) {
			return null;
		}

		// Both the definition schema and the caseType schema must be
		// configured before anything is written: publishing without the
		// caseType schema would deprecate the predecessor and leave the
		// caseType pointing at a version it can no longer pin.
		$configured = ($this->repository->isConfiguredFor(schemaKey: WorkflowDefinitionRepository::SCHEMA_DEFINITION) === true
			&& $this->repository->isConfiguredFor(schemaKey: WorkflowDefinitionRepository::SCHEMA_CASE_TYPE) === true);
		if ($configured === false) {
			return null;
		}

		$caseTypeId = (string)($current['caseType'] ?? '');

		// Resolve each transition's assignee role to its NC group id(s) and
		// freeze the result into the transition `authorization` list (OR PR
		// #153 declarative gate, ADR-022).
		$authoredTransitions = $this->stamper->stamp(transitions: $transitions);
		if ($this->deprecatePreviousActive(caseTypeId: $caseTypeId, id: $id) === false) {
			return null;
		}

		// Flip target to published+active, writing back the authorization-
		// enriched transitions (JSON-encoded STRING per the workflowTemplate
		// schema) when any were resolved.
		$updated = $this->repository->save(
			payload: $this->buildPublishPayload(authoredTransitions: $authoredTransitions),
			uuid: $id,
		);
		if ($updated === null) {
			return null;
		}

		// Pin caseType.workflowDefinition to the new active version.
		$this->repository->pinWorkflowDefinition(caseTypeId: $caseTypeId, definitionId: $id);

		return $updated;
	}//end publish()

	/**
	 * Deprecate a published definition. Refuses (returns null + logs) if
	 * doing so would leave the caseType with no published definition while
	 * open cases remain pinned to it.
	 *
	 * @param string $id The definition UUID to deprecate
	 *
	 * @return array<string, mixed>|null Updated definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function deprecate(string $id): ?array {
		$current = $this->repository->findById(id: $id);
		if ($current === null) {
			return null;
		}

		if ($this->guard->isDeprecatable(current: $current, id: $id) === false) {
			return null;
		}

		return $this->repository->save(
			payload: [
				'lifecycleStatus' => self::STATUS_DEPRECATED,
				'isActive' => false,
			],
			uuid: $id,
		);
	}//end deprecate()

	/**
	 * Clone an existing published or deprecated definition into a new
	 * draft with version + 1.
	 *
	 * @param string $id The source definition UUID
	 *
	 * @return array<string, mixed>|null New draft definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cloneDefinition(string $id): ?array {
		$source = $this->repository->findById(id: $id);
		if ($source === null) {
			return null;
		}

		$caseTypeId = (string)($source['caseType'] ?? '');
		$nextVersion = $this->repository->nextVersionFor(caseTypeId: $caseTypeId);

		$draft = [
			'title' => $this->cloneTitle(base: (string)($source['title'] ?? 'Workflow')),
			'description' => (string)($source['description'] ?? ''),
			'caseType' => $caseTypeId,
			'version' => $nextVersion,
			'isActive' => false,
			'isDraft' => true,
			'lifecycleStatus' => self::STATUS_DRAFT,
			'steps' => (string)($source['steps'] ?? ''),
			'transitions' => (string)($source['transitions'] ?? ''),
			'nodePositions' => (string)($source['nodePositions'] ?? ''),
		];

		return $this->repository->save(payload: $draft);
	}//end cloneDefinition()

	/**
	 * Create a brand-new draft definition from a fully-resolved payload.
	 * Used by seed-data / catalog import flows where the caller has already
	 * resolved caseType slug → UUID and statusType names → UUIDs.
	 *
	 * The payload SHALL provide:
	 *   - title (string, required)
	 *   - description (string, optional)
	 *   - caseType (UUID string, required)
	 *   - version (int, optional — defaults to next version for caseType)
	 *   - steps (array of step rows, will be JSON-encoded if not already a string)
	 *   - transitions (array of transition rows, will be JSON-encoded if not already a string)
	 *
	 * The method enforces lifecycleStatus=draft, isDraft=true, isActive=false.
	 * Returns the created definition (with id) or null on failure.
	 *
	 * @param array<string, mixed> $payload The fully-resolved draft payload
	 *
	 * @return array<string, mixed>|null The created draft or null on failure
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createDraft(array $payload): ?array {
		$caseTypeId = (string)($payload['caseType'] ?? '');
		if ($caseTypeId === '' || (string)($payload['title'] ?? '') === '') {
			$this->logger->warning(
				'Dossiq: createDraft() — missing caseType or title',
				['app' => Application::APP_ID]
			);
			return null;
		}

		$version = (int)($payload['version'] ?? 0);
		if ($version <= 0) {
			$version = $this->repository->nextVersionFor(caseTypeId: $caseTypeId);
		}

		$stepsValue = $this->encodeJsonProperty(value: ($payload['steps'] ?? []));
		$transitionsValue = $this->encodeJsonProperty(value: ($payload['transitions'] ?? []));

		$draft = [
			'title' => (string)$payload['title'],
			'description' => (string)($payload['description'] ?? ''),
			'caseType' => $caseTypeId,
			'version' => $version,
			'isActive' => false,
			'isDraft' => true,
			'lifecycleStatus' => self::STATUS_DRAFT,
			'steps' => $stepsValue,
			'transitions' => $transitionsValue,
			'nodePositions' => (string)($payload['nodePositions'] ?? ''),
		];

		return $this->repository->save(payload: $draft);
	}//end createDraft()

	// -----------------------------------------------------------------
	// Internal helpers.
	// -----------------------------------------------------------------

	/**
	 * Internal — coerce a draft payload property to the JSON string the workflowTemplate schema
	 * stores. Values that are already strings are passed through untouched.
	 *
	 * @param mixed $value The raw payload property value.
	 *
	 * @return string|false The JSON string, or false when encoding fails.
	 */
	private function encodeJsonProperty(mixed $value): string|false {
		if (is_string($value) === true) {
			return $value;
		}

		return json_encode($value);
	}//end encodeJsonProperty()

	/**
	 * Internal — move the currently active definition of a caseType to deprecated+inactive, unless
	 * it is the row being published itself.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 * @param string $id The definition UUID being published.
	 *
	 * @return bool True when nothing had to change or the write succeeded.
	 */
	private function deprecatePreviousActive(string $caseTypeId, string $id): bool {
		$previousActive = $this->getActiveDefinitionFor(caseTypeId: $caseTypeId);
		if ($previousActive === null || (string)($previousActive['id'] ?? '') === $id) {
			return true;
		}

		$saved = $this->repository->save(
			payload: [
				'lifecycleStatus' => self::STATUS_DEPRECATED,
				'isActive' => false,
			],
			uuid: (string)$previousActive['id'],
		);

		return ($saved !== null);
	}//end deprecatePreviousActive()

	/**
	 * Build the saveObject payload that flips a draft to published+active,
	 * including the authorization-enriched transitions when any resolved.
	 *
	 * @param array<int, array<string, mixed>>|null $authoredTransitions Enriched transitions, or null when none.
	 *
	 * @return array<string, mixed> The publish payload.
	 */
	private function buildPublishPayload(?array $authoredTransitions): array {
		$payload = [
			'lifecycleStatus' => self::STATUS_PUBLISHED,
			'isActive' => true,
			'isDraft' => false,
		];

		if ($authoredTransitions !== null) {
			$payload['transitions'] = json_encode($authoredTransitions);
		}

		return $payload;
	}//end buildPublishPayload()

	/**
	 * Decode a JSON-encoded array property; returns an empty array on any
	 * decoding error or non-array payload.
	 *
	 * @param mixed $raw The raw property value
	 *
	 * @return array<int, mixed>
	 */
	private function decodeArray(mixed $raw): array {
		if (is_array($raw) === true) {
			return $raw;
		}

		if (is_string($raw) === false || $raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decodeArray()

	/**
	 * Build a title for a cloned draft.
	 *
	 * @param string $base The source title
	 *
	 * @return string
	 */
	private function cloneTitle(string $base): string {
		return rtrim($base) . ' (kopie)';
	}//end cloneTitle()
}//end class
