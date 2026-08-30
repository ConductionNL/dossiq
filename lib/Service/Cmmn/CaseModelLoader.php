<?php

/**
 * Dossiq CMMN Case-Model Loader.
 *
 * Loads the single active (`lifecycleStatus: published`) `caseModel` for a
 * given `caseType` from OpenRegister, decodes `caseFileItems[]`/`planItems[]`
 * from JSON where needed, and caches the result per-request — a straight
 * port of `WorkflowTemplateLoader`'s lookup-by-caseType pattern onto the
 * CMMN definition schema (`design.md` §2).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cmmn
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
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cmmn;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Loads the active caseModel per caseType, memoised per request.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
 */
class CaseModelLoader {

	/**
	 * Per-request cache keyed by caseTypeId. `false` = confirmed miss.
	 *
	 * @var array<string, array<string, mixed>|false>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the active (published) caseModel for a caseType.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return array<string, mixed>|null The model, or null when none published.
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
	 */
	public function getActiveModel(string $caseTypeId): ?array {
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
		$modelSchema = $this->settingsService->getConfigValue(key: 'case_model_schema');
		if ($register === '' || $modelSchema === '') {
			$this->cache[$caseTypeId] = false;
			return null;
		}

		try {
			$found = $objectService->searchObjects(
				[
					'@self' => [
						'register' => (int)$register,
						'schema' => (int)$modelSchema,
					],
					'caseType' => $caseTypeId,
					'lifecycleStatus' => 'published',
				],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseModelLoader: searchObjects failed',
				['exception' => $e->getMessage(), 'caseType' => $caseTypeId],
			);
			$this->cache[$caseTypeId] = false;
			return null;
		}//end try

		$models = $this->normalise(value: $found);
		if (count($models) === 0) {
			$this->cache[$caseTypeId] = false;
			return null;
		}

		$model = $models[0];
		$this->decodeJsonField(model: $model, field: 'planItems');
		$this->decodeJsonField(model: $model, field: 'caseFileItems');

		$this->cache[$caseTypeId] = $model;
		return $model;
	}//end getActiveModel()

	/**
	 * Convenience: get a single plan item definition by its id.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 * @param string $itemId Plan-item id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
	 */
	public function getPlanItemById(string $caseTypeId, string $itemId): ?array {
		$model = $this->getActiveModel(caseTypeId: $caseTypeId);
		if ($model === null) {
			return null;
		}

		$planItems = $model['planItems'] ?? [];
		if (is_array($planItems) === false) {
			return null;
		}

		foreach ($planItems as $item) {
			if (is_array($item) === true && (string)($item['id'] ?? '') === $itemId) {
				return $item;
			}
		}

		return null;
	}//end getPlanItemById()

	/*
	 * NO clearCache() HERE.
	 *
	 * It emptied the per-request memo below and had no caller anywhere in the
	 * app — `CasePlanRepository`, its only consumer, reads through `load()`.
	 * The memo does not outlive the request, so there is no moment at which
	 * something would need clearing.
	 */

	/**
	 * Decode a JSON-encoded-string field on the model into a native array,
	 * in place. A field that is already a native array (e.g. a test fixture)
	 * or missing/invalid is left as an empty array rather than throwing.
	 *
	 * @param array<string, mixed> $model Model payload, modified by reference.
	 * @param string $field Field name to decode.
	 *
	 * @return void
	 */
	private function decodeJsonField(array &$model, string $field): void {
		$value = $model[$field] ?? null;
		if (is_array($value) === true) {
			return;
		}

		if (is_string($value) === true && $value !== '') {
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				$model[$field] = $decoded;
				return;
			}
		}

		$model[$field] = [];
	}//end decodeJsonField()

	/**
	 * Normalise the result of ObjectService::searchObjects() to a list of arrays.
	 *
	 * @param mixed $value Raw result.
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
}//end class
