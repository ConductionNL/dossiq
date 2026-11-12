<?php

/**
 * The rules a session definition sets, for planning, attendance and minutes.
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Session;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\Session\SessionDefinition;
use OCA\Dossiq\Service\Session\SessionRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Session\SessionRules
 * @uses   \OCA\Dossiq\Service\Session\SessionDefinition
 */
class SessionRulesTest extends TestCase {

	/**
	 * The rules of a session declared with this configuration.
	 *
	 * @param array<string, mixed> $config The session entry, besides its key and label.
	 *
	 * @return SessionRules
	 */
	private static function rules(array $config = []): SessionRules {
		return (new SessionDefinition(config: (['key' => 'hearing', 'label' => 'Hoorzitting'] + $config)))->rules();
	}//end rules()

	/**
	 * The inspection deadline lies the floor's days before the session.
	 *
	 * @return void
	 */
	public function testTheInspectionDeadlineLiesTheFloorBefore(): void {
		$scheduled = new DateTimeImmutable('2026-11-20T10:00:00+01:00');

		self::assertSame('2026-11-13T10:00:00+01:00', self::rules(config: ['inspectionFloorDays' => 7])->inspectionDeadline(scheduled: $scheduled)->format(DATE_ATOM));
		self::assertSame('2026-11-20T10:00:00+01:00', self::rules()->inspectionDeadline(scheduled: $scheduled)->format(DATE_ATOM));
	}//end testTheInspectionDeadlineLiesTheFloorBefore()

	/**
	 * A session exactly on the floor is accepted; a minute earlier is refused with the label.
	 *
	 * @return void
	 */
	public function testTheInspectionFloorBoundary(): void {
		$now = new DateTimeImmutable('2026-11-01T09:00:00+01:00');
		$rules = self::rules(config: ['inspectionFloorDays' => 7]);

		$rules->assertInspectionFloor(scheduled: new DateTimeImmutable('2026-11-08T09:00:00+01:00'), now: $now);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Hoorzitting must be planned at least 7 days ahead, so the file is open for inspection first.');
		$rules->assertInspectionFloor(scheduled: new DateTimeImmutable('2026-11-08T08:59:00+01:00'), now: $now);
	}//end testTheInspectionFloorBoundary()

	/**
	 * Too few invitees are refused with the minimum.
	 *
	 * @return void
	 */
	public function testTooFewInviteesAreRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Hoorzitting needs at least 2 invitee(s).');

