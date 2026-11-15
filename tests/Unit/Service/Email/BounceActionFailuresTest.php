<?php

/**
 * BounceAction failure-path unit tests
 *
 * A bounce that cannot go out is still recorded, and recorded as not sent:
 * without a from-address nothing is handed to the mailer, a mailer failure is
 * caught, and the log names who asked and that it could not be sent. A
 * message whose source could not be read goes out without an attachment and
 * the covering note says so; the send date is quoted when known.
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

use OCA\Dossiq\Service\Email\BounceAction;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for bounces that cannot be sent, or go without the original.
 *
 * @covers \OCA\Dossiq\Service\Email\BounceAction
 * @uses \OCA\Dossiq\Service\Email\AuthenticationVerdict
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class BounceActionFailuresTest extends TestCase {

	/**
	 * The reasons the log was given.
	 *
	 * @var array<int, string>
	 */
	private array $reasons = [];

	public function testWithoutAFromAddressNothingIsSentAndTheLogSaysSo(): void {
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createMessage');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('no from-address'));

		$result = $this->bounce(mailer: $mailer, fromAddress: '  ', logger: $logger)->bounce(
			message: $this->message(source: 'raw'),
			toAddress: 'info@anderegemeente.nl',
			reason: 'Hoort hiernaast.',
		);

		$this->assertFalse($result['sent']);
		$this->assertSame('entry-1', $result['entryId']);
		$this->assertSame(
			['A bounce to info@anderegemeente.nl was asked for by the intake pipeline and could not be sent. Reason given: Hoort hiernaast.'],
			$this->reasons
		);
	}//end testWithoutAFromAddressNothingIsSentAndTheLogSaysSo()

	public function testAMailerFailureIsCaughtAndRecordedAsNotSent(): void {
		$outbound = $this->outbound(body: $body);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($outbound);
		$mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: sending a bounce failed',
			['messageId' => 'm-1', 'error' => 'smtp down']
		);

		$result = $this->bounce(mailer: $mailer, fromAddress: 'zaken@gemeente.nl', logger: $logger)->bounce(
			message: $this->message(source: 'raw'),
			toAddress: 'info@anderegemeente.nl',
			reason: 'Hoort hiernaast.',
			actorId: 'jan',
		);

		$this->assertFalse($result['sent']);
		$this->assertStringContainsString('was asked for by jan and could not be sent', $this->reasons[0]);
	}//end testAMailerFailureIsCaughtAndRecordedAsNotSent()

	public function testAMessageWithoutItsSourceGoesWithoutAttachmentAndTheNoteSaysSo(): void {
		$outbound = $this->outbound(body: $body);
		$outbound->expects($this->never())->method('attach');
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($outbound);
		$mailer->expects($this->never())->method('createAttachment');
		$mailer->expects($this->once())->method('send')->willReturn([]);

		$result = $this->bounce(mailer: $mailer, fromAddress: 'zaken@gemeente.nl', logger: $this->createMock(LoggerInterface::class))->bounce(
			message: $this->message(source: '', sentAt: '2026-11-12T09:30:00+01:00'),
			toAddress: 'info@anderegemeente.nl',
			reason: 'Hoort hiernaast.',
		);

		$this->assertTrue($result['sent']);
		$this->assertStringContainsString("Verzonden: 2026-11-12T09:30:00+01:00\n\nHet originele bericht kon niet worden meegestuurd.", $body);
		$this->assertStringStartsWith('Forwarded to info@anderegemeente.nl by the intake pipeline under Awb 2:3', $this->reasons[0]);
	}//end testAMessageWithoutItsSourceGoesWithoutAttachmentAndTheNoteSaysSo()

	/**
	 * An outbound message double that keeps its plain body.
	 *
	 * @param string|null $body Receives the body.
	 *
	 * @return IMessage
	 */
	private function outbound(?string &$body): IMessage {
		$body = '';
		$outbound = $this->createMock(IMessage::class);
		$outbound->method('setFrom')->willReturnSelf();
		$outbound->method('setTo')->willReturnSelf();
		$outbound->method('setSubject')->willReturnSelf();
		$outbound->method('setPlainBody')->willReturnCallback(
			function (string $text) use (&$body, $outbound): IMessage {
				$body = $text;
				return $outbound;
			}
		);

		return $outbound;
	}//end outbound()

	/**
	 * The bounce over a mailer, a from-address and a logger.
	 *
	 * @param IMailer         $mailer      The mailer.
	 * @param string          $fromAddress The configured from-address.
	 * @param LoggerInterface $logger      The logger.
	 *
	 * @return BounceAction
	 */
	private function bounce(IMailer $mailer, string $fromAddress, LoggerInterface $logger): BounceAction {
		$this->reasons = [];
		$log = $this->createMock(IntakeLog::class);
		$log->method('record')->willReturnCallback(
			function (...$arguments): string {
				$this->reasons[] = $arguments['reason'] ?? $arguments[4];
				return 'entry-1';
			}
		);
		$log->method('amend')->willReturn(true);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'email_from_address' ? $fromAddress : $default)
		);

		return new BounceAction(mailer: $mailer, log: $log, appConfig: $config, logger: $logger);
	}//end bounce()

	/**
	 * A misdirected message.
	 *
	 * @param string $source The raw source ('' when it could not be read).
	 * @param string $sentAt When it was sent.
	 *
	 * @return InboundMessage
	 */
	private function message(string $source, string $sentAt = ''): InboundMessage {
		return new InboundMessage(
			accountId: 7,
			mailbox: 'INBOX',
			uid: 12,
			messageId: 'm-1',
			subject: 'Aanvraag',
			from: 'aanvrager@voorbeeld.nl',
			sentAt: $sentAt,
			source: $source,
		);
	}//end message()
}//end class
