<?php

/**
 * Failure paths of ExtensionNotice: a case that cannot be read is told by its id, with a warning.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\ExtensionNotice;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ExtensionNotice when its case cannot be read.
 *
 * @covers \OCA\Dossiq\Service\Termijn\ExtensionNotice
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ExtensionNoticeCaseReadTest extends TestCase {

	/**
	 * The notice over a store whose reads fail, logging to the given logger.
	 *
	 * @param TermijnNotificationService $notifications The sender.
	 * @param LoggerInterface            $logger        The logger.
	 *
	 * @return ExtensionNotice
	 */
	private function notice(TermijnNotificationService $notifications, LoggerInterface $logger): ExtensionNotice {
		$store = new class extends FakeTermijnStore {
			/**
			 * Every single-object read fails.
			 *
			 * @param int|string      $id       The id.
			 * @param array|null      $_extend  Unused.
			 * @param bool            $files    Unused.
			 * @param string|int|null $register Unused.
			 * @param string|int|null $schema   Unused.
			 *
			 * @return never
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): never {
				throw new RuntimeException('store offline');
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => '',
			}
		);

		return new ExtensionNotice(settings: $settings, notifications: $notifications, logger: $logger);
	}//end notice()

	/**
	 * An unreadable case is warned about, and the notice still goes out by the case id.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIsToldByItsId(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('Dossiq: the case behind an extension notice could not be read', ['case' => 'case-9']);
		$logger->expects(self::never())->method('error');

		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->expects(self::once())->method('sendTermijnNotification')
			->with(
				'extension',
				'ti-9',
				'',
				[
					'case' => 'case-9',
					'reason' => 'Advies',
					'newEinddatum' => '2026-12-01',
					'dedupeKey' => 'extension|ti-9|2026-12-01',
				],
				['id' => 'case-9']
			)
			->willReturn(['dispatch' => ['channel' => 'email']]);

		$answer = $this->notice(notifications: $notifications, logger: $logger)->tell('case-9', 'ti-9', 'Advies', '2026-12-01');

		self::assertSame(['noticeStatus' => 'sent', 'noticeChannel' => 'email', 'noticeReasonCode' => '', 'noticeReason' => ''], $answer);
	}//end testAnUnreadableCaseIsToldByItsId()

	/**
	 * A send answer without a channel is a sent notice on no named channel.
	 *
	 * @return void
	 */
	public function testASendWithoutAChannelHasNone(): void {
		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->method('sendTermijnNotification')->willReturn([]);

		$answer = $this->notice(notifications: $notifications, logger: $this->createMock(LoggerInterface::class))->tell('case-9', 'ti-9', 'Advies', '2026-12-01');

		self::assertSame('sent', $answer['noticeStatus']);
		self::assertSame('', $answer['noticeChannel']);
	}//end testASendWithoutAChannelHasNone()
}//end class
