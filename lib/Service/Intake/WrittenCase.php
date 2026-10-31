<?php

/**
 * Dossiq written case
 *
 * What a write answered, read once: the case's id, and the case number and
 * deadline the write itself produced. The confirmation a resident reads names
 * both, so they are read back from what was written rather than made up by
 * the caller.
 *
 * Its own class because "what did the store just answer" is a question about
 * the store's shapes, not about the request that asked for the write. The
 * object service answers an array, an entity with `jsonSerialize()`, or an
 * entity with `getObject()`, depending on its version and the call.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-intake-answers-with-the-case-number-and-deadline-req-sws-011
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\Support\ReadableDay;

/**
 * The id, number and deadline of a case that was just written.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-intake-answers-with-the-case-number-and-deadline-req-sws-011
 */
class WrittenCase {

	/**
	 * Each stored date of the answer and the key its readable day goes under.
	 */
	private const DAY_KEYS = [
		'deadline' => 'deadlineDay',
		'receivedAt' => 'receivedDay',
		'termStartsAt' => 'termStartsDay',
	];

	/**
	 * What the write answered.
	 *
	 * @param mixed $saved What the object service returned.
	 *
	 * @return array{id: string, answer: array<string, string>} The id, and the
	 *         number and date as far as the case carries them.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-intake-answers-with-the-case-number-and-deadline-req-sws-011
	 */
	public function read(mixed $saved): array {
		$row = $this->row(saved: $saved);

		return ['id' => $this->caseId(saved: $saved, row: $row), 'answer' => $this->numberAndDeadline(case: $row)];
	}//end read()

	/**
	 * The written case as an array, or [] when it cannot be read.
	 *
	 * @param mixed $saved What the object service returned.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function row(mixed $saved): array {
		if (is_array($saved) === true) {
			return $saved;
		}

		if (is_object($saved) === false) {
			return [];
		}

		foreach (['jsonSerialize', 'getObject'] as $method) {
			if (method_exists($saved, $method) === false) {
				continue;
			}

			$row = $saved->$method();
			if (is_array($row) === true) {
				return $row;
			}
		}

		return [];
	}//end row()

	/**
	 * The case's id, from the entity when it has one and from the row
	 * otherwise.
	 *
	 * @param mixed                $saved What the object service returned.
	 * @param array<string, mixed> $row   The same thing as an array.
	 *
	 * @return string The id, or ''.
	 */
	private function caseId(mixed $saved, array $row): string {
		if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
			return (string)$saved->getUuid();
		}

		return (string)($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
	}//end caseId()

	/**
	 * The case number and the date, as far as the case carries them.
	 *
	 * A key whose value is empty is left out rather than answered blank: the
	 * confirmation drops a sentence whose placeholder has no value, so an
	 * empty string would promise a date of ''.
	 *
	 * @param array<string, mixed> $case The written case.
	 *
	 * @return array<string, string> The keys that have a value.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-confirmation-names-its-dates-as-days-req-wdi-003
	 */
	private function numberAndDeadline(array $case): array {
		$answer = [];
		// The received moment and the term start ride along (decision 179):
		// the case is stamped inside its create, so the save result holds them.
		foreach (['identifier', 'deadline', 'receivedAt', 'termStartsAt'] as $key) {
			$value = trim((string)($case[$key] ?? ''));
			if ($value !== '') {
				$answer[$key] = $value;
			}
		}

		// THE DATES AS A RESIDENT READS THEM, beside the stored values, which
		// stay for every caller that computes with them. The portal's
		// confirmation names these, so it reads "14 oktober 2026" where it
		// printed "2026-10-14T12:34:29+00:00", the way the letter about the
		// same request writes it.
		$days = new ReadableDay();
		foreach (self::DAY_KEYS as $key => $dayKey) {
			$day = $days->dutch(value: (string)($answer[$key] ?? ''));
			if ($day !== '') {
				$answer[$dayKey] = $day;
			}
		}

		return $answer;
	}//end numberAndDeadline()
}//end class
