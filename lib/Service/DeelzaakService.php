<?php

/**
 * Procest Deelzaak (sub-case) Service
 *
 * Backend support for parent-child case relations: efficient sub-case
 * lookup, batch counts (used by the case list to avoid N+1 queries), parent
 * fetch with metadata, deletion safeguards, and constraint validation
 * against `caseType.subCaseTypes`.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\Deelzaak\CaseObjectReader;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Service for parent-child (deelzaak) case relations.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */
class DeelzaakService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared OR/settings resolver.
	 * @param LoggerInterface $logger Logger.
	 * @param CaseObjectReader $caseReader Single-object case/caseType lookups.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly CaseObjectReader $caseReader,
	) {
	}//end __construct()

	/**
	 * Fetch every sub-case linked to the given parent.
	 *
	 * @param string $parentCaseUuid Parent case UUID.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/deelzaak-support/tasks.md#T01
	 */
	public function listSubCases(string $parentCaseUuid): array {
		if ($parentCaseUuid === '') {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'parentCase' => $parentCaseUuid,
				'_limit' => 200,
			],
		);
	}//end listSubCases()

	/**
	 * Single-query sub-case counts keyed by parent UUID.
	 *
	 * The frontend case list calls this once per page so badge rendering
	 * never fires N independent network requests.
	 *
	 * @param array<int, string> $parentUuids Parent case UUIDs to count.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/deelzaak-support/tasks.md#T03
	 */
	public function getSubCaseCounts(array $parentUuids): array {
		$counts = $this->initialiseCountBuckets(parentUuids: $parentUuids);
		if ($counts === []) {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $counts;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if (empty($register) === true || empty($schema) === true) {
			return $counts;
		}

		// OR pre-filter on `parentCase != null`; we still need to bucket by parent
		// in PHP because OR doesn't expose a native group-by here, but it's one
		// round trip rather than N.
		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'_limit' => 5000,
				// Limit to children of the requested parents to keep the page small.
				'parentCase' => array_keys($counts),
			],
		);

		foreach ($rows as $row) {
			$parent = (string)($row['parentCase'] ?? '');
			if ($parent !== '' && isset($counts[$parent]) === true) {
				$counts[$parent]++;
			}
		}

		return $counts;
	}//end getSubCaseCounts()

	/**
	 * Seed a zero count for every usable parent UUID, dropping non-string and empty entries.
	 *
	 * @param array<int, string> $parentUuids Parent case UUIDs to count.
	 *
	 * @return array<string, int> Zero-valued buckets, keyed by parent UUID.
	 */
	private function initialiseCountBuckets(array $parentUuids): array {
		$counts = [];
		foreach ($parentUuids as $uuid) {
			if (is_string($uuid) === true && $uuid !== '') {
				$counts[$uuid] = 0;
			}
		}

		return $counts;
	}//end initialiseCountBuckets()

	/**
	 * Fetch the PARENT of a sub-case, by dereferencing the child's
	 * `parentCase` relation.
	 *
	 * The argument is the CHILD (sub-case) UUID — this method loads that
	 * child, reads its `parentCase` field, and returns the case it points
	 * at. Returns null when the child has no parent (it is not a sub-case),
	 * when the referenced parent no longer exists, or when the reference is
	 * self-pointing (a data-integrity guard so we never echo the child back
	 * as its own parent).
	 *
	 * @param string $childCaseUuid Sub-case (child) UUID.
	 *
	 * @return array<string, mixed>|null The parent case, or null.
	 *
	 * @spec openspec/changes/deelzaak-support/tasks.md#T02
	 */
	public function getParentCase(string $childCaseUuid): ?array {
		if ($childCaseUuid === '') {
			return null;
		}

		$child = $this->caseReader->fetchCaseById(caseUuid: $childCaseUuid);
		if ($child === null) {
			return null;
		}

		$parentRef = $this->caseReader->extractParentReference(case: $child);
		if ($parentRef === '' || $parentRef === $childCaseUuid) {
			// No parent (not a sub-case) or a self-reference — nothing to
			// dereference. Never return the child as its own parent.
			return null;
		}

		return $this->caseReader->fetchCaseById(caseUuid: $parentRef);
	}//end getParentCase()

	/**
	 * Validate that creating a sub-case is allowed.
	 *
	 * Rules (matched against the spec acceptance criteria):
	 *   1. Parent must exist.
	 *   2. Parent must not itself be a sub-case (no grandparenting).
	 *   3. Parent must not be closed (`endDate` null).
	 *   4. The chosen child caseType must appear in the parent caseType's
	 *      `subCaseTypes` allow-list.
	 *
	 * @param string $parentCaseUuid Parent UUID.
	 * @param string $childCaseTypeId Child caseType id/slug.
	 *
	 * @return array{ok: bool, reason?: string}
	 *
	 * @spec openspec/changes/deelzaak-support/tasks.md#T08
	 */
	public function validateCreate(string $parentCaseUuid, string $childCaseTypeId): array {
		// `validateCreate` receives the PARENT's own UUID (the proposed
		// parent of a new sub-case), so fetch that case directly rather than
		// dereferencing a `parentCase` relation.
		$parent = $this->caseReader->fetchCaseById(caseUuid: $parentCaseUuid);
		if ($parent === null) {
			return ['ok' => false, 'reason' => 'parent_not_found'];
		}

		if (empty($parent['parentCase']) === false) {
			return ['ok' => false, 'reason' => 'grandparenting_forbidden'];
		}

		if (empty($parent['endDate']) === false) {
			return ['ok' => false, 'reason' => 'parent_closed'];
		}

		$parentCaseTypeId = (string)($parent['caseType'] ?? '');
		if ($parentCaseTypeId === '') {
			return ['ok' => false, 'reason' => 'parent_missing_case_type'];
		}

		$parentCaseType = $this->caseReader->loadCaseType(caseTypeId: $parentCaseTypeId);
		if ($parentCaseType === null) {
			return ['ok' => false, 'reason' => 'parent_case_type_not_found'];
		}

		$allowed = (array)($parentCaseType['subCaseTypes'] ?? []);
		if ($allowed === [] || in_array($childCaseTypeId, $allowed, true) === false) {
			return ['ok' => false, 'reason' => 'case_type_not_allowed'];
		}

		return ['ok' => true];
	}//end validateCreate()

	/**
	 * Unlink every sub-case of the given parent — used by the delete-with-children
	 * confirmation flow to leave orphans accessible at the registry level.
	 *
	 * ⚠️ This is a synchronous fan-out of `saveObject()` on the request path,
	 * the `openregister#2420` shape (procest#793). Two things are true about it
	 * and they pull in opposite directions, so read both before "just deferring
	 * it to a background job":
	 *
	 * 1. The cost is real. Each iteration is a full OpenRegister save —
	 *    validation, shard write, event dispatch, every registered listener on
	 *    `ObjectUpdatedEvent`. gate-61 cannot see this because it inspects
	 *    post-event listeners and this is a plain service seam.
	 * 2. **The caller cannot tolerate deferral as-is.**
	 *    `DeelzaakDeleteWarningModal::confirmDelete()` awaits this call and then
	 *    deletes the parent. Enqueue-and-return would delete the parent while
	 *    the children still point at it, so the "orphans survive" guarantee
	 *    (REQ-DZS-006-B) would be broken by the very fix meant to protect it.
	 *    Deferral therefore needs the delete moved into the same job — an
	 *    architecture change, tracked in procest#793, deliberately NOT done here.
	 *
	 * What IS fixed here, because it is a correctness bug rather than a
	 * performance one: the previous implementation took `listSubCases()`'s
	 * `_limit => 200` page and reported plain success. A parent with more than
	 * 200 sub-cases had the remainder silently left linked, and the caller then
	 * deleted the parent — orphaning them under a dead reference while the API
	 * answered `200 OK`. Failures inside the loop were swallowed the same way.
	 * This now pages to exhaustion and reports what actually happened, so the
	 * caller can refuse to delete a parent whose children are not all detached.
	 *
	 * @param string $parentCaseUuid Parent UUID.
	 *
	 * @return array{unlinked: int, failed: int, total: int, complete: bool}
	 *                                                                       `complete` is true only when every sub-case was detached.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function unlinkSubCases(string $parentCaseUuid): array {
		$empty = [
			'unlinked' => 0,
			'failed' => 0,
			'total' => 0,
			'complete' => true,
		];

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $empty;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if (empty($register) === true || empty($schema) === true) {
			return $empty;
		}

		$unlinked = 0;
		$failed = 0;

		// Page to exhaustion. Each page re-queries the SAME filter, and a
		// successful unlink drops that record out of the result set, so the next
		// query returns the next batch. A record whose save FAILS stays in the
		// set, so it would be handed back on every subsequent page — which both
		// spins forever and double-counts. `$seen` makes each sub-case count
		// exactly once and turns "a page containing nothing new" into the
		// termination condition.
		$seen = [];
		while (true) {
			$page = $this->listSubCases(parentCaseUuid: $parentCaseUuid);
			if ($page === []) {
				break;
			}

			$outcome = $this->unlinkPage(
				page: $page,
				parentCaseUuid: $parentCaseUuid,
				objectService: $objectService,
				register: $register,
				schema: $schema,
				seen: $seen
			);

			$unlinked += $outcome['unlinked'];
			$failed += $outcome['failed'];

			if ($outcome['sawSomethingNew'] === false) {
				break;
			}
		}//end while

		$total = count($seen);

		if ($failed > 0) {
			$this->logger->error(
				'Sub-case unlink did not complete; the parent must not be deleted',
				['parent' => $parentCaseUuid, 'unlinked' => $unlinked, 'failed' => $failed]
			);
		}

		return [
			'unlinked' => $unlinked,
			'failed' => $failed,
			'total' => $total,
			'complete' => ($failed === 0),
		];
	}//end unlinkSubCases()

	/**
	 * Detach one page of sub-cases, skipping any already attempted.
	 *
	 * @param array<int, array<string, mixed>> $page The page of rows.
	 * @param string $parentCaseUuid Parent UUID, for logging.
	 * @param object $objectService The OR object service.
	 * @param string $register Register id.
	 * @param string $schema Case schema id.
	 * @param array<string, bool> $seen Ids already attempted, updated in place.
	 *
	 * @return array{unlinked: int, failed: int, sawSomethingNew: bool} The page outcome.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	private function unlinkPage(
		array $page,
		string $parentCaseUuid,
		object $objectService,
		string $register,
		string $schema,
		array &$seen,
	): array {
		$unlinked = 0;
		$failed = 0;
		$sawSomethingNew = false;

		foreach ($page as $subCase) {
			$id = (string)($subCase['id'] ?? '');
			if ($id === '' || isset($seen[$id]) === true) {
				continue;
			}

			$seen[$id] = true;
			$sawSomethingNew = true;

			try {
				$payload = $subCase;
				$payload['parentCase'] = null;
				$objectService->saveObject(
					object: $payload,
					register: $register,
					schema: $schema,
				);
				$unlinked++;
			} catch (\Throwable $e) {
				$failed++;
				$this->logger->warning(
					'Failed to unlink sub-case',
					['parent' => $parentCaseUuid, 'sub' => $id, 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		return [
			'unlinked' => $unlinked,
			'failed' => $failed,
			'sawSomethingNew' => $sawSomethingNew,
		];
	}//end unlinkPage()
}//end class
