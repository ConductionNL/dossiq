<?php

/**
 * Contract of CaseSplitExecutor
 *
 * The public surface of CaseSplitExecutor that other components call, so they depend
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
 * Contract of CaseSplitExecutor.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseSplitExecutorInterface {

	/**
	 * Which parts a split may divide on this case.
	 *
	 * 🔴 THE PICKER HAS TO ASK THIS BEFORE IT DRAWS. Without it the dialog
	 * lists every document and party the case holds, the handler ticks one,
	 * confirms, and learns only from the refusal that this case type does not
	 * allow documents to be divided. The rule was always there and always
	 * enforced; what was missing was saying it before the attempt rather than
	 * after it.
	 *
	 * It answers the policy and nothing else. The policy already reads an
	 * absent declaration as "everything may be divided", and an unreadable
	 * case type reaches it as null rather than as a type declaring nothing, so
	 * a case type that cannot be read offers all three rather than refusing
	 * everything.
	 *
	 * @param string $caseId The case being looked at.
	 *
	 * @return array<int, string> The parts this case type allows, in a stable order.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-picker-says-what-may-be-divided-before-the-handler-chooses-req-cm-49
	 */
	public function divisibleParts(string $caseId): array;

	/**
	 * Split a case, moving what the handler chose.
	 *
	 * @param string $caseId The case being split.
	 * @param string $title  The new case's title.
	 * @param array<string, array<int, string>> $chosen The ids the handler
	 *        picked, under the keys `documents`, `parties`, `tasks` and
	 *        `partiesOnBoth`. Written as a map rather than a shape because the
	 *        line the shape needs does not fit, and the plan below is what
	 *        actually refuses an unknown key.
	 * @param string $actor  Who split it.
	 *
	 * @return array{case: array<string, mixed>, moved: array<int, array<string, mixed>>, refused: array<int, array<string, mixed>>, note: string}
	 *         The new case, what moved, what the plan refused as not this case's, and the note recorded on the original.
	 *
	 * @throws RefusedException When the case cannot be read, the case type refuses, or the write fails.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function split(string $caseId, string $title, array $chosen, string $actor): array;
}//end interface
