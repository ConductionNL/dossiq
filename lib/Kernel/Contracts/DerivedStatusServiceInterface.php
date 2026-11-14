<?php

/**
 * Contract of DerivedStatusService
 *
 * The public surface of DerivedStatusService that other components call, so they depend
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
 * Contract of DerivedStatusService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DerivedStatusServiceInterface {

	/**
	 * The status the case should be in, when a derivation now says so.
	 *
	 * The FURTHEST satisfied status wins, by `order`. A case that satisfies
	 * both "Received" and "Complete" is complete: the earlier one stayed true,
	 * it did not become true again, and moving the case backwards on the
	 * strength of a condition it met weeks ago would undo a handler's work.
	 *
	 * Returns null when nothing derives, when the case is already there, or
	 * when the case sits at a LATER status than the one that derives. The last
	 * of those is what stops a derivation dragging a case out of Decision back
	 * into Complete every time a document is touched.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string|null The statusType id to move to, or null for no move.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function statusFor(array $case): ?string;
}//end interface
