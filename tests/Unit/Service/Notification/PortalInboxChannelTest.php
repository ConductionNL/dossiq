<?php

/**
 * Unit tests for the portal inbox channel.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
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
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Service\Notification\PortalInboxChannel;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Notification\PortalInboxChannel
 */
class PortalInboxChannelTest extends TestCase {

	/**
	 * Without a portal subject or without portaliq the channel is not offered; without a store it refuses.
	 *
	 * @return void
	 */
	public function testTheChannelIsOfferedOnlyWithASubjectAndPortaliq(): void {
		$installed = $this->createMock(originalClassName: IAppManager::class);
		$installed->method('isInstalled')->willReturn(value: true);
		$absent = $this->createMock(originalClassName: IAppManager::class);
		$absent->method('isInstalled')->willReturn(value: false);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$notice = ['subject' => 'Verlenging', 'body' => 'Uw termijn is verlengd.'];

		$channel = new PortalInboxChannel(appManager: $installed, logger: $logger);

		self::assertNull(actual: $channel->deliver(case: ['id' => 'case-1'], notice: $notice, store: null));
		self::assertNull(
			actual: (new PortalInboxChannel(appManager: $absent, logger: $logger))->deliver(case: ['portalSubject' => 'ps-1'], notice: $notice, store: null)
		);
		self::assertSame(
			expected: 'portal-inbox-unavailable',
			actual: $channel->deliver(case: ['portalSubject' => 'ps-1'], notice: $notice, store: null)['reasonCode'] ?? ''
		);
	}//end testTheChannelIsOfferedOnlyWithASubjectAndPortaliq()
}//end class
