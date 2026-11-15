<?php

/**
 * Contract of CaseRelationDetails
 *
 * The public surface of CaseRelationDetails that other components call, so they depend
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
 * Contract of CaseRelationDetails.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRelationDetailsInterface {

	/**
	 * Describe each related case the reader may open.
	 *
	 * @param array<int, array<string, mixed>> $rows    The relation rows, each with `caseId`.
	 * @param callable(string): bool           $canRead Whether the reader may open a case.
	 *
	 * @return array<int, array<string, mixed>> The rows that have a title, with
	 *         `identifier`, `caseTypeTitle`, `statusLabel`, `statusRole`,
	 *         `endDate`, `assignee` and `assigneeName` on the readable ones.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function describe(array $rows, callable $canRead): array;

	/**
	 * The planned follow-ups of a case as rows of the same list, in the
	 * `planned` group: a planned flow or a series, with its next date. They
	 * open no case, so they carry no `caseId`.
	 *
	 * @param array<int, array<string, mixed>> $planned The planned rows, as the planned endpoint lists them.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function plannedRows(array $planned): array;
}//end interface
