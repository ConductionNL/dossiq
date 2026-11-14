<?php

/**
 * The intake-source seed run against OpenRegister: create, refresh, refuse.
 *
 * IntakeSourceSeederTest pins the rows the seeder would write; this pins the
 * run that writes them. Each catalogue channel is looked up by slug and either
 * created or refreshed under its existing uuid, both paginated and flat
 * search answers are understood, the whole run is elevated to the system when
 * OpenRegister offers it, one refused channel does not stop the others, and a
 * missing register or service reports "not available" rather than failing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\IntakeSourceSeeder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The seed run over the intake-source catalogue.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeSourceSeeder
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */
class IntakeSourceSeederSeedTest extends TestCase {

	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	private const REGISTER_MAPPER = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * A fake ObjectService answering findAll from a callback and recording saves.
	 *
	 * @param callable $find     slug → the raw findAll answer.
	 * @param callable $onSave   Called per save; may throw to refuse.
	 * @param bool     $asSystem Whether to offer runAsSystem().
	 *
	 * @return object The fake.
	 */
	private function objectService(callable $find, ?callable $onSave = null, bool $asSystem = false): object {
		$base = new class ($find, $onSave) {
			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/** @var array<int, array<string, mixed>> */
			public array $queries = [];

			public int $systemRuns = 0;

			/**
			 * @param callable      $find   The lookup.
			 * @param callable|null $onSave The save hook.
			 */
			public function __construct(private $find, private $onSave) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $query): mixed {
				$this->queries[] = $query;
				return ($this->find)($query['filters']['slug']);
			}

			/**
			 * @param string               $register The register id.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $object   The row.
			 * @param string|null          $uuid     The uuid to update.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(string $register, string $schema, array $object, ?string $uuid = null): array {
				if ($this->onSave !== null) {
					($this->onSave)($object);
				}

				$this->saves[] = ['register' => $register, 'schema' => $schema, 'object' => $object, 'uuid' => $uuid];
				return $object;
			}
		};

		if ($asSystem === false) {
			return $base;
		}

		return new class ($base) {
			/**
			 * @param object $inner The wrapped fake.
			 */
			public function __construct(public object $inner) {
			}

			/**
			 * @param string       $name The method.
			 * @param array<mixed> $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				return $this->inner->$name(...$args);
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $query): mixed {
				return $this->inner->findAll($query);
			}

			/**
			 * @param string               $register The register id.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $object   The row.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(string $register, string $schema, array $object, ?string $uuid = null): array {
				return $this->inner->saveObject($register, $schema, $object, $uuid);
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->inner->systemRuns++;
				return $operation();
			}
		};
	}//end objectService()

	/**
	 * A register mapper whose find() answers register 7, or throws.
	 *
	 * @param bool $present Whether the register exists.
	 *
	 * @return object The fake mapper.
	 */
	private function registerMapper(bool $present = true): object {
		return new class ($present) {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param bool $present Whether the register exists.
			 */
			public function __construct(private bool $present) {
			}

			/**
			 * @param mixed ...$args The lookup arguments.
			 *
			 * @return object
			 */
			public function find(mixed ...$args): object {
				$this->calls[] = $args;
				if ($this->present === false) {
					throw new RuntimeException('no such register');
				}

				return new class {
					/**
					 * @return int
					 */
					public function getId(): int {
						return 7;
					}
				};
			}
		};
	}//end registerMapper()

	/**
	 * A seeder over a container that serves the given services.
	 *
	 * @param array<string, object> $services Service id → instance.
	 * @param LoggerInterface|null  $logger   The logger.
	 *
	 * @return IntakeSourceSeeder The seeder.
	 */
	private function seeder(array $services, ?LoggerInterface $logger = null): IntakeSourceSeeder {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new class ('absent: ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $services[$id];
			}
		);

		return new IntakeSourceSeeder(
			container: $container,
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
		);
	}//end seeder()

	/**
	 * The catalogue slugs, in order.
	 *
	 * @return array<int, string>
	 */
	private function slugs(): array {
		return array_column($this->seeder(services: [])->catalogue(), 'slug');
	}//end slugs()

