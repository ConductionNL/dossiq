<?php

/**
 * PublicationDateRule tests: a public notice is published on the decision
 * date, or on the first Wednesday from it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Dossiq\Service\Publication\PublicationDateRule;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PublicationDateRule.
 *
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/vth-admin-settings/spec.md
 */
class PublicationDateRuleTest extends TestCase {

	/**
	 * The first Wednesday from a Thursday is the next week's; a Wednesday is itself;
	 * any other rule is the decision date.
	 *
	 * @return void
	 */
	public function testThePublicationDate(): void {
		$rule = new PublicationDateRule();
		$thursday = new DateTimeImmutable('2026-11-05 10:00');
		$wednesday = new DateTimeImmutable('2026-11-11 10:00');

		$this->assertSame(expected: '2026-11-11', actual: $rule->publishOn(rule: 'first-wednesday', decided: $thursday)->format('Y-m-d'));
		$this->assertSame(expected: '2026-11-11', actual: $rule->publishOn(rule: 'first-wednesday', decided: $wednesday)->format('Y-m-d'));
		$this->assertSame(expected: '2026-11-05', actual: $rule->publishOn(rule: 'decision-date', decided: $thursday)->format('Y-m-d'));
		$this->assertSame(expected: '2026-11-05', actual: $rule->publishOn(rule: '', decided: $thursday)->format('Y-m-d'));
	}//end testThePublicationDate()

	/**
	 * A decision on each day of the week publishes on the Wednesday after it,
	 * and a Wednesday publishes the same day.
	 *
	 * @return void
	 */
	public function testEveryWeekdayLandsOnItsFirstWednesday(): void {
		$rule = new PublicationDateRule();

		// Monday 2026-11-02 through Sunday 2026-11-08.
		$expected = [
			'2026-11-02' => '2026-11-04',
			'2026-11-03' => '2026-11-04',
			'2026-11-04' => '2026-11-04',
			'2026-11-05' => '2026-11-11',
			'2026-11-06' => '2026-11-11',
			'2026-11-07' => '2026-11-11',
			'2026-11-08' => '2026-11-11',
		];

		foreach ($expected as $decided => $published) {
			$this->assertSame(
				expected: $published,
				actual: $rule->publishOn(rule: PublicationDateRule::FIRST_WEDNESDAY, decided: new DateTimeImmutable($decided . ' 09:30'))->format('Y-m-d'),
				message: $decided
			);
		}
	}//end testEveryWeekdayLandsOnItsFirstWednesday()

	/**
	 * A moved publication keeps the decision's time zone and falls to midnight;
	 * an unmoved one is the decision moment itself, to the second.
	 *
	 * `modify('next wednesday')` resets the time of day, so the two rules do
	 * not answer the same shape: pinned here because a caller formatting the
	 * answer as a date sees neither, and one formatting a time sees both.
	 *
	 * @return void
	 */
	public function testAMovedDateFallsToMidnightAndKeepsItsZone(): void {
		$decided = new DateTimeImmutable('2026-11-05 14:45:07', new DateTimeZone('Europe/Amsterdam'));

		$published = (new PublicationDateRule())->publishOn(rule: PublicationDateRule::FIRST_WEDNESDAY, decided: $decided);

		$this->assertSame(expected: '2026-11-11 00:00:00', actual: $published->format('Y-m-d H:i:s'));
		$this->assertSame(expected: 'Europe/Amsterdam', actual: $published->getTimezone()->getName());
		$this->assertSame(expected: $decided, actual: (new PublicationDateRule())->publishOn(rule: PublicationDateRule::DECISION_DATE, decided: $decided));
	}//end testAMovedDateFallsToMidnightAndKeepsItsZone()

	/**
	 * An unknown rule is the decision date, never the Wednesday: a misspelled
	 * setting must not move a publication the admin did not ask to move.
	 *
	 * @return void
	 */
	public function testAnUnknownRuleIsTheDecisionDate(): void {
		$rule = new PublicationDateRule();
		$thursday = new DateTimeImmutable('2026-11-05 10:00');

		foreach (['', 'decision-date', 'First-Wednesday', 'first_wednesday', 'wednesday', 'onbekend'] as $spelling) {
			$this->assertSame(
				expected: '2026-11-05',
				actual: $rule->publishOn(rule: $spelling, decided: $thursday)->format('Y-m-d'),
				message: $spelling
			);
		}
	}//end testAnUnknownRuleIsTheDecisionDate()

	/**
	 * Without a decision date the rule reads the clock, and still answers a
	 * Wednesday on or after today.
	 *
	 * @return void
	 */
	public function testWithoutADecisionDateTodayIsUsed(): void {
		$rule = new PublicationDateRule();
		$today = (new DateTimeImmutable())->format('Y-m-d');

		$this->assertSame(expected: $today, actual: $rule->publishOn(rule: PublicationDateRule::DECISION_DATE)->format('Y-m-d'));

		$published = $rule->publishOn(rule: PublicationDateRule::FIRST_WEDNESDAY);
		$this->assertSame(expected: '3', actual: $published->format('N'));
		$this->assertGreaterThanOrEqual($today, $published->format('Y-m-d'));
	}//end testWithoutADecisionDateTodayIsUsed()
}//end class
