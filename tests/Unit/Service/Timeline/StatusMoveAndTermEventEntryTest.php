<?php

/**
 * The status-move and term-event writers, on their own.
 *
 * The suite next door drives both writers through the stores that call them.
 * These tests hand each writer its input directly, so the branches a store
 * never reaches are held too: a move without a case, a first status, a status
 * whose name cannot be read, and a term event without a rationale.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Timeline
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
 * @spec openspec/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Timeline;

use DateTimeImmutable;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\StatusMoveEntry;
use OCA\Dossiq\Service\Timeline\TermEventEntry;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for StatusMoveEntry and TermEventEntry, called directly.
 *
 * @covers \OCA\Dossiq\Service\Timeline\StatusMoveEntry
 * @covers \OCA\Dossiq\Service\Timeline\TermEventEntry
 * @uses \OCA\Dossiq\Service\Timeline\StatusDisplayName
 */
class StatusMoveAndTermEventEntryTest extends TestCase {

	/**
	 * The mocked timeline seam.
	 *
	 * @var CaseTimeline|MockObject
	 */
	private CaseTimeline $timeline;

	/**
	 * The mocked status lookup.
	 *
	 * @var StatusTypeLookup|MockObject
	 */
	private StatusTypeLookup $statuses;

	/**
	 * Every call the seam received, as named keys.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Arm the seam so every call is captured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = [];
		$this->timeline = $this->createMock(CaseTimeline::class);
		$this->timeline->method('record')->willReturnCallback(
			function (
				string $caseId,
				string $kind,
				string $message,
				array $fields = [],
				string $visibility = 'internal',
				array $relatedCaseIds = [],
			): string {
				$this->calls[] = compact('caseId', 'kind', 'message', 'fields', 'visibility', 'relatedCaseIds');

				return 'entry-1';
			}
		);
		$this->statuses = $this->createMock(StatusTypeLookup::class);
		$this->statuses->method('announcedLabelOf')->willReturn('');
	}//end setUp()

	/**
	 * Build the status writer.
	 *
	 * @param string|null $sessionUid The signed-in user, null for nobody.
	 *
	 * @return StatusMoveEntry The writer under test.
	 */
	private function statusEntry(?string $sessionUid = null): StatusMoveEntry {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
		}

		$session->method('getUser')->willReturn($user);

