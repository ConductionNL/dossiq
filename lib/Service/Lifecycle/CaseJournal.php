<?php

/**
 * The case's own record of the acts performed on it.
 *
 * Every lifecycle act in this app appends one entry to `case.activity`, a
 * JSON-encoded array the case has carried since the suspend and resume
 * gestures were written. This class is that reader and writer, extracted so
 * the acts of `lifecycle-acts-on-the-case` do not each grow their own copy of
 * it: four private `readJournal()` implementations that agree today is four
 * chances to disagree about what "the record" is.
 *
 * 🔑 THE ENTRY NAMES WHO AND WHEN, ALWAYS. The gestures that existed before
 * this change recorded a type and a reason, which is enough while the case is
 * open and not enough afterwards. REQ-LIFE-11 asks that a reopened case still
 * name who ended it and when, and a record that does not carry the actor
 * cannot answer that however it is read later.
 *
 * What it does NOT do is decide anything. Whether an act is permitted, whether
 * a status may move, what a result means: all of that belongs to the act.
 * This writes down what happened.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use DateTimeImmutable;
use OCP\IUserSession;

/**
 * Reads and appends the case's lifecycle journal.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseJournal {

	/**
	 * The field on the case that holds the journal.
	 *
	 * @var string
	 */
	public const FIELD = 'activity';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Names the actor on every entry.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Read the journal off a case.
	 *
	 * The field is declared as a string holding a JSON array. Anything that
	 * does not parse reads as an empty journal rather than as an error: a case
	 * whose activity log was written by something else still has to work, and
	 * refusing every act on it would be a worse answer than starting a fresh
	 * record beside it.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return array<int, array<string, mixed>> The entries, oldest first.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function entries(array $case): array {
		$raw = ($case[self::FIELD] ?? '');
		$decoded = $raw;
		if (is_array($raw) === false) {
			$decoded = json_decode((string)$raw, true);
		}

		if (is_array($decoded) === false) {
			return [];
		}

		$entries = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) === true) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end entries()

	/**
	 * Append one entry to a case's journal, in memory.
	 *
	 * Returns the case rather than saving it, so an act writes its fields and
	 * its record in ONE save. Two saves would leave a window in which the case
	 * has moved and the record does not say so, and a crash inside that window
	 * is exactly the state nobody can reconstruct afterwards.
	 *
	 * @param array<string, mixed> $case The case, carrying any other change already.
	 * @param array<string, mixed> $entry The entry: `type` and whatever the act records.
	 *
	 * @return array<string, mixed> The case with the entry appended.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function append(array $case, array $entry): array {
		$entries = $this->entries(case: $case);
		$entry['at'] = (new DateTimeImmutable())->format('c');
		$entry['by'] = $this->actor();
		$entries[] = $entry;

		$case[self::FIELD] = json_encode($entries);

		return $case;
	}//end append()

	/**
	 * The most recent entry of one of the given types.
	 *
	 * Walks backwards, because "what happened last" is the question every
	 * caller has: whether the case is held, which act ended it, whether the
	 * last suspend has been resumed.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 * @param list<string> $types The entry types worth stopping on.
	 *
	 * @return array<string, mixed> The entry, empty when the journal holds none.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function latest(array $case, array $types): array {
		$entries = $this->entries(case: $case);
		for ($i = (count($entries) - 1); $i >= 0; $i--) {
			if (in_array((string)($entries[$i]['type'] ?? ''), $types, true) === true) {
				return $entries[$i];
			}
		}

		return [];
	}//end latest()

	/**
	 * The uid performing the act.
	 *
	 * An empty string when there is no session, which is the honest answer for
	 * a background job: the product did it, and the act says so in its own
	 * `type` rather than by borrowing somebody's name.
	 *
	 * @return string The uid, or the empty string.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end actor()
}//end class
