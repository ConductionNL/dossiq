<?php

/**
 * SenderBlocklist administration unit tests
 *
 * Saving the blocklist stores each entry once, lower-cased and trimmed, and
 * drops blank ones; the allow half asks Nextcloud Mail whether the bare
 * address is trusted and refuses a value that holds no address at all.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Service\Email\SenderBlocklist;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for saving the blocklist and the trusted-sender half.
 *
 * @covers \OCA\Dossiq\Service\Email\SenderBlocklist
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class SenderBlocklistAdministrationTest extends TestCase {

	public function testEntriesAreStoredOnceLowerCasedAndTrimmed(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueString')->with(
			'dossiq',
			SenderBlocklist::BLOCKLIST_KEY,
			'spam@example.nl,@reclame.nl'
		)->willReturn(true);

		(new SenderBlocklist(appConfig: $config, gateway: $this->createMock(MailGatewayInterface::class)))
			->replace(entries: [' Spam@Example.nl ', '', '@RECLAME.nl', 'spam@example.nl', '   ']);
	}//end testEntriesAreStoredOnceLowerCasedAndTrimmed()

	public function testAnEmptyListClearsTheSetting(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueString')->with('dossiq', SenderBlocklist::BLOCKLIST_KEY, '')->willReturn(true);

		(new SenderBlocklist(appConfig: $config, gateway: $this->createMock(MailGatewayInterface::class)))->replace(entries: []);
	}//end testAnEmptyListClearsTheSetting()

	public function testATrustedSenderIsAskedOfMailByItsBareAddress(): void {
		$gateway = $this->createMock(MailGatewayInterface::class);
		$gateway->expects($this->exactly(2))->method('isTrustedSender')->willReturnCallback(
			static fn (string $address): bool => $address === 'jan@example.nl'
		);
		$blocklist = new SenderBlocklist(appConfig: $this->createMock(IAppConfig::class), gateway: $gateway);

		$this->assertTrue($blocklist->allows(email: 'Jan Jansen <jan@example.nl>'));
		$this->assertFalse($blocklist->allows(email: 'piet@example.nl'));
	}//end testATrustedSenderIsAskedOfMailByItsBareAddress()

	public function testAValueWithoutAnAddressIsNeitherAllowedNorBlocked(): void {
		$gateway = $this->createMock(MailGatewayInterface::class);
		$gateway->expects($this->never())->method('isTrustedSender');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('@example.nl');
		$blocklist = new SenderBlocklist(appConfig: $config, gateway: $gateway);

		$this->assertFalse($blocklist->allows(email: ''));
		$this->assertFalse($blocklist->blocks(email: ''));
	}//end testAValueWithoutAnAddressIsNeitherAllowedNorBlocked()
}//end class
