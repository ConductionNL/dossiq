<?php

/**
 * The moves a term instance's deadline has made, read from the engine history.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use JsonSerializable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermMoveHistory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermMoveHistory
 */
class TermMoveHistoryTest extends TestCase {

	/**
	 * A history over an engine object, resolved by the settings bridge.
	 *
	 * @param object|null          $engine The engine timer service, or null when absent.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return TermMoveHistory
	 */
	private function history(?object $engine, ?LoggerInterface $logger = null): TermMoveHistory {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')
			->with(TermMoveHistory::ENGINE_TIMERS)
			->willReturn($engine);

		return new TermMoveHistory(settings: $settings, logger: $logger);
	}//end history()

	/**
	 * An engine whose history answers the given events and records the timer asked for.
	 *
	 * @param iterable<mixed>|mixed $events The events.
	 *
	 * @return object
	 */
	private function engine(mixed $events): object {
		return new class($events) {
			/**
			 * Timer ids asked for.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $events The events.
			 */
			public function __construct(private readonly mixed $events) {
			}

			/**
			 * The history of one timer.
			 *
			 * @param string $timerId The timer.
			 *
			 * @return mixed
			 */
			public function history(string $timerId): mixed {
				$this->asked[] = $timerId;
				return $this->events;
			}
		};
	}//end engine()

	public function testAnInstanceWithoutATimerHasNoMovesAndNeverAsksTheEngine(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getOpenRegisterClass');

		$history = new TermMoveHistory(settings: $settings);

		self::assertSame([], $history->movesFor(['engineTimerId' => '   ']));
		self::assertSame([], $history->movesFor([]));
	}//end testAnInstanceWithoutATimerHasNoMovesAndNeverAsksTheEngine()

	public function testAnAbsentEngineOrOneWithoutHistoryAnswersEmpty(): void {
		self::assertSame([], $this->history(engine: null)->movesFor(['engineTimerId' => 't-1']));
		self::assertSame([], $this->history(engine: new \stdClass())->movesFor(['engineTimerId' => 't-1']));
	}//end testAnAbsentEngineOrOneWithoutHistoryAnswersEmpty()

	public function testOnlySupersededEventsBecomeMovesInEngineOrder(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialised event.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return [
					'type' => ' superseded ',
					'created' => '2026-03-02T10:00:00Z',
					'previousFireAt' => '2026-03-10',
					'fireAt' => '2026-03-17',
				];
			}
		};

		$engine = $this->engine(events: [
			['type' => 'superseded', 'occurredAt' => '2026-02-01T08:00:00Z', 'created' => 'ignored', 'reason' => 'extension', 'previousFireAt' => '2026-02-10', 'fireAt' => '2026-03-10'],
			['type' => 'fired', 'occurredAt' => '2026-02-02T08:00:00Z'],
			'not an event',
			$entity,
		]);

		$moves = $this->history(engine: $engine)->movesFor(['engineTimerId' => ' t-42 ']);

		self::assertSame(['t-42'], $engine->asked);
		self::assertSame(
			[
				['at' => '2026-02-01T08:00:00Z', 'reason' => 'extension', 'from' => '2026-02-10', 'to' => '2026-03-10'],
				// A blank reason stays blank: the gap in the record is not papered over.
				['at' => '2026-03-02T10:00:00Z', 'reason' => '', 'from' => '2026-03-10', 'to' => '2026-03-17'],
			],
			$moves
		);
	}//end testOnlySupersededEventsBecomeMovesInEngineOrder()

	public function testANonIterableHistoryIsReadAsNoMoves(): void {
		self::assertSame([], $this->history(engine: $this->engine(events: 'garbage'))->movesFor(['engineTimerId' => 't-1']));
	}//end testANonIterableHistoryIsReadAsNoMoves()

	public function testAnUnreadableHistoryIsLoggedAndAnswersEmpty(): void {
		$engine = new class {
			/**
			 * Always fails.
			 *
			 * @param string $timerId The timer.
			 *
			 * @return array<int, mixed>
			 */
			public function history(string $timerId): array {
				throw new RuntimeException('engine down');
			}
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('timer history could not be read'),
				['timer' => 't-9', 'error' => 'engine down']
			);

		self::assertSame([], $this->history(engine: $engine, logger: $logger)->movesFor(['engineTimerId' => 't-9']));
	}//end testAnUnreadableHistoryIsLoggedAndAnswersEmpty()
}//end class
