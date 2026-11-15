<?php

/**
 * Contract of DocumentVerdicts
 *
 * The public surface of DocumentVerdicts that other components call, so they depend
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
 * Contract of DocumentVerdicts.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentVerdictsInterface {

	/**
	 * Each document under review with its verdict, `open` while it has none.
	 *
	 * Reads only the assessments the caller may read, as {@see self::forCase()}.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, string> The verdict per document id.
	 *
	 * @spec openspec/specs/files-review-row-verdict/spec.md#requirement-each-document-row-carries-its-verdict
	 */
	public function byDocument(string $caseId): array;

	/**
	 * The verdicts of one case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> `documents`, `assessed`, `remaining`, `counts` per verdict and `grounds`.
	 *
	 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
	 */
	public function forCase(string $caseId): array;

	/**
	 * Each document under review with the codes of the grounds its verdict cites, none while it has no verdict.
	 *
	 * Reads the same assessments as {@see self::byDocument()}, so it names the same documents.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, list<string>> The cited ground codes per document id.
	 *
	 * @spec openspec/specs/files-review-row-verdict/spec.md#requirement-each-document-row-carries-its-verdict
	 */
	public function groundsByDocument(string $caseId): array;
}//end interface
