<?php

/**
 * Procest Workflow Template Loader.
 *
 * Loads the single active `workflowTemplate` for a given `caseType` from
 * OpenRegister, decodes `transitions[]` and `steps[]` from JSON, and caches
 * the result per-request to avoid repeated lookups during a single transition.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Loads active workflow templates with per-request memoisation.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T03
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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the active workflow template for a caseType.
	 *
	 * @param string $caseTypeId The caseType UUID
	 *
	 * @return array<string, mixed>|null The template with `transitions` and `steps` decoded, or null when none active
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function getActiveTemplate(string $caseTypeId): ?array {
		if ($caseTypeId === '') {
			return null;
		}

		if (isset($this->cache[$caseTypeId]) === true) {
			if ($this->cache[$caseTypeId] === false) {
				return null;
			}

			return $this->cache[$caseTypeId];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$this->cache[$caseTypeId] = false;
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$templateSchema = $this->settingsService->getConfigValue(key: 'workflow_template_schema');
		if ($register === '' || $templateSchema === '') {
			$this->cache[$caseTypeId] = false;
			return null;
		}

		try {
			// OpenRegister's ObjectService exposes `searchObjects($query)` —
			// there is NO `findObjects()` method (its absence is what previously
			// broke the engine: the call threw and every lookup returned empty).
			// The register/schema context lives under the `@self` block; object
			// field filters (caseType, isActive) sit at the top level and are
			// applied as server-side equality matches.
			$found = $objectService->searchObjects(
				[
					'@self' => [
						'register' => (int)$register,
						'schema' => (int)$templateSchema,
					],
					'caseType' => $caseTypeId,
					'isActive' => true,
				],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'WorkflowTemplateLoader: searchObjects failed',
				['exception' => $e->getMessage(), 'caseType' => $caseTypeId],
			);
			$this->cache[$caseTypeId] = false;
			return null;
		}//end try

		// The normalise() helper already coerces any non-array result (e.g. the
		// int that searchObjects() returns in count mode) to an empty list.
		$templates = $this->normalise(value: $found);
		if (count($templates) === 0) {
			$this->cache[$caseTypeId] = false;
			return null;
		}

		$template = $templates[0];
		$this->decodeJsonField(template: $template, field: 'transitions');
		$this->decodeJsonField(template: $template, field: 'steps');

		$this->cache[$caseTypeId] = $template;
		return $template;
	}//end getActiveTemplate()

	/**
	 * Convenience: get a single transition definition by its id.
	 *
	 * @param string $caseTypeId CaseType UUID
	 * @param string $transitionId Transition id (from the template's transitions[])
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function getTransitionById(string $caseTypeId, string $transitionId): ?array {
		$template = $this->getActiveTemplate(caseTypeId: $caseTypeId);
		if ($template === null) {
			return null;
		}

		$transitions = $template['transitions'] ?? [];
		if (is_array($transitions) === false) {
			return null;
		}

		foreach ($transitions as $transition) {
			if (is_array($transition) === false) {
				continue;
			}

			if ((string)($transition['id'] ?? '') === $transitionId) {
				return $transition;
			}
		}

		return null;
	}//end getTransitionById()

	/*
	 * NO clearCache() HERE.
	 *
	 * Same as `Cmmn\CaseModelLoader`: it emptied the per-request memo below,
	 * had no caller in either consumer (`Cmmn\CaseModelLoader`,
	 * `StatusTransitionService`), and the memo does not outlive the request.
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
