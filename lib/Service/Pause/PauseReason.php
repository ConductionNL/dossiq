<?php

/**
 * Dossiq PauseReason.
 *
 * What a case type declares a term may be suspended for, as one vocabulary.
 *
 * A pause used to carry a free-text rationale and nothing else. That sentence
 * is fine evidence and useless as a rule: nobody can count it, nobody can put
 * a chasing schedule on it, and two handlers write the same wait two ways. So
 * the reason becomes declared data on the case type, the rationale stays beside
 * it as the note, and everything mechanical hangs off the reason.
 *
 * THE FOUR CATEGORIES ARE NOT DECORATION. An applicant wait suspends the
 * beslistermijn under Awb 4:5. A third-party wait is an advice somebody else
 * owes and stops no statutory clock of its own. An internal wait is ours. A
 * statutory wait is one the law imposes. They lead to different actions, so
 * collapsing them would make the queue count meaningless.
 *
 * `waitingOn` DELIBERATELY REUSES `statusType.waitingOn`. The queue already
 * counts cases into `us`, `applicant` and `thirdParty`, and a second spelling
 * here would split one bucket in two without anybody noticing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Pause
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
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pause;

use OCA\Dossiq\Service\Status\StatusDeclaration;

/**
 * One declared pause reason, normalised (REQ-TERM-011).
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */
final class PauseReason {
	/**
	 * A wait on the person who applied. Awb 4:5 suspends the term for it.
	 *
	 * @var string
	 */
	public const APPLICANT = 'applicant';

	/**
	 * A wait on somebody else: an advice, a document, another authority.
	 *
	 * @var string
	 */
	public const THIRD_PARTY = 'thirdParty';

	/**
	 * A wait of our own making, which stops no statutory clock.
	 *
	 * @var string
	 */
	public const INTERNAL = 'internal';

	/**
	 * A wait the law itself imposes, such as a mandatory viewing period.
	 *
	 * @var string
	 */
	public const STATUTORY = 'statutory';

	/**
	 * Every category a reason may declare.
	 *
	 * @var array<int, string>
	 */
	public const CATEGORIES = [
		self::APPLICANT,
		self::THIRD_PARTY,
		self::INTERNAL,
		self::STATUTORY,
	];

	/**
	 * How many reminders a reason may declare, at most.
	 *
	 * A budget is a promise to a citizen as much as a setting: past a handful
	 * the reminders stop being a service and start being pressure, and a typo
	 * that reads 500 would send 500. The ceiling is here rather than in the
	 * schema because the schema cannot refuse a number, only describe it.
	 *
	 * @var int
	 */
	public const MAX_BUDGET = 10;

	/**
	 * Private constructor: this is a vocabulary, not an object.
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * One declared row, in the shape every caller reads.
	 *
	 * A row that declares nonsense reads as a reason that never chases, rather
	 * than as no reason at all. The difference matters: a handler who picked
	 * "Advies gevraagd" still suspended the term for that reason, and losing
	 * the name because the interval was typed as `-3` would lose the record.
	 *
	 * @param array<string, mixed> $row The raw declaration.
	 *
	 * @return array{key: string, name: string, category: string, waitingOn: string,
	 *               legalBasis: string, chaseIntervalDays: int, chaseBudget: int,
	 *               countsWorkingDays: bool, chaseText: string, escalateTo: string}
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public static function normalise(array $row): array {
		$category = trim((string)($row['category'] ?? ''));
		if (in_array($category, self::CATEGORIES, true) === false) {
			$category = self::APPLICANT;
		}

		return [
			'key' => trim((string)($row['key'] ?? '')),
			'name' => trim((string)($row['name'] ?? '')),
			'category' => $category,
			'waitingOn' => self::waitingOn(row: $row, category: $category),
			'legalBasis' => trim((string)($row['legalBasis'] ?? '')),
			'chaseIntervalDays' => max(0, (int)($row['chaseIntervalDays'] ?? 0)),
			'chaseBudget' => min(self::MAX_BUDGET, max(0, (int)($row['chaseBudget'] ?? 0))),
			'countsWorkingDays' => self::flag(value: ($row['countsWorkingDays'] ?? true)),
			'chaseText' => trim((string)($row['chaseText'] ?? '')),
			'escalateTo' => trim((string)($row['escalateTo'] ?? '')),
		];
	}//end normalise()

	/**
	 * Whether this reason chases at all.
	 *
	 * A reason with no interval, no budget or no text sends nothing. All three
	 * are checked, because a schedule with an empty text would send a blank
	 * letter, which is worse than sending none.
	 *
	 * @param array<string, mixed> $reason A normalised reason.
	 *
	 * @return bool True when reminders go out for it.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public static function chases(array $reason): bool {
		return ((int)($reason['chaseIntervalDays'] ?? 0) > 0
			&& (int)($reason['chaseBudget'] ?? 0) > 0
			&& trim((string)($reason['chaseText'] ?? '')) !== '');
	}//end chases()

	/**
	 * Who a reason waits on, declared or inferred from its category.
	 *
	 * A case type that named the category and forgot the party still counts in
	 * the right queue bucket, because the two say the same thing in almost
	 * every case. An explicit `waitingOn` wins, so the rare reason that is
	 * categorised statutory while an applicant is genuinely being waited on
	 * can say so.
	 *
	 * @param array<string, mixed> $row      The raw declaration.
	 * @param string               $category The category already resolved.
	 *
	 * @return string One of the three `statusType.waitingOn` values.
	 */
	private static function waitingOn(array $row, string $category): string {
		$declared = trim((string)($row['waitingOn'] ?? ''));
		if (in_array($declared, StatusDeclaration::WAITING_ON_VALUES, true) === true) {
			return $declared;
		}

		if ($category === self::APPLICANT) {
			return StatusDeclaration::WAITING_ON_APPLICANT;
		}

		if ($category === self::THIRD_PARTY) {
			return StatusDeclaration::WAITING_ON_THIRD_PARTY;
		}

		return StatusDeclaration::WAITING_ON_US;
	}//end waitingOn()

	/**
	 * Coerce a JSON-shaped boolean, defaulting to true.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return bool False only for the values that mean false.
	 */
	private static function flag(mixed $value): bool {
		return in_array($value, [false, 0, '0', 'false'], true) === false;
	}//end flag()
}//end class
