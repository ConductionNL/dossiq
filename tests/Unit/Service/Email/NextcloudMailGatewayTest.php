<?php

/**
 * The one class that names Nextcloud Mail, exercised without Nextcloud Mail.
 *
 * That is the interesting case and not a limitation of the test environment.
 * Mail is an optional runtime dependency, so "the app is not installed" is a
 * state real instances are in, and the spec says intake must report itself
 * unavailable there rather than throw on a cron run nobody is watching.
 *
 * Every assertion here is about a REFUSING answer: no accounts, no folders, no
 * source, no threading match, no move, and `unavailable` rather than `pass`.
 * The direction matters. A gateway that failed OPEN would report an unreadable
 * mailbox as an empty one and an unchecked signature as a valid one.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\Email\NextcloudMailGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for the Nextcloud Mail gateway.
 *
 * @covers \OCA\Dossiq\Service\Email\NextcloudMailGateway
 */
class NextcloudMailGatewayTest extends TestCase {

	/**
	 * Build a gateway that believes Mail is installed or is not.
	 *
	 * @param boolean $installed Whether the Mail app answers.
	 *
	 * @return NextcloudMailGateway The gateway.
	 */
	private function gateway(bool $installed): NextcloudMailGateway {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not installed'));

		$tables = $this->createMock(MailMessageSource::class);
		$tables->method('allAccounts')->willReturn([]);
		$tables->method('mailboxesOf')->willReturn([]);
		$tables->method('listMailboxMessagesSince')->willReturn([]);

		return new NextcloudMailGateway(
			appManager: $appManager,
			container: $container,
			mailTables: $tables,
			logger: new NullLogger()
		);
	}//end gateway()

	/**
	 * An instance without the Mail app says so rather than throwing.
	 *
	 * @return void
	 */
	public function testIntakeIsUnavailableRatherThanBrokenWhenMailIsGone(): void {
		$gateway = $this->gateway(installed: false);

		self::assertFalse($gateway->isAvailable());
		self::assertSame([], $gateway->accounts());
		self::assertSame([], $gateway->mailboxes(7));
		self::assertSame([], $gateway->messages(7, 'INBOX', 10));
		self::assertSame('', $gateway->source(7, 'INBOX', 1));
		self::assertSame([], $gateway->attachments(7, 'INBOX', 1));
	}//end testIntakeIsUnavailableRatherThanBrokenWhenMailIsGone()

	/**
	 * A signature nobody could verify is `unavailable`, never `pass`.
	 *
	 * This is the assertion the whole change turns on. A DKIM verdict that
	 * could not be reached must not read like one that succeeded.
	 *
	 * @return void
	 */
	public function testAnUnverifiableSignatureIsUnavailableAndNotPass(): void {
		$gateway = $this->gateway(installed: true);

		$result = $gateway->dkimResult("DKIM-Signature: v=1; d=gemeente.nl\r\n\r\nbody");

		self::assertSame(AuthenticationResult::UNAVAILABLE, $result);
		self::assertNotSame(AuthenticationResult::PASS, $result);
	}//end testAnUnverifiableSignatureIsUnavailableAndNotPass()

	/**
	 * A message carrying no signature at all is `none`, which is its own fact.
	 *
	 * `none` says the sender published nothing to check; `unavailable` says we
	 * could not look. Collapsing them would lose the difference between a
	 * domain with no DKIM and a checker that was not there.
	 *
	 * @return void
	 */
	public function testAnUnsignedMessageIsNoneRatherThanUnavailable(): void {
		$gateway = $this->gateway(installed: true);

		self::assertSame(
			AuthenticationResult::NONE,
			$gateway->dkimResult("Subject: hallo\r\n\r\nbody")
		);
	}//end testAnUnsignedMessageIsNoneRatherThanUnavailable()

	/**
	 * A threading lookup that cannot run answers no.
	 *
	 * The safe direction: this check is what stops a forged In-Reply-To
	 * reaching somebody else's case, so a lookup that failed must not read as
	 * a match.
	 *
	 * @return void
	 */
	public function testAThreadingLookupThatCannotRunAnswersNo(): void {
		$gateway = $this->gateway(installed: true);

		self::assertFalse($gateway->holdsMessageId(7, '<abc@gemeente.nl>'));
		self::assertFalse($gateway->holdsMessageId(7, ''));
	}//end testAThreadingLookupThatCannotRunAnswersNo()

	/**
	 * A move to the folder a message is already in is refused.
	 *
	 * It would be a no-op that answered true, and a recorded move that never
	 * happened is exactly the kind of hollow success this app keeps finding.
	 *
	 * @return void
	 */
	public function testAMoveToTheSameFolderIsRefused(): void {
		$gateway = $this->gateway(installed: true);

		self::assertFalse($gateway->moveMessage(7, 'INBOX', 1, 'INBOX'));
		self::assertFalse($gateway->moveMessage(7, 'INBOX', 1, ''));
	}//end testAMoveToTheSameFolderIsRefused()

	/**
	 * An event that is not Mail's synchronisation event is not unpacked.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsNotUnpacked(): void {
		$gateway = $this->gateway(installed: true);

		self::assertNull($gateway->unpackSynchronisation(new \stdClass()));
	}//end testAnUnrelatedEventIsNotUnpacked()

	/**
	 * The Mail app id is the real one.
	 *
	 * 🔴 An app id nothing answers to makes `isInstalled()` return false
	 * forever, which turns this gateway into a silent no-op rather than an
	 * error. Pinned so a rename cannot happen by accident.
	 *
	 * @return void
	 */
	public function testTheMailAppIdIsTheRealOne(): void {
		self::assertSame('mail', NextcloudMailGateway::MAIL_APP_ID);
		self::assertSame(
			'OCA\\Mail\\Events\\NewMessagesSynchronized',
			NextcloudMailGateway::NEW_MESSAGES_EVENT
		);
	}//end testTheMailAppIdIsTheRealOne()
}//end class
