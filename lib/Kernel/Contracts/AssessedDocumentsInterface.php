<?php

/**
 * Contract of AssessedDocuments
 *
 * The public surface of AssessedDocuments that other components call, so they depend
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
 * Contract of AssessedDocuments.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssessedDocumentsInterface {

	/**
	 * Every document under review on a case, with its assessment.
	 *
	 * `outstanding` is the server's word, the same one the outstanding list
	 * gives. `assessment` is null for an outstanding document, and also for a
	 * document whose assessment the caller may not read: the assessments are
	 * read as the caller, so a reviewer's own work stays theirs.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{count: int, outstanding: int, documents: array<int, array<string, mixed>>} Each document as
	 *                                                                                        `{id, title, outstanding, assessment}`.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-every-document-with-its-assessment-req-das-004
	 */
	public function forCase(string $caseId): array;
}//end interface
