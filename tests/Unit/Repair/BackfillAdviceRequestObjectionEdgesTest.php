<?php

/**
 * BackfillAdviceRequestObjectionEdgesTest.
 *
 * The edges of the advice-request backfill: an unconfigured schema, a read
 * that returns no list, rows that arrive as entities, and a patch that fails
 * for one row while the others are still repaired.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillAdviceRequestObjection;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;

/**
 * Covers the backfill's refusals, its tally and its per-row failure.
 *
 * @covers \OCA\Dossiq\Repair\BackfillAdviceRequestObjection
 * @uses   \OCA\Dossiq\Repair\AdviceRequestStore
 */
class BackfillAdviceRequestObjectionEdgesTest extends TestCase {

	/**
	 * The name `occ maintenance:repair` shows.
	 *
	 * @return void
	 */
	public function testTheNameSaysWhatIsBackfilled(): void {
		self::assertSame(
			'Backfill bacAdviceRequest.bezwaar from the legacy objectionProceeding key',
			$this->step(objectService: $this->objectService(rows: []))->getName()
		);
	}//end testTheNameSaysWhatIsBackfilled()

	/**
	 * Without a configured register or schema nothing is read.
	 *
	 * @dataProvider unconfigured
	 *
	 * @param string $register The configured register.
	 * @param string $schema   The configured advice-request schema.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaSkipsTheBackfill(string $register, string $schema): void {
		$objectService = $this->objectService(rows: []);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Advice-request schema not configured — skipping backfill.');

		$this->step(objectService: $objectService, register: $register, schema: $schema)->run($output);

		self::assertSame(0, $objectService->reads);
	}//end testAnUnconfiguredSchemaSkipsTheBackfill()

	/**
	 * Register and schema settings that leave the backfill unconfigured.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unconfigured(): array {
		return [
			'no register' => ['', '143'],
			'no schema' => ['17', ''],
		];
	}//end unconfigured()

	/**
	 * A read that returns no list says there is nothing to read and writes nothing.
	 *
	 * @return void
	 */
	public function testAReadWithoutAListHasNothingToRead(): void {
		$objectService = $this->objectService(rows: null);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Advice-request backfill: nothing to read.');

		$this->step(objectService: $objectService)->run($output);

		self::assertSame(1, $objectService->reads);
		self::assertSame([], $objectService->patches);
	}//end testAReadWithoutAListHasNothingToRead()

	/**
	 * Entity rows are read through jsonSerialize(); a failed patch is logged
	 * and counted, and the rows after it are still repaired.
	 *
	 * @return void
	 */
	public function testAFailedRowIsLoggedAndTheRestAreRepaired(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed> The row.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'a-1', 'objectionProceeding' => 'bz-1'];
			}
		};
		$objectService = $this->objectService(
			rows: [
				$entity,
				['id' => 'a-2', 'objectionProceeding' => 'bz-2'],
				['uuid' => 'a-3', 'objectionProceeding' => 'bz-3'],
				['id' => 'a-4', 'bezwaar' => 'bz-4'],
			],
			failFor: 'a-2'
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'Dossiq: advice-request backfill failed for one row',
			['app' => 'dossiq', 'uuid' => 'a-2', 'exception' => 'locked']
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Advice-request backfill: 2 repaired, 1 already correct, 1 failed.');

		$this->step(objectService: $objectService, logger: $logger)->run($output);

		self::assertSame(
			[
				['a-1', ['bezwaar' => 'bz-1'], '17', '143'],
				['a-2', ['bezwaar' => 'bz-2'], '17', '143'],
				['a-3', ['bezwaar' => 'bz-3'], '17', '143'],
			],
			$objectService->patches
		);
		self::assertSame([['filters' => ['register' => '17', 'schema' => '143']]], $objectService->queries);
	}//end testAFailedRowIsLoggedAndTheRestAreRepaired()

	/**
	 * An ObjectService stand-in that records reads and patches.
	 *
	 * @param array<int, mixed>|null $rows    The rows findAll() returns; null returns no list.
	 * @param string                 $failFor A row id whose patch fails.
	 *
	 * @return object The fake.
	 */
	private function objectService(?array $rows, string $failFor = ''): object {
		return new class($rows, $failFor) {
			public int $reads = 0;

			/** @var array<int, array<string, mixed>> */
			public array $queries = [];

			/** @var array<int, array{0: string, 1: array<string, mixed>, 2: string, 3: string}> */
			public array $patches = [];

			/**
			 * @param array<int, mixed>|null $rows    The rows.
			 * @param string                 $failFor A row id whose patch fails.
			 */
			public function __construct(private readonly ?array $rows, private readonly string $failFor) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed What it returns.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>|null The rows.
			 */
			public function findAll(array $config): ?array {
				$this->reads++;
				$this->queries[] = $config;
				return $this->rows;
			}

			/**
			 * @param string               $objectId The row id.
			 * @param array<string, mixed> $data     The patch.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The patched row.
			 */
			public function patchObject(ObjectPatch $patch): array {
				$objectId = $patch->objectId;
				$data = $patch->data;
				$register = $patch->target->register;
				$schema = $patch->target->schema;
				$this->patches[] = [$objectId, $data, $register, $schema];
				if ($objectId === $this->failFor) {
					throw new RuntimeException('locked');
				}

				return $data;
			}
		};
	}//end objectService()

	/**
	 * The step under test.
	 *
	 * @param object               $objectService The ObjectService fake.
	 * @param string               $register      The configured register.
	 * @param string               $schema        The configured schema.
	 * @param LoggerInterface|null $logger        The logger, or a mock.
	 *
	 * @return BackfillAdviceRequestObjection The step.
	 */
	private function step(object $objectService, string $register = '17', string $schema = '143', ?LoggerInterface $logger = null): BackfillAdviceRequestObjection {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register') ? $register : $schema
		);

		return new BackfillAdviceRequestObjection($settings, $appConfig, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end step()
}//end class
