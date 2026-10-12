<?php

/**
 * Dossiq applicant term note.
 *
 * What the applicant is told about the term of their case, as two case
 * fields the portal reads: `termNote`, one sentence, and
 * `legalDecisionDate`, the day the decision is due. Works for any case
 * type. It computes no date: the deadline is the term engine's, the pause is
 * the open request for completion the case already carries
 * (`waitingOnApplicant`), and the extension is the lifecycle journal's
 * last `extend` entry with the case type's own extension period.
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
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-applicant-reads-the-decision-date-and-the-state-of-the-term-req-wds-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateInterval;
use Throwable;

/**
 * The sentence and the date an applicant reads about the term of a case.
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-applicant-reads-the-decision-date-and-the-state-of-the-term-req-wds-002
 */
class ApplicantTermNote {

	/**
	 * The case field that holds the sentence.
	 *
	 * @var string
	 */
	public const NOTE_FIELD = 'termNote';

	/**
	 * The case field that holds the day the decision is due.
	 *
	 * @var string
	 */
	public const DATE_FIELD = 'legalDecisionDate';

	/**
	 * The longest sentence the case schema keeps.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 200;

	/**
	 * Dutch month names, because the applicant reads Dutch and a server's
	 * locale is not the applicant's.
	 *
	 * @var list<string>
	 */
	private const MONTHS = [
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
	 * Dutch number words for the periods a case type usually states.
	 *
	 * @var array<int, string>
	 */
	private const NUMBER_WORDS = [
		1 => 'een',
		2 => 'twee',
		3 => 'drie',
		4 => 'vier',
		5 => 'vijf',
		6 => 'zes',
		7 => 'zeven',
		8 => 'acht',
		9 => 'negen',
		10 => 'tien',
		11 => 'elf',
		12 => 'twaalf',
	];

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
	public function fieldsFor(array $case, string $deadline, callable $extensionPeriod): array {
		$date = substr(trim($deadline), 0, 10);
		if ($date === '') {
			$date = null;
		}

		if (($case['waitingOnApplicant'] ?? false) === true) {
			return [
				self::NOTE_FIELD => $this->standingStill(since: (string)($case['waitingOnApplicantSince'] ?? '')),
				self::DATE_FIELD => null,
			];
		}

		$reason = $this->extensionReason(case: $case);
		if ($reason === null) {
			return [self::NOTE_FIELD => '', self::DATE_FIELD => $date];
		}

		$period = $this->periodInWords(period: (string)$extensionPeriod((string)($case['caseType'] ?? '')));

		return [
			self::NOTE_FIELD => $this->extended(period: $period, reason: $reason),
			self::DATE_FIELD => $date,
		];
	}//end fieldsFor()

	/**
	 * The sentence while the term stands still.
	 *
	 * @param string $since When the open request was sent.
	 *
	 * @return string The sentence.
	 */
	private function standingStill(string $since): string {
		$day = $this->dayInWords(moment: $since);
		if ($day === '') {
			return 'De termijn staat stil. Hij loopt weer zodra wij uw antwoord hebben.';
		}

		return 'De termijn staat stil sinds ' . $day . '. Hij loopt weer zodra wij uw antwoord hebben.';
	}//end standingStill()

	/**
	 * The sentence after an extension, cut to what the schema keeps.
	 *
	 * @param string $period The period in words, empty when the case type states none readable.
	 * @param string $reason The reason the handler gave.
	 *
	 * @return string The sentence.
	 */
	private function extended(string $period, string $reason): string {
		$sentence = 'Wij hebben de termijn verlengd.';
		if ($period !== '') {
			$sentence = 'Wij hebben de termijn met ' . $period . ' verlengd.';
		}

		if ($reason !== '') {
			$sentence .= ' De reden: ' . $reason;
		}

		if (mb_strlen($sentence) > self::MAX_LENGTH) {
			$sentence = rtrim(mb_substr($sentence, 0, (self::MAX_LENGTH - 1))) . '…';
		}

		return $sentence;
	}//end extended()

	/**
	 * The reason of the last extension, or null when the term was never extended.
	 *
	 * Read off the lifecycle journal the case carries (`activity`), where the
	 * extend act writes it in the same save as the count.
	 *
	 * @param array<string, mixed> $case The case being saved.
	 *
	 * @return string|null The reason, possibly empty, or null without an extension.
	 */
	private function extensionReason(array $case): ?string {
		if ((int)($case['extensionCount'] ?? 0) < 1) {
			return null;
		}

		$journal = ($case['activity'] ?? '');
		if (is_array($journal) === false) {
			$journal = json_decode((string)$journal, true);
		}

		if (is_array($journal) === false) {
			return '';
		}

		foreach (array_reverse($journal) as $entry) {
			if (is_array($entry) === true && (string)($entry['type'] ?? '') === 'extend') {
				return trim((string)($entry['reason'] ?? ''));
			}
		}

		return '';
	}//end extensionReason()

	/**
	 * A moment as a Dutch day, "6 oktober 2026", or the empty string.
	 *
	 * @param string $moment An ISO 8601 date or date-time.
	 *
	 * @return string The day in words.
	 */
	private function dayInWords(string $moment): string {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($moment), $parts) !== 1) {
			return '';
		}

		$month = (self::MONTHS[((int)$parts[2] - 1)] ?? '');
		if ($month === '' || (int)$parts[3] < 1 || (int)$parts[3] > 31) {
			return '';
		}

		return (int)$parts[3] . ' ' . $month . ' ' . $parts[1];
	}//end dayInWords()

	/**
	 * An ISO 8601 period in Dutch words: "twee weken", "een maand", "10 dagen".
	 *
	 * Whole weeks read as weeks, because that is how a term is told. A
	 * period that mixes units, or cannot be read, answers the empty string
	 * and the sentence then names no period.
	 *
	 * @param string $period The case type's extension period, for example `P14D`.
	 *
	 * @return string The period in words, or the empty string.
	 */
	private function periodInWords(string $period): string {
		try {
			$interval = new DateInterval(trim($period));
		} catch (Throwable) {
			return '';
		}

		$parts = array_filter(['y' => $interval->y, 'm' => $interval->m, 'd' => $interval->d]);
		if (count($parts) !== 1 || $interval->h !== 0 || $interval->i !== 0 || $interval->s !== 0) {
			return '';
		}

		$unit = (string)array_key_first($parts);
		$count = (int)$parts[$unit];
		$names = ['y' => ['jaar', 'jaar'], 'm' => ['maand', 'maanden'], 'd' => ['dag', 'dagen']];
		if ($unit === 'd' && ($count % 7) === 0) {
			$count = intdiv($count, 7);
			$names['d'] = ['week', 'weken'];
		}

		$name = $names[$unit][1];
		if ($count === 1) {
			$name = $names[$unit][0];
		}

		return (self::NUMBER_WORDS[$count] ?? (string)$count) . ' ' . $name;
	}//end periodInWords()
}//end class
