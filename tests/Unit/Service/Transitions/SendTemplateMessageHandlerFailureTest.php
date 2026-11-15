<?php

/**
 * SendTemplateMessageHandler failure tests.
 *
 * An incomplete action sends nothing, and a send that throws fails the step
 * and gives a send-once claim back so a later attempt may still send it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\Termijn\TermNoticeLedger;
use OCA\Dossiq\Service\Transitions\RecipientResolvers;
use OCA\Dossiq\Service\Transitions\SendTemplateMessageHandler;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\SendTemplateMessageHandler
 *
 * @uses \OCA\Dossiq\Service\AssigneeResolver
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 * @uses \OCA\Dossiq\Service\Transitions\RecipientResolvers
 */
class SendTemplateMessageHandlerFailureTest extends TestCase {

	/**
	 * The handler over the given mail, ledger and logger; the role resolves to one address.
	 *
	 * @param CaseEmailService&MockObject $mail   The mail service.
	 * @param TermNoticeLedger&MockObject $ledger The send-once ledger.
	 * @param LoggerInterface             $logger The logger.
	 *
	 * @return SendTemplateMessageHandler The handler.
	 */
	private function handler(CaseEmailService $mail, TermNoticeLedger $ledger, LoggerInterface $logger): SendTemplateMessageHandler {
		$roles = $this->createMock(RoleResolverService::class);
		$roles->method('resolve')->willReturn(['Bureau@Example.nl']);

		return new SendTemplateMessageHandler(
			recipients: new RecipientResolvers(
				assignees: new AssigneeResolver(logger: new NullLogger()),
				roles: $roles,
				users: $this->createMock(IUserManager::class),
			),
			contacts: $this->createMock(CaseContactDirectory::class),
			mail: $mail,
			logger: $logger,
			ledger: $ledger
		);
	}//end handler()

	/**
	 * An action without a template sends nothing and claims nothing.
	 *
	 * @return void
	 */
	public function testAnActionWithoutATemplateIsIncomplete(): void {
		$mail = $this->createMock(CaseEmailService::class);
		$mail->expects($this->never())->method('sendFromTemplate');
		$ledger = $this->createMock(TermNoticeLedger::class);
		$ledger->expects($this->never())->method('claim');

		$result = $this->handler($mail, $ledger, new NullLogger())->handle(['emailTemplate' => '  ', 'recipientRole' => 'r'], ['id' => 'case-1'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('send_message_incomplete', $result->error);
	}//end testAnActionWithoutATemplateIsIncomplete()

	/**
	 * A send that throws fails the step, is logged, and gives the claim back.
	 *
	 * @return void
	 */
	public function testAFailedSendReleasesTheClaim(): void {
		$mail = $this->createMock(CaseEmailService::class);
		$mail->expects($this->once())
			->method('sendFromTemplate')
			->with('case-1', 'tpl', 'bureau@example.nl')
			->willThrowException(new RuntimeException('smtp down'));

		$key = hash('sha256', 'dossiq:once:herinnering:case-1:');
		$ledger = $this->createMock(TermNoticeLedger::class);
		$ledger->expects($this->once())->method('claim')->willReturn(true);
		$ledger->expects($this->once())->method('release')->with($key);
		$ledger->expects($this->never())->method('settle');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('SendTemplateMessageHandler: the message was not sent', ['case' => 'case-1', 'exception' => 'smtp down']);

		$result = $this->handler($mail, $ledger, $logger)->handle(
			['emailTemplate' => 'tpl', 'recipientRole' => 'role-architect', 'onceKey' => 'herinnering'],
			['id' => 'case-1'],
			[]
		);

		$this->assertFalse($result->succeeded);
		$this->assertSame('send_message_failed', $result->error);
	}//end testAFailedSendReleasesTheClaim()

	/**
	 * A failed send without a once-key has no claim to give back.
	 *
	 * @return void
	 */
	public function testAFailedSendWithoutAClaimReleasesNothing(): void {
		$mail = $this->createMock(CaseEmailService::class);
		$mail->method('sendFromTemplate')->willThrowException(new RuntimeException('smtp down'));
		$ledger = $this->createMock(TermNoticeLedger::class);
		$ledger->expects($this->never())->method('claim');
		$ledger->expects($this->never())->method('release');

		$result = $this->handler($mail, $ledger, new NullLogger())->handle(
			['emailTemplate' => 'tpl', 'recipientRole' => 'role-architect'],
			['id' => 'case-1'],
			[]
		);

		$this->assertSame('send_message_failed', $result->error);
	}//end testAFailedSendWithoutAClaimReleasesNothing()
}//end class
