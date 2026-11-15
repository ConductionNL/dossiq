<?php

/**
 * Contract of ApplicantTermNote
 *
 * The public surface of ApplicantTermNote that other components call, so they depend
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
 * Contract of ApplicantTermNote.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApplicantTermNoteInterface {

	/**
	 * The two fields for a case as it is about to be saved.
	 *
	 * While a request for completion is open the term stands still: the
	 * sentence says since when, and no date is given, because none is known
	 * until the term runs again. After an extension the sentence carries the
	 * period and the reason. Otherwise the sentence is empty.
	 *
	 * @param array<string, mixed>     $case            The case being saved.
	 * @param string                   $deadline        The case deadline of this save, as the term engine keeps it.
	 * @param callable(string): string $extensionPeriod Answers the ISO 8601 extension period of a case type id.
	 *
	 * @return array{termNote: string, legalDecisionDate: string|null} The two fields.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-applicant-reads-the-decision-date-and-the-state-of-the-term-req-wds-002
	 */
	public function fieldsFor(array $case, string $deadline, callable $extensionPeriod): array;
}//end interface
