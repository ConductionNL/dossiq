<?php

/**
 * MoveDecisionTablesToOpenRegister tests.
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
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MoveDecisionTablesToOpenRegister;
use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\Dmn\StoredDecisionTablesTest;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\MoveDecisionTablesToOpenRegister
 * @uses \OCA\Dossiq\Service\Dmn\StoredDecisionTables
 */
class MoveDecisionTablesToOpenRegisterTest extends TestCase {

	/**
	 * A dossiq table as the old schema stored it.
	 *
	 * @param string $key The key.
	 * @param bool $enabled Whether it was switched on.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function old(string $key, bool $enabled = true): array {
		return [
			'@register' => 'dossiq/decisionTable',
			'id' => 'old-' . $key,
			'@self' => ['id' => 'old-' . $key, 'register' => '12'],
			'key' => $key,
			'name' => 'Tabel ' . $key,
			'description' => '',
			'hitPolicy' => 'UNIQUE',
			'inputs' => [['name' => 'bedrag', 'label' => 'Bedrag', 'type' => 'number']],
			'outputs' => [['name' => 'uitkomst', 'label' => 'Uitkomst', 'type' => 'string']],
			'rules' => [['id' => 'r1', 'annotation' => '', 'inputEntries' => ['>100'], 'outputEntries' => ['hoog']]],
			'enabled' => $enabled,
		];
	}//end old()

	/**
	 * The step over a fake object service holding both registers.
	 *
	 * @param object $objects The object service.
	 *
	 * @return MoveDecisionTablesToOpenRegister The step.
	 */
	private function step(object $objects): MoveDecisionTablesToOpenRegister {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => 'dossiq', 'decision_table_schema' => 'decisionTable'][$key] ?? '')
		);
		$tables = new StoredDecisionTables($settings, $this->createMock(ContainerInterface::class));

		return new MoveDecisionTablesToOpenRegister($settings, $tables, $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * Enabled tables move under their stored key and fit OpenRegister's schema;
	 * a disabled table and one already under its key stay; the old rows are not touched.
	 *
	 * @return void
	 */
	public function testTablesMoveOnceAndDisabledOnesStay(): void {
		$objects = StoredDecisionTablesTest::objectService(
			[
				self::old(key: 'Subsidie Hoogte'),
				self::old(key: 'uit', enabled: false),
				self::old(key: 'al-verhuisd'),
				['@register' => 'decision-tables/decision-table', 'id' => 'or-1', 'key' => 'dossiq.al-verhuisd'],
			]
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('1 moved, 1 already in OpenRegister, 1 disabled'));

		$this->step($objects)->run($output);

		self::assertCount(1, $objects->saved);
		$saved = $objects->saved[0];
		self::assertSame(['decision-tables', 'decision-table'], [$saved['register'], $saved['schema']]);
		self::assertSame('dossiq.subsidie-hoogte', $saved['object']['key']);
		self::assertTrue(StoredDecisionTablesTest::validAgainstOpenRegister($saved['object']), 'the moved row must fit OpenRegister\'s schema');
	}//end testTablesMoveOnceAndDisabledOnesStay()

	/**
	 * A second run moves nothing new.
	 *
	 * @return void
	 */
	public function testASecondRunMovesNothing(): void {
		$objects = StoredDecisionTablesTest::objectService([self::old(key: 'toets')]);
		$this->step($objects)->run($this->createMock(IOutput::class));
		$objects->rows[] = ['@register' => 'decision-tables/decision-table', 'id' => 'or-1'] + $objects->saved[0]['object'];

		$this->step($objects)->run($this->createMock(IOutput::class));

		self::assertCount(1, $objects->saved);
	}//end testASecondRunMovesNothing()

	/**
	 * An OpenRegister whose register cannot be read moves nothing and warns.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterMovesNothing(): void {
		$objects = new class() {
			/** @var list<mixed> */
			public array $saved = [];

			/**
			 * @param string $register Register.
			 * @param string $schema Schema.
			 * @param array<string, mixed> $filters Filters.
			 * @param bool $_rbac RBAC.
			 * @param bool $_multitenancy Multitenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($register === 'decision-tables') {
					throw new RuntimeException('register decision-tables not found');
				}

				return [['key' => 'toets', 'name' => 'Toets']];
			}

			/**
			 * @return array<string, mixed>
			 */
			public function saveObject(): array {
				$this->saved[] = func_get_args();
				return [];
			}
		};
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step($objects)->run($output);

		self::assertSame([], $objects->saved);
	}//end testAnUnreadableRegisterMovesNothing()
}//end class
