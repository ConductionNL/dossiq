<?php

/**
 * ApplicantTermNote unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-applicant-reads-the-decision-date-and-the-state-of-the-term-req-wds-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\Termijn\ApplicantTermNote;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sentence and the date an applicant reads about the term, for any case type.
 *
 * @covers \OCA\Dossiq\Service\Termijn\ApplicantTermNote
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class ApplicantTermNoteTest extends TestCase {

	/**
	 * A period reader that answers one period and counts its calls.
	 *
	 * @param string $period The ISO 8601 period.
	 * @param int    $calls  Counts the reads.
	 *
	 * @return callable(string): string
	 */
	private function period(string $period, int &$calls = 0): callable {
		return static function (string $caseTypeId) use ($period, &$calls): string {
			$calls++;

			return $period;
		};
	}//end period()

	/**
	 * The journal a case carries after one extension.
	 *
	 * @param string $reason The reason the handler gave.
	 *
	 * @return string The `activity` field.
	 */
	private function journal(string $reason): string {
		return (string)json_encode([
			['type' => 'hold', 'reason' => 'Wacht op advies'],
			['type' => 'extend', 'reason' => 'Eerdere verlenging', 'newEndDate' => '2026-10-20'],
			['type' => 'extend', 'reason' => $reason, 'newEndDate' => '2026-11-03'],
			['type' => 'release', 'reason' => 'Advies binnen'],
		]);
	}//end journal()

	/**
	 * A paused term says why and gives no date.
	 *
	 * @return void
	 */
	public function testAPausedTermSaysWhyAndGivesNoDate(): void {
		$calls = 0;
		$fields = (new ApplicantTermNote())->fieldsFor(
			case: [
				'caseType' => 'ct-1',
				'waitingOnApplicant' => true,
				'waitingOnApplicantSince' => '2026-10-06T09:12:00+02:00',
				'extensionCount' => 1,
				'activity' => $this->journal(reason: 'Wij moeten derden om hun mening vragen.'),
			],
			deadline: '2026-11-03',
			extensionPeriod: $this->period(period: 'P14D', calls: $calls),
		);

		self::assertSame(
			'De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben.',
			$fields['termNote']
		);
		self::assertNull($fields['legalDecisionDate'], 'No date is known until the term runs again.');
		self::assertSame(0, $calls, 'A standing term reads no case type.');
	}//end testAPausedTermSaysWhyAndGivesNoDate()

	/**
	 * A pause without a readable start still says the term stands still.
	 *
	 * @return void
	 */
	public function testAPauseWithoutAStartNamesNoDay(): void {
		$fields = (new ApplicantTermNote())->fieldsFor(
			case: ['waitingOnApplicant' => true, 'waitingOnApplicantSince' => null],
			deadline: '2026-11-03',
			extensionPeriod: $this->period(period: 'P14D'),
		);

		self::assertSame('De termijn staat stil. Hij loopt weer zodra wij uw antwoord hebben.', $fields['termNote']);
		self::assertNull($fields['legalDecisionDate']);
	}//end testAPauseWithoutAStartNamesNoDay()

	/**
	 * An extension carries its period and its reason, and the date is the deadline's day.
	 *
	 * @return void
	 */
	public function testAnExtensionCarriesItsReason(): void {
		$fields = (new ApplicantTermNote())->fieldsFor(
			case: [
				'caseType' => 'ct-1',
				'waitingOnApplicant' => false,
				'extensionCount' => 2,
				'activity' => $this->journal(reason: 'Wij moeten derden om hun mening vragen.'),
			],
			deadline: '2026-11-03T00:00:00+01:00',
			extensionPeriod: $this->period(period: 'P14D'),
		);

		self::assertSame(
			'Wij hebben de termijn met twee weken verlengd. De reden: Wij moeten derden om hun mening vragen.',
			$fields['termNote']
		);
		self::assertSame('2026-11-03', $fields['legalDecisionDate']);
	}//end testAnExtensionCarriesItsReason()

	/**
	 * A running term has no note, and reads no case type.
	 *
	 * @return void
	 */
	public function testARunningTermHasNoNote(): void {
		$calls = 0;
		$fields = (new ApplicantTermNote())->fieldsFor(
			case: ['caseType' => 'ct-1', 'extensionCount' => 0, 'activity' => $this->journal(reason: 'Telt niet zonder teller')],
			deadline: '2026-10-20',
			extensionPeriod: $this->period(period: 'P14D', calls: $calls),
		);

		self::assertSame(['termNote' => '', 'legalDecisionDate' => '2026-10-20'], $fields);
		self::assertSame(0, $calls);

		self::assertSame(
			['termNote' => '', 'legalDecisionDate' => null],
			(new ApplicantTermNote())->fieldsFor(case: [], deadline: '', extensionPeriod: $this->period(period: '')),
			'A case without a deadline has no date to tell.'
		);
	}//end testARunningTermHasNoNote()

	/**
	 * Case type periods and the words the applicant reads.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function periods(): array {
		return [
			'two weeks in days' => ['P14D', 'Wij hebben de termijn met twee weken verlengd. De reden: X'],
			'six weeks in days' => ['P42D', 'Wij hebben de termijn met zes weken verlengd. De reden: X'],
			'one week' => ['P1W', 'Wij hebben de termijn met een week verlengd. De reden: X'],
			'days that are no whole weeks' => ['P10D', 'Wij hebben de termijn met tien dagen verlengd. De reden: X'],
			'one day' => ['P1D', 'Wij hebben de termijn met een dag verlengd. De reden: X'],
			'a month' => ['P1M', 'Wij hebben de termijn met een maand verlengd. De reden: X'],
			'more than the number words' => ['P30D', 'Wij hebben de termijn met 30 dagen verlengd. De reden: X'],
			'mixed units name no period' => ['P1M14D', 'Wij hebben de termijn verlengd. De reden: X'],
			'no period stated' => ['', 'Wij hebben de termijn verlengd. De reden: X'],
			'not a period' => ['twee weken', 'Wij hebben de termijn verlengd. De reden: X'],
		];
	}//end periods()

	/**
	 * The period is told in the applicant's words, or left out when it cannot be read.
	 *
	 * @param string $period   The case type's extension period.
	 * @param string $expected The sentence.
	 *
	 * @return void
	 */
	#[DataProvider('periods')]
	public function testThePeriodIsToldInWords(string $period, string $expected): void {
		$fields = (new ApplicantTermNote())->fieldsFor(
			case: ['extensionCount' => 1, 'activity' => [['type' => 'extend', 'reason' => 'X']]],
			deadline: '2026-11-03',
			extensionPeriod: $this->period(period: $period),
		);

		self::assertSame($expected, $fields['termNote']);
	}//end testThePeriodIsToldInWords()

	/**
	 * An extension whose reason the journal does not hold still says the term was extended.
	 *
	 * @return void
	 */
	public function testAnExtensionWithoutAJournalReasonSaysSoWithoutOne(): void {
		$note = new ApplicantTermNote();

		foreach (['', 'not json', '[]', (string)json_encode([['type' => 'hold', 'reason' => 'x']])] as $activity) {
			self::assertSame(
				'Wij hebben de termijn met twee weken verlengd.',
				$note->fieldsFor(
					case: ['extensionCount' => 1, 'activity' => $activity],
					deadline: '2026-11-03',
					extensionPeriod: $this->period(period: 'P14D'),
				)['termNote']
			);
		}
	}//end testAnExtensionWithoutAJournalReasonSaysSoWithoutOne()

	/**
	 * What is written is what the real case schema keeps, long reason included.
	 *
	 * @return void
	 */
	public function testTheFieldsFitTheRealCaseSchema(): void {
		$validator = new RealSchemaValidator();
		$note = new ApplicantTermNote();

		$long = $note->fieldsFor(
			case: ['extensionCount' => 1, 'activity' => [['type' => 'extend', 'reason' => str_repeat('Lange reden. ', 40)]]],
			deadline: '2026-11-03',
			extensionPeriod: $this->period(period: 'P14D'),
		);
		self::assertSame(ApplicantTermNote::MAX_LENGTH, mb_strlen($long['termNote']));
		self::assertStringEndsWith('…', $long['termNote']);
		self::assertSame([], $validator->errors(slug: 'case', payload: $long, creating: false));

		$paused = $note->fieldsFor(
			case: ['waitingOnApplicant' => true, 'waitingOnApplicantSince' => '2026-10-06'],
			deadline: '2026-11-03',
			extensionPeriod: $this->period(period: 'P14D'),
		);
		self::assertSame([], $validator->errors(slug: 'case', payload: $paused, creating: false), 'A cleared date is a null the schema takes.');

		self::assertNotSame(
			[],
			$validator->errors(slug: 'case', payload: ['legalDecisionDate' => 'binnenkort'], creating: false),
			'The schema declares the date, so the validator would refuse a wrong one.'
		);
	}//end testTheFieldsFitTheRealCaseSchema()
}//end class
