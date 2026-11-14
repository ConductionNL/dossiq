<?php

/**
 * Contract of CaseDocumentDrafts
 *
 * The public surface of CaseDocumentDrafts that other components call, so they depend
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
 * Contract of CaseDocumentDrafts.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDocumentDraftsInterface {

	/**
	 * Every document type a case's case type drafts itself, under any purpose.
	 *
	 * Each type is named as declared and as its catalogue id, because a
	 * drafted document is filed under the id and older ones carry the name.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return list<string> The type names and ids, or [] when the case type drafts nothing.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-a-case-type-names-the-template-it-drafts-a-document-from-req-cdd-001
	 */
	public function documentTypesForCase(string $caseId): array;

	/**
	 * Draft the case type's document for a purpose and file it on the case.
	 *
	 * @param string $caseId  The case uuid.
	 * @param string $purpose The declared purpose, for example `decision`.
	 *
	 * @return array{status: string, reason: string, informatieobject: string, fileId: int}
	 *         `status` is `drafted`, `not-declared`, `filinq-missing`, `refused` (the data cannot be
	 *         built; `reason` says why) or `failed` (filinq refused or nothing was filed).
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-is-asked-of-filinq-and-filed-on-the-case-req-cdd-003
	 */
	public function draft(string $caseId, string $purpose): array;
}//end interface
