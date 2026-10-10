<?php

/**
 * HearingService writes each Awb entry onto the session's OpenRegister trail.
 *
 * No act stands without its entry (REQ-BAT-003): a created hearing whose
 * entry fails is deleted, a change is recorded before it is made, and a
 * refusal refuses whether or not its entry is written.
 *
 * Lives beside the class, in Bezwaar/: tests/Unit/Service/HearingServiceTest.php
 * is the test of the other HearingService, OCA\Dossiq\Service\HearingService.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\HearingService
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 * @uses \OCA\Dossiq\Service\Bezwaar\HearingSchedulePlanner
 * @uses \OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder
 */
class HearingServiceTest extends TestCase {
	use MakesBezwaarAuditTrail;

	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
	}

	/**
	 * A hearing whose entry cannot be written is deleted and refused (REQ-BAT-003).
	 *
	 * @return void
	 */
	public function testAHearingWhoseEntryCannotBeWrittenIsDeletedAndRefused(): void {
		$this->trail->failsAll = true;
		$service = $this->realHearingService(trail: $this->bezwaarAuditTrail());

		try {
			$service->schedule(
				caseId: 'case-1',
				scheduledDate: (new DateTimeImmutable('+20 days'))->format(DateTimeInterface::ATOM),
				chairpersonId: 'chair-1',
				invitees: [['role' => 'bezwaarmaker', 'channel' => 'email']],
			);
			$this->fail('a hearing without its entry must be refused');
		} catch (RuntimeException $refused) {
			$this->assertStringContainsString('hearing-scheduled', $refused->getMessage());
		}

		$this->assertSame(1, $this->store->writes, 'the session was saved before the entry was tried');
		$this->assertSame([], $this->store->all('hearingSession'), 'the saved session must be deleted again');
		$this->assertNotSame([], $this->auditErrors, 'the unwritten entry must be logged');
	}

	/**
	 * A waiver writes an Awb art. 7:3 row with its reason.
	 *
	 * @return void
	 */
	public function testAWaiverWritesAnAwb73RowWithItsReason(): void {
		$saved = $this->realHearingService(trail: $this->bezwaarAuditTrail())->waive(caseId: 'case-1', reason: 'Bezwaarmaker ziet af van horen');

		$context = $this->rowContext(uuid: (string) $saved['id'], action: 'dossiq.bezwaar.hearing-waived');
		$this->assertSame('awb-art-7:3', $context['tag']);
		$this->assertSame(['case' => 'case-1', 'reason' => 'Bezwaarmaker ziet af van horen'], $context['payload']);
		$this->assertArrayNotHasKey('auditTrail', $this->store->row('hearingSession', (string) $saved['id']));
	}

	/**
	 * A refused audio upload is recorded under AVG art. 6, and refused even when that entry fails.
	 *
	 * @return void
	 */
	public function testARefusedAudioUploadIsRecordedUnderAvgArt6(): void {
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1', 'recordingConsent' => 'denied']);
		$service = $this->realHearingService(trail: $this->bezwaarAuditTrail());
		$minutes = ['minutesSummary' => 'Verslag', 'audioRecording' => 'file-9'];

		try {
			$service->addMinutes(sessionId: 'session-1', payload: $minutes);
			$this->fail('an upload without consent must be refused');
		} catch (RuntimeException $refused) {
			$this->assertStringContainsString('toestemming', $refused->getMessage());
		}

		$this->assertSame('avg-art-6', $this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.audio-upload-denied')['tag']);
		$this->assertArrayNotHasKey('audioRecording', $this->store->row('hearingSession', 'session-1'));

		$this->trail->failsAll = true;
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('toestemming');
		try {
			$service->addMinutes(sessionId: 'session-1', payload: $minutes);
		} finally {
			$this->assertSame('dossiq.bezwaar.audio-upload-denied', $this->auditErrors[0][1]['action'] ?? null, 'the unwritten refusal must be logged with its entry');
			$this->assertSame('avg-art-6', $this->auditErrors[0][1]['entry']['tag'] ?? null);
		}
	}

	/**
	 * The verslag is recorded before the patch, and a failed patch leaves a not-applied row.
	 *
	 * @return void
	 */
	public function testMinutesAreRecordedBeforeThePatch(): void {
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1']);
		$service = $this->realHearingService(trail: $this->bezwaarAuditTrail());

		$service->addMinutes(sessionId: 'session-1', payload: ['minutesSummary' => 'Verslag van de hoorzitting']);
		$this->assertSame(['dossiq.bezwaar.verslag-recorded'], $this->trail->actionsOn('session-1'));
		$this->assertSame('executed', $this->store->row('hearingSession', 'session-1')['status']);

		$this->store->seed('hearingSession', 'session-2', ['case' => 'case-1']);
		$this->store->refuseSaves = true;
		try {
			$service->addMinutes(sessionId: 'session-2', payload: ['minutesSummary' => 'Verslag']);
			$this->fail('a failed patch must raise');
		} catch (RuntimeException $failed) {
			$this->assertSame('Could not add minutes', $failed->getMessage());
		}

		$this->assertSame(['dossiq.bezwaar.verslag-recorded', 'dossiq.bezwaar.verslag-recorded-not-applied'], $this->trail->actionsOn('session-2'));

		$this->store->refuseSaves = false;
		$this->store->seed('hearingSession', 'session-3', ['case' => 'case-1']);
		$this->trail->failsAll = true;
		try {
			$service->addMinutes(sessionId: 'session-3', payload: ['minutesSummary' => 'Verslag']);
			$this->fail('minutes whose entry cannot be written must be refused');
		} catch (RuntimeException $refused) {
			$this->assertArrayNotHasKey('status', $this->store->row('hearingSession', 'session-3'), 'nothing may change without its entry');
		}
	}
}
