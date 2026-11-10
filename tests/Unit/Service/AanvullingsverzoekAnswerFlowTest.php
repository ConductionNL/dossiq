<?php

/**
 * What recording an answer to an aanvullingsverzoek writes, in which order.
 *
 * Pins the refusals, the writes, the resume of the clock, the case's waiting
 * flag and the log lines of AanvullingsverzoekResolutionService::recordAnswer,
 * on the partial and the full answer, so a restructuring of the method cannot
 * change any of them.
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
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AanvullingsverzoekResolutionService;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\InformationRequestService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The calls one recorded answer makes.
 *
 * @covers \OCA\Dossiq\Service\AanvullingsverzoekResolutionService
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class AanvullingsverzoekAnswerFlowTest extends TestCase {

	/**
	 * Every call the collaborators saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The open request, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $open = [
		'id' => 'avr-1',
		'state' => 'open',
		'missingItems' => [
			['item' => 'Bankafschrift', 'received' => false],
			['item' => 'Huurcontract', 'received' => false],
		],
	];

	/**
	 * A partial answer writes the items, logs the count and resumes nothing.
	 *
	 * @return void
	 */
	public function testAPartialAnswerWritesTheItemsAndLogsWhatIsOutstanding(): void {
		$result = $this->service()->recordAnswer(caseId: 'c1', received: ['Bankafschrift'], complete: false, userId: 'u1', when: new DateTimeImmutable('2026-09-10T08:00:00+00:00'));

		self::assertSame(['write', 'log'], array_map(static fn (array $call): string => $call[0], $this->calls));
		self::assertSame(['missingItems'], array_keys($this->calls[0][1]));
		self::assertSame('avr-1', $this->calls[0][2]);
		self::assertSame(['log', 'info', 'Dossiq: part of an aanvullingsverzoek arrived; the term stays suspended', ['app' => 'dossiq', 'case' => 'c1', 'outstanding' => 1]], $this->calls[1]);
		self::assertSame(array_merge($this->open, $this->calls[0][1]), $result);
	}//end testAPartialAnswerWritesTheItemsAndLogsWhatIsOutstanding()

	/**
	 * A full answer resumes the clock first, then writes, clears the waiting
	 * flag and logs.
	 *
	 * @return void
	 */
	public function testAFullAnswerResumesFirstThenWritesAndClears(): void {
		$when = new DateTimeImmutable('2026-09-10T08:00:00+00:00');
		$result = $this->service()->recordAnswer(caseId: 'c1', received: ['Bankafschrift', 'Huurcontract'], complete: true, userId: 'u1', when: $when);

		self::assertSame(['receive', 'write', 'waiting', 'log'], array_map(static fn (array $call): string => $call[0], $this->calls));
		self::assertSame(['receive', 'c1', ['Bankafschrift', 'Huurcontract'], $when], $this->calls[0]);
		self::assertSame(['missingItems', 'state', 'answeredAt', 'answeredBy'], array_keys($this->calls[1][1]));
		self::assertSame(['answered', '2026-09-10T08:00:00+00:00', 'u1'], array_slice(array_values($this->calls[1][1]), 1));
		self::assertSame('avr-1', $this->calls[1][2]);
		self::assertSame(['waiting', 'c1', null], $this->calls[2]);
		self::assertSame(['log', 'info', 'Dossiq: an aanvullingsverzoek was answered in full and the term resumed', ['app' => 'dossiq', 'case' => 'c1']], $this->calls[3]);
		self::assertSame(array_merge($this->open, $this->calls[1][1]), $result);
	}//end testAFullAnswerResumesFirstThenWritesAndClears()

	/**
	 * The two refusals carry their rule, sentence and status, and touch nothing.
	 *
	 * @return void
	 */
	public function testTheRefusalsCarryTheirSentences(): void {
		$refusals = [];
		try {
			$this->service()->recordAnswer(caseId: 'c1', received: ['Bankafschrift'], complete: true, userId: 'u1');
		} catch (RefusedException $e) {
			$refusals[] = [$e->getRule(), $e->getSentence()];
		}

		$this->open = null;
		try {
			$this->service()->recordAnswer(caseId: 'c1', received: [], complete: false, userId: 'u1');
		} catch (RefusedException $e) {
			$refusals[] = [$e->getRule(), $e->getSentence()];
		}

		self::assertSame(
			[
				['aanvullingsverzoek-still-outstanding', 'This cannot be closed yet. Still missing: Huurcontract.'],
				['aanvullingsverzoek-none-open', 'This case is not waiting on the applicant, so there is no request to answer.'],
			],
			$refusals
		);
		self::assertSame([], $this->calls);
	}//end testTheRefusalsCarryTheirSentences()

	/**
	 * The service over doubles that write down every call.
	 *
	 * @return AanvullingsverzoekResolutionService
	 */
	private function service(): AanvullingsverzoekResolutionService {
		$requests = $this->createMock(AanvullingsverzoekService::class);
		$requests->method('openFor')->willReturnCallback(fn (string $caseId): ?array => $this->open);
		$requests->method('write')->willReturnCallback(
			function (array $request, string $id = ''): array {
				$this->calls[] = ['write', $request, $id];
				return $request;
			}
		);
		$requests->method('markCaseWaiting')->willReturnCallback(
			function (string $caseId, ?string $since): void {
				$this->calls[] = ['waiting', $caseId, $since];
			}
		);

		$act = $this->createMock(InformationRequestService::class);
		$act->method('receive')->willReturnCallback(
			function (string $caseId, array $items, ?DateTimeImmutable $when = null): array {
				$this->calls[] = ['receive', $caseId, $items, $when];
				return [];
			}
		);

		return new AanvullingsverzoekResolutionService(requests: $requests, act: $act, logger: $this->recordingLogger());
	}//end service()

	/**
	 * A logger that writes each line into the call list.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class($this) extends AbstractLogger {
			/**
			 * @param AanvullingsverzoekAnswerFlowTest $test The test that keeps the calls.
			 */
			public function __construct(private readonly AanvullingsverzoekAnswerFlowTest $test) {
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