		return new StatusMoveEntry(timeline: $this->timeline, statuses: $this->statuses, userSession: $session);
	}//end statusEntry()

	/**
	 * A move without a case or without a target status writes nothing, and
	 * asks the lookup nothing either.
	 *
	 * @return void
	 */
	public function testAMoveWithoutACaseOrTargetWritesNothing(): void {
		$this->statuses->expects($this->never())->method('nameFor');
		$entry = $this->statusEntry();

		$entry->record(caseId: '', toStatus: 'st-b', fromStatus: 'st-a', label: 'Door', comment: null, record: []);
		$entry->record(caseId: 'case-1', toStatus: '', fromStatus: 'st-a', label: 'Door', comment: null, record: []);

		self::assertSame([], $this->calls);
	}//end testAMoveWithoutACaseOrTargetWritesNothing()

	/**
	 * A first status is "set", not "changed", and names only the status entered.
	 *
	 * @return void
	 */
	public function testAFirstStatusIsSetRatherThanChanged(): void {
		$this->statuses->method('nameFor')->willReturnMap([['st-intake', 'Intake']]);

		$this->statusEntry()->record(
			caseId: 'case-1',
			toStatus: 'st-intake',
			fromStatus: '',
			label: 'Start',
			comment: null,
			record: ['@self' => ['id' => 'record-9']],
		);

		self::assertCount(1, $this->calls);
		self::assertSame('Status gezet op Intake', $this->calls[0]['message']);
		self::assertSame('', $this->calls[0]['fields']['from']);
		self::assertSame('', $this->calls[0]['fields']['explanation']);
		self::assertSame('record-9', $this->calls[0]['fields']['statusRecordId']);
		self::assertSame(CaseTimeline::INTERNAL, $this->calls[0]['visibility']);
	}//end testAFirstStatusIsSetRatherThanChanged()

	/**
	 * A status whose name cannot be read, or reads blank, is named by its id,
	 * so the sentence never ends in nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrBlankStatusNameFallsBackToItsId(): void {
		$this->statuses->method('nameFor')->willReturnCallback(
			static function (string $statusTypeId): string {
				if ($statusTypeId === 'st-a') {
					throw new RuntimeException('the status type is gone');
				}

				return '   ';
			}
		);

		$this->statusEntry()->record(
			caseId: 'case-1',
			toStatus: 'st-b',
			fromStatus: 'st-a',
			label: 'Door',
			comment: 'Klaar',
			record: ['id' => 'record-1'],
		);

		self::assertSame('Status gewijzigd van st-a naar st-b', $this->calls[0]['message']);
		self::assertSame('record-1', $this->calls[0]['fields']['statusRecordId']);
	}//end testAnUnreadableOrBlankStatusNameFallsBackToItsId()

	/**
	 * The caller's actor wins, trimmed; without one the session user is the
	 * actor, and with nobody signed in the actor is ''.
	 *
	 * @return void
	 */
	public function testTheActorIsTheCallersThenTheSessionsThenNobody(): void {
		$this->statuses->method('nameFor')->willReturn('Naam');

		$this->statusEntry(sessionUid: 'session-user')->record(
			caseId: 'case-1',
			toStatus: 'st-b',
			fromStatus: 'st-a',
			label: 'Door',
			comment: null,
			record: [],
			actor: '  named-user  ',
		);
		$this->statusEntry(sessionUid: 'session-user')->record(
			caseId: 'case-1',
			toStatus: 'st-b',
			fromStatus: 'st-a',
			label: 'Door',
			comment: null,
			record: [],
		);
		$this->statusEntry()->record(
			caseId: 'case-1',
			toStatus: 'st-b',
			fromStatus: 'st-a',
			label: 'Door',
			comment: null,
			record: [],
		);

		self::assertSame('named-user', $this->calls[0]['fields']['actor']);
		self::assertSame('session-user', $this->calls[1]['fields']['actor']);
		self::assertSame('', $this->calls[2]['fields']['actor']);
		self::assertSame('', $this->calls[2]['fields']['statusRecordId']);
	}//end testTheActorIsTheCallersThenTheSessionsThenNobody()

	/**
	 * A term event without a rationale reads the default sentence, and its
	 * fields fall back to the calculated end and the statutory term.
	 *
	 * @return void
	 */
	public function testATermEventWithoutARationaleReadsTheDefaultSentence(): void {
		(new TermEventEntry(timeline: $this->timeline))->recordEvent(
			instance: ['case' => ' case-1 ', 'endDateCalculated' => '2026-12-01', 'id' => 'term-1'],
			type: 'pause-expired',
			basis: 'Awb 4:15',
			rationale: '   ',
			moment: new DateTimeImmutable('2026-11-01T10:00:00+00:00'),
		);

		self::assertCount(1, $this->calls);
		self::assertSame('case-1', $this->calls[0]['caseId']);
		self::assertSame('termijngebeurtenis', $this->calls[0]['kind']);
		self::assertSame('Termijngebeurtenis vastgelegd', $this->calls[0]['message']);
		self::assertSame(
			[
				'event' => 'pause-expired',
				'term' => 'statutory',
				'occurredAt' => '2026-11-01T10:00:00+00:00',
				'dueAt' => '2026-12-01',
				'startedAt' => '',
				'basis' => 'Awb 4:15',
				'termijnId' => 'term-1',
			],
			$this->calls[0]['fields']
		);
		self::assertSame(CaseTimeline::INTERNAL, $this->calls[0]['visibility']);
	}//end testATermEventWithoutARationaleReadsTheDefaultSentence()

	/**
	 * A term event on an instance that names no case writes nothing.
	 *
	 * @return void
	 */
	public function testATermEventWithoutACaseWritesNothing(): void {
		(new TermEventEntry(timeline: $this->timeline))->recordEvent(
			instance: ['case' => '  '],
			type: 'pause-expired',
			basis: '',
			rationale: 'Gepauzeerd',
			moment: null,
		);

		self::assertSame([], $this->calls);
	}//end testATermEventWithoutACaseWritesNothing()
}//end class
