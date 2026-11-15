<?php

/**
 * Dossiq letter days
 *
 * The days a letter prints, in the language that letter is written in. A
 * letter decides its language once, from the case type's declared locale, and
 * then every date in it is spelled the same way: "15 juni 2026" in a Dutch
 * letter, "15 June 2026" in an English one.
 *
 * It exists so the language travels as an object instead of as a boolean
 * passed to every date. {@see \OCA\Dossiq\Service\Termijn\TermLetters} used to
 * thread `$english` through two private helpers, which left each call site
 * deciding the language again and two of them deciding it by omission.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Prints stored dates as the days of one letter's language.
 *
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
 */
class LetterDays {

	/**
	 * The declared locale of an English letter.
	 */
	private const ENGLISH = 'en';

	/**
	 * Constructor.
	 *
	 * The language is named as the locale the case type declared, never as a
	 * flag. Anything other than `en` is Dutch: the language of the register,
	 * and of every letter that carries no locale or whose wording exists in
	 * Dutch only.
	 *
	 * @param string      $language The letter's declared locale.
	 * @param ReadableDay $spelling Spells a stored date as a day.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
	 */
	public function __construct(
		private readonly string $language,
		private readonly ReadableDay $spelling=new ReadableDay(),
	) {
	}//end __construct()

	/**
	 * One stored moment as the day this letter reads it.
	 *
	 * @param string $value The stored ISO 8601 date or moment.
	 *
	 * @return string The day, or an empty string when there is none to read.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
	 */
	public function day(string $value): string {
		if ($this->language === self::ENGLISH) {
			return $this->spelling->english(value: $value);
		}

		return $this->spelling->dutch(value: $value);
	}//end day()

	/**
	 * A date as this letter prints it. A placeholder such as "–", or anything
	 * else that is no date, stays as it is rather than disappearing.
	 *
	 * @param string $value The stored date or moment, or a placeholder.
	 *
	 * @return string The day as printed, or the value itself when it is no date.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
	 */
	public function shown(string $value): string {
		$day = $this->day(value: $value);
		if ($day === '') {
			return $value;
		}

		return $day;
	}//end shown()
}//end class
