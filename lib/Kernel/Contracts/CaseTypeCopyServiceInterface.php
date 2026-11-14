<?php

/**
 * Contract of CaseTypeCopyService
 *
 * The public surface of CaseTypeCopyService that other components call, so they depend
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
 * Contract of CaseTypeCopyService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeCopyServiceInterface {

	/**
	 * Deep-copy a case type into a new draft.
	 *
	 * Copies the case type itself (new id, title prefixed "Copy of ",
	 * forced back to draft, publication fields cleared, workflow-version
	 * pin and sibling case-type links dropped) plus every owned
	 * sub-object, re-pointed at the new case type's id.
	 *
	 * @param string $caseTypeId The source case type's OpenRegister id.
	 *
	 * @return array<string, mixed>|null The newly created case type, or
	 *                                   `null` when the source does not
	 *                                   resolve (or OpenRegister is
	 *                                   unavailable / misconfigured).
	 *
	 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
	 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T02
	 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T03
	 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T04
	 */
	public function copy(string $caseTypeId): ?array;

	/**
	 * Start a new version of a published case type.
	 *
	 * A version is NOT a duplicate, and the difference is the whole point.
	 * A duplicate is a second case type: new title, new identifier, no relation
	 * to the first. A version is the SAME case type later on, so it keeps the
	 * title and the identifier (ZGW's `identificatie` is what makes two rows
	 * versions of one zaaktype) and gains a link back to the version it came
	 * from.
	 *
	 * 🔴 THE POINT OF MINTING AN OBJECT RATHER THAN EDITING ONE IS THE CASES
	 * THAT ARE ALREADY RUNNING. Every case carries `caseType` as the id of one
	 * specific row, and its `status` is a statusType owned by that row. Editing
	 * a published case type in place reaches every case of that type
	 * immediately, which is why the page's own banner ("changes will only apply
	 * to new cases") was not true. Copying instead leaves the running cases on
	 * the objects they started under, and pins them there with no extra field
	 * on the case: the reference they already hold IS the pin. Nothing migrates
	 * a running case forward BY ITSELF, deliberately. Its current status is a
	 * row the new version does not contain, and its deadline was computed from
	 * the old version's `processingDeadline`. Moving one is a deliberate act
	 * somebody performs and gives a reason for, and it is
	 * {@see \OCA\Dossiq\Service\CaseType\CaseVersionMove}, never a consequence
	 * of publishing.
	 *
	 * The new version starts as a draft. It becomes the version new cases get
	 * when it is published, which is when {@see CaseTypePublishService} writes
	 * `supersededBy` onto the version it replaces.
	 *
	 * @param string $caseTypeId The case type to make the next version of.
	 *
	 * @return array<string, mixed>|null The new draft version, or `null` when
	 *                                   the source does not resolve.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function newVersion(string $caseTypeId): ?array;
}//end interface
