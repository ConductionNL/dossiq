<?php

/**
 * Contract of CaseTypeVersionChain
 *
 * The public surface of CaseTypeVersionChain that other components call, so they depend
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
 * Contract of CaseTypeVersionChain.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeVersionChainInterface {

	/**
	 * Every version of the case type this id belongs to, newest first.
	 *
	 * A case type with no identifier is its own chain of one. That is not a
	 * degenerate case to guard against, it is what a type authored before the
	 * identifier was auto-generated looks like, and answering the empty list
	 * for it would tell a handler their case runs on a version that does not
	 * exist.
	 *
	 * @param string $caseTypeId The case type to read the chain of.
	 *
	 * @return array<int, array<string, mixed>> The versions, newest first.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function versionsOf(string $caseTypeId): array;
}//end interface