		self::rules(config: ['minInvitees' => 2])->assertInvitees(invitees: [['role' => 'applicant']]);
	}//end testTooFewInviteesAreRefused()

	/**
	 * An invitee that is not an object is refused.
	 *
	 * @return void
	 */
	public function testAnInviteeMustBeAnObject(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Each invitee must be an object.');

		self::rules()->assertInvitees(invitees: ['anna']);
	}//end testAnInviteeMustBeAnObject()

	/**
	 * Without declared roles any role is invited; with them, only those.
	 *
	 * @return void
	 */
	public function testInviteeRolesAreKeptOnlyWhenDeclared(): void {
		self::rules()->assertInvitees(invitees: [['role' => 'anything'], []]);
		self::rules(config: ['inviteeRoles' => ['applicant', 'objector']])->assertInvitees(invitees: [['role' => 'objector']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Hoorzitting invites only these roles: applicant, objector.');
		self::rules(config: ['inviteeRoles' => ['applicant', 'objector']])->assertInvitees(invitees: [['role' => 'applicant'], ['role' => 7]]);
	}//end testInviteeRolesAreKeptOnlyWhenDeclared()

	/**
	 * Attendance freezes the grace hours after the session starts.
	 *
	 * @return void
	 */
	public function testAttendanceFreezesAfterTheGraceHours(): void {
		$scheduled = new DateTimeImmutable('2026-11-20T10:00:00+01:00');

		self::assertSame('2026-11-20T11:00:00+01:00', self::rules()->attendanceFreezesAt(scheduled: $scheduled)->format(DATE_ATOM));
		self::assertSame('2026-11-20T14:00:00+01:00', self::rules(config: ['attendanceGraceHours' => 4])->attendanceFreezesAt(scheduled: $scheduled)->format(DATE_ATOM));
	}//end testAttendanceFreezesAfterTheGraceHours()

	/**
	 * Open attendance takes any entry; closed attendance needs a reason on each.
	 *
	 * @return void
	 */
	public function testClosedAttendanceNeedsACorrectionReason(): void {
		$rules = self::rules();
		$rules->assertAttendance(entries: [['present' => true], 'x'], closed: false);
		$rules->assertAttendance(entries: [['correctionReason' => 'Te laat genoteerd']], closed: true);

		foreach ([['correctionReason' => '  '], ['correctionReason' => 5], [], 'x'] as $entry) {
			try {
				$rules->assertAttendance(entries: [['correctionReason' => 'ok'], $entry], closed: true);
				self::fail('A late entry without a reason was accepted.');
			} catch (InvalidArgumentException $e) {
				self::assertSame('Attendance is closed for this session; each change needs a correction reason.', $e->getMessage());
			}
		}
	}//end testClosedAttendanceNeedsACorrectionReason()

	/**
	 * Required minutes are met by a summary or a document, and missing without both.
	 *
	 * @return void
	 */
	public function testRequiredMinutesNeedASummaryOrADocument(): void {
		$rules = self::rules();
		$rules->assertMinutes(summary: 'Verslag', document: '', audio: '', consent: '');
		$rules->assertMinutes(summary: '', document: 'doc-1', audio: '', consent: '');
		self::rules(config: ['minutesRequired' => false])->assertMinutes(summary: '', document: '', audio: '', consent: '');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Hoorzitting needs minutes: a summary or a document.');
		$rules->assertMinutes(summary: '', document: '', audio: '', consent: 'granted');
	}//end testRequiredMinutesNeedASummaryOrADocument()

	/**
	 * An audio recording needs granted consent, unless the session does not ask for it.
	 *
	 * @return void
	 */
	public function testAnAudioRecordingNeedsConsent(): void {
		self::rules()->assertMinutes(summary: 'Verslag', document: '', audio: 'audio-1', consent: 'granted');
		self::rules(config: ['recordingConsentRequired' => false])->assertMinutes(summary: 'Verslag', document: '', audio: 'audio-1', consent: '');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An audio recording needs the recording consent of the parties.');
		self::rules()->assertMinutes(summary: 'Verslag', document: '', audio: 'audio-1', consent: 'denied');
	}//end testAnAudioRecordingNeedsConsent()

	/**
	 * Invitees are stamped with the moment unless they already carry one; non-objects are dropped.
	 *
	 * @return void
	 */
	public function testInviteesAreStampedOnce(): void {
		$when = new DateTimeImmutable('2026-11-12T08:00:00+01:00');

		self::assertSame(
			[
				['participant' => 'anna', 'invitedAt' => '2026-11-12T08:00:00+01:00'],
				['participant' => 'ben', 'invitedAt' => '2026-10-01T12:00:00+02:00'],
				['participant' => 'cor', 'invitedAt' => '2026-11-12T08:00:00+01:00'],
				['participant' => 'dewi', 'invitedAt' => '2026-11-12T08:00:00+01:00'],
			],
			self::rules()->stampInvitees(
				invitees: [
					['participant' => 'anna'],
					['participant' => 'ben', 'invitedAt' => '2026-10-01T12:00:00+02:00'],
					'erik',
					['participant' => 'cor', 'invitedAt' => ''],
					['participant' => 'dewi', 'invitedAt' => 20261001],
				],
				when: $when
			)
		);
	}//end testInviteesAreStampedOnce()
}//end class
