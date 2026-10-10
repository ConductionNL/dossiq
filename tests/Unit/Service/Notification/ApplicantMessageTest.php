<?php

/**
 * A message to the applicant that did not go out is never recorded as sent.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Notification\ApplicantMessage;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Notification\ApplicantMessage
 * @uses \OCA\Dossiq\Service\CaseTypeAcknowledgement
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Exception\NoticeNotSentException
 */
class ApplicantMessageTest extends TestCase {

	/**
	 * A case type that sends at case-decided.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE_TYPE = ['notificationMoments' => [['moment' => 'case-decided', 'template' => 'niet-ontvankelijk', 'enabled' => true]]];

	/**
	 * The message service over the given notifications and timeline.
	 *
	 * @param TermijnNotificationService $notifications The transport.
	 * @param CaseTimeline               $timeline      The timeline.
	 *
	 * @return ApplicantMessage The service.
	 */
	private function messages(TermijnNotificationService $notifications, CaseTimeline $timeline): ApplicantMessage {
		return new ApplicantMessage(
			moments: new CaseTypeAcknowledgement(),
			contacts: new CaseContactDirectory(),
			terms: $this->createMock(TermijnService::class),
			notifications: $notifications,
			timeline: $timeline,
			logger: new NullLogger(),
		);
	}//end messages()

	/**
	 * REQ-WRN-001: no transport took it, so the answer is not sent and no public line says it was.
	 *
	 * @return void
	 */
	public function testANotSentNoticeIsNotRecordedAsSent(): void {
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->method('sendTermijnNotification')->willThrowException(
			new NoticeNotSentException(reasonCode: 'mail-failed', reason: 'The mail server did not accept the notice.')
		);
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->expects(self::never())->method('record');

		$outcome = $this->messages($notifications, $timeline)->send(
			['id' => 'case-1', 'email' => 'aanvrager@example.nl'],
			self::CASE_TYPE,
			'case-decided',
			'niet-ontvankelijk',
		);

		self::assertFalse($outcome['sent']);
		self::assertSame('send-failed', $outcome['reason']);
		self::assertSame('', $outcome['sentAt']);
	}//end testANotSentNoticeIsNotRecordedAsSent()

	/**
	 * REQ-WRN-002: a resident with only a portal account is reached, and the sender gets the case.
	 *
	 * @return void
	 */
	public function testAPortalOnlyResidentIsHandedToTheSenderWithTheCase(): void {
		$case = ['id' => 'case-1', 'portalSubject' => 'ps-abc'];
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->expects(self::once())->method('sendTermijnNotification')
			->with('niet-ontvankelijk', '', 'ps-abc', self::anything(), $case)
			->willReturn(['dispatch' => ['status' => 'sent', 'channel' => 'portal-inbox']]);
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->expects(self::once())->method('record')->willReturn('entry-1');

		$outcome = $this->messages($notifications, $timeline)->send($case, self::CASE_TYPE, 'case-decided', 'niet-ontvankelijk');

		self::assertTrue($outcome['sent']);
	}//end testAPortalOnlyResidentIsHandedToTheSenderWithTheCase()
}//end class
