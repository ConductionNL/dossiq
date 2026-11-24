<?php

/**
 * SessionWrites records the Awb art. 7:7 entries before the session patch they guard.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Hearing
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Hearing;

use OCA\Dossiq\Service\Hearing\SessionPatch;
use OCA\Dossiq\Service\Hearing\SessionStore;
use OCA\Dossiq\Service\Hearing\SessionWrites;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Hearing\SessionWrites
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryCommand
 * @uses \OCA\Dossiq\Service\Hearing\SessionPatch
 * @uses \OCA\Dossiq\Service\Hearing\SessionStore
 * @uses \OCA\Dossiq\Service\Support\ObjectAuditEvents
 */
class SessionWritesTest extends TestCase {
	use MakesBezwaarAuditTrail;

	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
	}

	/**
	 * The writer over the shared store and trail.
	 *
	 * @return array{0: SessionWrites, 1: SessionStore}
	 */
	private function writes(): array {
		return [
			new SessionWrites(auditTrail: $this->bezwaarAuditTrail(), logger: $this->createMock(LoggerInterface::class)),
			new SessionStore(objectService: $this->store, register: 'dossiq', schema: 'hearingSession'),
		];
	}

	/**
	 * Every late correction is recorded, then the attendance is patched.
	 *
	 * @return void
	 */
	public function testEveryCorrectionIsRecordedThenThePatchApplies(): void {
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1', 'attendance' => []]);
		[$writes, $store] = $this->writes();
		$patch = new SessionPatch(
			sessionId: 'session-1',
			current: ['case' => 'case-1', 'attendance' => []],
			update: ['attendance' => [['invitee' => 'a'], ['invitee' => 'b']]],
			entries: [['invitee' => 'a'], ['invitee' => 'b']],
		);

		$updated = $writes->attendance(store: $store, patch: $patch);

		$this->assertSame(
			['dossiq.bezwaar.attendance-late-correction', 'dossiq.bezwaar.attendance-late-correction'],
			$this->trail->actionsOn('session-1')
		);
		$this->assertCount(2, $updated['attendance']);
		$this->assertCount(2, $this->store->row('hearingSession', 'session-1')['attendance']);
	}

	/**
	 * A failed attendance patch leaves one not-applied row per correction.
	 *
	 * @return void
	 */
	public function testAFailedAttendancePatchLeavesANotAppliedRowPerCorrection(): void {
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1', 'attendance' => []]);
		$this->store->refuseSaves = true;
		[$writes, $store] = $this->writes();
		$patch = new SessionPatch(
			sessionId: 'session-1',
			current: ['case' => 'case-1', 'attendance' => []],
			update: ['attendance' => [['invitee' => 'a'], ['invitee' => 'b']]],
			entries: [['invitee' => 'a'], ['invitee' => 'b']],
		);

		try {
			$writes->attendance(store: $store, patch: $patch);
			$this->fail('a failed patch must raise');
		} catch (RuntimeException $failed) {
			$this->assertSame('Could not record attendance', $failed->getMessage());
		}

		$this->assertSame(
			[
				'dossiq.bezwaar.attendance-late-correction',
				'dossiq.bezwaar.attendance-late-correction',
				'dossiq.bezwaar.attendance-late-correction-not-applied',
				'dossiq.bezwaar.attendance-late-correction-not-applied',
			],
			$this->trail->actionsOn('session-1')
		);
		$this->assertSame([], $this->store->row('hearingSession', 'session-1')['attendance']);
	}

	/**
	 * The minutes are recorded as one verslag entry before the session changes, and refused without it.
	 *
	 * @return void
	 */
	public function testMinutesAreRecordedThenAppliedAndRefusedWithoutTheirEntry(): void {
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1']);
		[$writes, $store] = $this->writes();
		$patch = new SessionPatch(
			sessionId: 'session-1',
			current: ['case' => 'case-1'],
			update: ['minutesSummary' => 'Verslag'],
			entries: [['hasSummary' => true, 'hasDocument' => false]],
		);

		$updated = $writes->minutes(store: $store, patch: $patch);
		$this->assertSame('Verslag', $updated['minutesSummary']);
		$this->assertSame(['dossiq.bezwaar.verslag-recorded'], $this->trail->actionsOn('session-1'));

		$this->store->seed('hearingSession', 'session-2', ['case' => 'case-1']);
		$this->trail->failsAll = true;
		try {
			$writes->minutes(store: $store, patch: new SessionPatch(sessionId: 'session-2', current: ['case' => 'case-1'], update: ['minutesSummary' => 'Verslag'], entries: [['hasSummary' => true]]));
			$this->fail('minutes without their entry must be refused');
		} catch (RuntimeException $refused) {
			$this->assertSame('Could not add minutes', $refused->getMessage());
		}

		$this->assertArrayNotHasKey('minutesSummary', $this->store->row('hearingSession', 'session-2'));
	}
}
