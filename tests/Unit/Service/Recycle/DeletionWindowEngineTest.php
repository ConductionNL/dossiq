<?php

/**
 * Asking OpenRegister for a deletion window first, and reading a deleted entity's marker and payload.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Recycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Recycle;

use OCA\Dossiq\Service\Recycle\DeletionWindowReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Recycle\DeletionWindowReader
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 */
class DeletionWindowEngineTest extends TestCase {

	/**
	 * A reader whose bridge resolves the window service to the given object.
	 *
	 * @param object|null          $service The OpenRegister window service.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return DeletionWindowReader
	 */
	private function reader(?object $service, ?LoggerInterface $logger = null): DeletionWindowReader {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(DeletionWindowReader::WINDOW_SERVICE)->willReturn($service);

		return new DeletionWindowReader(settingsService: $settings, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end reader()

	/**
	 * A soft-deleted entity carrying a marker and a payload.
	 *
	 * @param mixed $marker  The deleted marker.
	 * @param mixed $payload The object payload.
	 *
	 * @return object
	 */
	private function entity(mixed $marker, mixed $payload = []): object {
		return new class ($marker, $payload) {
			/**
			 * Constructor.
			 *
			 * @param mixed $marker  Marker.
			 * @param mixed $payload Payload.
			 */
			public function __construct(private mixed $marker, private mixed $payload) {
			}

			/**
			 * The marker.
			 *
			 * @return mixed
			 */
			public function getDeleted(): mixed {
				return $this->marker;
			}

			/**
			 * The payload.
			 *
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->payload;
			}
		};
	}//end entity()

	/**
	 * A window service stating the given window, recording the entity it was asked about.
	 *
	 * @param array<string, mixed>|null $window The stated window, or null.
	 *
	 * @return object
	 */
	private function service(?array $window): object {
		return new class ($window) {
			/**
			 * Arguments received.
			 *
			 * @var array<int, array{0: object, 1: mixed}>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null $window Window.
			 */
			public function __construct(private ?array $window) {
			}

			/**
			 * The window for an object.
			 *
			 * @param object $object The entity.
			 * @param mixed  $schema The schema.
			 *
			 * @return object|null
			 */
			public function windowFor(object $object, mixed $schema): ?object {
				$this->asked[] = [$object, $schema];
				if ($this->window === null) {
					return null;
				}

				return new class ($this->window) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $window Window.
					 */
					public function __construct(private array $window) {
					}

					/**
					 * As an array.
					 *
					 * @return array<string, mixed>
					 */
					public function toArray(): array {
						return $this->window;
					}
				};
			}
		};
	}//end service()

	public function testTheEnginesOwnWindowIsAnsweredAsItStatesIt(): void {
		$stated = ['destroyableFrom' => '2026-12-01', 'retentionSource' => 'schema', 'daysRemaining' => 19];
		$service = $this->service(window: $stated);
		$entity = $this->entity(marker: ['destroyableFrom' => '2000-01-01']);

		self::assertSame($stated, $this->reader(service: $service)->windowFor(entity: $entity));
		self::assertSame([[$entity, null]], $service->asked);
	}//end testTheEnginesOwnWindowIsAnsweredAsItStatesIt()

	public function testWhenTheEngineStatesNothingTheMarkerIsRead(): void {
		$window = $this->reader(service: $this->service(window: null))->windowFor(
			entity: $this->entity(marker: ['purgeDate' => '2000-01-31T00:00:00Z', 'deleted' => ' 2000-01-01T10:00:00Z ', 'retentionPeriod' => '0'])
		);

		self::assertSame('2000-01-01T10:00:00Z', $window['deletedAt']);
		self::assertSame('2000-01-31T00:00:00', substr($window['destroyableFrom'], 0, 19));
		self::assertSame(0, $window['daysRemaining']);
		self::assertTrue($window['lapsed']);
		self::assertSame(DeletionWindowReader::DEFAULT_RETENTION_DAYS, $window['retentionDays']);
		self::assertSame('default', $window['retentionSource']);
	}//end testWhenTheEngineStatesNothingTheMarkerIsRead()

	public function testAFailingEngineIsNotedAndTheMarkerIsRead(): void {
		$service = new class {
			/**
			 * Always fails.
			 *
			 * @param object $object The entity.
			 * @param mixed  $schema The schema.
			 *
			 * @return object
			 */
			public function windowFor(object $object, mixed $schema): object {
				throw new RuntimeException('no schema');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringContains('could not state the window'), ['error' => 'no schema']);

		$window = $this->reader(service: $service, logger: $logger)->windowFor(
			entity: $this->entity(marker: ['destroyableFrom' => '2000-02-01', 'retentionPeriod' => 90])
		);

		self::assertSame(90, $window['retentionDays']);
	}//end testAFailingEngineIsNotedAndTheMarkerIsRead()

	public function testWithoutAnEngineOrAMarkerThereIsNoWindow(): void {
		self::assertNull($this->reader(service: null)->windowFor(entity: $this->entity(marker: null)));
		self::assertNull($this->reader(service: new \stdClass())->windowFor(entity: $this->entity(marker: ['deletedAt' => 'x'])));
	}//end testWithoutAnEngineOrAMarkerThereIsNoWindow()

	public function testMarkerAndPayloadReadOnlyArrays(): void {
		$reader = $this->reader(service: null);

		self::assertSame(['deletedAt' => 'x'], $reader->marker(entity: $this->entity(marker: ['deletedAt' => 'x'])));
		self::assertSame([], $reader->marker(entity: $this->entity(marker: 'yes')));
		self::assertSame([], $reader->marker(entity: new \stdClass()));
		self::assertSame(['title' => 'Zaak'], $reader->payload(entity: $this->entity(marker: [], payload: ['title' => 'Zaak'])));
		self::assertSame([], $reader->payload(entity: $this->entity(marker: [], payload: 'raw')));
		self::assertSame([], $reader->payload(entity: new \stdClass()));
	}//end testMarkerAndPayloadReadOnlyArrays()

	public function testSoftDeletionIsTheEntitysOwnAnswerElseAMarker(): void {
		$reader = $this->reader(service: null);
		$own = new class {
			/**
			 * Not deleted, whatever the marker says.
			 *
			 * @return boolean
			 */
			public function isSoftDeleted(): bool {
				return false;
			}

			/**
			 * A marker.
			 *
			 * @return array<string, string>
			 */
			public function getDeleted(): array {
				return ['deletedAt' => 'x'];
			}
		};

		self::assertFalse($reader->isSoftDeleted(entity: $own));
		self::assertTrue($reader->isSoftDeleted(entity: $this->entity(marker: ['deletedAt' => 'x'])));
		self::assertFalse($reader->isSoftDeleted(entity: $this->entity(marker: [])));
	}//end testSoftDeletionIsTheEntitysOwnAnswerElseAMarker()
}//end class
