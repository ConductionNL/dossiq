<?php

/**
 * Repair-step tests for the rewrite of renamed ids in stored layouts.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RewriteRenamedLayoutIds;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\RewriteRenamedLayoutIds
 * @uses   \OCA\Dossiq\Service\Dashboard\RenamedLayoutIds
 */
final class RewriteRenamedLayoutIdsTest extends TestCase {

	/**
	 * Dossiq's arrangements are rewritten once; another app's is left; a second run writes nothing.
	 *
	 * @return void
	 */
	public function testDossiqArrangementsAreRewrittenOnce(): void {
		$store = self::store(
			[
				['@self' => ['id' => 'v-1'], 'register' => 'openbuild-dossiq', 'manifestDelta' => ['pages' => [['id' => 'Doorlooptijd', 'config' => ['layout' => [['widgetId' => 'dt-woo']]]]]]],
				['@self' => ['id' => 'v-2'], 'register' => 'openbuild-procest', 'manifestDelta' => ['menu' => [['id' => 'WooReportsMenu']]]],
				['@self' => ['id' => 'v-3'], 'register' => 'openbuild-pipelinq', 'manifestDelta' => ['pages' => [['id' => 'x', 'config' => ['layout' => [['widgetId' => 'dt-woo']]]]]]],
				['@self' => ['id' => 'v-4'], 'register' => 'openbuild-dossiq', 'manifestDelta' => ['pages' => [['id' => 'Cases']]]],
			]
		);
		$step = new RewriteRenamedLayoutIds(settingsService: $this->settings(store: $store), logger: $this->createMock(LoggerInterface::class));

		$first = $this->createMock(IOutput::class);
		$first->expects($this->once())->method('info')->with($this->stringContains('2 stored layout'));
		$step->run($first);

		self::assertSame(['v-1', 'v-2'], array_keys($store->patched));
		self::assertSame('processing-time-case-type-deadlines', $store->rows[0]['manifestDelta']['pages'][0]['config']['layout'][0]['widgetId']);
		self::assertSame('DocumentReviewReportsMenu', $store->rows[1]['manifestDelta']['menu'][0]['id']);
		self::assertSame('dt-woo', $store->rows[2]['manifestDelta']['pages'][0]['config']['layout'][0]['widgetId'], 'Another app is not touched.');

		$store->patched = [];
		$second = $this->createMock(IOutput::class);
		$second->expects($this->never())->method('info');
		$step->run($second);
		self::assertSame([], $store->patched);
	}//end testDossiqArrangementsAreRewrittenOnce()

	/**
	 * Without buildiq, or without OpenRegister, there is nothing to do and nothing throws.
	 *
	 * @return void
	 */
	public function testNothingToDoWithoutBuildiqOrOpenRegister(): void {
		$store = self::store(rows: [], missing: true);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		(new RewriteRenamedLayoutIds(settingsService: $this->settings(store: $store), logger: $this->createMock(LoggerInterface::class)))->run($output);
		self::assertSame([], $store->patched);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		(new RewriteRenamedLayoutIds(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)))->run($output);
	}//end testNothingToDoWithoutBuildiqOrOpenRegister()

	/**
	 * Settings over the store.
	 *
	 * @param object $store The store.
	 *
	 * @return SettingsService The stub.
	 */
	private function settings(object $store): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);

		return $settings;
	}//end settings()

	/**
	 * An in-memory buildiq register of arrangements.
	 *
	 * @param array<int, array<string, mixed>> $rows    The arrangements.
	 * @param bool                             $missing Whether the register is absent.
	 *
	 * @return object The store.
	 */
	private static function store(array $rows, bool $missing = false): object {
		return new class($rows, $missing) {
			/**
			 * What was patched, by uuid.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $patched = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $rows    The arrangements.
			 * @param bool                             $missing Whether the register is absent.
			 */
			public function __construct(public array $rows, private bool $missing) {
			}

			/**
			 * Run the work.
			 *
			 * @param callable $operation The work.
			 *
			 * @return mixed Its result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * Every arrangement.
			 *
			 * @param string               $register      The register.
			 * @param string               $schema        The schema slug.
			 * @param array<string, mixed> $filters       Ignored.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>> The arrangements.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) OpenRegister's signature.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->missing === true || $register !== 'buildiq' || $schema !== 'applicationVersion') {
					throw new RuntimeException('Register not found: ' . $register);
				}

				return $this->rows;
			}

			/**
			 * Write fields onto one arrangement.
			 *
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The fields.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 *
			 * @return array<string, mixed> The stored arrangement.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) OpenRegister's signature.
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				foreach ($this->rows as $index => $row) {
					if ($row['@self']['id'] === $objectId) {
						$this->rows[$index] = array_merge($row, $data);
						$this->patched[$objectId] = $data;
						return $this->rows[$index];
					}
				}

				throw new RuntimeException('not found');
			}
		};
	}//end store()
}//end class
