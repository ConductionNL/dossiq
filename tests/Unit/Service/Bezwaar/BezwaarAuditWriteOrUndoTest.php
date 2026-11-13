<?php

/**
 * Writing a bezwaar entry before the act it records: a new record without its entry is deleted, a change
 * is not made without its entry, and a failed change is recorded as not applied.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses   \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 * @uses   \OCA\Dossiq\Service\Support\ObjectAuditEvents
 */
class BezwaarAuditWriteOrUndoTest extends TestCase {
	use MakesBezwaarAuditTrail;

	/**
	 * One hearing session in the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1']);
	}//end setUp()

	public function testANewRecordWithItsEntryIsKept(): void {
		$this->bezwaarAuditTrail()->recordForNewRecord(
			objectService: $this->store,
			register: 'dossiq',
			schema: 'hearingSession',
			objectUuid: 'session-1',
			event: 'hearing-scheduled',
			payload: ['case' => 'case-1'],
			tag: BezwaarAuditTrail::TAG_SCHEDULED,
		);

		self::assertSame('case-1', $this->store->row('hearingSession', 'session-1')['case']);
		self::assertSame(['dossiq.bezwaar.hearing-scheduled'], $this->trail->actionsOn('session-1'));
		self::assertSame([], $this->auditErrors);
	}//end testANewRecordWithItsEntryIsKept()

	public function testANewRecordWhoseEntryCannotBeWrittenIsDeletedAndTheActRefused(): void {
		$this->trail->failsAll = true;

		try {
			$this->bezwaarAuditTrail()->recordForNewRecord(
				objectService: $this->store,
				register: 'dossiq',
				schema: 'hearingSession',
				objectUuid: 'session-1',
				event: 'hearing-scheduled',
				payload: [],
			);
			self::fail('the act must be refused');
		} catch (BezwaarEntryNotWrittenException $notWritten) {
			self::assertSame('dossiq.bezwaar.hearing-scheduled', $notWritten->logContext()['action']);
		}

		self::assertSame([], $this->store->row('hearingSession', 'session-1'));
		self::assertCount(1, $this->auditErrors);
		self::assertStringContainsString('the record it describes is deleted', $this->auditErrors[0][0]);
	}//end testANewRecordWhoseEntryCannotBeWrittenIsDeletedAndTheActRefused()

	public function testAFailedDeleteIsLoggedAndTheOriginalRefusalStillThrown(): void {
		$this->trail->failsAll = true;
		$objects = new class {
			/**
			 * Refuses every delete.
			 *
			 * @param string $register Register.
			 * @param string $schema   Schema.
			 * @param string $uuid     Uuid.
			 *
			 * @return bool
			 */
			public function deleteObject(string $register, string $schema, string $uuid): bool {
				throw new RuntimeException('locked');
			}
		};

		$this->expectException(BezwaarEntryNotWrittenException::class);
		try {
			$this->bezwaarAuditTrail()->recordForNewRecord(objectService: $objects, register: 'dossiq', schema: 'hearingSession', objectUuid: 'session-1', event: 'hearing-scheduled', payload: []);
		} finally {
			self::assertSame(
				['Dossiq bezwaar: the record without an entry could not be deleted', ['object' => 'session-1', 'exception' => 'locked']],
				$this->auditErrors[1]
			);
		}
	}//end testAFailedDeleteIsLoggedAndTheOriginalRefusalStillThrown()

	public function testRecordThenApplyWritesTheEntryFirstAndAnswersTheChange(): void {
		$order = [];
		$result = $this->bezwaarAuditTrail()->recordThenApply(
			register: 'dossiq',
			schema: 'hearingSession',
			objectUuid: 'session-1',
			event: 'hearing-waived',
			payload: ['reason' => 'afgezien'],
			tag: BezwaarAuditTrail::TAG_WAIVER,
			apply: function () use (&$order): string {
				$order[] = $this->trail->actionsOn('session-1');
				return 'applied';
			},
		);

		self::assertSame('applied', $result);
		self::assertSame([['dossiq.bezwaar.hearing-waived']], $order);
	}//end testRecordThenApplyWritesTheEntryFirstAndAnswersTheChange()

	public function testWithoutItsEntryTheChangeIsNotMade(): void {
		$this->trail->failsAll = true;
		$applied = false;

		try {
			$this->bezwaarAuditTrail()->recordThenApply(
				register: 'dossiq',
				schema: 'hearingSession',
				objectUuid: 'session-1',
				event: 'hearing-waived',
				payload: [],
				tag: BezwaarAuditTrail::TAG_WAIVER,
				apply: static function () use (&$applied): void {
					$applied = true;
				},
			);
			self::fail('must throw');
		} catch (BezwaarEntryNotWrittenException $notWritten) {
			self::assertFalse($applied);
		}

		self::assertStringContainsString('the change it records is not made', $this->auditErrors[0][0]);
	}//end testWithoutItsEntryTheChangeIsNotMade()

	public function testAFailedChangeIsRecordedAsNotAppliedAndRethrown(): void {
		try {
			$this->bezwaarAuditTrail()->recordThenApply(
				register: 'dossiq',
				schema: 'hearingSession',
				objectUuid: 'session-1',
				event: 'verslag-recorded',
				payload: ['summary' => 'x'],
				tag: BezwaarAuditTrail::TAG_VERSLAG,
				apply: static function (): void {
					throw new RuntimeException('save failed');
				},
			);
			self::fail('must rethrow');
		} catch (RuntimeException $e) {
			self::assertSame('save failed', $e->getMessage());
		}

		self::assertSame(['dossiq.bezwaar.verslag-recorded', 'dossiq.bezwaar.verslag-recorded-not-applied'], $this->trail->actionsOn('session-1'));
		$context = $this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.verslag-recorded-not-applied');
		self::assertSame(['summary' => 'x', 'error' => 'save failed'], $context['payload']);
		self::assertSame(BezwaarAuditTrail::TAG_VERSLAG, $context['tag']);
	}//end testAFailedChangeIsRecordedAsNotAppliedAndRethrown()

	public function testARefusalAnswersWhetherItsEntryWasWritten(): void {
		$trail = $this->bezwaarAuditTrail();

		self::assertTrue($trail->recordRefusal(register: 'dossiq', schema: 'hearingSession', objectUuid: 'session-1', event: 'audio-upload-denied', payload: []));
		self::assertFalse($trail->recordRefusal(register: 'dossiq', schema: 'hearingSession', objectUuid: 'session-ghost', event: 'audio-upload-denied', payload: []));
		self::assertSame('Dossiq bezwaar: entry not written', $this->auditErrors[0][0]);
		self::assertSame('session-ghost', $this->auditErrors[0][1]['object']);
	}//end testARefusalAnswersWhetherItsEntryWasWritten()
}//end class
