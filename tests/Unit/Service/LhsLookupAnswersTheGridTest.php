<?php

/**
 * The simple LHS lookup answers the shipped grid on every path.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md#REQ-DTO-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\LhsLookupService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryDecisionTableStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The 16 shipped grid cells are the reference (decision 213). The lookup has
 * three sources: the stored decision table, the grid cells in the register and
 * the embedded table. Each is read here from the file that ships it and asked
 * for all 16 positions, so moving the lookup onto the stored table changes no
 * recommendation.
 *
 * The files are found by what they hold, not by a fixed path: the grid ships
 * as `lib/Settings/lhs_matrix_seed.json` and moves into the `lhs-matrix`
 * package, and the stored table ships with the `lhs-intervention` package.
 *
 * @covers \OCA\Dossiq\Service\LhsLookupService
 * @uses \OCA\Dossiq\Service\Dmn\StoredDecisionTables
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class LhsLookupAnswersTheGridTest extends TestCase {

	/**
	 * The behaviour rows of the grid.
	 */
	private const ROWS = ['A', 'B', 'C', 'D'];

	/**
	 * The consequence columns of the grid.
	 */
	private const COLUMNS = ['1', '2', '3', '4'];

	/**
	 * With no stored table and no cells in the register, the embedded table
	 * answers the grid's intervention on all 16 positions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md#REQ-DTO-005
	 */
	public function testTheEmbeddedTableAnswersTheGrid(): void {
		$grid = $this->shippedGrid();
		$service = $this->lookupOver(cells: null, tables: []);

		$answers = $this->answersOf(service: $service);

		$this->assertSame([], $this->differences(grid: $grid, answers: $answers, withDescription: false));
		$this->assertSame(['fallback'], array_values(array_unique(array_column($answers, 'source'))));
	}//end testTheEmbeddedTableAnswersTheGrid()

	/**
	 * With the cells in the register and no stored table row, the lookup
	 * answers the cells: what development answers today.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md#REQ-DTO-005
	 */
	public function testWithoutATableRowTheCellsAnswer(): void {
		$grid = $this->shippedGrid();
		$service = $this->lookupOver(cells: array_values($grid), tables: []);

		$answers = $this->answersOf(service: $service);

		$this->assertSame([], $this->differences(grid: $grid, answers: $answers, withDescription: true));
		$this->assertSame(['register'], array_values(array_unique(array_column($answers, 'source'))));
	}//end testWithoutATableRowTheCellsAnswer()

	/**
	 * Every shipped row of the stored table answers the grid, intervention
	 * and explanation, on all 16 positions. The register holds no cells here,
	 * so an answer can only come from the table.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md#REQ-DTO-005
	 */
	public function testEveryShippedTableRowAnswersTheGrid(): void {
		$grid = $this->shippedGrid();
		$shipped = $this->shippedTables();

		foreach ($shipped as $file => $table) {
			$service = $this->lookupOver(cells: null, tables: [LhsLookupService::DECISION_TABLE => $table]);
			$answers = $this->answersOf(service: $service);

			$this->assertSame([], $this->differences(grid: $grid, answers: $answers, withDescription: true), $file);
			$this->assertSame(['decision-table'], array_values(array_unique(array_column($answers, 'source'))), $file);
		}

		// No package ships the row before the `lhs-intervention` package
		// lands; from then on at most one does.
		$this->assertLessThanOrEqual(1, count($shipped), implode(', ', array_keys($shipped)));
	}//end testEveryShippedTableRowAnswersTheGrid()

	/**
	 * Control: a table built from the grid answers the grid, and the same
	 * table with one cell changed is reported on that position alone. Without
	 * this the three tests above could pass on a comparison that sees nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md#REQ-DTO-005
	 */
	public function testOneChangedCellIsReported(): void {
		$grid = $this->shippedGrid();
		$table = $this->tableFrom(grid: $grid);

		$same = $this->lookupOver(cells: null, tables: [LhsLookupService::DECISION_TABLE => $table]);
		$this->assertSame([], $this->differences(grid: $grid, answers: $this->answersOf(service: $same), withDescription: true));

		foreach ($table['rules'] as $index => $rule) {
			if ($rule['id'] === 'C:2') {
				$table['rules'][$index]['outputEntries'][0] = 'Something else';
			}
		}

		$changed = $this->lookupOver(cells: null, tables: [LhsLookupService::DECISION_TABLE => $table]);
		$this->assertSame(
			['C:2'],
			$this->differences(grid: $grid, answers: $this->answersOf(service: $changed), withDescription: true)
		);

		// The same control on the cells: one changed cell in the register.
		$cells = $grid;
		$cells['B:3']['interventionStep'] = 'Something else';
		$fromCells = $this->lookupOver(cells: array_values($cells), tables: []);
		$this->assertSame(
			['B:3'],
			$this->differences(grid: $grid, answers: $this->answersOf(service: $fromCells), withDescription: true)
		);
	}//end testOneChangedCellIsReported()

	/**
	 * The positions on which the answers differ from the grid.
	 *
	 * @param array<string, array<string, mixed>> $grid The grid by position.
	 * @param array<string, array<string, mixed>> $answers The answers by position.
	 * @param bool $withDescription Whether the explanation is compared too.
	 *
	 * @return list<string> The differing positions.
	 */
	private function differences(array $grid, array $answers, bool $withDescription): array {
		$this->assertSame(array_keys($grid), array_keys($answers));

		$differing = [];
		foreach ($grid as $position => $cell) {
			$answer = $answers[$position];
			$differs = ($answer['interventionStep'] ?? null) !== $cell['interventionStep'];
			if ($withDescription === true && ($answer['description'] ?? null) !== $cell['description']) {
				$differs = true;
			}

			if ($differs === true) {
				$differing[] = $position;
			}
		}

		return $differing;
	}//end differences()

	/**
	 * The lookup's answer on all 16 positions.
	 *
	 * @param LhsLookupService $service The lookup.
	 *
	 * @return array<string, array<string, mixed>> The answers by position.
	 */
	private function answersOf(LhsLookupService $service): array {
		$answers = [];
		foreach (self::ROWS as $row) {
			foreach (self::COLUMNS as $column) {
				$answers[$row . ':' . $column] = $service->lookup(behaviour: $row, gevolg: $column);
			}
		}

		return $answers;
	}//end answersOf()

	/**
	 * A lookup over the given register cells and stored tables.
	 *
	 * @param list<array<string, mixed>>|null $cells The cells in the register, null for no register.
	 * @param array<string, array<string, mixed>> $tables The stored tables by key.
	 *
	 * @return LhsLookupService The lookup.
	 */
	private function lookupOver(?array $cells, array $tables): LhsLookupService {
		$register = null;
		if ($cells !== null) {
			$register = new class($cells) {

				/**
				 * Constructor.
				 *
				 * @param list<array<string, mixed>> $cells The stored cells.
				 */
				public function __construct(
					private readonly array $cells,
				) {
				}//end __construct()

				/**
				 * The cells matching the filters.
				 *
				 * @param string $register The register slug.
				 * @param string $schema The schema slug.
				 * @param array<string, mixed> $filters The filters.
				 *
				 * @return list<array<string, mixed>> The matching cells.
				 */
				public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
					if ($schema !== 'lhsMatrixCell') {
						return [];
					}

					return array_values(
						array_filter(
							$this->cells,
							static fn (array $cell): bool => $cell['behaviourRow'] === $filters['behaviourRow']
								&& $cell['consequenceColumn'] === $filters['consequenceColumn']
						)
					);
				}//end searchObjectsBySlug()
			};
		}//end if

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturn('dossiq');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new InMemoryDecisionTableStore($tables));

		return new LhsLookupService(
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
			tables: new StoredDecisionTables($settings, $container),
		);
	}//end lookupOver()

	/**
	 * The shipped grid: the one seed file whose rows are the 16 cells.
	 *
	 * @return array<string, array<string, mixed>> The cells by position, in grid order.
	 */
	private function shippedGrid(): array {
		$found = [];
		foreach ($this->shippedSeedFiles() as $file => $data) {
			$rows = ($data['cells'] ?? $data['rows'] ?? null);
			if (is_array($rows) === false || isset($rows[0]['behaviourRow'], $rows[0]['consequenceColumn']) === false) {
				continue;
			}

			$found[$file] = $rows;
		}

		$this->assertCount(1, $found, 'Exactly one shipped file holds the LHS grid cells: ' . implode(', ', array_keys($found)));

		$byPosition = [];
		foreach (reset($found) as $cell) {
			$byPosition[$cell['behaviourRow'] . ':' . $cell['consequenceColumn']] = $cell;
		}

		$grid = [];
		foreach (self::ROWS as $row) {
			foreach (self::COLUMNS as $column) {
				$position = $row . ':' . $column;
				$this->assertArrayHasKey($position, $byPosition);
				$this->assertNotSame('', (string)($byPosition[$position]['interventionStep'] ?? ''));
				$this->assertIsString($byPosition[$position]['description'] ?? null);
				$grid[$position] = $byPosition[$position];
			}
		}

		$this->assertCount(16, $byPosition);

		return $grid;
	}//end shippedGrid()

	/**
	 * Every shipped seed row stored under the lookup's table key.
	 *
	 * @return array<string, array<string, mixed>> The table rows by file.
	 */
	private function shippedTables(): array {
		$tables = [];
		foreach ($this->shippedSeedFiles() as $file => $data) {
			foreach (($data['rows'] ?? []) as $row) {
				if (is_array($row) === true && ($row['key'] ?? null) === LhsLookupService::DECISION_TABLE) {
					$tables[$file] = $row;
				}
			}
		}

		return $tables;
	}//end shippedTables()

	/**
	 * Every JSON file under lib/Settings that can hold LHS seed rows.
	 *
	 * @return array<string, array<string, mixed>> The decoded files by path.
	 */
	private function shippedSeedFiles(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$paths = array_merge(
			(glob($root . '/lhs*.json') ?: []),
			(glob($root . '/packages/lhs*/seed/*.json') ?: [])
		);

		$files = [];
		foreach ($paths as $path) {
			$data = json_decode((string)file_get_contents($path), true);
			$this->assertIsArray($data, $path);
			$files[substr($path, (strlen($root) + 1))] = $data;
		}

		$this->assertNotSame([], $files, 'No LHS seed file ships under lib/Settings.');

		return $files;
	}//end shippedSeedFiles()

	/**
	 * A stored table with one rule per grid cell.
	 *
	 * @param array<string, array<string, mixed>> $grid The grid by position.
	 *
	 * @return array<string, mixed> The table.
	 */
	private function tableFrom(array $grid): array {
		$rules = [];
		foreach ($grid as $position => $cell) {
			$rules[] = [
				'id' => $position,
				'inputEntries' => [$cell['behaviourRow'], $cell['consequenceColumn']],
				'outputEntries' => [$cell['interventionStep'], $cell['description']],
			];
		}

		return [
			'key' => LhsLookupService::DECISION_TABLE,
			'name' => 'LHS',
			'hitPolicy' => 'UNIQUE',
			'inputs' => [['name' => 'behaviour', 'type' => 'string'], ['name' => 'consequence', 'type' => 'string']],
			'outputs' => [['name' => 'interventionStep', 'type' => 'string'], ['name' => 'description', 'type' => 'string']],
			'rules' => $rules,
		];
	}//end tableFrom()
}//end class
