<?php

/**
 * Dossiq readable day
 *
 * One stored date or moment as the day a person reads: "15 juni 2026", or
 * "15 June 2026" in English. The letters to an applicant print every date
 * this way (letters-print-every-date-the-same-way), and what the portal says
 * right after a request is sent uses the same words, so a resident never
 * reads "2026-10-14T12:34:29+00:00" on one screen and "14 oktober 2026" in
 * the letter about the same request.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use DateTimeImmutable;
use Throwable;

/**
 * Writes a stored date as a day with the month spelled out.
 *
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
 */
class ReadableDay {

	/**
	 * The Dutch month names, January first.
	 */
	private const MONTHS_NL = [
		'januari',
		'februari',
		'maart',
		'april',
		'mei',
		'juni',
		'juli',
		'augustus',
		'september',
		'oktober',
		'november',
		'december',
	];

	/**
	 * The English month names, January first.
	 */
	private const MONTHS_EN = [
		'January',
		'February',
		'March',
		'April',
		'May',
		'June',
		'July',
		'August',
		'September',
		'October',
		'November',
		'December',
	];

	/**
	 * One stored moment as a day a Dutch text reads it: "14 oktober 2026".
	 *
	 * The month names are spelled out here, not through `IL10N`, because the
	 * language is the one the text is written in (a letter's case type, a
	 * Dutch portal sentence), not the language of whoever happens to be signed
	 * in when it is produced.
	 *
	 * @param string $value The stored ISO 8601 date or moment.
	 *
	 * @return string The day, or an empty string when there is none to read.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-confirmation-names-its-dates-as-days-req-wdi-003
	 */
	public function dutch(string $value): string {
		return $this->spelled(value: $value, months: self::MONTHS_NL);
	}//end dutch()

	/**
	 * One stored moment as a day an English text reads it: "14 October 2026".
	 *
	 * @param string $value The stored ISO 8601 date or moment.
	 *
	 * @return string The day, or an empty string when there is none to read.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
	 */
	public function english(string $value): string {
		return $this->spelled(value: $value, months: self::MONTHS_EN);
	}//end english()

	/**
	 * The day with its month spelled from the given names.
	 *
	 * @param string             $value  The stored ISO 8601 date or moment.
	 * @param array<int, string> $months The month names, January first.
	 *
	 * @return string The day, or an empty string when there is none to read.
	 */
	private function spelled(string $value, array $months): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		try {
			$moment = new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return '';
		}

		return $moment->format('j').' '.$months[((int)$moment->format('n') - 1)].' '.$moment->format('Y');
	}//end spelled()
}//end class
