<?php

/**
 * What one request for information hands each collaborator, in order.
 *
 * Pins the letter's arguments, the suspension's arguments, the recorded event
 * and the log lines of InformationRequestService::ask, on both the sent and the
 * failed path, so a restructuring of the method cannot change any of them.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * The calls one request for information makes.
 *
 * @covers \OCA\Dossiq\Service\InformationRequestService
 * @uses \OCA\Dossiq\Service\TermKind
 */
class InformationRequestAskFlowTest extends TestCase {
	use BindsTermFixtures;

	/**
	 * Every call the collaborators saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Whether the letter fails.
	 *
	 * @var boolean
	 */
	private bool $sendFails = false;

	/**
	 * A sent request: the letter, then the pause, then the event, then the log.
	 *
	 * @return void
	 */
	public function testASentRequestHandsEachCollaboratorItsPart(): void {
		$result = $this->service()->ask(
			caseId: 'c1',
			items: [' Bankafschrift ', ''],
			recipient: 'burger1',
			durationDays: 0,
			rationale: 'Stukken ontbreken',
			pauseReason: 'missing-documents',
		);

		self::assertSame(['send', 'pause', 'event', 'log'], array_map(static fn (array $call): string => $call[0], $this->calls));
		self::assertSame(['send', InformationRequestService::TEMPLATE, 't1', 'burger1', ['items' => ['Bankafschrift'], 'pauseDeadline' => '2026-09-29', 'case' => 'c1']], $this->calls[0]);
		self::assertSame(['pause', 't1', 1, 'Stukken ontbreken (Awb 4:5)', 'missing-documents'], $this->calls[1]);
		self::assertSame(['event', 't1', InformationRequestService::EVENT_REQUESTED, 'Awb 4:5', 'Stukken ontbreken', 1, ['Bankafschrift']], array_slice($this->calls[2], 0, 7));
		self::assertSame(['log', 'info', 'Dossiq term: the applicant was asked and the term was suspended in one act', ['case' => 'c1', 'instance' => 't1', 'items' => 1]], $this->calls[3]);
		self::assertSame(
			['sent' => true, 'suspended' => true, 'instance' => ['id' => 't1', 'status' => 'paused'], 'record' => ['recorded' => InformationRequestService::EVENT_REQUESTED], 'error' => ''],
			$result
		);
	}//end testASentRequestHandsEachCollaboratorItsPart()

	/**
	 * A request without a rationale is suspended for the standard reason.
	 *
	 * @return void
	 */
	public function testARequestWithoutRationaleUsesTheStandardReason(): void {
		$this->service()->ask(caseId: 'c1', items: ['Bankafschrift'], recipient: 'burger1', durationDays: 21);

		self::assertSame(['pause', 't1', 21, 'Aanvulling gevraagd (Awb 4:5)', ''], $this->calls[1]);
		self::assertSame('Aanvulling gevraagd', $this->calls[2][4]);
		self::assertSame(21, $this->calls[2][5]);
	}//end testARequestWithoutRationaleUsesTheStandardReason()

	/**
	 * A failed letter records the failure, logs a warning and reports why.
	 *
	 * @return void
	 */
	public function testAFailedLetterIsRecordedLoggedAndReported(): void {
		$this->sendFails = true;

		$result = $this->service()->ask(caseId: 'c1', items: ['Bankafschrift'], recipient: 'burger1', durationDays: 14);

		self::assertSame(['send', 'event', 'log'], array_map(static fn (array $call): string => $call[0], $this->calls));
		self::assertSame(['event', 't1', InformationRequestService::EVENT_FAILED, 'Awb 4:5', 'De aanvraag om aanvulling kon niet worden verzonden, dus de termijn loopt door.', 0, ['Bankafschrift']], array_slice($this->calls[1], 0, 7));
		self::assertSame(['log', 'warning', 'Dossiq term: a request for information could not be sent, so the term was NOT suspended', ['instance' => 't1', 'error' => 'transport down']], $this->calls[2]);
		self::assertSame('send-failed', $result['reasonCode']);
		self::assertSame([], $result['notice']);
		self::assertSame('transport down', $result['error']);
		self::assertSame(['recorded' => InformationRequestService::EVENT_FAILED], $result['record']);
		self::assertSame('t1', $result['instance']['id']);
	}//end testAFailedLetterIsRecordedLoggedAndReported()

	/**
	 * The service, wired to collaborators that write down every call.
	 *
	 * @return InformationRequestService
	 */
	private function service(): InformationRequestService {
		$termService = $this->createMock(TermijnService::class);
		$termService->method('instancesForCase')->willReturn(
			[array_merge($this->instanceOf(kind: TermKind::STATUTORY, end: '2026-11-01'), ['id' => 't1'])]
		);
		$termService->method('recordEvent')->willReturnCallback(
			function (string $instanceId, string $type, string $basis, string $rationale, int $daysImpact, ?DateTimeImmutable $moment = null, string $documentLink = '', string $actor = 'system', array $items = []): array {
				$this->calls[] = ['event', $instanceId, $type, $basis, $rationale, $daysImpact, $items, $moment];
				return ['recorded' => $type];
			}
		);

		$pause = $this->createMock(DeadlinePauseService::class);
		$pause->method('registerPauze')->willReturnCallback(
			function (string $termInstanceId, int $durationDays, string $rationale, string $documentLink = '', string $pauseReason = ''): array {
				$this->calls[] = ['pause', $termInstanceId, $durationDays, $rationale, $pauseReason];
				return ['id' => 't1', 'status' => 'paused'];
			}
		);

		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->method('sendTermijnNotification')->willReturnCallback(
			function (string $template, string $instanceId, string $recipient, array $variables): array {
				$this->calls[] = ['send', $template, $instanceId, $recipient, $variables];
				if ($this->sendFails === true) {
					throw new RuntimeException('transport down');
				}

				return ['subject' => 's'];
			}
		);

		$terms = $this->createMock(CaseTermsService::class);
		$terms->method('endAfter')->willReturn('2026-09-29');

		return new InformationRequestService(
			termService: $termService,
			pause: $pause,
			notifications: $notifications,
			terms: $terms,
			logger: $this->recordingLogger(),
		);
	}//end service()

	/**
	 * A logger that writes each line into the call list.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * @param InformationRequestAskFlowTest $test The test that keeps the calls.
			 */
			public function __construct(private readonly InformationRequestAskFlowTest $test) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|Stringable    $message The line.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->record(['log', $level, (string)$message, $context]);
			}
		};
	}//end recordingLogger()

	/**
	 * Write one call down.
	 *
	 * @param array<int, mixed> $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()
}//end class
