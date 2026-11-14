<?php

/**
 * Dossiq Workflow Template Loader.
 *
 * Loads the `workflowTemplate` a case runs on from OpenRegister, decodes
 * `transitions[]` and `steps[]` from JSON, and caches the result per-request to
 * avoid repeated lookups during a single transition.
 *
 * 🔴 RESOLUTION FOLLOWS THE CASE, NOT THE CASE TYPE, AND IT DID NOT USED TO.
 * This class searched `caseType = X AND isActive = true` and took the first row
 * the store returned. With exactly one active definition per case type that was
 * right by accident. A case type may now carry several ROUTES (see
 * openspec/specs/workflow-variants/spec.md), each with its own active
 * definition, and under that rule taking the first row is a coin flip: a case on
 * the spoedeisende route would be offered the ordinary route's transitions, with
 * no error and nothing in the log.
 *
 * So {@see self::getTemplateForCase()} is the entry point a caller with a case
 * in hand must use. It reads the case's own pin first, which is also the promise
 * `workflow-definition-model` already made about versions and this loader never
 * honoured. {@see self::getActiveTemplate()} answers for a case type alone, and
 * answers with its DEFAULT route.
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

use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use Psr\Log\LoggerInterface;

/**
 * Loads active workflow templates with per-request memoisation.
 *
 * @spec openspec/changes/archive/2026-05-11-status-transition-engine/tasks.md#T03
 */
class WorkflowTemplateLoader {

	/**
	 * Per-request cache keyed by caseTypeId. The value is either a decoded
	 * template array, or `false` to indicate a confirmed miss (so we don't
	 * re-query on every lookup).
	 *
	 * @var array<string, array<string, mixed>|false>
	 */
	private array $cache = [];

	/**
	 * Per-request cache of templates read by their own uuid, for pinned cases.
	 *
	 * @var array<string, array<string, mixed>|false>
	 */
	private array $byId = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config
	 * @param WorkflowDefinitionRepository $repository Reads a definition by uuid
	 * @param WorkflowLifecycleGuard $guard Resolves routes and the default route
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WorkflowDefinitionRepository $repository,
		private readonly WorkflowLifecycleGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the workflow template a case runs on.
	 *
	 * 🔑 THIS IS THE ONE TO CALL WHEN YOU HAVE A CASE. A case pinned to a
	 * definition runs THAT definition, even when a newer version of its route
	 * has since been published, and even when its case type carries other
	 * routes. Only an unpinned case falls through to the case type's default
	 * route.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed>|null The template with `transitions` and `steps` decoded, or null
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function getTemplateForCase(array $case): ?array {
		$pinned = $this->referenceId(value: ($case['workflowTemplate'] ?? ''));
		if ($pinned !== '') {
			$template = $this->findById(id: $pinned);
			if ($template !== null) {
				return $template;
			}

			$this->logger->warning(
				'WorkflowTemplateLoader: the case names a workflow definition that could not be read',
				['workflowTemplate' => $pinned, 'caseType' => (string)($case['caseType'] ?? '')],
			);
		}

		return $this->getActiveTemplate(caseTypeId: (string)($case['caseType'] ?? ''));
	}//end getTemplateForCase()

