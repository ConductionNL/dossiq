<?php

/**
 * Contract of CaseTypeVersionWindow
 *
 * The public surface of CaseTypeVersionWindow that other components call, so they depend
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
 * Contract of CaseTypeVersionWindow.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeVersionWindowInterface {

	/**
	 * Close a published version for new cases, as a deliberate act.
	 *
	 * 🔴 IT IS A SERVER ACT AND NOT A FIELD WRITE FROM THE PAGE, AND THE REASON
	 * IS THE WORD "TODAY". The design had Deprecate patch `validUntil: @today`
	 * through the object store. A declared `object-op` merges its `values` into
	 * the row VERBATIM: the token is not resolved for that action type, so the
	 * string `@today` would have been written into a date field, and
	 * OpenRegister would have stored it. The field reads filled in, the date
	 * reads as nonsense, and nothing refuses. Here the day comes from the clock.
	 *
	 * The guard is the second reason. A version with nothing to replace it is
	 * the only version cases can be filed under, so closing it would leave the
	 * case type unusable with no message saying why. The page hides the button
	 * in that state; this refuses it, because a hidden button is not a rule.
	 *
	 * @param string $caseTypeId The version to close.
	 *
	 * @return array{deprecated: bool, findings: array<int, string>, validUntil: ?string}
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function deprecate(string $caseTypeId): array;
}//end interface
