<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shipped intervention table answers what the shipped grid cells say.
 *
 * The enforcement lookup reads the 16 `lhsMatrixCell` rows of the lhs-matrix
 * package today. A later change makes it read the decision table of the
 * lhs-intervention package first. The first table that shipped was built from
 * a code fallback and differed from the grid in all 16 positions, so that
 * change would have replaced every recommendation without anyone deciding it.
 * The grid is right (decision 213): the table is generated from it, and this
 * test holds the two files together.
 *
 * The table is answered by OpenRegister's decision table evaluator, the class
 * that answers it on a running instance (tests/bootstrap.php loads a verbatim
 * copy when OpenRegister is not beside this app), not by reading the rules as
 * data here.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The lhs-intervention table against the lhs-matrix grid, position by position.
 *
 * @coversNothing
 */
class LhsInterventionTableMatchesGridTest extends TestCase {

	private const PACKAGES = (__DIR__ . '/../../../lib/Settings/packages');

	private const ROWS = ['A', 'B', 'C', 'D'];

	private const COLUMNS = ['1', '2', '3', '4'];

	/**
	 * The first row of a shipped seed file.
	 *
	 * @param string $file Path below the packages directory.
	 *
	 * @return array<int, array<string, mixed>> The seed rows.
	 */
	private function rows(string $file): array {
		$path = (self::PACKAGES . '/' . $file);
		$this->assertFileExists($path);
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded);
		$this->assertIsArray($decoded['rows'] ?? null, $file . ' holds no rows');

		return $decoded['rows'];

	}//end rows()

	/**
	 * The 16 shipped grid cells.
	 *
	 * @return array<int, array<string, mixed>> The cells.
	 */
	private function cells(): array {
		return $this->rows('lhs-matrix/seed/cells.json');

	}//end cells()

	/**
	 * The shipped intervention table.
	 *
	 * @return array<string, mixed> The decision table.
	 */
	private function table(): array {
		$rows = $this->rows('lhs-intervention/seed/table.json');
		$this->assertCount(1, $rows, 'the package ships one table');

		return $rows[0];

	}//end table()

	/**
	 * The positions where the table's answer is not what the grid cell says.
	 *
	 * @param array<string, mixed> $table The decision table.
	 * @param array<int, array<string, mixed>> $cells The grid cells.
	 *
	 * @return array<int, string> One line per differing position, empty when they agree.
	 */
	private function differences(array $table, array $cells): array {
		$evaluator = new DecisionTableEvaluator();
		$different = [];

		foreach (self::ROWS as $row) {
			foreach (self::COLUMNS as $column) {
				$position = ($row . ':' . $column);
				$here = array_values(array_filter(
					$cells,
					static fn (array $cell): bool => ($cell['behaviourRow'] ?? null) === $row && ($cell['consequenceColumn'] ?? null) === $column
				));
				if (count($here) !== 1) {
					$different[] = ($position . ': the grid holds ' . count($here) . ' cells');
					continue;
				}

				try {
					$answer = $evaluator->evaluate($table, ['behaviour' => $row, 'consequence' => $column]);
				} catch (Throwable $e) {
					$different[] = ($position . ': the table does not answer (' . $e->getMessage() . ')');
					continue;
				}

				foreach (['interventionStep', 'description'] as $output) {
					$said = ($answer['outputs'][$output] ?? null);
					$cell = ($here[0][$output] ?? null);
					if ($said !== $cell) {
						$different[] = ($position . ' ' . $output . ': table ' . var_export($said, true) . ', grid ' . var_export($cell, true));
					}
				}
			}
		}

		return $different;

	}//end differences()

	/**
	 * For all 16 positions the table answers the intervention and the explanation of the grid cell.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function testTheTableAnswersWhatEachGridCellSays(): void {
		$cells = $this->cells();
		$this->assertCount(16, $cells);

		$this->assertSame([], $this->differences($this->table(), $cells));

	}//end testTheTableAnswersWhatEachGridCellSays()

	/**
	 * Control: one changed grid cell is found, and named.
	 *
	 * Without this the comparison above could pass by comparing nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function testOneChangedGridCellIsNamed(): void {
		$cells = $this->cells();
		foreach ($cells as $index => $cell) {
			if ($cell['behaviourRow'] === 'B' && $cell['consequenceColumn'] === '3') {
				$cells[$index]['interventionStep'] = 'Something else';
			}
		}

		$different = $this->differences($this->table(), $cells);

		$this->assertCount(1, $different);
		$this->assertStringStartsWith('B:3 interventionStep:', $different[0]);

	}//end testOneChangedGridCellIsNamed()

	/**
	 * Control: one changed rule of the table is found, and named.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function testOneChangedRuleIsNamed(): void {
		$table = $this->table();
		foreach ($table['rules'] as $index => $rule) {
			if ($rule['inputEntries'] === ['D', '1']) {
				$table['rules'][$index]['outputEntries'][1] = 'Another explanation';
			}
		}

		$different = $this->differences($table, $this->cells());

		$this->assertCount(1, $different);
		$this->assertStringStartsWith('D:1 description:', $different[0]);

	}//end testOneChangedRuleIsNamed()

	/**
	 * Control: a position the table has no rule for is found, and named.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function testAMissingRuleIsNamed(): void {
		$table = $this->table();
		$table['rules'] = array_values(array_filter(
			$table['rules'],
			static fn (array $rule): bool => $rule['inputEntries'] !== ['C', '4']
		));

		$different = $this->differences($table, $this->cells());

		$this->assertCount(1, $different);
		$this->assertStringStartsWith('C:4: the table does not answer', $different[0]);

	}//end testAMissingRuleIsNamed()

}//end class