	/**
	 * With nothing stored, every channel is created, switched off, in register 7.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterGetsEveryChannelCreated(): void {
		$objects = $this->objectService(find: static fn (): array => []);
		$mapper = $this->registerMapper();

		$result = $this->seeder(services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $mapper])->seed();

		$this->assertSame(
			expected: ['available' => true, 'created' => count($this->slugs()), 'updated' => 0, 'refused' => []],
			actual: $result
		);
		$this->assertSame(expected: [['intake-sources', false, false]], actual: $mapper->calls);
		$this->assertSame(expected: $this->slugs(), actual: array_column(array_column($objects->saves, 'object'), 'slug'));
		foreach ($objects->saves as $save) {
			$this->assertSame(expected: '7', actual: $save['register']);
			$this->assertSame(expected: 'intake-source', actual: $save['schema']);
			$this->assertNull(actual: $save['uuid']);
			$this->assertFalse(condition: $save['object']['enabled']);
		}

		$this->assertSame(
			expected: [
				'filters' => ['register' => '7', 'schema' => 'intake-source', 'slug' => $this->slugs()[0]],
				'limit' => 1,
			],
			actual: $objects->queries[0]
		);
	}//end testAnEmptyRegisterGetsEveryChannelCreated()

	/**
	 * A paginated hit is refreshed under its uuid, keeping the administrator's switch.
	 *
	 * @return void
	 */
	public function testAPaginatedHitIsRefreshedUnderItsUuid(): void {
		$first = $this->slugs()[0];
		$objects = $this->objectService(
			find: static fn (string $slug): array => $slug === $first
				? ['results' => [['id' => 'uuid-1', 'slug' => $first, 'enabled' => true, 'title' => 'Old']], 'total' => 1]
				: ['results' => [], 'total' => 0]
		);

		$result = $this->seeder(
			services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper()]
		)->seed();

