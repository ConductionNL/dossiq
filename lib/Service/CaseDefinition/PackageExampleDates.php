<?php

/**
 * Relative dates in a package's example rows.
 *
 * Example rows describe a case in flight, so their dates are written relative
 * to the day the package is installed: `{{date:-25}}` is the date 25 days ago,
 * `{{datetime:-16 10:00}}` that moment 16 days ago. A string that is not
 * exactly one such token is left as it is, except a list-of-records answer
 * the register stores as JSON text (`"[{\"date\":\"{{date:-12}}\"}]"`):
 * its tokens are filled inside and it is stored as JSON text again.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use DateTimeImmutable;

/**
 * Fills the relative date tokens of example rows.
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */
final class PackageExampleDates {

	/**
	 * A date token: days from today.
	 */
	private const DATE = '/^\{\{date:(-?\d+)\}\}$/';

	/**
	 * A date-time token: days from today and a time of day.
	 */
	private const DATETIME = '/^\{\{datetime:(-?\d+) (\d{2}):(\d{2})\}\}$/';

	/**
	 * Fill every date token in the rows, relative to today.
	 *
	 * @param array<int|string, mixed> $rows  The rows, or any nested value of them.
	 * @param DateTimeImmutable|null   $today The install day; today when null.
	 *
	 * @return array<int|string, mixed> The rows with every token replaced.
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function fill(array $rows, ?DateTimeImmutable $today=null): array {
		$today  = ($today ?? new DateTimeImmutable('today'));
		$filled = [];
		foreach ($rows as $key => $value) {
			$filled[$key] = $this->value(value: $value, today: $today);
		}

		return $filled;
	}//end fill()

	/**
	 * One value with its token replaced.
	 *
	 * @param mixed             $value The value.
	 * @param DateTimeImmutable $today The install day.
	 *
	 * @return mixed The value.
	 */
	private function value(mixed $value, DateTimeImmutable $today): mixed {
		if (is_array($value) === true) {
			return $this->fill(rows: $value, today: $today);
		}

		if (is_string($value) === false) {
			return $value;
		}

		$day = $today->setTime(0, 0);
		if (preg_match(self::DATE, $value, $match) === 1) {
			return $day->modify("{$match[1]} days")->format('Y-m-d');
		}

		if (preg_match(self::DATETIME, $value, $match) === 1) {
			return $day->modify("{$match[1]} days")->setTime((int)$match[2], (int)$match[3])->format(DATE_ATOM);
		}

		return $this->jsonText(value: $value, today: $today);
	}//end value()

	/**
	 * A JSON list or object stored as text, its tokens filled; any other string as it is.
	 *
	 * @param string            $value The string.
	 * @param DateTimeImmutable $today The install day.
	 *
	 * @return string The string.
	 */
	private function jsonText(string $value, DateTimeImmutable $today): string {
		$first = substr(ltrim($value), 0, 1);
		if (($first !== '[' && $first !== '{') || str_contains($value, '{{') === false) {
			return $value;
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === false) {
			return $value;
		}

		return (string)json_encode($this->fill(rows: $decoded, today: $today), (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}//end jsonText()
}//end class
