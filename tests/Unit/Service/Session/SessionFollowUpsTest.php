<?php

/**
 * What follows a session plan: the meeting, the history entry and the log of a refusal.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Session
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Session;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\CaseTimelineInterface;
use OCA\Dossiq\Kernel\Contracts\TimelineKindsInterface;
use OCA\Dossiq\Service\Session\SessionDefinition;
use OCA\Dossiq\Service\Session\SessionFollowUps;
use OCA\Dossiq\Service\Session\SessionWrite;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Session\SessionFollowUps
 * @uses   \OCA\Dossiq\Service\Session\SessionDefinition
 * @uses   \OCA\Dossiq\Service\Session\SessionWrite
 */
class SessionFollowUpsTest extends TestCase {

	/**
	 * Without the decision app no meeting is opened, and without a timeline nothing is recorded.
	 *
	 * @return void
	 */
	public function testWithoutItsPartsNothingFollows(): void {
		$followUps = new SessionFollowUps();

		self::assertSame(expected: '', actual: $followUps->openMeeting(session: ['id' => 's-1'], location: ['dossiq', 'hearingSession']));
		$followUps->recordPlanned(write: $this->write(label: 'Hearing'), session: ['id' => 's-1']);
		$followUps->saveRefused(caseId: 'case-1', error: new RuntimeException('refused'));
		$this->addToAssertionCount(count: 1);
	}//end testWithoutItsPartsNothingFollows()

	/**
	 * A session without a label is recorded under its key, with the stored id from `@self`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/complaints-are-a-case-type/specs/complaint-management/spec.md
	 */
	public function testASessionWithoutALabelIsRecordedUnderItsKey(): void {
		$timeline = $this->createMock(originalClassName: CaseTimelineInterface::class);
		$timeline->expects($this->once())->method('record')->with(
			'case-1',
			TimelineKindsInterface::SESSION,
			'hearing planned for 2026-10-20 10:00',
			[
				'act'           => 'planned',
				'sessionId'     => 's-9',
				'sessionKey'    => 'hearing',
				'scheduledDate' => '2026-10-20T10:00:00+00:00',
			],
		)->willReturn('entry-1');

		(new SessionFollowUps(timeline: $timeline))->recordPlanned(write: $this->write(label: ''), session: ['@self' => ['id' => 's-9']]);
	}//end testASessionWithoutALabelIsRecordedUnderItsKey()

	/**
	 * A refused session is logged with the case and the register's own words.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function testARefusedSessionIsLoggedWithItsCause(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->anything(),
			['caseId' => 'case-1', 'exception' => 'chairperson is not a uuid'],
		);

		(new SessionFollowUps(logger: $logger))->saveRefused(caseId: 'case-1', error: new RuntimeException('chairperson is not a uuid'));
	}//end testARefusedSessionIsLoggedWithItsCause()

	/**
	 * A planned hearing on case-1 at 2026-10-20 10:00 UTC.
	 *
	 * @param string $label The declared label.
	 *
	 * @return SessionWrite The write.
	 */
	private function write(string $label): SessionWrite {
		$write             = new SessionWrite();
		$write->caseId     = 'case-1';
		$write->definition = new SessionDefinition(config: ['key' => 'hearing', 'label' => $label]);
		$write->scheduled  = new DateTimeImmutable('2026-10-20T10:00:00+00:00');

		return $write;
	}//end write()
}//end class
