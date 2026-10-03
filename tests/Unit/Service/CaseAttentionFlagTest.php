<?php

/**
 * Neither act happens without a reason, and neither act deletes the other.
 *
 * REQ-MRK-01. Three things are pinned here, and each of them is a way this
 * requirement could pass its own e2e test and still be worthless.
 *
 * ONE, A REASON OF NOTHING IS REFUSED IN BOTH DIRECTIONS. It is easy to
 * require a reason on raising and forget it on clearing, and clearing is the
 * act worth governing: it is the one somebody does to make a nuisance go away.
 * An empty string, a string of spaces and a full stop are all refused, because
 * a required field satisfied by a full stop is a required field in name only.
 *
 * TWO, FOUR RAISINGS ARE STILL FOUR RAISINGS A YEAR LATER. A flag written as a
 * boolean that toggles keeps the last reason and loses the other three, and
 * nothing anywhere fails. So a case raised and cleared four times is read back
 * and every one of the eight rows is counted, with its own reason intact.
 *
 * THREE, THE BOOLEAN THE WORK LIST FACETS ON FOLLOWS THE HISTORY. Two answers
 * to "is this case flagged" is one too many, and the one that drifts is always
 * the cheap one. `isRaised()` reads the last row rather than the boolean, so a
 * case whose `needsAttention` was written by something else still answers what
 * its own history says.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseAttentionFlagService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class CaseAttentionFlagTest extends TestCase {

	/**
	 * The service, over a settings service that resolves nothing.
	 *
	 * Every assertion below is about the RULES, which run before any read or
	 * write. The gestures that reach OpenRegister are covered by the e2e spec,
	 * where there is a real store to reach.
	 *
	 * @return CaseAttentionFlagService The service.
	 */
	private function service(): CaseAttentionFlagService {
		$settings = $this->createMock(originalClassName: SettingsService::class);

		return new CaseAttentionFlagService(settingsService: $settings, logger: new NullLogger());
	}//end service()

	/**
	 * One history row.
	 *
	 * @param string $act    `raised` or `cleared`.
	 * @param string $reason What the person wrote.
	 * @param string $actor  Who performed it.
	 * @param string $moment When.
	 *
	 * @return array<string, string> The row.
	 */
	private function row(string $act, string $reason, string $actor, string $moment): array {
		return ['act' => $act, 'reason' => $reason, 'actor' => $actor, 'moment' => $moment];
	}//end row()

	/**
	 * A raising with no reason behind it is refused, naming the reason.
	 *
	 * @return void
	 */
	public function testAReasonOfNothingIsRefused(): void {
		foreach (['', '   ', '.', "\n\t"] as $empty) {
			try {
				$this->service()->requireReason(reason: $empty);
				self::fail(message: sprintf('a reason of "%s" must be refused', addcslashes($empty, "\n\t")));
			} catch (RuntimeException $e) {
				self::assertSame(expected: 'reason_required', actual: $e->getMessage());
			}
		}
	}//end testAReasonOfNothingIsRefused()

	/**
	 * A reason somebody actually wrote is taken, trimmed.
	 *
	 * @return void
	 */
	public function testARealReasonIsTakenAndTrimmed(): void {
		self::assertSame(
			expected: 'Waiting on the water board since March',
			actual: $this->service()->requireReason(reason: '  Waiting on the water board since March  ')
		);
	}//end testARealReasonIsTakenAndTrimmed()

	/**
	 * A case with no history carries no flag, whatever its boolean says.
	 *
	 * The boolean is the cheap answer and it is the one that drifts. A case
	 * whose `needsAttention` was written by an import, a migration or a bulk
	 * correction has no raising behind it, and a flag with no raising behind
	 * it cannot be cleared with a reason because there is nothing to clear.
	 *
	 * @return void
	 */
	public function testTheBooleanDoesNotOutrankTheHistory(): void {
		$service = $this->service();

		self::assertFalse(
			condition: $service->isRaised(case: ['needsAttention' => true, 'attentionFlagHistory' => []]),
			message: 'a case with no raising in its history is not flagged'
		);

		self::assertTrue(
			condition: $service->isRaised(
				case: [
					'needsAttention' => false,
					'attentionFlagHistory' => [$this->row(act: 'raised', reason: 'A neighbour called twice', actor: 'ahmed', moment: '2026-03-01T09:00:00+00:00')],
				]
			),
			message: 'a case whose last act was a raising is flagged'
		);
	}//end testTheBooleanDoesNotOutrankTheHistory()

	/**
	 * A case raised and cleared four times shows four of each, none overwritten.
	 *
	 * This is the scenario the spec names, and it is the one a toggling
	 * boolean passes by losing seven of the eight rows in silence.
	 *
	 * @return void
	 */
	public function testFourRaisingsAndFourClearingsAreAllStillThere(): void {
		$history = [];
		$reasons = [];
		for ($round = 1; $round <= 4; $round++) {
			$raised = sprintf('Round %d: the applicant stopped answering', $round);
			$cleared = sprintf('Round %d: they answered, nothing outstanding', $round);
			$reasons[] = $raised;
			$reasons[] = $cleared;

			$history[] = $this->row(
				act: 'raised',
				reason: $raised,
				actor: sprintf('handler-%d', $round),
				moment: sprintf('2026-0%d-01T09:00:00+00:00', $round),
			);
			$history[] = $this->row(
				act: 'cleared',
				reason: $cleared,
				actor: sprintf('teamleider-%d', $round),
				moment: sprintf('2026-0%d-14T09:00:00+00:00', $round),
			);
		}

		$flag = $this->service()->describe(caseId: 'case-7', case: ['attentionFlagHistory' => $history]);

		self::assertSame(expected: 4, actual: $flag['raisings']);
		self::assertSame(expected: 4, actual: $flag['clearings']);
		self::assertFalse(condition: $flag['raised'], message: 'the last act was a clearing');
		self::assertSame(
			expected: $reasons,
			actual: array_column($flag['history'], 'reason'),
			message: 'every reason is still readable, in the order it was written'
		);
	}//end testFourRaisingsAndFourClearingsAreAllStillThere()

	/**
	 * A half-written row is dropped rather than shown as an act.
	 *
	 * A history that exists to be evidence is worse for carrying a row nobody
	 * can read: it says somebody did something and cannot say what or why.
	 *
	 * @return void
	 */
	public function testAHalfWrittenRowIsNotAnAct(): void {
		$flag = $this->service()->describe(
			caseId: 'case-7',
			case: [
				'attentionFlagHistory' => [
					$this->row(act: 'raised', reason: 'A neighbour called twice', actor: 'ahmed', moment: '2026-03-01T09:00:00+00:00'),
					['act' => 'cleared'],
					['reason' => 'no act named'],
					$this->row(act: 'invented', reason: 'not one of the two acts', actor: 'ahmed', moment: '2026-03-02T09:00:00+00:00'),
					'not a row at all',
				],
			]
		);

		self::assertCount(expectedCount: 1, haystack: $flag['history']);
		self::assertSame(expected: 1, actual: $flag['raisings']);
		self::assertSame(expected: 0, actual: $flag['clearings']);
	}//end testAHalfWrittenRowIsNotAnAct()

	/**
	 * The flag a reader sees names the reason, the person and the moment.
	 *
	 * @return void
	 */
	public function testBothActsKeepTheirNameAndTheirMoment(): void {
		$flag = $this->service()->describe(
			caseId: 'case-7',
			case: [
				'attentionFlagHistory' => [
					$this->row(act: 'raised', reason: 'The applicant is in hospital', actor: 'ahmed', moment: '2026-03-01T09:00:00+00:00'),
					$this->row(act: 'cleared', reason: 'They are home and the file is complete', actor: 'nadia', moment: '2026-04-02T11:30:00+00:00'),
				],
			]
		);

		self::assertSame(expected: 'ahmed', actual: $flag['history'][0]['actor']);
		self::assertSame(expected: '2026-03-01T09:00:00+00:00', actual: $flag['history'][0]['moment']);
		self::assertSame(expected: 'nadia', actual: $flag['history'][1]['actor']);
		self::assertSame(expected: '2026-04-02T11:30:00+00:00', actual: $flag['history'][1]['moment']);
	}//end testBothActsKeepTheirNameAndTheirMoment()
}//end class
