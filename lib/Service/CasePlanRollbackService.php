<?php

/**
 * The reverse projection: OpenRegister case-item rows back into a blob.
 *
 * This is the R1 rollback of retire-cmmn-caseplanstate (design.md section 4).
 * The bridge release prefers OpenRegister's rows; rolling back is two moves,
 * and this is the second one. First set `cmmn_prefer_openregister_case_plan`
 * to `no`, which sends the panel back to the local engine for every case that
 * still has a blob. Then run this for the cases that no longer have one.
 *
 * IT IS TOTAL, and that is not luck. A row carries strictly more than the blob
 * ever did: the six states are identical on both sides, and everything the
 * blob held (item state, milestone achievement, the event log) is a projection
 * of a row or an audit entry. So the mapping loses nothing, and the direction
 * that CAN lose something, blob to rows, is the migration in group 2 and is
 * deliberately not here.
 *
 * IT NEVER DELETES A ROW. Rolling back writes a blob and stops. OpenRegister
 * keeps its plan, so rolling forward again is flipping the flag back, with no
 * second migration and no window in which a case has neither.
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
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Regenerates a `casePlanState` blob from OpenRegister's plan-item rows.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
class CasePlanRollbackService {

	use SearchesObjects;

	/**
	 * OpenRegister's case layer, resolved by name.
	 *
	 * @var string
	 */
	public const CASE_PLAN_SERVICE = CasePlanProjectionService::CASE_PLAN_SERVICE;

	/**
	 * The empty blob, and the authority on its four keys.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const EMPTY_BLOB = [
		'planItemStates' => [],
		'milestones' => [],
		'caseFile' => [],
		'eventLog' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and config.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build a `casePlanState` blob from a plan as OpenRegister answers it.
	 *
	 * Pure: no I/O, no container.
	 *
	 * Rows are keyed by `key`, the definition item id, because that is what the
	 * engine's blob named and what a sentry's `onPart.planItem` still names.
	 * Keying on the row uuid would produce a blob the engine cannot read: every
	 * item id in it would name nothing in the caseModel, and the engine answers
	 * an unknown id with its initial state rather than an error.
	 *
	 * @param array<string, mixed> $plan  The plan (`items`, `audit`).
	 * @param array<string, mixed> $caseFile Case-file values to carry, when any.
	 *
	 * @return array<string, mixed> The blob: `planItemStates`, `milestones`,
	 *                              `caseFile` and `eventLog`.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function toBlob(array $plan, array $caseFile = []): array {
		$items = ($plan['items'] ?? []);
		if (is_array($items) === false) {
			$items = [];
		}

		$states = [];
		$milestones = [];
		$keyById = [];
		foreach ($items as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$key = trim((string)($row['key'] ?? ''));
			if ($key === '') {
				continue;
			}

			$keyById[(string)($row['id'] ?? '')] = $key;
			$states[$key] = (string)($row['state'] ?? 'available');

			if ((string)($row['type'] ?? '') === 'milestone' && $states[$key] === 'completed') {
				$milestones[$key] = [
					'achieved' => true,
					'achievedAt' => (string)($row['enteredAt'] ?? $row['updated'] ?? ''),
				];
			}
		}

		return [
			'planItemStates' => $states,
			'milestones' => $milestones,
			'caseFile' => $caseFile,
			'eventLog' => $this->toEventLog(audit: ($plan['audit'] ?? []), keyById: $keyById),
		];
	}//end toBlob()

	/**
	 * Regenerate one case's blob from the rows OpenRegister holds, and write it.
	 *
	 * @param string $caseId The case object uuid.
	 *
	 * @return array<string, mixed> `caseId`, `written`, `reason` and `items`.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function rollbackCase(string $caseId): array {
		$built = $this->buildFor(caseId: $caseId);
		if ($built['blob'] === null) {
			return $built['report'];
		}

		return $this->write(caseId: $caseId, blob: $built['blob'], count: $built['report']['items']);
	}//end rollbackCase()

	/**
	 * Report what a rollback would write for one case, and write nothing.
	 *
	 * A separate method rather than a `$dryRun` argument: the two answer
	 * different questions, and a caller that got the boolean the wrong way
	 * round would write a blob onto a case somebody meant only to inspect.
	 *
	 * @param string $caseId The case object uuid.
	 *
	 * @return array<string, mixed> `caseId`, `written`, `reason` and `items`.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function previewCase(string $caseId): array {
		$built = $this->buildFor(caseId: $caseId);
		if ($built['blob'] === null) {
			return $built['report'];
		}

		return [
			'caseId' => $caseId,
			'written' => false,
			'reason' => 'dry_run',
			'items' => $built['report']['items'],
		];
	}//end previewCase()

	/**
	 * Read the plan and build the blob, without writing anything.
	 *
	 * @param string $caseId The case object uuid.
	 *
	 * @return array{blob: array<string, mixed>|null, report: array<string, mixed>} The blob, or null with the reason.
	 */
	private function buildFor(string $caseId): array {
		$refused = static fn (string $reason, int $items = 0): array => [
			'blob' => null,
			'report' => ['caseId' => $caseId, 'written' => false, 'reason' => $reason, 'items' => $items],
		];

		$plans = $this->settingsService->getOpenRegisterClass(class: self::CASE_PLAN_SERVICE);
		if ($plans === null) {
			return $refused('case_layer_unavailable');
		}

		try {
			$plan = $plans->getPlan(objectUuid: $caseId, uid: null);
		} catch (Throwable $e) {
			return $refused('no_plan_in_openregister');
		}

		if (is_array($plan) === false) {
			$plan = [];
		}

		$blob = $this->toBlob(plan: $plan);
		$count = count($blob['planItemStates']);
		if ($count === 0) {
			return $refused('plan_has_no_items');
		}

		return [
			'blob' => $blob,
			'report' => ['caseId' => $caseId, 'written' => false, 'reason' => 'built', 'items' => $count],
		];
	}//end buildFor()

	/**
	 * Write the regenerated blob onto the case.
	 *
	 * 🔴 A PARTIAL WRITE, AND IT HAS TO BE. `saveObject()` with a uuid is
	 * PUT-semantic: the payload IS the new object, so a save carrying
	 * `casePlanState` alone nulls every other property of the case or is
	 * refused outright for missing a required one. `patchObjectAsArray()` is
	 * the seam that reads, merges and writes.
	 *
	 * @param string               $caseId The case object uuid.
	 * @param array<string, mixed> $blob   The regenerated blob.
	 * @param integer              $count  How many items it records.
	 *
	 * @return array{caseId: string, written: boolean, reason: string, items: integer} What it did.
	 */
	private function write(string $caseId, array $blob, int $count): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return ['caseId' => $caseId, 'written' => false, 'reason' => 'storage_unavailable', 'items' => $count];
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				// The property is declared `type: string` and read back
				// decoded; the engine's own repository writes it encoded, and
				// so does this.
				changes: ['casePlanState' => json_encode($blob)],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'CasePlanRollbackService: could not write the regenerated blob',
				['case' => $caseId, 'exception' => $e->getMessage()]
			);

			return ['caseId' => $caseId, 'written' => false, 'reason' => $e->getMessage(), 'items' => $count];
		}

		return ['caseId' => $caseId, 'written' => true, 'reason' => 'rolled_back', 'items' => $count];
	}//end write()

	/**
	 * Turn OpenRegister's audit entries into the blob's event log.
	 *
	 * An entry whose item is not in the plan is dropped rather than written
	 * with an id naming nothing: the engine reads the log by item id, and an
	 * unresolvable one would read as an item stuck in its initial state.
	 *
	 * @param mixed                 $audit   The audit entries.
	 * @param array<string, string> $keyById Row id to definition item key.
	 *
	 * @return array<int, array<string, mixed>> The event log.
	 */
	private function toEventLog(mixed $audit, array $keyById): array {
		if (is_array($audit) === false) {
			return [];
		}

		$log = [];
		foreach ($audit as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$key = ($keyById[(string)($entry['caseItemId'] ?? '')] ?? null);
			if ($key === null) {
				continue;
			}

			$log[] = [
				'at' => (string)($entry['created'] ?? ''),
				'itemId' => $key,
				'itemType' => '',
				'from' => (string)($entry['fromState'] ?? ''),
				'to' => (string)($entry['toState'] ?? ''),
			];
		}

		return $log;
	}//end toEventLog()
}//end class
