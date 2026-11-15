<?php

/**
 * Contract of DeelzaakService
 *
 * The public surface of DeelzaakService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of DeelzaakService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeelzaakServiceInterface {

	/**
	 * Create a sub-case as a derivation of its parent.
	 *
	 * The guards run first and unchanged: a sub-case that may not be created is
	 * not created, whatever the parent would have passed down. Only then does
	 * OpenRegister derive it, which is what applies the inheritance the case
	 * schema declares on `parentCase` and records what the child took.
	 *
	 * This exists because the modal used to save an ordinary case with
	 * `parentCase` filled in. That creates the hierarchy and nothing else: no
	 * inherited confidentiality, no inherited handler, and no record of either.
	 * A sub-case that quietly starts more open than its parent is a disclosure,
	 * and nothing in the old path could have shown it happening.
	 *
	 * @param string $parentCaseUuid Parent case UUID.
	 * @param string $childCaseTypeId The sub-case's case type.
	 * @param array<string, mixed> $childData The sub-case as the form filled it in.
	 *
	 * @return array{ok: bool, reason?: string, object?: array<string, mixed>, inherited?: array<string, mixed>, inheritanceApplied?: bool}
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function createSubCase(string $parentCaseUuid, string $childCaseTypeId, array $childData): array;

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
	 * @spec openspec/changes/archive/2026-06-13-deelzaak-support/tasks.md#T02
	 */
	public function getParentCase(string $childCaseUuid): ?array;

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
	 * @spec openspec/changes/archive/2026-06-13-deelzaak-support/tasks.md#T03
	 */
	public function getSubCaseCounts(array $parentUuids): array;

	/**
	 * Fetch every sub-case linked to the given parent.
	 *
	 * @param string $parentCaseUuid Parent case UUID.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-06-13-deelzaak-support/tasks.md#T01
	 */
	public function listSubCases(string $parentCaseUuid): array;

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
	public function unlinkSubCases(string $parentCaseUuid): array;

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
	 * @spec openspec/changes/archive/2026-06-13-deelzaak-support/tasks.md#T08
	 */
	public function validateCreate(string $parentCaseUuid, string $childCaseTypeId): array;
}//end interface
