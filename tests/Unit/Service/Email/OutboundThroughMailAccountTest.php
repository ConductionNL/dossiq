<?php

/**
 * Case mail leaves through the selected Nextcloud Mail account (REQ-IMF-11).
 *
 * Drives NextcloudMailGateway::sendMessage() against stand-ins for Mail's
 * AccountService, OutboxService and LocalMessage, so the real mapping from
 * Mail's outbox status to dossiq's delivery state runs, and OutboundCaseMail
 * over it. Also pins that the upgrade deletes the stored SMTP credential.
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Repair\RetireImapCredentials;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\Email\MailTransportPolicy;
use OCA\Dossiq\Service\Email\NextcloudMailGateway;
use OCA\Dossiq\Service\Email\OutboundCaseMail;
use OCA\Dossiq\Service\Email\OutboundState;
use OCA\Dossiq\Service\Email\SenderIdentity;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\OptOutGate;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use OCA\Dossiq\Tests\Support\RecordingMessage;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/../../../Support/Mail/LocalMessage.php';

/**
 * @covers \OCA\Dossiq\Service\Email\NextcloudMailGateway::sendMessage
 * @covers \OCA\Dossiq\Service\Email\OutboundCaseMail
 * @covers \OCA\Dossiq\Service\Email\OutboundState
 * @covers \OCA\Dossiq\Repair\RetireImapCredentials
 *
 * @uses \OCA\Dossiq\Service\Email\NextcloudMailGateway
 * @uses \OCA\Dossiq\Service\Email\SenderIdentity
 * @uses \OCA\Dossiq\Service\Email\MailTransportPolicy
 * @uses \OCA\Dossiq\Service\Email\CaseMailOptOut
 * @uses \OCA\Dossiq\Service\Email\IntakeAccount
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class OutboundThroughMailAccountTest extends TestCase {

	/**
	 * What Mail's outbox stand-in saw.
	 *
	 * @var array<string, mixed>
	 */
	private array $outboxCalls = [];

	/**
	 * Build the gateway over stand-ins for Mail's account and outbox services.
	 *
	 * @param integer|null   $status    The status Mail's send chain leaves on the message, or null to throw on send.
	 * @param boolean        $saveFails Whether saving to the outbox throws.
	 * @param boolean        $installed Whether Mail is installed.
	 *
	 * @return NextcloudMailGateway The gateway.
	 */
	private function gateway(?int $status, bool $saveFails = false, bool $installed = true): NextcloudMailGateway {
		$this->outboxCalls = [];
		$calls = &$this->outboxCalls;

		$account = new class {
			/**
			 * @return string The owner.
			 */
			public function getUserId(): string {
				return 'zaken';
			}
		};

		$accounts = new class ($account) {
			/**
			 * @param object $account The one account.
			 */
			public function __construct(private object $account) {
			}

			/**
			 * @param integer $id The account id.
			 *
			 * @return object The account.
			 */
			public function findById(int $id): object {
				if ($id !== 7) {
					throw new RuntimeException('no such account');
				}

				return $this->account;
			}
		};

		$outbox = new class ($status, $saveFails, $calls) {
			/**
			 * @param integer|null         $status    The status to leave.
			 * @param boolean              $saveFails Whether save throws.
			 * @param array<string, mixed> $calls     Where to record.
			 */
			public function __construct(private ?int $status, private bool $saveFails, private array &$calls) {
			}

			/**
			 * Mail's saveMessage().
			 *
			 * @return object The saved message.
			 */
			public function saveMessage(object $account, object $message, array $to, array $cc, array $bcc, array $attachments = []): object {
				if ($this->saveFails === true) {
					throw new RuntimeException('database gone');
				}

				$message->setId(41);
				$this->calls['save'] = ['message' => $message, 'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'attachments' => $attachments];

				return $message;
			}

			/**
			 * Mail's sendMessage().
			 *
			 * @return object The message after the send chain.
			 */
			public function sendMessage(object $message, object $account): object {
				$this->calls['send'] = true;
				if ($this->status === null) {
					throw new RuntimeException('smtp down');
				}

				$message->setStatus($this->status);

				return $message;
			}
		};

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $name): object => match ($name) {
				'OCA\\Mail\\Service\\AccountService' => $accounts,
				'OCA\\Mail\\Service\\OutboxService' => $outbox,
				default => throw new RuntimeException('not stubbed: ' . $name),
			}
		);

		return new NextcloudMailGateway(
			appManager: $appManager,
			container: $container,
			mailTables: $this->createMock(MailMessageSource::class),
			logger: new NullLogger()
		);
	}//end gateway()

	/**
	 * The message dossiq hands over.
	 *
	 * @return array<string, mixed> The message.
	 */
	private function message(): array {
		return [
			'to' => ['burger@example.nl'],
			'subject' => 'Uw zaak',
			'html' => '<p>Tekst</p>',
			'plain' => 'Tekst',
			'attachments' => ['Documenten/brief.pdf'],
		];
	}//end message()

	/**
	 * A case mail is saved for the account and sent through it; filed means SENT.
	 *
	 * @return void
	 */
	public function testACaseMailIsSentThroughTheAccountAndFiled(): void {
		$result = $this->gateway(status: 12)->sendMessage(7, $this->message());

		self::assertSame(['state' => OutboundState::SENT, 'outboxId' => 41], $result);
		$saved = $this->outboxCalls['save'];
		self::assertSame([['email' => 'burger@example.nl']], $saved['to']);
		self::assertSame([['type' => 'cloud', 'fileName' => 'Documenten/brief.pdf']], $saved['attachments']);
		$values = $saved['message']->values;
		self::assertSame(0, $values['type']);
		self::assertSame(7, $values['accountId']);
		self::assertSame('Uw zaak', $values['subject']);
		self::assertSame('<p>Tekst</p>', $values['bodyHtml']);
		self::assertSame('Tekst', $values['bodyPlain']);
		self::assertTrue($values['html']);
		// Due now, so Mail's own outbox job retries it if this send fails.
		self::assertLessThanOrEqual(time(), $values['sendAt']);
	}//end testACaseMailIsSentThroughTheAccountAndFiled()

	/**
	 * Sent, but the copy could not be filed in the sent folder.
	 *
	 * @return void
	 */
	public function testASentMessageThatCouldNotBeFiledSaysSo(): void {
		$result = $this->gateway(status: 11)->sendMessage(7, $this->message());

		self::assertSame(OutboundState::SENT_NOT_FILED, $result['state']);
		self::assertTrue(OutboundState::isSent($result['state']));
	}//end testASentMessageThatCouldNotBeFiledSaysSo()

	/**
	 * An SMTP failure leaves the message in Mail's outbox: queued, never dropped.
	 *
	 * @return void
	 */
	public function testASendFailureLeavesTheMessageQueued(): void {
		self::assertSame(
			['state' => OutboundState::QUEUED, 'outboxId' => 41],
			$this->gateway(status: 10)->sendMessage(7, $this->message())
		);
		self::assertSame(
			['state' => OutboundState::QUEUED, 'outboxId' => 41],
			$this->gateway(status: null)->sendMessage(7, $this->message())
		);
		self::assertFalse(OutboundState::isSent(OutboundState::QUEUED));
	}//end testASendFailureLeavesTheMessageQueued()

	/**
	 * When Mail takes nothing, the answer is unavailable.
	 *
	 * @return void
	 */
	public function testAnUnreachableAccountIsUnavailable(): void {
		$unavailable = ['state' => OutboundState::UNAVAILABLE, 'outboxId' => null];

		self::assertSame($unavailable, $this->gateway(status: 12, saveFails: true)->sendMessage(7, $this->message()));
		self::assertSame($unavailable, $this->gateway(status: 12)->sendMessage(99, $this->message()));
		self::assertSame($unavailable, $this->gateway(status: 12, installed: false)->sendMessage(7, $this->message()));
		self::assertArrayNotHasKey('send', $this->outboxCalls);
	}//end testAnUnreachableAccountIsUnavailable()

	/**
	 * OutboundCaseMail over the fake gateway, with a configurable transport map.
	 *
	 * @param FakeMailGateway $gateway    The fake Mail app.
	 * @param string          $transports The `mail_transport_by_kind` JSON.
	 * @param IMailer|null    $mailer     Nextcloud's mailer.
	 * @param string          $from       The configured IMailer sender.
	 *
	 * @return OutboundCaseMail The outbound path.
	 */
	private function outbound(FakeMailGateway $gateway, string $transports = '', ?IMailer $mailer = null, string $from = 'zaken@gemeente.nl'): OutboundCaseMail {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				IntakeAccount::ACCOUNT_KEY => '7',
				MailTransportPolicy::CONFIG_KEY => $transports,
				'email_from_address' => $from,
				default => $default,
			}
		);
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('referenceId')->willReturn('');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new OutboundCaseMail(
			$gateway,
			new SenderIdentity($gateway, new IntakeAccount($appConfig), $this->createMock(CaseTypeResolver::class), $store),
			new MailTransportPolicy($appConfig),
			new CaseMailOptOut($this->createMock(OptOutGate::class), $l10n),
			($mailer ?? $this->createMock(IMailer::class)),
			$appConfig,
			new NullLogger()
		);
	}//end outbound()

	/**
	 * OutboundCaseMail refuses as unavailable when the account took nothing, and passes the rest through.
	 *
	 * @return void
	 */
	public function testOutboundCaseMailRefusesOnlyWhenNothingWasTaken(): void {
		$gateway  = new FakeMailGateway();
		$outbound = $this->outbound(gateway: $gateway);

		$sender = $outbound->senderFor(['title' => 'Dakkapel'], MailTransportPolicy::KIND_CASE_MAIL);
		self::assertSame(MailTransportPolicy::TRANSPORT_MAIL_ACCOUNT, $sender['transport']);
		self::assertSame('zaken@gemeente.nl', $sender['from']);

		$gateway->sendState = OutboundState::QUEUED;
		self::assertSame(OutboundState::QUEUED, $outbound->send($sender, 'burger@example.nl', 'S', '<p>B</p>', null)['state']);
		self::assertSame(['burger@example.nl'], $gateway->sent[0]['message']['to']);
		self::assertSame('<p>B</p>', $gateway->sent[0]['message']['html']);

		$gateway->sendState = OutboundState::UNAVAILABLE;
		try {
			$outbound->send($sender, 'burger@example.nl', 'S', '<p>B</p>', null);
			self::fail('A send nothing took must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('mail-account-unavailable', $e->getRule());
			self::assertSame(503, $e->getStatus());
		}
	}//end testOutboundCaseMailRefusesOnlyWhenNothingWasTaken()

	/**
	 * Case mail configured to IMailer leaves through Nextcloud's mailer, from the configured sender.
	 *
	 * @return void
	 */
	public function testAKindConfiguredToTheMailerLeavesThroughIt(): void {
		$gateway = new FakeMailGateway();
		$message = new RecordingMessage();
		$mailer  = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects(self::once())->method('send')->with($message);
		$outbound = $this->outbound(gateway: $gateway, transports: '{"case-mail":"imailer"}', mailer: $mailer, from: 'service@gemeente.nl');

		$sender = $outbound->senderFor([], MailTransportPolicy::KIND_CASE_MAIL);
		self::assertSame(MailTransportPolicy::TRANSPORT_IMAILER, $sender['transport']);
		self::assertSame('service@gemeente.nl', $sender['from']);

		$result = $outbound->send($sender, 'burger@example.nl', 'S', '<p>B</p>', null);

		self::assertSame(OutboundState::SENT, $result['state']);
		self::assertSame([], $gateway->sent, 'nothing went through the Mail account');
		self::assertSame('<p>B</p>', $message->htmlBody);
	}//end testAKindConfiguredToTheMailerLeavesThroughIt()

	/**
	 * Through the mailer: no sender configured, attachments, or a refusing server each refuse visibly.
	 *
	 * @return void
	 */
	public function testTheMailerPathRefusesWhatItCannotSend(): void {
		$gateway = new FakeMailGateway();
		try {
			$this->outbound(gateway: $gateway, transports: '{"case-mail":"imailer"}', from: '')->senderFor([], MailTransportPolicy::KIND_CASE_MAIL);
			self::fail('No sender address must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('mail-sender-not-configured', $e->getRule());
		}

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn(new RecordingMessage());
		$mailer->method('send')->willThrowException(new RuntimeException('smtp.internal:25 refused'));
		$outbound = $this->outbound(gateway: $gateway, transports: '{"case-mail":"imailer"}', mailer: $mailer);
		$sender   = $outbound->senderFor([], MailTransportPolicy::KIND_CASE_MAIL);

		try {
			$outbound->send($sender, 'burger@example.nl', 'S', 'B', null, ['brief.pdf']);
			self::fail('Attachments through the mailer must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('attachments-need-mail-account', $e->getRule());
		}

		try {
			$outbound->send($sender, 'burger@example.nl', 'S', 'B', null);
			self::fail('A refusing mail server must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('mail-transport-unavailable', $e->getRule());
			self::assertStringNotContainsString('smtp.internal', $e->getSentence());
		}
	}//end testTheMailerPathRefusesWhatItCannotSend()

	/**
	 * The upgrade deletes the stored outbound SMTP credential, in both app namespaces.
	 *
	 * @return void
	 */
	public function testTheStoredSmtpCredentialIsDeletedOnUpgrade(): void {
		self::assertContains('email_smtp_password', RetireImapCredentials::RETIRED_KEYS);
		self::assertContains('email_smtp_host', RetireImapCredentials::RETIRED_KEYS);

		$deleted = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturnCallback(
			static fn (string $app, string $key): bool => ($key === 'email_smtp_password')
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			static function (string $app, string $key) use (&$deleted): void {
				$deleted[] = $app . ':' . $key;
			}
		);

		(new RetireImapCredentials($appConfig, new NullLogger()))->run($this->createMock(IOutput::class));

		self::assertSame(['dossiq:email_smtp_password', 'procest:email_smtp_password'], $deleted);
	}//end testTheStoredSmtpCredentialIsDeletedOnUpgrade()
}//end class
