<?php

/**
 * Unit tests for the two ends of one control: who may open a case by mail.
 *
 * 🔴 THE BLOCK HALF IS DOSSIQ'S AND STAYS DOSSIQ'S. C-intake-48's note says an
 * allowlist and a blocklist are the same control read from two ends, so the
 * allow half is Nextcloud Mail's trusted-sender list read as it stands, and the
 * block half is a dossiq app-config value. A block that reached into Mail would
 * stop the sender mailing a colleague, which is a different decision from
 * refusing to open a case for them.
 * {@see self::testBlockingInDossiqDoesNotBlockTheMailbox} asserts the gateway
 * offers no write at all, rather than asserting that this class happens not to
 * call one: a method nobody can call cannot be called later either.
 *
 * A DOMAIN MATCH NEEDS THE `@`. `example.nl` as an entry would otherwise also
 * block `notexample.nl`, and a blocklist that blocks more than it says is worse
 * than one that blocks less.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Service\Email\SenderBlocklist;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the block half, the allow half, and the line between them.
 *
 * @covers \OCA\Dossiq\Service\Email\SenderBlocklist
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class SenderBlocklistTest extends TestCase {

	/**
	 * A blocklist over the given administered entries.
	 *
	 * @param string $stored  The stored app-config value.
	 * @param array  $trusted Addresses Nextcloud Mail trusts.
	 *
	 * @return SenderBlocklist The control under test.
	 */
	private function blocklist(string $stored, array $trusted = []): SenderBlocklist {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($stored);

		$gateway = $this->createMock(MailGatewayInterface::class);
		$gateway->method('isTrustedSender')->willReturnCallback(
			static function (string $email) use ($trusted): bool {
				return in_array($email, $trusted, true);
			}
		);

		return new SenderBlocklist($appConfig, $gateway);
	}//end blocklist()

	/**
	 * A blocked address opens no case.
	 *
	 * @return void
	 */
	public function testABlockedSenderIsBlocked(): void {
		$list = $this->blocklist('spammer@voorbeeld.nl');

		self::assertTrue($list->blocks('spammer@voorbeeld.nl'));
		self::assertTrue($list->blocks('Spammer <spammer@voorbeeld.nl>'), 'A display name is not a disguise.');
		self::assertFalse($list->blocks('aanvrager@voorbeeld.nl'));
	}//end testABlockedSenderIsBlocked()

	/**
	 * A whole domain is blocked only when the entry says so with an `@`.
	 *
	 * @return void
	 */
	public function testADomainEntryNeedsItsAtSign(): void {
		$withAt = $this->blocklist('@voorbeeld.nl');
		self::assertTrue($withAt->blocks('wie.dan.ook@voorbeeld.nl'));

		$without = $this->blocklist('voorbeeld.nl');
		self::assertFalse(
			$without->blocks('wie.dan.ook@voorbeeld.nl'),
			'A bare domain is not a domain match, because it would also match nietvoorbeeld.nl.'
		);
	}//end testADomainEntryNeedsItsAtSign()

	/**
	 * A domain entry does not reach a domain that merely ends the same way.
	 *
	 * @return void
	 */
	public function testADomainBlockDoesNotReachALongerDomain(): void {
		$list = $this->blocklist('@voorbeeld.nl');

		self::assertFalse($list->blocks('iemand@nietvoorbeeld.nl'));
	}//end testADomainBlockDoesNotReachALongerDomain()

	/**
	 * An empty list blocks nobody, which is the shipped default.
	 *
	 * @return void
	 */
	public function testAnEmptyListBlocksNobody(): void {
		$list = $this->blocklist('');

		self::assertFalse($list->blocks('aanvrager@voorbeeld.nl'));
		self::assertSame([], $list->entries());
	}//end testAnEmptyListBlocksNobody()

	/**
	 * The allow half is Nextcloud Mail's list, read rather than copied.
	 *
	 * @return void
	 */
	public function testTheAllowHalfIsReadFromNextcloudMail(): void {
		$list = $this->blocklist('', ['bekend@gemeente.nl']);

		self::assertTrue($list->allows('Bekend <bekend@gemeente.nl>'));
		self::assertFalse($list->allows('onbekend@voorbeeld.nl'));
		self::assertFalse($list->allows(''), 'Nothing is not an address.');
	}//end testTheAllowHalfIsReadFromNextcloudMail()

	/**
	 * 🔴 Blocking in dossiq cannot change what the mailbox accepts.
	 *
	 * @return void
	 */
	public function testBlockingInDossiqDoesNotBlockTheMailbox(): void {
		$writers = [];
		foreach ((new \ReflectionClass(MailGatewayInterface::class))->getMethods() as $method) {
			if (preg_match('/^(add|set|remove|delete|store|trust|block|untrust)/i', $method->getName()) === 1) {
				$writers[] = $method->getName();
			}
		}

		self::assertSame(
			[],
			$writers,
			'The gateway exposes no write to Nextcloud Mail, so a dossiq block cannot reach the mailbox.'
		);

		$trusted = new ReflectionMethod(MailGatewayInterface::class, 'isTrustedSender');
		self::assertSame('bool', (string)$trusted->getReturnType(), 'The allow half is read-only.');
	}//end testBlockingInDossiqDoesNotBlockTheMailbox()
}//end class
