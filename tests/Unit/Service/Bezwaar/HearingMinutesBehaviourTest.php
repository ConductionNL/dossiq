<?php

/**
 * Recording hearing minutes: the recording-consent guard, the minutes update, late corrections and the art. 7:7 report.
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
use OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder
 */
class HearingMinutesBehaviourTest extends TestCase {

	/**
	 * The audit trail the refusals are written to.
	 *
	 * @var BezwaarAuditTrail&MockObject
	 */
	private BezwaarAuditTrail $audit;

	/**
	 * The recorder under test.
	 *
	 * @var HearingMinutesRecorder
	 */
	private HearingMinutesRecorder $recorder;

	/**
	 * A recorder over a mocked audit trail.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->audit = $this->createMock(BezwaarAuditTrail::class);
		$this->recorder = new HearingMinutesRecorder(auditTrail: $this->audit);
	}//end setUp()

	public function testAudioWithGrantedConsentPassesWithoutAuditing(): void {
		$this->audit->expects($this->never())->method('recordRefusal');

		$this->recorder->guardRecordingConsent(sessionId: 's-1', payload: ['audioRecording' => 'file-1'], current: ['recordingConsent' => 'granted'], register: 'r', schema: 'h');
		$this->recorder->guardRecordingConsent(sessionId: 's-1', payload: ['audioRecording' => 'file-1', 'recordingConsent' => 'granted'], current: [], register: 'r', schema: 'h');
		$this->recorder->guardRecordingConsent(sessionId: 's-1', payload: ['audioRecording' => ''], current: [], register: 'r', schema: 'h');
	}//end testAudioWithGrantedConsentPassesWithoutAuditing()

	public function testAudioWithoutConsentIsAuditedAndRefused(): void {
		$this->audit->expects($this->once())->method('recordRefusal')->with(
			'dossiq',
			'hearing',
			's-2',
			'audio-upload-denied',
			['consent' => 'not_requested'],
			BezwaarAuditTrail::TAG_RECORDING_CONSENT,
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('geen toestemming gegeven voor audio-opname');
		$this->recorder->guardRecordingConsent(sessionId: 's-2', payload: ['audioRecording' => 'file-1'], current: [], register: 'dossiq', schema: 'hearing');
	}//end testAudioWithoutConsentIsAuditedAndRefused()

	public function testAStoredDenialCannotBeOverturnedWithTheMinutes(): void {
		$this->audit->expects($this->once())->method('recordRefusal')->with(
			'dossiq',
			'hearing',
			's-3',
			'recording-consent-change-refused',
			['consent' => 'granted'],
			BezwaarAuditTrail::TAG_RECORDING_CONSENT,
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('De geweigerde toestemming voor audio-opname kan niet met het verslag worden gewijzigd');
		$this->recorder->guardRecordingConsent(sessionId: 's-3', payload: ['recordingConsent' => 'granted'], current: ['recordingConsent' => 'denied'], register: 'dossiq', schema: 'hearing');
	}//end testAStoredDenialCannotBeOverturnedWithTheMinutes()

	public function testRestatingOrOmittingAStoredDenialPasses(): void {
		$this->audit->expects($this->never())->method('recordRefusal');

		$this->recorder->guardRecordingConsent(sessionId: 's-4', payload: ['recordingConsent' => 'denied'], current: ['recordingConsent' => 'denied'], register: 'r', schema: 'h');
		$this->recorder->guardRecordingConsent(sessionId: 's-4', payload: ['minutesSummary' => 'x'], current: ['recordingConsent' => 'denied'], register: 'r', schema: 'h');
	}//end testRestatingOrOmittingAStoredDenialPasses()

	public function testTheMinutesUpdateExecutesTheHearingAndKeepsOnlyGivenFields(): void {
		self::assertSame(
			['minutesSummary' => 'Kort verslag', 'minutesDocument' => null, 'status' => 'executed'],
			$this->recorder->buildMinutesUpdate(payload: ['audioRecording' => ''], summary: 'Kort verslag', document: '')
		);
		self::assertSame(
			['minutesSummary' => null, 'minutesDocument' => 'doc-1', 'status' => 'executed', 'audioRecording' => 'file-9', 'recordingConsent' => 'granted'],
			$this->recorder->buildMinutesUpdate(payload: ['audioRecording' => 'file-9', 'recordingConsent' => 'granted'], summary: '', document: 'doc-1')
		);
	}//end testTheMinutesUpdateExecutesTheHearingAndKeepsOnlyGivenFields()

	public function testLateCorrectionsApplyOnlyOnceAttendanceIsFrozen(): void {
		$entries = [['invitee' => 'p-1', 'present' => 1, 'correctionReason' => 'Te laat'], ['invitee' => 'p-2', 'correctionReason' => 'Afgemeld']];

		self::assertSame([], $this->recorder->lateCorrections(entries: [['invitee' => 'p-1']], isFrozen: false));
		self::assertSame(
			[
				['invitee' => 'p-1', 'present' => true, 'correctionReason' => 'Te laat'],
				['invitee' => 'p-2', 'present' => false, 'correctionReason' => 'Afgemeld'],
			],
			$this->recorder->lateCorrections(entries: $entries, isFrozen: true)
		);
	}//end testLateCorrectionsApplyOnlyOnceAttendanceIsFrozen()

	public function testAFrozenCorrectionWithABlankReasonIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Aanwezigheidscorrectie vereist toelichting');
		$this->recorder->lateCorrections(entries: [['invitee' => 'p-1', 'correctionReason' => '   ']], isFrozen: true);
	}//end testAFrozenCorrectionWithABlankReasonIsRefused()

	public function testTheMinutesTextsFallBackToTheStoredOnes(): void {
		self::assertSame(['Nieuw', 'doc-1'], $this->recorder->minutesTexts(payload: ['minutesSummary' => 'Nieuw'], current: ['minutesSummary' => 'Oud', 'minutesDocument' => 'doc-1']));
		self::assertSame(['Oud', ''], $this->recorder->minutesTexts(payload: [], current: ['minutesSummary' => 'Oud']));
	}//end testTheMinutesTextsFallBackToTheStoredOnes()

	public function testAHearingWithoutAnyReportIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Verslag (art. 7:7) ontbreekt');
		$this->recorder->minutesTexts(payload: ['minutesSummary' => '  '], current: ['minutesDocument' => '']);
	}//end testAHearingWithoutAnyReportIsRefused()
}//end class
