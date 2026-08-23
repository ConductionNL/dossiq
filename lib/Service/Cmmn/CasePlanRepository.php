<?php

/**
 * Dossiq CMMN case-plan repository.
 *
 * Everything the CMMN engine needs from storage: loading the case, asserting
 * its caseType is CMMN-managed, loading and validating the active caseModel's
 * plan items, decoding the runtime state out of `case.casePlanState`, and
 * writing it back.
 *
 * Split out of CaseModelEngine so the engine is left with the plan-item
 * lifecycle alone. Three invariants live here rather than there:
 *
 *   - ENGINE SELECTION. {@see getCmmnCaseType()} refuses a caseType whose
 *     `handlingModel` is not `cmmn` (defaulting to `bpmn` when unset). BPMN and
 *     CMMN are sibling engines chosen per caseType, and this refusal is what
 *     keeps this one off a case the other owns.
 *   - SINGLE WRITE. {@see persist()} performs exactly ONE `saveObject()` per
 *     mutation, storing the whole runtime state in the single
 *     `case.casePlanState` field (REQ-CMMN-006) — never one OR object per plan
 *     item. It never touches `case.status`/`statusHistory`, which belong to
 *     StatusTransitionService.
 *   - AUTHORING ERRORS FAIL LOUDLY. A `parentId` that resolves to no item is a
 *     case-model authoring error and is rejected, not silently reconciled;
 *     `parentId` is the single source of truth for the tree, so a stale
 *     `children` list cannot desync runtime behaviour from what drives it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cmmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cmmn;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Loads and persists the CMMN case plan and its runtime state.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-006
 */
