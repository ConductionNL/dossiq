<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Recycle;

use DateTimeImmutable;
use OCA\Dossiq\Service\Recycle\DeletionWindowReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The whole window `fromMarker` reads off a deletion marker, and when it reads none.
 */
class DeletionWindowFromMarkerTest extends TestCase {

	/**
	 * The warnings logged, as message and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The window of an entity carrying `$marker`.
	 *
	 * @param mixed $marker What getDeleted answers.
	 *
	 * @return array<string, mixed>|null The window.
	 */
	private function windowOf(mixed $marker): ?array {
		$entity = new class ($marker) {
			/**
			 * @param mixed $marker The marker.
			 */
			public function __construct(private mixed $marker) {
			}

			/**
			 * @return mixed The marker.
			 */
			public function getDeleted(): mixed {
				return $this->marker;
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return (new DeletionWindowReader(settingsService: $this->createMock(SettingsService::class), logger: $logger))->fromMarker(entity: $entity);
	}//end windowOf()

	/**
	 * Midnight of a day in this server's zone, as the window writes it.
	 *
	 * @param string $day The day, `Y-m-d`.
	 *
	 * @return string The moment.
	 */
	private static function midnight(string $day): string {
		return (new DateTimeImmutable($day . ' 00:00:00'))->format(DATE_ATOM);
	}//end midnight()

	/**
	 * A lapsed window, read from the purge date, with its retention and deletion moment.
	 *
	 * @return void
	 */
	public function testAWindowFromThePurgeDate(): void {
		self::assertSame(
			[
				'deletedAt' => '2020-01-01T10:00:00+00:00',
				'destroyableFrom' => self::midnight(day: '2020-02-01'),
				'daysRemaining' => 0,
				'retentionDays' => 45,
				'retentionSource' => 'default',
				'lapsed' => true,
			],
			$this->windowOf(['deleted' => ' 2020-01-01T10:00:00+00:00 ', 'purgeDate' => ' 2020-02-01T12:00:00+00:00', 'retentionPeriod' => '45'])
		);
		self::assertSame([], $this->warnings);
	}//end testAWindowFromThePurgeDate()

	/**
	 * destroyableFrom wins over purgeDate; a retention that is no positive number takes the default.
	 *
	 * @return void
	 */
	public function testTheDefaultsAndTheOrder(): void {
		foreach ([null, 'x', 0, '0', -3] as $retention) {
			$window = $this->windowOf(['deletedAt' => 'a', 'destroyableFrom' => '2020-03-01', 'purgeDate' => '2020-02-01', 'retentionPeriod' => $retention]);
			self::assertSame(DeletionWindowReader::DEFAULT_RETENTION_DAYS, $window['retentionDays']);
			self::assertSame(self::midnight(day: '2020-03-01'), $window['destroyableFrom']);
			self::assertSame('a', $window['deletedAt']);
		}

		$future = $this->windowOf(['destroyableFrom' => '2999-01-01']);
		self::assertGreaterThan(0, $future['daysRemaining']);
		self::assertFalse($future['lapsed']);
		self::assertSame('', $future['deletedAt']);
	}//end testTheDefaultsAndTheOrder()

	/**
	 * No date, or no marker, reads no window and says nothing; an unreadable date is said out loud.
	 *
	 * @return void
	 */
	public function testNoWindow(): void {
		self::assertNull($this->windowOf(['destroyableFrom' => ' ']));
		self::assertNull($this->windowOf('not a marker'));
		self::assertSame([], $this->warnings);

		self::assertNull($this->windowOf(['destroyableFrom' => 'soon']));
		self::assertSame([['Dossiq: a deletion marker carries no readable destroyable-from date', ['value' => 'soon']]], $this->warnings);
	}//end testNoWindow()
}//end class
