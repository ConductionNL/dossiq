<?php

/**
 * Term notices reach the citizen.
 *
 * The real path from the detector to the mailer: PauseChaseService (the
 * reminder on a suspended term, run by PauseChaseJob and the engine rung) to
 * TermijnNotificationService to TermNoticeSender, which asks integriq through
 * dossiq's OptOutGate and mails with the unsubscribe link and headers. Only
 * the integriq listener, the mailer and the database are doubles.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
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
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\OptOutGate;
use OCA\Dossiq\Service\Pause\ChaseSchedule;
use OCA\Dossiq\Service\Pause\PauseChaseService;
use OCA\Dossiq\Service\Pause\PauseReason;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\FakeIntegriqOptOuts;
use OCA\Dossiq\Tests\Support\InMemoryEventDispatcher;
use OCA\Dossiq\Tests\Support\InMemoryTermNoticeLedger;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\RecordingMessage;
use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * A deadline near expiry produces one mail, and none to a person who opted out.
 */
class TermNoticeDeliveryTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The dispatcher the opt-out question travels through.
	 *
	 * @var InMemoryEventDispatcher
	 */
	private InMemoryEventDispatcher $dispatcher;

	/**
	 * Integriq's opt-out list, answering on that dispatcher.
	 *
	 * @var FakeIntegriqOptOuts|null
	 */
	private ?FakeIntegriqOptOuts $optOuts = null;

	/**
	 * Every message the mailer was handed.
	 *
	 * @var list<RecordingMessage>
	 */
	private array $sent = [];

	/**
	 * The sent-notice ledger.
	 *
	 * @var InMemoryTermNoticeLedger
	 */
	private InMemoryTermNoticeLedger $ledger;

	/**
	 * Info lines the services logged.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $logged = [];

	/**
	 * Wire the dispatcher, the integriq fake and the ledger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = new InMemoryEventDispatcher();
		$this->optOuts = FakeIntegriqOptOuts::on($this->dispatcher);
		$this->ledger = new InMemoryTermNoticeLedger();
		$this->sent = [];
		$this->logged = [];
	}//end setUp()

	/**
	 * A logger that keeps every line.
	 *
	 * @return AbstractLogger The logger.
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param TermNoticeDeliveryTest $test The test collecting the lines.
			 */
			public function __construct(private TermNoticeDeliveryTest $test) {
			}//end __construct()

			/**
			 * Keep the line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->logged[] = [(string)$message, $context];
			}//end log()
		};
	}//end logger()

	/**
	 * The sender on the real gate, with a recording mailer.
	 *
	 * @param string $fromAddress The configured sender address.
	 *
	 * @return TermNoticeSender The sender.
	 */
	private function sender(string $fromAddress = 'zaken@gemeente.nl'): TermNoticeSender {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(static fn (): RecordingMessage => new RecordingMessage());
		$mailer->method('send')->willReturnCallback(
			function (IMessage $message): array {
				$this->sent[] = $message;
				return [];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'email_from_address' => $fromAddress,
				'email_from_name' => 'Gemeente',
				default => $default,
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		$gateConfig = $this->createMock(IAppConfig::class);
		$gateConfig->method('getValueString')->willReturnArgument(2);
		$gate = new OptOutGate($this->dispatcher, $gateConfig, new NullLogger());

		return new TermNoticeSender(
			mailer: $mailer,
			appConfig: $appConfig,
			optOut: new CaseMailOptOut($gate, $l10n, new UnsubscribeHeaders(new NullLogger())),
			ledger: $this->ledger,
			logger: $this->logger(),
		);
	}//end sender()

	/**
	 * The notification service over a term store that answers one instance.
	 *
	 * @param array<string, mixed> $instance The instance.
	 * @param TermNoticeSender     $sender   The sender.
	 *
	 * @return array{0: TermijnNotificationService, 1: TermijnService} The service and its term store.
	 */
	private function notifications(array $instance, TermNoticeSender $sender): array {
		$terms = $this->createMock(TermijnService::class);
		$terms->method('getTermijnInstance')->willReturn($instance);
		$terms->method('updateTermijnInstance')->willReturnCallback(static fn (string $id, array $patch): array => array_merge(['id' => $id], $patch));
		$terms->method('recordEvent')->willReturn([]);

		return [new TermijnNotificationService($terms, $sender, $this->logger()), $terms];
	}//end notifications()

	/**
	 * The detector: the reminder on a suspended term, as PauseChaseJob runs it.
	 *
	 * @param TermijnNotificationService $notifications The real notification service.
	 * @param TermijnService             $terms         The term store.
	 * @param string                     $recipient     The address on the open request.
	 *
	 * @return PauseChaseService The service.
	 */
	private function chases(TermijnNotificationService $notifications, TermijnService $terms, string $recipient): PauseChaseService {
		$reasons = $this->createMock(PauseReasonReader::class);
		$reasons->method('caseTypeForCase')->willReturn(['handling' => ['automaticMessages' => [PauseChaseService::TEMPLATE]]]);
		$reasons->method('reasonsIn')->willReturn(
			[
				PauseReason::normalise(
					row: [
						'key' => 'aanvulling-aanvrager',
						'name' => 'Aanvulling gevraagd',
						'category' => 'applicant',
						'legalBasis' => 'Awb 4:5',
						'chaseIntervalDays' => 5,
						'chaseBudget' => 2,
						'countsWorkingDays' => false,
						'chaseText' => 'Wij hebben uw aanvulling nog niet ontvangen.',
						'escalateTo' => 'handler',
					]
				),
			]
		);
		$aanvullingen = $this->createMock(AanvullingsverzoekService::class);
		$aanvullingen->method('openFor')->willReturn(['recipient' => $recipient]);

		return new PauseChaseService(
			termService: $terms,
			reasons: $reasons,
			schedule: new ChaseSchedule(calendar: new WorkingDayCalculator(), dates: $this->caseDates()),
			notifications: $notifications,
			timeline: $this->createMock(CaseTimeline::class),
			settings: $this->createMock(SettingsService::class),
			logger: $this->logger(),
			aanvullingen: $aanvullingen,
		);
	}//end chases()

	/**
	 * One suspended instance whose pause deadline is close.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function instance(): array {
		return [
			'id' => 't1',
			'case' => 'case-1',
			'status' => 'paused',
			'pauzeStartDatum' => '2026-09-01',
			'pauseDeadline' => '2026-09-15',
			'pauseReason' => 'aanvulling-aanvrager',
			'chasesSent' => 0,
		];
	}//end instance()

	/**
	 * Two triggers on one due reminder mail the citizen once, with the link.
	 *
	 * The store keeps answering the uncounted row, which is what a timer rung
	 * and the daily sweep see when they run at the same moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function testADueReminderIsMailedOnceWithTheUnsubscribeLink(): void {
		[$notifications, $terms] = $this->notifications($this->instance(), $this->sender());
		$chases = $this->chases($notifications, $terms, 'burger@example.nl');
		$now = new DateTimeImmutable('2026-09-07T09:00:00+02:00');

		self::assertTrue($chases->chaseIfDue(instance: ['id' => 't1'], now: $now));
		self::assertTrue($chases->chaseIfDue(instance: ['id' => 't1'], now: $now), 'the second trigger finds the reminder already sent');

		self::assertCount(1, $this->sent, 'exactly one mail');
		$mail = $this->sent[0];
		self::assertSame(['burger@example.nl'], $mail->to);
		$url = 'https://nc.example/index.php/apps/integriq/unsubscribe/tok-' . md5('burger@example.nl|case-1');
		self::assertStringContainsString($url, $mail->plainBody);
		self::assertSame('<' . $url . '>', ($mail->headers['List-Unsubscribe'] ?? null));
		self::assertSame('List-Unsubscribe=One-Click', ($mail->headers['List-Unsubscribe-Post'] ?? null));

		self::assertSame('service', $this->optOuts->log[0]['category']);
		self::assertSame('email', $this->optOuts->log[0]['channel']);
		self::assertSame('case-1', $this->optOuts->log[0]['caseRef']);
		self::assertCount(1, $this->optOuts->log, 'integriq is asked once per notice');
	}//end testADueReminderIsMailedOnceWithTheUnsubscribeLink()

	/**
	 * The next reminder on the same term is a new notice, not a duplicate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function testTheSecondReminderIsANewNotice(): void {
		$second = array_merge($this->instance(), ['chasesSent' => 1, 'lastChasedAt' => '2026-09-06T09:00:00+02:00']);
		[$notifications, $terms] = $this->notifications($second, $this->sender());
		$chases = $this->chases($notifications, $terms, 'burger@example.nl');

		$this->ledger->rows[hash('sha256', 'k|t1:chase:1')] = ['outcome' => 'sent', 'template' => PauseChaseService::TEMPLATE, 'instance' => 't1', 'at' => 1];

		self::assertTrue($chases->chaseIfDue(instance: ['id' => 't1'], now: new DateTimeImmutable('2026-09-12T09:00:00+02:00')));
		self::assertCount(1, $this->sent);
	}//end testTheSecondReminderIsANewNotice()

	/**
	 * A person who opted out is not mailed, nothing is counted, and it is logged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 */
	public function testAnOptedOutPersonIsNotMailedAndItIsLogged(): void {
		$this->optOuts->optOut('burger@example.nl', 'case-1');
		[$notifications, $terms] = $this->notifications($this->instance(), $this->sender());
		$terms->expects(self::never())->method('updateTermijnInstance');
		$chases = $this->chases($notifications, $terms, 'burger@example.nl');
		$now = new DateTimeImmutable('2026-09-07T09:00:00+02:00');

		self::assertFalse($chases->chaseIfDue(instance: ['id' => 't1'], now: $now));
		self::assertFalse($chases->chaseIfDue(instance: ['id' => 't1'], now: $now));

		self::assertSame([], $this->sent);
		self::assertCount(1, $this->optOuts->log, 'one decision per notice, not one per sweep');
		$lines = array_filter($this->logged, static fn (array $line): bool => str_contains($line[0], 'not sent') && (($line[1]['code'] ?? '') === 'opted-out'));
		self::assertNotEmpty($lines, 'the refusal is logged with its code');
	}//end testAnOptedOutPersonIsNotMailedAndItIsLogged()

	/**
	 * The ontvangstbevestiging is statutory: it reaches an opted-out person, without a link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 */
	public function testTheAcknowledgementIsStatutoryAndCarriesNoLink(): void {
		$this->optOuts->optOut('burger@example.nl');
		[$notifications] = $this->notifications(['id' => 't1', 'case' => 'case-1', 'endDateCurrent' => '2026-11-01'], $this->sender());

		$payload = $notifications->sendTermijnNotification('ontvangstbevestiging', 't1', 'burger@example.nl', ['case' => 'Z-1']);

		self::assertSame('sent', $payload['dispatch']['status']);
		self::assertSame('statutory', $this->optOuts->log[0]['category']);
		self::assertCount(1, $this->sent);
		self::assertStringNotContainsString('unsubscribe', strtolower($this->sent[0]->plainBody));
		self::assertSame([], $this->sent[0]->headers);
	}//end testTheAcknowledgementIsStatutoryAndCarriesNoLink()

	/**
	 * Decision 158: a beschikking announcement is statutory mail (Awb 3:41). It
	 * reaches a requester who opted out of case mail, without a link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function testABeschikkingIsStatutoryAndReachesAnOptedOutRequester(): void {
		$this->optOuts->optOut('burger@example.nl');

		$result = $this->sender()->send(
			template: 'beschikking',
			instanceId: 'besch-1',
			recipient: 'burger@example.nl',
			caseRef: 'case-1',
			subject: 'Uw besluit B-2026-7',
			body: 'Het besluit op uw aanvraag staat klaar.',
			dedupeKey: 'beschikking:besch-1',
		);

		self::assertSame('statutory', $this->optOuts->log[0]['category']);
		self::assertCount(1, $this->sent, 'the opt-out does not stop a statutory notice');
		self::assertStringNotContainsString('unsubscribe', strtolower($this->sent[0]->plainBody));
		self::assertNotSame([], $result);
	}//end testABeschikkingIsStatutoryAndReachesAnOptedOutRequester()

	/**
	 * Without integriq a service notice is refused, and the next run may try again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 */
	public function testWithoutIntegriqTheNoticeIsRefusedAndRetriedLater(): void {
		$this->dispatcher = new InMemoryEventDispatcher();
		[$notifications] = $this->notifications($this->instance(), $this->sender());

		try {
			$notifications->sendTermijnNotification(PauseChaseService::TEMPLATE, 't1', 'burger@example.nl', ['dedupeKey' => 't1:chase:1']);
			self::fail('A service notice must not go out unchecked.');
		} catch (NoticeNotSentException $e) {
			self::assertSame(OptOutGate::CODE_UNAVAILABLE, $e->getReasonCode());
		}

		self::assertSame([], $this->sent);
		self::assertSame([], $this->ledger->rows, 'the claim is given back, so a later run retries');

		$this->optOuts = FakeIntegriqOptOuts::on($this->dispatcher);
		$notifications->sendTermijnNotification(PauseChaseService::TEMPLATE, 't1', 'burger@example.nl', ['dedupeKey' => 't1:chase:1']);
		self::assertCount(1, $this->sent);
	}//end testWithoutIntegriqTheNoticeIsRefusedAndRetriedLater()

	/**
	 * A recipient that is not an e-mail address is refused, not reported sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 */
	public function testANonMailAddressIsRefused(): void {
		[$notifications] = $this->notifications($this->instance(), $this->sender());

		$this->expectException(NoticeNotSentException::class);
		$notifications->sendTermijnNotification(PauseChaseService::TEMPLATE, 't1', 'portal-subject-123', []);
	}//end testANonMailAddressIsRefused()

	/**
	 * A failed mail is not a sent notice: the claim is given back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function testAMissingSenderAddressSendsNothingAndKeepsNoClaim(): void {
		[$notifications] = $this->notifications($this->instance(), $this->sender(fromAddress: ''));

		try {
			$notifications->sendTermijnNotification(PauseChaseService::TEMPLATE, 't1', 'burger@example.nl', ['dedupeKey' => 't1:chase:1']);
			self::fail('Nothing can be sent without a sender address.');
		} catch (NoticeNotSentException $e) {
			self::assertSame('no-sender-address', $e->getReasonCode());
		}

		self::assertSame([], $this->sent);
		self::assertSame([], $this->ledger->rows);
	}//end testAMissingSenderAddressSendsNothingAndKeepsNoClaim()
}//end class