		$this->assertSame(expected: 1, actual: $result['updated']);
		$this->assertSame(expected: count($this->slugs()) - 1, actual: $result['created']);
		$this->assertSame(expected: 'uuid-1', actual: $objects->saves[0]['uuid']);
		$this->assertTrue(condition: $objects->saves[0]['object']['enabled']);
		$this->assertNotSame(expected: 'Old', actual: $objects->saves[0]['object']['title']);
	}//end testAPaginatedHitIsRefreshedUnderItsUuid()

	/**
	 * A flat list answer holding an entity is read through getObject()/getUuid().
	 *
	 * @return void
	 */
	public function testAFlatEntityHitIsReadThroughItsAccessors(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['slug' => 'x', 'enabled' => true, 'connection' => 'mail-kcc'];
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'uuid-entity';
			}
		};
		$objects = $this->objectService(find: static fn (): array => [$entity]);

		$result = $this->seeder(
			services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper()]
		)->seed();

		$this->assertSame(expected: count($this->slugs()), actual: $result['updated']);
		$this->assertSame(expected: 'uuid-entity', actual: $objects->saves[0]['uuid']);
		$this->assertSame(expected: 'mail-kcc', actual: $objects->saves[0]['object']['connection']);
		$this->assertSame(expected: $this->slugs()[0], actual: $objects->saves[0]['object']['slug']);
	}//end testAFlatEntityHitIsReadThroughItsAccessors()

	/**
	 * A serialisable hit without getObject() is read through jsonSerialize(); one
	 * without any uuid is still refreshed, by a null uuid.
	 *
	 * @return void
	 */
	public function testASerialisableHitWithoutUuidIsReadThroughJson(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['enabled' => true, 'location' => 'INBOX'];
			}
		};
		$objects = $this->objectService(find: static fn (): array => [$entity]);

		$this->seeder(services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper()])->seed();

		$this->assertNull(actual: $objects->saves[0]['uuid']);
		$this->assertSame(expected: 'INBOX', actual: $objects->saves[0]['object']['location']);
		$this->assertTrue(condition: $objects->saves[0]['object']['enabled']);
	}//end testASerialisableHitWithoutUuidIsReadThroughJson()

	/**
	 * An array hit carrying its uuid under `uuid` rather than `id` is updated by it.
	 *
	 * @return void
	 */
	public function testAnArrayHitFallsBackToItsUuidKey(): void {
		$objects = $this->objectService(find: static fn (): array => [['uuid' => 'uuid-2', 'enabled' => false]]);

		$this->seeder(services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper()])->seed();

		$this->assertSame(expected: 'uuid-2', actual: $objects->saves[0]['uuid']);
	}//end testAnArrayHitFallsBackToItsUuidKey()

	/**
	 * A non-array search answer is "not found", so the channel is created.
	 *
	 * @return void
	 */
	public function testANonArraySearchAnswerCreatesTheChannel(): void {
		$objects = $this->objectService(find: static fn (): mixed => null);

		$result = $this->seeder(
			services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper()]
		)->seed();

		$this->assertSame(expected: count($this->slugs()), actual: $result['created']);
	}//end testANonArraySearchAnswerCreatesTheChannel()

	/**
	 * One refused channel is reported and logged; the rest are still written.
	 *
	 * @return void
	 */
	public function testOneRefusedChannelDoesNotStopTheOthers(): void {
		$refusedSlug = $this->slugs()[1];
		$objects = $this->objectService(
			find: static fn (): array => [],
			onSave: static function (array $row) use ($refusedSlug): void {
				if ($row['slug'] === $refusedSlug) {
					throw new \Error('Anonymous may not create');
				}
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: failed to seed an intake source',
			['slug' => $refusedSlug, 'exception' => 'Anonymous may not create']
		);

		$result = $this->seeder(
			services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper()],
			logger: $logger
		)->seed();

		$this->assertSame(expected: [$refusedSlug], actual: $result['refused']);
		$this->assertSame(expected: count($this->slugs()) - 1, actual: $result['created']);
	}//end testOneRefusedChannelDoesNotStopTheOthers()

	/**
	 * The writes run as the system when OpenRegister can elevate.
	 *
	 * @return void
	 */
	public function testTheRunIsElevatedWhenOpenRegisterOffersIt(): void {
		$objects = $this->objectService(find: static fn (): array => [], asSystem: true);

		$result = $this->seeder(
			services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper()]
		)->seed();

		$this->assertSame(expected: 1, actual: $objects->inner->systemRuns);
		$this->assertSame(expected: count($this->slugs()), actual: $result['created']);
	}//end testTheRunIsElevatedWhenOpenRegisterOffersIt()

	/**
	 * A missing register is logged at info and reported as not available.
	 *
	 * @return void
	 */
	public function testAMissingRegisterReportsNotAvailable(): void {
		$objects = $this->objectService(find: static fn (): array => []);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Dossiq: the OpenRegister intake-sources register is not present, no channel seeded',
			['exception' => 'no such register']
		);

		$result = $this->seeder(
			services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper(present: false)],
			logger: $logger
		)->seed();

		$this->assertSame(expected: ['available' => false, 'created' => 0, 'updated' => 0, 'refused' => []], actual: $result);
		$this->assertSame(expected: [], actual: $objects->saves);
	}//end testAMissingRegisterReportsNotAvailable()

	/**
	 * Without OpenRegister's services the seed is a quiet no-op.
	 *
	 * @return void
	 */
	public function testAnAbsentServiceReportsNotAvailable(): void {
		$mapper = $this->registerMapper();

		$result = $this->seeder(services: [self::REGISTER_MAPPER => $mapper])->seed();

		$this->assertFalse(condition: $result['available']);
		$this->assertSame(expected: [], actual: $mapper->calls);
	}//end testAnAbsentServiceReportsNotAvailable()

	/**
	 * A hit whose data or uuid cannot be read is refreshed from the catalogue alone, by a null uuid.
	 *
	 * Each channel gets a hit of a different unreadable shape: an entity whose
	 * getObject() and getUuid() answer the wrong types, a serialisable whose
	 * JSON is no array, an object with no accessor at all and an array whose
	 * id is no string. None of them may lend the row stray data or a uuid.
	 *
	 * @return void
	 */
	public function testAnUnreadableHitIsRefreshedFromTheCatalogueAloneByANullUuid(): void {
		$wrongTypes = new class {
			/**
			 * @return mixed
			 */
			public function getObject(): mixed {
				return 'not a row';
			}

			/**
			 * @return mixed
			 */
			public function getUuid(): mixed {
				return 42;
			}
		};
		$scalarJson = new class implements \JsonSerializable {
			/**
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return 'not a row';
			}
		};
		$hits = [
			'dossiq-mail' => [$wrongTypes],
			'dossiq-portal' => [$scalarJson],
			'dossiq-api' => [new \stdClass()],
			'dossiq-kcc' => [['id' => 5, 'enabled' => true]],
		];
		$objects = $this->objectService(find: static fn (string $slug): array => ($hits[$slug] ?? []));
		$seeder = $this->seeder(services: [self::OBJECT_SERVICE => $objects, self::REGISTER_MAPPER => $this->registerMapper()]);
		$catalogue = $seeder->catalogue();

		$result = $seeder->seed();

		$this->assertSame(['available' => true, 'created' => 1, 'updated' => 4, 'refused' => []], $result);
		foreach ([0, 1, 2] as $index) {
			$this->assertNull($objects->saves[$index]['uuid'], $catalogue[$index]['slug']);
			$this->assertSame(
				$seeder->refreshedRow(existing: [], source: $catalogue[$index]),
				$objects->saves[$index]['object'],
				$catalogue[$index]['slug']
			);
			$this->assertArrayNotHasKey('enabled', $objects->saves[$index]['object']);
		}

		// The array hit keeps its own data but lends no uuid that is not a string.
		$this->assertNull($objects->saves[3]['uuid']);
		$this->assertTrue($objects->saves[3]['object']['enabled']);
		$this->assertSame('dossiq-kcc', $objects->saves[3]['object']['slug']);
	}//end testAnUnreadableHitIsRefreshedFromTheCatalogueAloneByANullUuid()
}//end class
