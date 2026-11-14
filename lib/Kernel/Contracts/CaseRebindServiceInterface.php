<?php

/**
 * Contract of CaseRebindService
 *
 * The public surface of CaseRebindService that other components call, so they depend
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
 * Contract of CaseRebindService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRebindServiceInterface {

	/**
	 * Whether this user may rebind at all.
	 *
	 * Answered as its own question so the action can be hidden from people who
	 * would only be refused, and so the refusal and the hiding read the same
	 * group rather than two lists that drift.
	 *
	 * @param string $uid The user id, or '' for nobody.
	 *
	 * @return boolean True when they may.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function mayRebind(string $uid): bool;

	/**
	 * The case types this case could be rebound to, and where it stands now.
	 *
	 * Every published case type other than the one the case is on, including
	 * the other versions of its own type: a rebind is a superset of the version
	 * move, and a coordinator who opened this dialog should not be told to go
	 * and find a different one because their target happened to be a version.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The current binding and the targets.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function options(string $caseId): array;

	/**
	 * What rebinding onto that type, landing in that status, would ask for.
	 *
	 * The `impact` block is the same computation {@see rebind()} applies: what
	 * is dropped, what is ported where, and what the target still requires.
	 * `canRebind` is true only when a status is chosen and that impact is
	 * complete, so the dialog cannot enable a button the write would refuse.
	 *
	 * @param string                $caseId           The case uuid.
	 * @param string                $targetCaseTypeId The case type asked about.
	 * @param string                $targetStatusId   The status the coordinator picked, or ''.
	 * @param array<string, string> $remap            Dropped answer name => target field name.
	 * @param array<string, mixed>  $properties       Answers to the target's required fields.
	 *
	 * @return array<string, mixed> The statuses to choose from, the impact, and whether it can be done.
	 *
	 * @throws RefusedException When the case or the target cannot be read, or a remap cannot be made.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function preview(
		string $caseId,
		string $targetCaseTypeId,
		string $targetStatusId,
		array $remap = [],
		array $properties = [],
	): array;

	/**
	 * Rebind this case, in the order D-2 sets out.
	 *
	 * @param string               $caseId           The case uuid.
	 * @param string               $targetCaseTypeId The case type to rebind onto.
	 * @param string               $targetStatusId   The status it lands in.
	 * @param string               $reason           Why it is being rebound.
	 * @param array<string, mixed> $properties       The answers to what the target requires and the case lacks.
	 * @param string               $actorUid         The coordinator doing it.
	 * @param array<string, string> $remap           Dropped answer name => target field name.
	 * @param array<int, string>   $confirmDropped   The dropped answer names the coordinator saw and accepted.
	 *
	 * @return array<string, mixed> What was applied.
	 *
	 * @throws RefusedException When the rebind is refused, or a write fails.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function rebind(
		string $caseId,
		string $targetCaseTypeId,
		string $targetStatusId,
		string $reason,
		array $properties,
		string $actorUid,
		array $remap = [],
		array $confirmDropped = [],
	): array;
}//end interface
