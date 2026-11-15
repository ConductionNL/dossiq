<?php

/**
 * ExtensionNotice answers whether the requester was told, and never throws.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\ExtensionNotice;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Termijn\ExtensionNotice
 * @uses \OCA\Dossiq\Exception\NoticeNotSentException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ExtensionNoticeTest extends TestCase {

	/**
	 * The notice over a register holding one case, and the given notification service.
	 *
	 * @param TermijnNotificationService $notifications The notification service.
	 * @param bool                       $configured    Whether the register is configured.
	 *
	 * @return ExtensionNotice The notice.
	 */
	private function notice(TermijnNotificationService $notifications, bool $configured = true): ExtensionNotice {
		$store = new FakeTermijnStore();
		$store->seed('case', ['id' => 'case-woo', 'identifier' => 'WOO-2026-7', 'portalSubject' => 'ps-abc']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => ($configured === true ? 'dossiq' : ''),
				'case_schema' => 'case',
				default => '',
			}
		);

		return new ExtensionNotice(settings: $settings, notifications: $notifications, logger: new NullLogger());
	}//end notice()

	/**
	 * A sent notice: the case row, the reason and the new end go to the sender.
	 *
	 * @return void
	 */
	public function testASentNoticeHandsTheCaseTheReasonAndTheNewEnd(): void {
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->expects(self::once())->method('sendTermijnNotification')
			->with(
				'extension',
				'ti-woo',
				'',
				self::callback(static fn (array $context): bool => $context['reason'] === 'Zienswijzen' && $context['newEinddatum'] === '2026-11-16'),
				self::callback(static fn (array $case): bool => ($case['portalSubject'] ?? '') === 'ps-abc')
			)
			->willReturn(['dispatch' => ['status' => 'sent', 'channel' => 'portal-inbox']]);

		$answer = $this->notice(notifications: $notifications)->tell('case-woo', 'ti-woo', 'Zienswijzen', '2026-11-16');

		self::assertSame(['noticeStatus' => 'sent', 'noticeChannel' => 'portal-inbox', 'noticeReasonCode' => '', 'noticeReason' => ''], $answer);
	}//end testASentNoticeHandsTheCaseTheReasonAndTheNewEnd()

	/**
	 * A not-sent notice is an answer with the reason, not an exception.
	 *
	 * @return void
	 */
	public function testANotSentNoticeIsAnAnswerWithItsReason(): void {
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->method('sendTermijnNotification')->willThrowException(new NoticeNotSentException(reasonCode: 'no-channel', reason: 'No address.'));

		$answer = $this->notice(notifications: $notifications)->tell('case-woo', 'ti-woo', 'Zienswijzen', '2026-11-16');

		self::assertSame('not-sent', $answer['noticeStatus']);
		self::assertSame('no-channel', $answer['noticeReasonCode']);
		self::assertSame('No address.', $answer['noticeReason']);
	}//end testANotSentNoticeIsAnAnswerWithItsReason()

	/**
	 * Anything else that goes wrong is not-sent too, and an unconfigured register still sends by id.
	 *
	 * @return void
	 */
	public function testAFailureOrAnUnreadableCaseNeverThrows(): void {
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->method('sendTermijnNotification')
			->with('extension', 'ti-woo', '', self::anything(), ['id' => 'case-woo'])
			->willThrowException(new RuntimeException('boom'));

		$answer = $this->notice(notifications: $notifications, configured: false)->tell('case-woo', 'ti-woo', 'Zienswijzen', '2026-11-16');

		self::assertSame('not-sent', $answer['noticeStatus']);
		self::assertSame('send-failed', $answer['noticeReasonCode']);
	}//end testAFailureOrAnUnreadableCaseNeverThrows()
}//end class
