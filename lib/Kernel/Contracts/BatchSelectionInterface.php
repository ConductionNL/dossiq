<?php

/**
 * Contract of BatchSelection
 *
 * The public surface of BatchSelection that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of BatchSelection.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BatchSelectionInterface {

	/**
	 * The documents a batch takes: the listed ones, or those matching every field of the filter.
	 *
	 * @param string                $caseId    The case UUID.
	 * @param list<string>          $documents The listed documents; used when no filter is given.
	 * @param array<string, string> $filter    The filter, by field of the review or of the provenance.
	 *
	 * @return list<string> The documents.
	 *
	 * @throws RefusedException When the filter names a field neither carries.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function select(string $caseId, array $documents, array $filter): array;
}//end interface
