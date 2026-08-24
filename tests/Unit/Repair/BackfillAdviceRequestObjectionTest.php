<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillAdviceRequestObjection;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the backfill of `bacAdviceRequest.bezwaar`.
 *
 * The defect it repairs was invisible: advice requests were written with the
 * objection id under the name of the SCHEMA it references
 * (`objectionProceeding`) rather than the property the schema declares
 * (`bezwaar`), so BezwaarDetail's advice-request widgets — which filter on
 * `bezwaar` — rendered an empty list. An empty list is also what a bezwaar with
 * no advice requests looks like, so nothing ever looked wrong.
 */
class BackfillAdviceRequestObjectionTest extends TestCase {
	/**
	 * An ObjectService stand-in that records saves.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 *
	 * @return object The fake.
	 */
	private function objectServiceFake(array $rows): object {
		return new class($rows) {
			/**
			 * @var array<int, array{uuid: string, object: array<string, mixed>}>
			 */
			public array $saves = [];

			/**
			 * @param array<int, array<string, mixed>> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * Run the callable straight through.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAsSystem(callable $operation) {
				return $operation();
			}

			/**
			 * Return the configured rows.
			 *
			 * @param array<string, mixed> $config The query config.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config): array {
				return $this->rows;
			}

			/**
			 * Record the patch.
			 *
			 * @param array<string, mixed> $object The patch.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string $uuid The row uuid.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema, string $uuid): array {
				$this->saves[] = ['uuid' => $uuid, 'object' => $object];

				return $object;
			}
		};
	}

	/**
	 * Build the repair step around the given rows.
	 *
	 * @param object $objectService The ObjectService fake.
	 *
	 * @return BackfillAdviceRequestObjection The repair step.
	 */
	private function step(object $objectService): BackfillAdviceRequestObjection {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register') ? '17' : '143'
		);

		return new BackfillAdviceRequestObjection(
			$settings,
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A legacy row gets `bezwaar` written from `objectionProceeding`.
	 *
	 * @return void
	 */
	public function testItCopiesTheLegacyKeyOntoTheDeclaredOne(): void {
		$objectService = $this->objectServiceFake([
			['id' => 'req-1', 'objectionProceeding' => 'bezwaar-42', 'status' => 'assigned'],
		]);

		$this->step($objectService)->run($this->createMock(IOutput::class));

		$this->assertSame(
			[['uuid' => 'req-1', 'object' => ['bezwaar' => 'bezwaar-42']]],
			$objectService->saves
		);
	}

	/**
	 * A row that already has `bezwaar` is left alone.
	 *
	 * @return void
	 */
	public function testItSkipsARowThatIsAlreadyCorrect(): void {
		$objectService = $this->objectServiceFake([
			['id' => 'req-1', 'bezwaar' => 'bezwaar-42', 'objectionProceeding' => 'bezwaar-42'],
		]);

		$this->step($objectService)->run($this->createMock(IOutput::class));

		$this->assertSame([], $objectService->saves, 'A correct row must not be rewritten.');
	}

	/**
	 * A row with NEITHER key is skipped, never written with an empty value.
	 *
	 * Writing `bezwaar => ''` would satisfy the required property while pointing
	 * at nothing — a row that looks repaired and still cannot be found by the
	 * filter that needed it.
	 *
	 * @return void
	 */
	public function testItSkipsARowItCannotRepair(): void {
		$objectService = $this->objectServiceFake([['id' => 'req-1', 'status' => 'assigned']]);

		$this->step($objectService)->run($this->createMock(IOutput::class));

		$this->assertSame([], $objectService->saves);
	}

	/**
	 * Running twice writes once — the second pass sees the repaired key.
	 *
	 * @return void
	 */
	public function testItIsIdempotent(): void {
		$objectService = $this->objectServiceFake([
			['id' => 'req-1', 'objectionProceeding' => 'bezwaar-42'],
		]);
		$step = $this->step($objectService);

		$step->run($this->createMock(IOutput::class));
		$first = count($objectService->saves);

		// Second pass over the REPAIRED shape, which is what a re-run reads.
		$repaired = $this->objectServiceFake([
			['id' => 'req-1', 'bezwaar' => 'bezwaar-42', 'objectionProceeding' => 'bezwaar-42'],
		]);
		$this->step($repaired)->run($this->createMock(IOutput::class));

		$this->assertSame(1, $first);
		$this->assertSame([], $repaired->saves);
	}

	/**
	 * With OpenRegister absent the step is a no-op rather than a fatal.
	 *
	 * An upgrade must not fail because a projection could not complete.
	 *
	 * @return void
	 */
	public function testItIsANoOpWithoutOpenRegister(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$step = new BackfillAdviceRequestObjection(
			$settings,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$step->run($output);
	}
}