	/**
	 * Get one transition definition from the template a case runs on.
	 *
	 * @param array<string, mixed> $case The case row.
	 * @param string $transitionId Transition id from the template's transitions[].
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function getTransitionForCase(array $case, string $transitionId): ?array {
		return $this->transitionIn(
			template: $this->getTemplateForCase(case: $case),
			transitionId: $transitionId
		);
	}//end getTransitionForCase()

	/**
	 * Get the active workflow template of a caseType's DEFAULT route.
	 *
	 * Use {@see self::getTemplateForCase()} whenever a case is in hand. This
	 * method knows only the case type, so it can only answer for the route new
	 * cases take, and a case pinned to another route would get the wrong graph.
	 *
	 * @param string $caseTypeId The caseType UUID
	 *
	 * @return array<string, mixed>|null The template with `transitions` and `steps` decoded, or null when none active
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function getActiveTemplate(string $caseTypeId): ?array {
		if ($caseTypeId === '') {
			return null;
		}

		if (isset($this->cache[$caseTypeId]) === true) {
			return $this->cachedTemplate(caseTypeId: $caseTypeId);
		}

		$search = $this->templateSearch(caseTypeId: $caseTypeId);
		if ($search === null) {
			return $this->noTemplate(caseTypeId: $caseTypeId);
		}

		return $this->searchTemplate(objectService: $search[0], query: $search[1], caseTypeId: $caseTypeId);
	}//end getActiveTemplate()

	/**
	 * The object service and the active-template query for a case type.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return array{0: object, 1: array}|null Null when OpenRegister or the query is not available.
	 */
	private function templateSearch(string $caseTypeId): array|null {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$query = $this->activeTemplateQuery(caseTypeId: $caseTypeId);
		if ($query === null) {
			return null;
		}

		return [$objectService, $query];
	}//end templateSearch()

	/**
	 * Search the active template and remember the answer for the case type.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param array  $query         The active-template query.
	 * @param string $caseTypeId    The caseType UUID.
	 *
	 * @return array<string, mixed>|null The template, or null when none is active or the search failed.
	 */
	private function searchTemplate(object $objectService, array $query, string $caseTypeId): array|null {
		try {
			$found = $objectService->searchObjects($query);
		} catch (\Throwable $e) {
			$this->logger->error(
				'WorkflowTemplateLoader: searchObjects failed',
				['exception' => $e->getMessage(), 'caseType' => $caseTypeId],
			);
			$this->cache[$caseTypeId] = false;
			return null;
		}//end try

		return $this->rememberedTemplate(caseTypeId: $caseTypeId, found: $found);
	}//end searchTemplate()

	/**
	 * The cached answer for a case type: its template, or null when it had none.
	 *
	 * @param string $caseTypeId The case type UUID, cached.
	 *
	 * @return array|null
	 */
	private function cachedTemplate(string $caseTypeId): ?array {
		if ($this->cache[$caseTypeId] === false) {
			return null;
		}

		return $this->cache[$caseTypeId];
	}//end cachedTemplate()

	/**
	 * Remember that the case type has no template, and answer so.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return null
	 */
	private function noTemplate(string $caseTypeId): null {
		$this->cache[$caseTypeId] = false;
		return null;
	}//end noTemplate()

	/**
	 * The search for the case type's active templates, or null when the register is not configured.
	 *
	 * OpenRegister's ObjectService exposes `searchObjects($query)` — there is
	 * NO `findObjects()` method (its absence is what previously broke the
	 * engine: the call threw and every lookup returned empty). The
	 * register/schema context lives under the `@self` block; object field
	 * filters (caseType, isActive) sit at the top level and are applied as
	 * server-side equality matches.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return array|null
	 */
	private function activeTemplateQuery(string $caseTypeId): ?array {
		$register = $this->settingsService->getConfigValue(key: 'register');
		$templateSchema = $this->settingsService->getConfigValue(key: 'workflow_template_schema');
		if ($register === '' || $templateSchema === '') {
			return null;
		}

		return [
			'@self' => [
				'register' => (int)$register,
				'schema' => (int)$templateSchema,
			],
			'caseType' => $caseTypeId,
			'isActive' => true,
		];
	}//end activeTemplateQuery()

	/**
	 * The default among the active templates found, decoded and cached; none caches the miss.
	 *
	 * The normalise() helper already coerces any non-array result (e.g. the
	 * int that searchObjects() returns in count mode) to an empty list.
	 *
	 * @param string $caseTypeId The case type UUID.
	 * @param mixed  $found      What the search answered.
	 *
	 * @return array|null
	 */
	private function rememberedTemplate(string $caseTypeId, mixed $found): ?array {
		$templates = $this->normalise(value: $found);
		if (count($templates) === 0) {
			return $this->noTemplate(caseTypeId: $caseTypeId);
		}

		$template = $this->decoded(
			template: $this->guard->defaultAmong(active: $templates, caseTypeId: $caseTypeId)
		);

		$this->cache[$caseTypeId] = $template;
		return $template;
	}//end rememberedTemplate()

