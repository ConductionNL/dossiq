<?php

/**
 * Contract of StatusTypeLookup
 *
 * The public surface of StatusTypeLookup that other components call, so they depend
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
 * Contract of StatusTypeLookup.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StatusTypeLookupInterface {

	/**
	 * The words a status is announced in, or '' when it is not announced.
	 *
	 * NOT THE SAME QUESTION AS {@see StatusPublicLabels::publicLabelOf()}, and
	 * the difference is the whole point. That one answers "what does the
	 * applicant read for this status" and falls back to the administered name,
	 * which is right for a page that has already decided to show the status.
	 * This one answers "is this status announced at all", and a fallback would
	 * make every status announced the moment a case type forgot to fill a
	 * public label in. The empty string here is a decision, not a gap.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return string The public label, or '' when the status is not announced.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function announcedLabelOf(string $statusTypeId): string;

	/**
	 * A case type's statuses as `id => name`.
	 *
	 * 🔴 THE LINK LIVES ON THE CHILD, NOT THE PARENT. `caseType` has no
	 * `statusTypes` property — every `statusType` carries a `caseType`
	 * back-reference instead (see how TemplateLibraryService creates them:
	 * `$statusData['caseType'] = $caseTypeId`). An earlier version of this
	 * method read `caseType['statusTypes']`, which does not exist, so it always
	 * returned an empty map — meaning `idForName()` always returned '' and
	 * `SetStatusHandler` refused EVERY status move. The flow could never move a
	 * case, and every unit test passed, because the fixtures were written to
	 * match the assumption rather than the schema. Found by the e2e.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, string> The statuses, keyed by id.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function statusesOf(string $caseTypeId): array;

	/**
	 * The statusType ROWS a case of this type can be in, inherited ones included.
	 *
	 * `statusesOf()` answers id to name, which is what a status picker needs
	 * and not what an act needs: finishing a case has to know which statuses
	 * are terminal, and closing it early has to know which phases it never
	 * reached, and both of those live on the row rather than in its name.
	 *
	 * The same resolver, deliberately. A second read of the same relation
	 * would be a second answer about which statuses a derived case type has,
	 * and that question has already been got wrong twice in this class.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty when unresolvable.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function rowsOf(string $caseTypeId): array;
}//end interface