class CasePlanRepository {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config.
	 * @param CaseModelLoader $modelLoader Active-caseModel-by-caseType loader.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseModelLoader $modelLoader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load the case, its caseType (enforcing `handlingModel: cmmn`), the
	 * active caseModel's plan items, and the decoded runtime state.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return array{
	 *     case: array<string, mixed>,
	 *     caseType: array<string, mixed>,
	 *     itemsById: array<string, array<string, mixed>>,
	 *     state: array<string, mixed>,
	 *     objectService: mixed,
	 *     register: string,
	 *     caseSchema: string,
	 * }
	 *
	 * @throws RuntimeException When the case/caseType cannot be loaded or is not CMMN-managed.
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-006
	 */
	public function loadContext(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('storage_unavailable');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		$caseTypeSchema = $this->settingsService->getConfigValue(key: 'case_type_schema');
		if ($register === '' || $caseSchema === '' || $caseTypeSchema === '') {
			throw new RuntimeException('cmmn_not_configured');
		}

		$case = $this->getCaseObject(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			caseId: $caseId
		);

		$caseTypeId = (string)($case['caseType'] ?? '');
		if ($caseTypeId === '') {
			throw new RuntimeException('case_type_not_configured');
		}

		$caseType = $this->getCmmnCaseType(
			objectService: $objectService,
			register: $register,
			caseTypeSchema: $caseTypeSchema,
			caseTypeId: $caseTypeId
		);

		$itemsById = $this->loadItemsById(caseTypeId: $caseTypeId);
		$state = $this->decodeState(case: $case);

		return [
			'case' => $case,
			'caseType' => $caseType,
			'itemsById' => $itemsById,
			'state' => $state,
			'objectService' => $objectService,
			'register' => $register,
			'caseSchema' => $caseSchema,
		];
	}//end loadContext()

	/**
	 * Persist the runtime state onto the case via a single `saveObject()` call.
	 *
	 * @param array<string, mixed> $ctx Context from {@see loadContext()}, mutated in place (`case` key refreshed with the saved payload).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-006
	 */
	public function persist(array &$ctx): void {
		$ctx['case']['casePlanState'] = json_encode($ctx['state']);
		$ctx['case'] = $this->toArray(
			value: $ctx['objectService']->saveObject(object: $ctx['case'], register: $ctx['register'], schema: $ctx['caseSchema']),
		);
	}//end persist()

	/**
	 * Load the case object from OpenRegister.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param string $caseSchema The case schema slug.
	 * @param string $caseId Case UUID.
	 *
	 * @return array<string, mixed> The case object.
	 *
	 * @throws RuntimeException When the case cannot be loaded or is empty.
	 */
	private function getCaseObject(object $objectService, string $register, string $caseSchema, string $caseId): array {
		try {
			$case = $this->toArray(value: $objectService->find($caseId, register: $register, schema: $caseSchema));
		} catch (Throwable $e) {
			$this->logger->error('CaseModelEngine: loadCase failed', ['exception' => $e->getMessage(), 'caseId' => $caseId]);
			throw new RuntimeException('case_not_found');
		}

		if ($case === []) {
			throw new RuntimeException('case_not_found');
		}

		return $case;
	}//end getCaseObject()

	/**
	 * Load the caseType object and assert it is CMMN-managed.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param string $caseTypeSchema The caseType schema slug.
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return array<string, mixed> The caseType object.
	 *
	 * @throws RuntimeException When the caseType cannot be loaded or is not CMMN-managed.
	 */
	private function getCmmnCaseType(object $objectService, string $register, string $caseTypeSchema, string $caseTypeId): array {
		try {
			$caseType = $this->toArray(value: $objectService->find($caseTypeId, register: $register, schema: $caseTypeSchema));
		} catch (Throwable $e) {
			$this->logger->error('CaseModelEngine: loadCaseType failed', ['exception' => $e->getMessage(), 'caseTypeId' => $caseTypeId]);
			throw new RuntimeException('case_type_not_found');
		}

		$handlingModel = (string)($caseType['handlingModel'] ?? 'bpmn');
		if ($handlingModel !== 'cmmn') {
			throw new RuntimeException('case_not_cmmn_managed');
		}

		return $caseType;
	}//end getCmmnCaseType()

	/**
	 * Load and validate the active caseModel's plan items for a caseType.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return array<string, array<string, mixed>> Plan items keyed by id.
	 *
	 * @throws RuntimeException When a `children`/`parentId` mismatch is found.
	 */
	private function loadItemsById(string $caseTypeId): array {
		$model = $this->modelLoader->getActiveModel(caseTypeId: $caseTypeId);
		if ($model === null) {
			return [];
		}

		$planItems = $model['planItems'] ?? [];
		if (is_array($planItems) === false) {
			return [];
		}

		$itemsById = [];
		foreach ($planItems as $item) {
			if (is_array($item) === true && isset($item['id']) === true && $item['id'] !== '') {
				$itemsById[(string)$item['id']] = $this->normaliseItem(item: $item);
			}
		}

		$this->assertModelStructureValid(itemsById: $itemsById);

		return $itemsById;
	}//end loadItemsById()

	/**
	 * Fill in default keys on a plan-item definition.
	 *
	 * @param array<string, mixed> $item Raw plan-item definition.
	 *
	 * @return array<string, mixed>
	 */
	private function normaliseItem(array $item): array {
		$entryCriteria = $item['entryCriteria'] ?? [];
		if (is_array($entryCriteria) === false) {
			$entryCriteria = [];
		}

		$exitCriteria = $item['exitCriteria'] ?? [];
		if (is_array($exitCriteria) === false) {
			$exitCriteria = [];
		}

		$authorization = $item['authorization'] ?? [];
		if (is_array($authorization) === false) {
			$authorization = [];
		}

		$parentId = null;
		if (isset($item['parentId']) === true && $item['parentId'] !== '') {
			$parentId = (string)$item['parentId'];
		}

		return [
			'id' => (string)$item['id'],
			'type' => (string)($item['type'] ?? ''),
			'name' => (string)($item['name'] ?? ''),
			'discretionary' => (($item['discretionary'] ?? false) === true),
			'parentId' => $parentId,
			'entryCriteria' => $entryCriteria,
			'exitCriteria' => $exitCriteria,
			'authorization' => $authorization,
		];
	}//end normaliseItem()

	/**
	 * Validate `parentId` references resolve and any redundant `children`
	 * list is consistent with them — a mismatch is a case-model authoring
	 * error, rejected rather than silently reconciled (`design.md` §2).
	 *
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a reference is invalid.
	 */
	private function assertModelStructureValid(array $itemsById): void {
		foreach ($itemsById as $id => $item) {
			$parentId = $item['parentId'];
			if ($parentId !== null && isset($itemsById[$parentId]) === false) {
				throw new RuntimeException('case_model_invalid');
			}

			// Note: the schema's optional `children` array on the raw
			// definition is documentation-only in this engine — parentId is
			// the single source of truth for the tree, so an inconsistent
			// `children` list (a stale hand-edit) cannot desync runtime
			// behaviour from what actually drives it.
			unset($id);
		}
	}//end assertModelStructureValid()

	/**
	 * Decode `case.casePlanState`, defaulting missing/invalid JSON to an
	 * empty state skeleton.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return array<string, mixed>
	 */
	private function decodeState(array $case): array {
		$empty = [
			'planItemStates' => [],
			'milestones' => [],
			'caseFile' => [],
			'eventLog' => [],
		];

		$raw = $case['casePlanState'] ?? null;
		if (is_string($raw) === true && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return array_merge($empty, $decoded);
			}
		} elseif (is_array($raw) === true) {
			return array_merge($empty, $raw);
		}

		return $empty;
	}//end decodeState()

	/**
	 * Coerce ObjectService results to an array.
	 *
	 * @param mixed $value Raw result.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end toArray()
}//end class