	/**
	 * Convenience: get a single transition from a caseType's default route.
	 *
	 * @param string $caseTypeId CaseType UUID
	 * @param string $transitionId Transition id (from the template's transitions[])
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function getTransitionById(string $caseTypeId, string $transitionId): ?array {
		return $this->transitionIn(
			template: $this->getActiveTemplate(caseTypeId: $caseTypeId),
			transitionId: $transitionId
		);
	}//end getTransitionById()

	/**
	 * Find one transition inside an already-resolved template.
	 *
	 * @param array<string, mixed>|null $template The resolved template, or null.
	 * @param string $transitionId The transition id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function transitionIn(?array $template, string $transitionId): ?array {
		$transitions = ($template['transitions'] ?? []);
		if (is_array($transitions) === false) {
			return null;
		}

		foreach ($transitions as $transition) {
			if (is_array($transition) === true && (string)($transition['id'] ?? '') === $transitionId) {
				return $transition;
			}
		}

		return null;
	}//end transitionIn()

	/**
	 * Read one workflow definition by its own uuid, for a pinned case.
	 *
	 * Goes through the definition repository rather than this class's own
	 * search: reading a row by uuid is exactly what that repository is for, and
	 * a second copy of the read here would be a second place for the schema
	 * configuration to be wrong.
	 *
	 * @param string $id The definition UUID.
	 *
	 * @return array<string, mixed>|null The decoded definition, or null.
	 */
	private function findById(string $id): ?array {
		if (array_key_exists($id, $this->byId) === true) {
			$cached = $this->byId[$id];
			if ($cached === false) {
				return null;
			}

			return $cached;
		}

		$row = $this->repository->findById(id: $id);
		if ($row === null) {
			$this->byId[$id] = false;
			return null;
		}

		$this->byId[$id] = $this->decoded(template: $row);

		return $this->byId[$id];
	}//end findById()

	/**
	 * The uuid a reference property holds, whether it answered as a plain uuid
	 * or as the expanded object.
	 *
	 * @param mixed $value The reference property value.
	 *
	 * @return string The uuid, or the empty string.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value['id'] ?? ($value['uuid'] ?? ''));
		}

		return (string)$value;
	}//end referenceId()

	/**
	 * Decode a template's JSON-string fields.
	 *
	 * @param array<string, mixed> $template The template.
	 *
	 * @return array<string, mixed> The template with `transitions` and `steps` decoded.
	 */
	private function decoded(array $template): array {
		$this->decodeJsonField(template: $template, field: 'transitions');
		$this->decodeJsonField(template: $template, field: 'steps');

		return $template;
	}//end decoded()

	/*
	 * NO clearCache() HERE.
	 *
	 * It emptied the per-request memo below, had no caller in
	 * `StatusTransitionService`, and the memo does not outlive the request.
	 */

	/**
	 * Normalise the result of ObjectService::searchObjects() to a list of arrays.
	 *
	 * @param mixed $value Raw result
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalise(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$list = [];
		foreach ($value as $item) {
			if (is_array($item) === true) {
				$list[] = $item;
				continue;
			}

			if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
				$serialized = $item->jsonSerialize();
				if (is_array($serialized) === true) {
					$list[] = $serialized;
				}
			}
		}

		return $list;
	}//end normalise()

	/**
	 * Decode a JSON-string field on the template in place.
	 *
	 * @param array<string, mixed> $template The template (passed by reference)
	 * @param string $field The field name
	 *
	 * @return void
	 */
	private function decodeJsonField(array &$template, string $field): void {
		$value = $template[$field] ?? null;
		if (is_string($value) === true && $value !== '') {
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				$template[$field] = $decoded;
			}
		}
	}//end decodeJsonField()
}//end class
