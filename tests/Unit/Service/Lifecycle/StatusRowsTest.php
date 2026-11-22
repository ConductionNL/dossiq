<?php

/**
 * StatusRows unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Service\Lifecycle\StatusRows;
use OCA\Dossiq\Service\Support\LanguageMapText;
use PHPUnit\Framework\TestCase;

/**
 * A case type's status rows, read for an ending act.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\StatusRows
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class StatusRowsTest extends TestCase {

	/**
	 * The rows under test.
	 *
	 * @return StatusRows
	 */
	private function rows(): StatusRows {
		return new StatusRows(text: new LanguageMapText());
	}//end rows()

	/**
	 * A named terminal status wins; otherwise the first in process order, an unordered one last.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheNamedTerminalStatusWinsElseTheFirstInOrder(): void {
		$terminal = [
			['id' => 'unordered', 'isFinal' => true],
			['uuid' => 'late', 'order' => 9, 'isFinal' => '1'],
			['@self' => ['id' => 'early'], 'order' => '2', 'isFinal' => 'true'],
		];

		$this->assertSame(expected: 'late', actual: $this->rows()->namedTerminal(terminal: $terminal, named: 'late'));
		$this->assertSame(expected: 'early', actual: $this->rows()->namedTerminal(terminal: $terminal, named: 'other'));
		$this->assertSame(expected: 'early', actual: $this->rows()->namedTerminal(terminal: $terminal, named: ''));
	}//end testTheNamedTerminalStatusWinsElseTheFirstInOrder()

	/**
	 * The phases after the one left and before the one landed on are named; final ones are not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheSkippedPhasesAreNamedInProcessOrder(): void {
		$rows = [
			['id' => 'end', 'name' => 'Afgehandeld', 'order' => 5, 'isFinal' => true],
			['id' => 'check', 'name' => 'Toets', 'order' => 3],
			['id' => 'intake', 'name' => 'Intake', 'order' => 1],
			['id' => 'decide', 'order' => 4],
			['id' => 'stopped', 'name' => 'Ingetrokken', 'order' => 4, 'isFinal' => 1],
		];

		$this->assertSame(
			expected: ['Toets', 'decide'],
			actual: $this->rows()->skippedNames(rows: $rows, fromStatus: 'intake', toStatus: 'end')
		);
		$this->assertSame(expected: [], actual: $this->rows()->skippedNames(rows: $rows, fromStatus: 'decide', toStatus: 'end'));
		$this->assertTrue(condition: $this->rows()->isFinal(row: ['isFinal' => 'true']));
		$this->assertFalse(condition: $this->rows()->isFinal(row: ['isFinal' => 'yes']));
	}//end testTheSkippedPhasesAreNamedInProcessOrder()
}//end class
