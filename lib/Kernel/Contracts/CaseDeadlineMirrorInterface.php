<?php

/**
 * Contract of CaseDeadlineMirror
 *
 * The public surface of CaseDeadlineMirror that other components call, so they depend
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
 * Contract of CaseDeadlineMirror.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDeadlineMirrorInterface {

	/**
	 * The case property the write-back sets.
	 *
	 * NOT `deadline` itself. `deadline` is declared readOnly, and OpenRegister
	 * refuses an update that changes a readOnly value, so a patch naming it
	 * would be answered 422. This field is ordinary; changing it is what makes
	 * the save happen, and the pre-persist listener then writes `deadline`
	 * inside that save, after validation, where readOnly does not apply.
	 */
	public const FIELD = 'statutoryDeadline';

	/**
	 * The deadline a case's statutory term decides, or null.
	 *
	 * Null means there is no statutory term, and the case keeps its
	 * fallback. Terms that could not be read are a refusal, not a null.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string|null The `Y-m-d` deadline, or null.
	 *
	 * @throws RefusedException When the terms of the case could not be read.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function deadlineFor(string $caseId): ?string;

	/**
	 * Bring the stored case in line with its statutory term.
	 *
	 * Reads the case and writes only when it disagrees, so calling this after
	 * every save of an instance costs one read when nothing moved.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return bool True when the case was written.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function follow(string $caseId): bool;

	/**
	 * Bring the case in line after this instance was saved, when it is statutory.
	 *
	 * The term engine's one call: a planned, internal or phase term never
	 * decides the case's deadline, so it costs nothing.
	 *
	 * @param array<string, mixed>|null $instance The instance as stored, or null when the store refused.
	 *
	 * @return bool True when the case was written.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function followInstance(?array $instance): bool;

	/**
	 * Whether an instance is the kind that decides a case's deadline.
	 *
	 * @param array<string, mixed> $instance The instance.
	 *
	 * @return bool True for a statutory term, including one written before kinds existed.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function isStatutory(array $instance): bool;
}//end interface
