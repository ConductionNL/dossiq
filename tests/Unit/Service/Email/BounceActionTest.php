<?php

/**
 * Bounce is the doorzendplicht, and move is a mailbox act.
 *
 * 🔴 THE TWO WAYS TO GET BOUNCE WRONG ARE BOTH ASSERTED AGAINST. A bounce
 * implemented as a rejection loses the Awb 2:3 duty entirely, so the sender
 * must receive nothing. A bounce that creates a case first and closes it
 * records a case that never existed, so no case may be created. Both are
 * asserted, because a bounce that merely "worked" says nothing about either.
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

use OCA\Dossiq\Service\Email\BounceAction;
use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\MoveAction;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use OCP\IAppConfig;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the bounce and move acts.
 *
 * @covers \OCA\Dossiq\Service\Email\BounceAction
 * @covers \OCA\Dossiq\Service\Email\MoveAction
 * @uses \OCA\Dossiq\Service\Email\AuthenticationVerdict
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class BounceActionTest extends TestCase {

	/**
	 * Every recipient the mailer was asked to send to.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $sentTo = [];

	/**
	 * Everything the log was asked to record.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * The message that was bounced.
	 *
	 * @var InboundMessage
	 */
	private InboundMessage $message;

	/**
	 * Set up one misdirected message.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sentTo = [];
		$this->recorded = [];
		$this->message = (new InboundMessage(
			accountId: 7,
			mailbox: 'INBOX',
			uid: 12,
			messageId: 'verkeerd@voorbeeld.nl',
			subject: 'Aanvraag rijbewijs',
			from: 'Aanvrager <aanvrager@voorbeeld.nl>',
		))->withSource(
			source: "From: Aanvrager <aanvrager@voorbeeld.nl>\r\nSubject: Aanvraag rijbewijs\r\n\r\ntekst"
		);
	}//end setUp()

	/**
	 * A mailer that records what it was asked to send.
	 *
	 * @return IMailer The mailer.
	 */
	private function recordingMailer(): IMailer {
		$outbound = $this->createMock(IMessage::class);
		$outbound->method('setTo')->willReturnCallback(
			function (array $recipients) use ($outbound): IMessage {
				$this->sentTo[] = array_values($recipients);
				return $outbound;
			}
		);
		$outbound->method('setFrom')->willReturn($outbound);
		$outbound->method('setSubject')->willReturn($outbound);
		$outbound->method('setPlainBody')->willReturn($outbound);
		$outbound->method('attach')->willReturn($outbound);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($outbound);
		$mailer->method('createAttachment')->willReturn($this->createMock(IAttachment::class));
		$mailer->method('send')->willReturn([]);

		return $mailer;
	}//end recordingMailer()

	/**
	 * A log that records what it was asked to write.
	 *
	 * @return IntakeLog The log.
	 */
	private function recordingLog(): IntakeLog {
		$log = $this->createMock(IntakeLog::class);
		$log->method('record')->willReturnCallback(
			function (
				InboundMessage $message,
				FilterVerdict $verdict,
				array $results,
				string $outcome,
				string $reason,
				string $caseId = '',
			): string {
				unset($message, $results);
				$this->recorded[] = [
					'outcome' => $outcome,
					'reason' => $reason,
					'caseId' => $caseId,
					'verdict' => $verdict,
				];
				return 'entry-1';
			}
		);
		$log->method('amend')->willReturn(true);

		return $log;
	}//end recordingLog()

	/**
	 * App config naming a from-address.
	 *
	 * @return IAppConfig The config.
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'email_from_address') {
					return 'zaken@gemeente.nl';
				}

				if ($key === 'email_from_name') {
					return 'Gemeente Voorbeeld';
				}

				return $default;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * A misdirected aanvraag is sent on, and the forwarding is recorded.
	 *
	 * @return void
	 */
	public function testAMisdirectedAanvraagIsSentOnAndRecorded(): void {
		$bounce = new BounceAction(
			mailer: $this->recordingMailer(),
			log: $this->recordingLog(),
			appConfig: $this->appConfig(),
			logger: new NullLogger()
		);

		$result = $bounce->bounce(
			message: $this->message,
			toAddress: 'info@anderegemeente.nl',
			reason: 'Dit hoort bij de gemeente hiernaast.',
			actorId: 'jan'
		);

		self::assertTrue($result['sent']);
		self::assertSame([['info@anderegemeente.nl']], $this->sentTo);
		self::assertCount(1, $this->recorded);
		self::assertSame(IntakeLog::OUTCOME_FORWARDED, $this->recorded[0]['outcome']);
		self::assertStringContainsString('info@anderegemeente.nl', $this->recorded[0]['reason']);
		self::assertStringContainsString('jan', $this->recorded[0]['reason']);
		self::assertStringContainsString(
			'Dit hoort bij de gemeente hiernaast.',
			$this->recorded[0]['reason']
		);
	}//end testAMisdirectedAanvraagIsSentOnAndRecorded()

	/**
	 * A bounce creates no case.
	 *
	 * The message was never ours, so a case for it would be a record of
	 * something that did not happen.
	 *
	 * @return void
	 */
	public function testABounceCreatesNoCase(): void {
		$bounce = new BounceAction(
			mailer: $this->recordingMailer(),
			log: $this->recordingLog(),
			appConfig: $this->appConfig(),
			logger: new NullLogger()
		);

		$bounce->bounce(
			message: $this->message,
			toAddress: 'info@anderegemeente.nl',
			reason: 'Verkeerd bestuursorgaan.',
			actorId: 'jan'
		);

		self::assertSame('', $this->recorded[0]['caseId']);
		self::assertSame(FilterOutcome::FORWARD, $this->recorded[0]['verdict']->outcome);
	}//end testABounceCreatesNoCase()

	/**
	 * A bounce is not a rejection: the sender receives nothing.
	 *
	 * @return void
	 */
	public function testABounceIsNotARejection(): void {
		$bounce = new BounceAction(
			mailer: $this->recordingMailer(),
			log: $this->recordingLog(),
			appConfig: $this->appConfig(),
			logger: new NullLogger()
		);

		$bounce->bounce(
			message: $this->message,
			toAddress: 'info@anderegemeente.nl',
			reason: 'Verkeerd bestuursorgaan.',
			actorId: 'jan'
		);

		$everyRecipient = array_merge(...$this->sentTo);
		self::assertNotContains('aanvrager@voorbeeld.nl', $everyRecipient);
	}//end testABounceIsNotARejection()

	/**
	 * A bounce without a usable address sends nothing.
	 *
	 * @return void
	 */
	public function testABounceWithoutAnAddressSendsNothing(): void {
		$bounce = new BounceAction(
			mailer: $this->recordingMailer(),
			log: $this->recordingLog(),
			appConfig: $this->appConfig(),
			logger: new NullLogger()
		);

		$result = $bounce->bounce(
			message: $this->message,
			toAddress: 'geen adres',
			reason: 'Verkeerd bestuursorgaan.'
		);

		self::assertFalse($result['sent']);
		self::assertSame([], $this->sentTo);
	}//end testABounceWithoutAnAddressSendsNothing()

	/**
	 * A move files the message in another folder on the mail server.
	 *
	 * Asserted against the fake account's own state rather than against a call
	 * count, because a move that was CALLED and left the message where it was
	 * looks exactly like one that worked.
	 *
	 * @return void
	 */
	public function testAMessageIsFiledInAnotherFolder(): void {
		$gateway = new FakeMailGateway();
		$gateway->deliver(
			accountId: 7,
			mailbox: 'INBOX',
			uid: 12,
			source: "Subject: Aanvraag rijbewijs\r\n\r\ntekst"
		);

		$move = new MoveAction(
			gateway: $gateway,
			log: $this->recordingLog(),
			logger: new NullLogger()
		);

		$result = $move->move(
			message: $this->message,
			target: 'Team B',
			note: 'Hoort bij team B.',
			actorId: 'jan'
		);

		self::assertTrue($result['moved']);
		self::assertSame([], $gateway->messages(7, 'INBOX', 10));
		self::assertCount(1, $gateway->messages(7, 'Team B', 10));
		self::assertSame(IntakeLog::OUTCOME_MOVED, $this->recorded[0]['outcome']);
		self::assertStringContainsString('Team B', $this->recorded[0]['reason']);
		self::assertStringContainsString('jan', $this->recorded[0]['reason']);
	}//end testAMessageIsFiledInAnotherFolder()

	/**
	 * A move to a folder the account does not have is refused and recorded.
	 *
	 * @return void
	 */
	public function testAMoveToAnUnknownFolderIsRefused(): void {
		$gateway = new FakeMailGateway();
		$move = new MoveAction(
			gateway: $gateway,
			log: $this->recordingLog(),
			logger: new NullLogger()
		);

		$result = $move->move(message: $this->message, target: 'Bestaat Niet', note: '', actorId: 'jan');

		self::assertFalse($result['moved']);
		self::assertStringContainsString('did not confirm', $this->recorded[0]['reason']);
	}//end testAMoveToAnUnknownFolderIsRefused()
}//end class
