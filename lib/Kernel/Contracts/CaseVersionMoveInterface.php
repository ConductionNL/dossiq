<?php

/**
 * Contract of CaseVersionMove
 *
 * The public surface of CaseVersionMove that other components call, so they depend
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
 * Contract of CaseVersionMove.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseVersionMoveInterface {

	/**
	 * Move the case onto another version of its case type.
	 *
	 * The preview is re-computed here rather than trusted from the caller: the
	 * dialog showed a preview a person read, and between reading it and
	 * pressing the button the target version may have been published over. A
	 * move that applies a stale mapping is the write this re-read prevents.
	 *
	 * @param string $caseId           The case uuid.
	 * @param string $targetCaseTypeId The version to move onto.
	 * @param string $reason           Why the case is being moved.
	 * @param string $actorUid         Who is moving it.
	 *
	 * @return array<string, mixed> The preview that was applied, with `moved` on it.
	 *
	 * @throws RefusedException When the move is refused, or the write fails.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function move(string $caseId, string $targetCaseTypeId, string $reason, string $actorUid): array;

	/**
	 * The versions this case could move to, and the one it is on.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{current: array<string, mixed>, targets: array<int, array<string, mixed>>}
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function options(string $caseId): array;

	/**
	 * What moving this case onto that version would change.
	 *
	 * @param string $caseId             The case uuid.
	 * @param string $targetCaseTypeId   The version to move onto.
	 *
	 * @return array<string, mixed> The preview, including whether it can be done.
	 *
	 * @throws RefusedException When the case or the target cannot be read, or the target is not a version of this type.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function preview(string $caseId, string $targetCaseTypeId): array;
}//end interface
