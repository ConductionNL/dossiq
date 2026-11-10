<?php

/**
 * What registering one pause writes, in which order.
 *
 * Pins the refusals, the patch on the instance, the engine calls and the
 * recorded event of DeadlinePauseService::registerPauze, so a restructuring
 * of the method cannot change any of them.
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
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The writes of one registered pause.
 *
 * @covers \OCA\Dossiq\Service\DeadlinePauseService
 */
class DeadlinePauseRegistrationFlowTest extends TestCase {

	/**
	 * Every call the collaborators saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The instance the store holds, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $instance = ['id' => 't1', 'case' => 'c1', 'endDateCurrent' => '2026-11-01', 'status' => 'lopend'];

	/**
	 * Without an engine, the patch and the event carry the whole pause.
	 *
	 * @return void
	 */
	public function testAPauseWithoutTheEngineWritesThePatchAndTheEvent(): void {
		$today = new DateTimeImmutable();
		$result = $this->service(timers: null)->registerPauze(
			termInstanceId: 't1',
			durationDays: 10,
			rationale: 'Stukken ontbreken',
			documentLink: 'doc://brief',
		);

		self::assertSame(['update', 'event'], array_map(static fn (array $call): string => $call[0], $this->calls));
		self::assertSame(
			[
				'update',
				't1',
				[
					'endDateCurrent' => '2026-11-11',
					'status' => 'paused',
					'pauseDeadline' => $today->modify('+10 days')->format('Y-m-d'),
					'pauzeStartDatum' => $today->format('Y-m-d'),
					'pauzeDuurDagen' => 10,
					'pauseReason' => '',
					'pauseWaitingOn' => '',
					'chasesSent' => 0,
					'lastChasedAt' => null,
					'chaseEscalatedAt' => null,
				],
			],
			$this->calls[0]
		);
		self::assertSame(['event', 't1', 'pause', 'AWB 4:5', 'Stukken ontbreken', 10, 'doc://brief'], array_slice($this->calls[1], 0, 7));
		self::assertSame($today->format('Y-m-d'), $this->calls[1][7]->format('Y-m-d'));
		self::assertSame(['id' => 't1', 'updated' => true], $result);
	}//end testAPauseWithoutTheEngineWritesThePatchAndTheEvent()

	/**
	 * With the engine, the beslistermijn is suspended and the helper armed
	 * before the patch, and the helper's id lands on the patch.
	 *
	 * @return void
	 */
	public function testAPauseWithTheEngineSuspendsAndArmsFirst(): void {
		$today = new DateTimeImmutable();
		$this->service(timers: $this->timers(timerId: 'timer-9'))->registerPauze(termInstanceId: 't1', durationDays: 3, rationale: 'r');

		self::assertSame(['roll', 'suspend', 'arm', 'update', 'event'], array_map(static fn (array $call): string => $call[0], $this->calls));
		self::assertSame(['suspend', 't1', 'r', $today->modify('+3 days')->format('Y-m-d')], $this->calls[1]);
		self::assertSame(['arm', 't1', 3, []], $this->calls[2]);
		self::assertSame('timer-9', $this->calls[3][2]['pauseTimerId']);
		self::assertSame('2026-11-04', $this->calls[3][2]['endDateCurrent']);
	}//end testAPauseWithTheEngineSuspendsAndArmsFirst()

	/**
	 * A helper the engine did not arm leaves no timer id on the patch.
	 *
	 * @return void
	 */
	public function testAnUnarmedHelperLeavesNoTimerId(): void {
		$this->service(timers: $this->timers(timerId: null))->registerPauze(termInstanceId: 't1', durationDays: 3, rationale: 'r');

		self::assertArrayNotHasKey('pauseTimerId', $this->calls[3][2]);
	}//end testAnUnarmedHelperLeavesNoTimerId()

	/**
	 * A missing instance and a paused one are refused with their messages,
	 * and nothing is written.
	 *
	 * @return void
	 */
	public function testAMissingOrPausedInstanceIsRefused(): void {
		$messages = [];
		foreach ([null, ['id' => 't1', 'status' => 'paused']] as $instance) {
			$this->instance = $instance;
			try {
				$this->service(timers: null)->registerPauze(termInstanceId: 't1', durationDays: 3, rationale: 'r');
			} catch (RuntimeException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(['TermijnInstance not found: t1', 'TermijnInstance already paused: t1'], $messages);
		self::assertSame([], $this->calls);
	}//end testAMissingOrPausedInstanceIsRefused()

	/**
	 * The service over a store that writes down every call.
	 *
	 * @param TermijnTimerService|null $timers The engine bridge, or none.
	 *
	 * @return DeadlinePauseService
	 */
	private function service(?TermijnTimerService $timers): DeadlinePauseService {
		$store = $this->createMock(TermijnService::class);
		$store->method('getTermijnInstance')->willReturnCallback(fn (string $id): ?array => $this->instance);
		$store->method('updateTermijnInstance')->willReturnCallback(
			function (string $id, array $patch): array {
				$this->calls[] = ['update', $id, $patch];
				return ['id' => $id, 'updated' => true];
			}
		);
		$store->method('recordEvent')->willReturnCallback(
			function (string $instanceId, string $type, string $basis, string $rationale, int $daysImpact, ?DateTimeImmutable $moment = null, string $documentLink = ''): ?array {
				$this->calls[] = ['event', $instanceId, $type, $basis, $rationale, $daysImpact, $documentLink, $moment];
				return null;
			}
		);

		return new DeadlinePauseService(termService: $store, timerService: $timers);
	}//end service()

	/**
	 * An engine bridge that writes down every call.
	 *
	 * @param string|null $timerId The id the helper is armed under, or null.
	 *
	 * @return TermijnTimerService
	 */
	private function timers(?string $timerId): TermijnTimerService {
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnCallback(
			function (DateTimeImmutable $date): DateTimeImmutable {
				$this->calls[] = ['roll'];
				return $date;
			}
		);
		$timers->method('suspendBeslistermijn')->willReturnCallback(
			function (array $instance, string $reason, ?DateTimeImmutable $until): bool {
				$this->calls[] = ['suspend', $instance['id'], $reason, $until?->format('Y-m-d')];
				return true;
			}
		);
		$timers->method('armHersteltermijn')->willReturnCallback(
			function (array $instance, int $durationDays, array $chaseOffsets = []) use ($timerId): ?string {
				$this->calls[] = ['arm', $instance['id'], $durationDays, $chaseOffsets];
				return $timerId;
			}
		);

		return $timers;
	}//end timers()
}//end class
