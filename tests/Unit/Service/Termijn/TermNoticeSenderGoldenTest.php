<?php

/**
 * Characterisation tests for TermNoticeSender::send: every ledger, opt-out and
 * transport path, with the calls it makes in order, what it logs, and the
 * record or refusal it answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\OutboundCaseMail;
use OCA\Dossiq\Service\Termijn\TermNoticeLedger;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class TermNoticeSenderGoldenTest extends TestCase {

	/**
	 * Every call made, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * A sender over scripted collaborators.
	 *
	 * @param array      $claims   What each claim answers, in turn.
	 * @param array|null $row      What find answers.
	 * @param array      $decision What the opt-out decides.
	 * @param \Throwable|null $sendFailure What the transport throws.
	 *
	 * @return TermNoticeSender The sender.
	 */
	private function sender(array $claims = [true], ?array $row = null, array $decision = ['send' => true, 'code' => 'allowed', 'reason' => '', 'unsubscribe' => ['u' => 1]], ?\Throwable $sendFailure = null): TermNoticeSender {
		$ledger = $this->createMock(TermNoticeLedger::class);
		$ledger->method('claim')->willReturnCallback(function (string $key, string $template, string $instance, int $now) use (&$claims): bool {
			$this->calls[] = ['claim', $key, $template, $instance];
			return array_shift($claims) ?? false;
		});
		$ledger->method('find')->willReturnCallback(function (string $key) use ($row): ?array {
			$this->calls[] = ['find', $key];
			return $row;
		});
		$ledger->method('settle')->willReturnCallback(function (string $key, string $outcome, int $now): void {
			$this->calls[] = ['settle', $key, $outcome];
		});
		$ledger->method('release')->willReturnCallback(function (string $key): void {
			$this->calls[] = ['release', $key];
		});

		$optOut = $this->createMock(CaseMailOptOut::class);
		$optOut->method('decide')->willReturnCallback(function (string $recipient, string $category, string $caseId) use ($decision): array {
			$this->calls[] = ['decide', $recipient, $category, $caseId];
			return $decision;
		});

		$outbound = $this->createMock(OutboundCaseMail::class);
		$outbound->method('senderFor')->willReturnCallback(function (array $caseData, string $kind): array {
			$this->calls[] = ['senderFor', $caseData, $kind];
			return ['from' => 'zaken@example.org'];
		});
		$outbound->method('send')->willReturnCallback(function (...$args) use ($sendFailure): array {
			$this->calls[] = ['send', $args];
			if ($sendFailure !== null) {
				throw $sendFailure;
			}

			return [];
		});

		$calls = &$this->calls;
		$logger = new class($calls) extends AbstractLogger {
			public function __construct(private array &$calls) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->calls[] = ['log', (string)$level, (string)$message, $context];
			}
		};

		return new TermNoticeSender($outbound, $optOut, $ledger, $logger);
	}//end sender()

	/**
	 * Send the standard notice.
	 *
	 * @param TermNoticeSender $sender    The sender.
	 * @param string           $dedupeKey The caller's key.
	 *
	 * @return array The record.
	 */
	private function sendWith(TermNoticeSender $sender, string $dedupeKey = ''): array {
		return $sender->send(
			template: 'herinnering',
			instanceId: 'inst-1',
			recipient: ' Anna@Example.ORG ',
			caseRef: 'case-1',
			subject: 'Herinnering',
			body: "Regel 1\n<b>",
			dedupeKey: $dedupeKey,
			case: ['id' => 'case-1'],
		);
	}//end sendWith()

	/**
	 * The refusal a send threw.
	 *
	 * @param \Closure $send The send.
	 *
	 * @return array{0: string, 1: string} Its code and message.
	 */
	private function refusal(\Closure $send): array {
		try {
			$send();
		} catch (NoticeNotSentException $e) {
			return [$e->getReasonCode(), $e->getMessage()];
		}

		self::fail('The notice was sent.');
	}//end refusal()

	private function contentKey(): string {
		return hash('sha256', implode('|', ['c', 'herinnering', 'inst-1', 'anna@example.org', 'Herinnering', "Regel 1\n<b>"]));
	}//end contentKey()

	public function testANoticeIsClaimedAskedSentSettledAndLogged(): void {
		$record = $this->sendWith($this->sender());
		$key = $this->contentKey();

		self::assertSame(
			[
				['claim', $key, 'herinnering', 'inst-1'],
				['decide', 'anna@example.org', 'service', 'case-1'],
				['senderFor', ['id' => 'case-1'], 'notice'],
				['send', [['from' => 'zaken@example.org'], 'anna@example.org', 'Herinnering', 'Regel 1<br />' . "\n" . '&lt;b&gt;', ['u' => 1], [], "Regel 1\n<b>"]],
				['settle', $key, 'sent'],
				['log', 'info', 'Dossiq term notice sent', ['app' => 'dossiq', 'template' => 'herinnering', 'instance' => 'inst-1', 'category' => 'service', 'code' => 'allowed']],
			],
			$this->calls
		);
		self::assertSame('email', $record['notificationChannel']);
		self::assertTrue($record['sent']);
		self::assertFalse($record['duplicate']);
		self::assertSame('service', $record['category']);
		self::assertSame('allowed', $record['code']);
		self::assertSame('systeem', $record['sentBy']);
		self::assertSame('TN-' . substr($key, 0, 12), $record['messageId']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $record['sentOn']);
	}//end testANoticeIsClaimedAskedSentSettledAndLogged()

	public function testACallerKeyNamesTheNotice(): void {
		$this->sendWith($this->sender(), ' reminder-2 ');
		self::assertSame(hash('sha256', 'k|reminder-2'), $this->calls[0][1]);
	}//end testACallerKeyNamesTheNotice()

	public function testANonMailAddressIsRefusedAndLoggedWithoutAClaim(): void {
		$refusal = $this->refusal(fn () => $this->sender()->send('beschikking', 'i', 'geen adres', 'c', 's', 'b'));

		self::assertSame(['no-email-address', 'This notice has no e-mail address to go to, so it was not sent.'], $refusal);
		self::assertSame(
			[['log', 'info', 'Dossiq term notice not sent', ['app' => 'dossiq', 'template' => 'beschikking', 'instance' => 'i', 'category' => '', 'code' => 'no-email-address']]],
			$this->calls
		);
	}//end testANonMailAddressIsRefusedAndLoggedWithoutAClaim()

	public function testAWishIsKeptAndAnyOtherRefusalGivenBack(): void {
		$refusal = $this->refusal(fn () => $this->sendWith($this->sender(decision: ['send' => false, 'code' => 'opted-out', 'reason' => 'Opted out.'])));
		self::assertSame(['opted-out', 'Opted out.'], $refusal);
		self::assertSame(['settle', $this->contentKey(), 'refused:opted-out'], $this->calls[2]);
		self::assertSame(['log', 'info', 'Dossiq term notice not sent', ['app' => 'dossiq', 'template' => 'herinnering', 'instance' => 'inst-1', 'category' => 'service', 'code' => 'opted-out']], $this->calls[3]);
		self::assertCount(4, $this->calls);

		$this->calls = [];
		$refusal = $this->refusal(fn () => $this->sendWith($this->sender(decision: ['send' => false, 'code' => 'integriq-unavailable', 'reason' => 'Down.'])));
		self::assertSame(['integriq-unavailable', 'Down.'], $refusal);
		self::assertSame(['release', $this->contentKey()], $this->calls[2]);
	}//end testAWishIsKeptAndAnyOtherRefusalGivenBack()

	public function testARefusedTransportGivesTheClaimBackAndSaysWhy(): void {
		$refusal = $this->refusal(fn () => $this->sendWith($this->sender(sendFailure: new RefusedException(rule: 'mail-transport-unavailable', sentence: 'No transport.'))));

		self::assertSame(['mail-failed', 'No transport.'], $refusal);
		self::assertSame(['release', $this->contentKey()], $this->calls[4]);
		self::assertSame(['log', 'warning', 'Dossiq term notice not sent: the outbound mail refused it', ['app' => 'dossiq', 'code' => 'mail-failed', 'rule' => 'mail-transport-unavailable']], $this->calls[5]);
		self::assertCount(6, $this->calls);

		$this->calls = [];
		self::assertSame(['other-rule', 'Other.'], $this->refusal(fn () => $this->sendWith($this->sender(sendFailure: new RefusedException(rule: 'other-rule', sentence: 'Other.')))));
	}//end testARefusedTransportGivesTheClaimBackAndSaysWhy()

	public function testAnEarlierSendAnswersAsADuplicate(): void {
		$record = $this->sendWith($this->sender(claims: [false], row: ['outcome' => 'sent', 'at' => time()]));

		self::assertSame([['claim', $this->contentKey(), 'herinnering', 'inst-1'], ['find', $this->contentKey()]], $this->calls);
		self::assertFalse($record['sent']);
		self::assertTrue($record['duplicate']);
		self::assertSame('already-sent', $record['code']);
		self::assertSame('service', $record['category']);
	}//end testAnEarlierSendAnswersAsADuplicate()

	public function testAnEarlierRefusalIsAnsweredAgain(): void {
		self::assertSame(
			['no-consent', 'This notice was refused before, so it was not sent.'],
			$this->refusal(fn () => $this->sendWith($this->sender(claims: [false], row: ['outcome' => 'refused:no-consent', 'at' => time()])))
		);
	}//end testAnEarlierRefusalIsAnsweredAgain()

	public function testAReleasedClaimIsTriedOnceMore(): void {
		$this->sendWith($this->sender(claims: [false, true], row: null));
		self::assertSame('claim', $this->calls[2][0]);
		self::assertSame('decide', $this->calls[3][0]);

		$this->calls = [];
		self::assertSame(
			['in-flight', 'Another run is sending this notice.'],
			$this->refusal(fn () => $this->sendWith($this->sender(claims: [false, false], row: null)))
		);
	}//end testAReleasedClaimIsTriedOnceMore()

	public function testAStaleClaimIsReleasedAndTakenOverAndAFreshOneIsInFlight(): void {
		$this->sendWith($this->sender(claims: [false, true], row: ['outcome' => 'claimed', 'at' => 0]));
		self::assertSame(['release', $this->contentKey()], $this->calls[2]);
		self::assertSame('claim', $this->calls[3][0]);
		self::assertSame('decide', $this->calls[4][0]);

		$this->calls = [];
		self::assertSame(
			['in-flight', 'Another run is sending this notice.'],
			$this->refusal(fn () => $this->sendWith($this->sender(claims: [false, false], row: ['outcome' => 'claimed', 'at' => 0])))
		);
		self::assertSame('release', $this->calls[2][0]);

		$this->calls = [];
		self::assertSame(
			['in-flight', 'Another run is sending this notice.'],
			$this->refusal(fn () => $this->sendWith($this->sender(claims: [false], row: ['outcome' => 'claimed', 'at' => time()])))
		);
		self::assertCount(2, $this->calls);
	}//end testAStaleClaimIsReleasedAndTakenOverAndAFreshOneIsInFlight()
}//end class
