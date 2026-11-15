<?php

/**
 * Contract of AssigneeResolver
 *
 * The public surface of AssigneeResolver that other components call, so they depend
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
 * Contract of AssigneeResolver.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssigneeResolverInterface {

	/**
	 * Why a resolution came back empty, as a clause a person can read.
	 *
	 * Callers that refuse put this after "could not resolve the assignee X, and".
	 *
	 * @param string $fallback The declared second choice, or ''.
	 *
	 * @return string The clause.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function refusalReason(string $fallback): string;

	/**
	 * The principal an authored assignee resolves to on this case.
	 *
	 * Four steps, each one a declared field rather than a guess: the authored
	 * assignee, its declared fallback, the case's own handler, and then
	 * nobody. The third step is what this class was missing: an action that
	 * named no assignee at all created a task addressed to nobody, so the task
	 * schema's `taskAssigned` notification went nowhere and the work sat on a
	 * case whose handler never heard about it.
	 *
	 * The team is NOT a step here, because the answer is a string and a team
	 * is a different field on the task. See `resolveTeam()`.
	 *
	 * `assignee: "none"` short-circuits everything and answers '', which is
	 * how an action keeps a queue task unclaimed.
	 *
	 * @param string               $primary  The authored assignee, template or literal.
	 * @param string               $fallback The declared second choice, or ''.
	 * @param array<string, mixed> $case     The case to render against.
	 *
	 * @return string The rendered principal, or '' when nothing names anybody.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function resolve(string $primary, string $fallback, array $case): string;
}//end interface
