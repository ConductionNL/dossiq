<?php

/**
 * Dossiq case deadline calculator.
 *
 * A case's deadline is its start date plus its case type's processing term,
 * rolled to the first working day on the organisation's calendar when the
 * term definition does not switch the roll off (Algemene termijnenwet art. 1).
 * {@see \OCA\Dossiq\Listener\CaseDeadlineListener} writes the answer onto
 * the case; this class computes it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\TermijnTimerService;

/**
 * Compute a case's deadline from its start date and its case type's term.
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */
class CaseDeadlineCalculator {
	/**
	 * Constructor.
	 *
	 * @param TermijnTimerService|null  $timerService The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 * @param TermDefinitions|null      $definitions  The case type's term definition,
	 *        read for its `rollToWorkingDay` switch.
	 * @param CaseTypeSlugResolver|null $slugs        A case carries its case type as a
	 *        uuid; term definitions are keyed by slug.
	 */
	public function __construct(
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?TermDefinitions $definitions = null,
		private readonly ?CaseTypeSlugResolver $slugs = null,
	) {
	}//end __construct()

	/**
	 * The case type's active term definition, for its roll switch.
	 *
	 * No definition answers an empty array, and an empty definition gets the
	 * roll: the Awt applies by law, not by configuration. A read that fails
	 * throws; the caller decides what a case without one gets.
	 *
	 * @param string $caseTypeId The case's case type (uuid or slug).
	 *
	 * @return array<string, mixed> The definition, or an empty array.
	 *
	 * @throws \Throwable When the definition or the slug cannot be read.
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	public function definitionFor(string $caseTypeId): array {
		if ($this->definitions === null || $caseTypeId === '') {
			return [];
		}

		$slug = ($this->slugs?->toSlug(reference: $caseTypeId) ?? $caseTypeId);
		if ($slug === '') {
			return [];
		}

		return ($this->definitions->activeFor(caseType: $slug) ?? []);
	}//end definitionFor()

	/**
	 * The start date plus the term, rolled, and the date before the roll.
	 *
	 * An empty start date means today, which is what the declarative
	 * `startDate` calculation fills in on create.
	 *
	 * @param string               $startDate The case's start date, or the empty string.
	 * @param string               $term      An ISO 8601 duration such as P28D.
	 * @param array<string, mixed> $definitie The term definition, for `rollToWorkingDay`.
	 *
	 * @return array{deadline: string, deadlineBeforeRoll: string} The dates.
	 *
	 * @throws \Throwable When the start date or the duration does not parse.
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	public function deadlineFrom(string $startDate, string $term, array $definitie): array {
		$start = new DateTimeImmutable('today');
		if (trim($startDate) !== '') {
			$start = new DateTimeImmutable(substr(trim($startDate), 0, 10));
		}

		$end = $start->add(new DateInterval($term));
		$rolled = ($this->timerService?->rollTermEndFor(date: $end, definitie: $definitie) ?? $end);

		return ['deadline' => $rolled->format('Y-m-d'), 'deadlineBeforeRoll' => $end->format('Y-m-d')];
	}//end deadlineFrom()
}//end class
