<?php

/**
 * Which moments an applicant message is declared for, and what it logs when it cannot go out.
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

use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Notification\ApplicantMessage;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Notification\ApplicantMessage
 * @uses \OCA\Dossiq\Service\Notification\ApplicantNotice
 */
final class ApplicantMessageEdgeTest extends TestCase {
	/**
	 * The log lines, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A message service over the declared moments, the case's addresses and its term.
	 *
	 * @param array<int, array<string, mixed>> $moments       The declared moments.
	 * @param array<int, string>               $addresses     The case's addresses.
	 * @param TermijnNotificationService|null  $notifications The transport.
	 * @param CaseTimeline|null                $timeline      The timeline.
	 * @param array<string, mixed>|null        $term          The case's term instance.
	 *
	 * @return ApplicantMessage
	 */
	private function messages(
		array $moments,
		array $addresses = [],
		?TermijnNotificationService $notifications = null,
		?CaseTimeline $timeline = null,
		?array $term = null,
	): ApplicantMessage {
		$ack = $this->createMock(CaseTypeAcknowledgement::class);
		$ack->method('momentsFor')->willReturn($moments);
		$contacts = $this->createMock(CaseContactDirectory::class);
		$contacts->method('collectAddresses')->willReturn($addresses);
		$terms = $this->createMock(TermijnService::class);
		$terms->method('getTermijnInstanceForZaak')->willReturn($term);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}

		return new ApplicantMessage(
			moments: $ack,
			contacts: $contacts,
			terms: $terms,
			notifications: ($notifications ?? $this->createMock(TermijnNotificationService::class)),
			timeline: ($timeline ?? $this->createMock(CaseTimeline::class)),
			logger: $logger,
		);
	}//end messages()

	/**
	 * A moment counts as declared unless explicitly disabled; the first matching declaration wins.
	 *
	 * @return void
	 */
	public function testDeclares(): void {
		$messages = $this->messages([
			['moment' => 'case-opened'],
			['moment' => 'case-decided', 'enabled' => false],
			['moment' => 'case-decided', 'enabled' => true],
			['moment' => 'case-closed', 'enabled' => 0],
		]);

		$this->assertTrue($messages->declares([], 'case-opened'));
		$this->assertFalse($messages->declares([], 'case-decided'));
		$this->assertTrue($messages->declares([], 'case-closed'));
		$this->assertFalse($messages->declares([], 'case-paused'));
	}//end testDeclares()

	/**
	 * A disabled moment sends nothing and reports moment-not-declared with empty fields.
	 *
	 * @return void
	 */
	public function testADisabledMomentSendsNothing(): void {
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->expects($this->never())->method('sendTermijnNotification');

		$outcome = $this->messages([['moment' => 'case-decided', 'enabled' => false]], ['a@example.nl'], $notifications)
			->send(['id' => 'case-1'], [], 'case-decided', 'tpl');

		$this->assertSame(['sent' => false, 'reason' => 'moment-not-declared', 'recipient' => '', 'template' => '', 'sentAt' => ''], $outcome);
	}//end testADisabledMomentSendsNothing()

	/**
	 * No address and no portal subject warns with the moment and template.
	 *
	 * @return void
	 */
	public function testNoAddressWarns(): void {
		$outcome = $this->messages([['moment' => 'm']], [])->send(['id' => 'case-1', 'portalSubject' => '  '], [], 'm', 'tpl');

		$this->assertSame('no-address', $outcome['reason']);
		$this->assertSame([['warning', 'Dossiq applicant message: the case carries no address, so nothing was sent', ['moment' => 'm', 'template' => 'tpl']]], $this->logged);
	}//end testNoAddressWarns()

	/**
	 * A failing transport is send-failed, logged with the error, and not put on the timeline.
	 *
	 * @return void
	 */
	public function testAFailingTransportIsNotRecorded(): void {
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->method('sendTermijnNotification')->willThrowException(new RuntimeException('smtp down'));
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->expects($this->never())->method('record');

		$outcome = $this->messages([['moment' => 'm']], [' first@example.nl ', 'second@example.nl'], $notifications, $timeline)
			->send(['id' => 'case-1'], [], 'm', 'tpl');

		$this->assertSame(['sent' => false, 'reason' => 'send-failed', 'recipient' => 'first@example.nl', 'template' => '', 'sentAt' => ''], $outcome);
		$this->assertSame([['error', 'Dossiq applicant message: the message could not be sent', ['moment' => 'm', 'template' => 'tpl', 'error' => 'smtp down']]], $this->logged);
	}//end testAFailingTransportIsNotRecorded()

	/**
	 * The term id and case uuid are placed on the notice, and the caller's context may override the case reference.
	 *
	 * @return void
	 */
	public function testTheNoticeIsPlacedOnTheTerm(): void {
		$case = ['uuid' => ' case-u ', 'identifier' => 'Z-9'];
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->expects($this->once())->method('sendTermijnNotification')
			->with('tpl', 'term-u', 'a@example.nl', ['case' => 'override', 'x' => 1], $case)
			->willReturn([]);
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->expects($this->once())->method('record')->with('case-u')->willReturn('entry-1');

		$outcome = $this->messages([['moment' => 'm']], ['a@example.nl'], $notifications, $timeline, ['uuid' => 'term-u'])
			->send($case, [], 'm', 'tpl', ['case' => 'override', 'x' => 1]);

		$this->assertTrue($outcome['sent']);
	}//end testTheNoticeIsPlacedOnTheTerm()
}//end class
