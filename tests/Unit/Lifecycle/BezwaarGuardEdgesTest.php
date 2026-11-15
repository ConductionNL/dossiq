<?php

/**
 * Edge cases of the two bezwaar lifecycle guards.
 *
 * BezwaarLifecycleTest asserts the main allow/deny split of both guards. This
 * file pins the edges that split leaves open: the deadline day itself, an
 * unparseable or blank deadline, the exact refusal sentences, and which
 * spellings of the hearing-waiver flag count as a waiver.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use DateTimeImmutable;
use OCA\Dossiq\Lifecycle\BezwaarDeadlineGuard;
use OCA\Dossiq\Lifecycle\HoorzittingAfzienGuard;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of BezwaarDeadlineGuard and HoorzittingAfzienGuard.
 *
 * @covers \OCA\Dossiq\Lifecycle\BezwaarDeadlineGuard
 * @covers \OCA\Dossiq\Lifecycle\HoorzittingAfzienGuard
 */
final class BezwaarGuardEdgesTest extends TestCase {

	private const DEADLINE_REFUSAL = 'De beslistermijn van het bezwaar is verstreken (AWB art. 7:10). '
		. 'Leg eerst een verdaging of opschorting vast voordat de beslissing wordt genomen.';

	private const HEARING_REFUSAL = 'De hoorzitting mag alleen worden overgeslagen als het hoorrecht is afgezien (AWB art. 7:3).';

	/**
	 * A deadline guard whose "today" is pinned, so no assertion depends on the clock.
	 *
	 * @param string $today The pinned current day (Y-m-d).
	 *
	 * @return BezwaarDeadlineGuard The guard.
	 */
	private function deadlineGuardOn(string $today): BezwaarDeadlineGuard {
		return new class($today) extends BezwaarDeadlineGuard {
			/**
			 * @param string $today The pinned current day.
			 */
			public function __construct(private readonly string $today) {
			}

			/**
			 * @return DateTimeImmutable The pinned day at 00:00.
			 */
			protected function now(): DateTimeImmutable {
				return new DateTimeImmutable($this->today);
			}
		};
	}//end deadlineGuardOn()

	/**
	 * A decision taken on the deadline date itself is still within the term.
	 *
	 * @return void
	 */
	public function testTheDeadlineDayItselfIsStillAllowed(): void {
		$result = $this->deadlineGuardOn('2026-03-10')->check(['decisionDeadline' => '2026-03-10'], 'beslissen', 'bob');

		$this->assertTrue($result->isAllowed());
		$this->assertNull($result->getMessage());
	}//end testTheDeadlineDayItselfIsStillAllowed()

	/**
	 * The day after the deadline is refused with the statutory sentence.
	 *
	 * @return void
	 */
	public function testTheDayAfterTheDeadlineIsRefusedWithTheStatutorySentence(): void {
		$result = $this->deadlineGuardOn('2026-03-11')->check(['decisionDeadline' => '2026-03-10'], 'beslissen', 'bob');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::DEADLINE_REFUSAL, $result->getMessage());
	}//end testTheDayAfterTheDeadlineIsRefusedWithTheStatutorySentence()

	/**
	 * Surrounding whitespace on a stored deadline does not change the verdict.
	 *
	 * @return void
	 */
	public function testAPaddedDeadlineIsReadTrimmed(): void {
		$guard = $this->deadlineGuardOn('2026-03-11');

		$this->assertFalse($guard->check(['decisionDeadline' => '  2026-03-10  '], 'beslissen', 'bob')->isAllowed());
		$this->assertTrue($guard->check(['decisionDeadline' => ' 2026-03-12 '], 'beslissen', 'bob')->isAllowed());
	}//end testAPaddedDeadlineIsReadTrimmed()

	/**
	 * A deadline that cannot be parsed fails open instead of blocking the decision.
	 *
	 * @return void
	 */
	public function testAnUnparseableDeadlineFailsOpen(): void {
		$result = $this->deadlineGuardOn('2026-03-11')->check(['decisionDeadline' => 'not-a-date'], 'beslissen', 'bob');

		$this->assertTrue($result->isAllowed());
		$this->assertNull($result->getMessage());
	}//end testAnUnparseableDeadlineFailsOpen()

	/**
	 * A blank or null deadline counts as no deadline recorded.
	 *
	 * @return void
	 */
	public function testABlankOrNullDeadlineIsNotEnforced(): void {
		$guard = $this->deadlineGuardOn('2030-01-01');

		$this->assertTrue($guard->check(['decisionDeadline' => '   '], 'beslissen', 'bob')->isAllowed());
		$this->assertTrue($guard->check(['decisionDeadline' => null], 'beslissen', 'bob')->isAllowed());
	}//end testABlankOrNullDeadlineIsNotEnforced()

	/**
	 * The unpinned guard reads today, so a deadline long past is refused.
	 *
	 * @return void
	 */
	public function testTheRealClockRefusesADeadlineLongPast(): void {
		$this->assertFalse((new BezwaarDeadlineGuard())->check(['decisionDeadline' => '2000-01-01'], 'beslissen', 'bob')->isAllowed());
	}//end testTheRealClockRefusesADeadlineLongPast()

	/**
	 * Every accepted spelling of a waiver allows skipping the hearing.
	 *
	 * @param mixed $flag The stored hearingWaived value.
	 *
	 * @dataProvider waivedProvider
	 *
	 * @return void
	 */
	public function testAWaiverInAnyAcceptedSpellingAllowsTheSkip(mixed $flag): void {
		$result = (new HoorzittingAfzienGuard())->check(['hearingWaived' => $flag], 'hoorzitting_overslaan', 'alice');

		$this->assertTrue($result->isAllowed());
		$this->assertNull($result->getMessage());
	}//end testAWaiverInAnyAcceptedSpellingAllowsTheSkip()

	/**
	 * Spellings that count as a waiver.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function waivedProvider(): array {
		return [
			'boolean true' => [true],
			'string true' => ['true'],
			'integer one' => [1],
			'string one' => ['1'],
		];
	}//end waivedProvider()

	/**
	 * Anything else is refused with the AWB art. 7:3 sentence.
	 *
	 * @param mixed $flag The stored hearingWaived value.
	 *
	 * @dataProvider notWaivedProvider
	 *
	 * @return void
	 */
	public function testAnythingElseRefusesTheSkipWithTheStatutorySentence(mixed $flag): void {
		$result = (new HoorzittingAfzienGuard())->check(['hearingWaived' => $flag], 'hoorzitting_overslaan', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::HEARING_REFUSAL, $result->getMessage());
	}//end testAnythingElseRefusesTheSkipWithTheStatutorySentence()

	/**
	 * Spellings that do not count as a waiver.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function notWaivedProvider(): array {
		return [
			'null' => [null],
			'string false' => ['false'],
			'integer zero' => [0],
			'string zero' => ['0'],
			'string yes' => ['yes'],
			'upper-case TRUE' => ['TRUE'],
			'integer two' => [2],
		];
	}//end notWaivedProvider()
}//end class
