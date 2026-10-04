<?php

/**
 * Dossiq Woo Written Case (site-woo-request-in-steps D4)
 *
 * What a write answered, read once: the case's id, and the case number and
 * deadline the write itself produced. The confirmation a resident reads names
 * both, so they are read back from what was written rather than made up by
 * the caller.
 *
 * Its own class because {@see WooRequestIntake} is at the complexity phpmd
 * refuses, and because "what did the store just answer" is a question about
 * the store's shapes, not about Woo requests. The object service answers an
 * array, an entity with `jsonSerialize()`, or an entity with `getObject()`,
 * depending on its version and the call.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
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
 * @spec openspec/changes/site-woo-request-in-steps/specs/woo-request-intake/spec.md#requirement-the-intake-answers-with-the-case-number-and-deadline-req-sws-011
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * The id, number and deadline of a case that was just written.
 *
 * @spec openspec/changes/site-woo-request-in-steps/specs/woo-request-intake/spec.md#requirement-the-intake-answers-with-the-case-number-and-deadline-req-sws-011
 */
class WooWrittenCase {

	/**
	 * What the write answered.
	 *
	 * @param mixed $saved What the object service returned.
	 *
	 * @return array{id: string, answer: array<string, string>} The id, and the
	 *         number and date as far as the case carries them.
	 *
	 * @spec openspec/changes/site-woo-request-in-steps/specs/woo-request-intake/spec.md#requirement-the-intake-answers-with-the-case-number-and-deadline-req-sws-011
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
	 */
	private function numberAndDeadline(array $case): array {
		$answer = [];
		foreach (['identifier', 'deadline'] as $key) {
			$value = trim((string)($case[$key] ?? ''));
			if ($value !== '') {
				$answer[$key] = $value;
			}
		}

		return $answer;
	}//end numberAndDeadline()
}//end class
