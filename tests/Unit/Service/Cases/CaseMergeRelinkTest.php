<?php

/**
 * Moving the rows of a merged case, and moving them back, past bad input.
 *
 * Only a row that actually moved may be recorded as a move, or a reversal
 * would "move back" a row that never left. And a reversal must touch only
 * the rows it recorded, under the field the rule declares.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseMergeRelink;
use OCA\Dossiq\Service\Cases\CaseMergeRule;
use OCA\Dossiq\Service\Cases\CaseMergeStore;
use PHPUnit\Framework\TestCase;

/**
 * Skipping undeclared, unidentified and unwritten rows.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseMergeRelink
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseMergeRelinkTest extends TestCase {

	/**
	 * Only rows that were written are recorded as moves.
	 *
	 * @return void
	 */
	public function testOnlyRowsThatWereWrittenAreRecordedAsMoves(): void {
		$rule = $this->createMock(originalClassName: CaseMergeRule::class);
		$rule->method('relinkDeclarations')->willReturn(
			[
				['schema' => '', 'field' => 'case'],
				['schema' => 'role', 'field' => ''],
				['schema' => 'unconfigured', 'field' => 'case'],
				['schema' => 'role', 'field' => 'case'],
			]
		);

		$store = $this->createMock(originalClassName: CaseMergeStore::class);
		$store->method('schemaId')->willReturnCallback(
			static fn (string $slug): string => ['role' => 'role-id'][$slug] ?? ''
		);
		$store->expects($this->once())
			->method('rowsFor')
			->with('role-id', ['case' => 'from-case'])
			->willReturn([['id' => ''], ['title' => 'no id'], ['id' => 'r-1'], ['id' => 'r-2']]);
		$store->expects($this->exactly(2))
			->method('patchRow')
			->willReturnCallback(
				static function (string $schemaId, string $id, array $changes): bool {
					self::assertSame('role-id', $schemaId);
					self::assertSame(['case' => 'to-case'], $changes);

					return $id === 'r-1';
				}
			);

		$moves = (new CaseMergeRelink(rule: $rule, store: $store))->move(fromId: 'from-case', toId: 'to-case');

		self::assertSame([['schema' => 'role', 'id' => 'r-1']], $moves);
	}//end testOnlyRowsThatWereWrittenAreRecordedAsMoves()

	/**
	 * A reversal writes back only well-formed moves of a declared, configured kind.
	 *
	 * @return void
	 */
	public function testAReversalWritesBackOnlyWellFormedMoves(): void {
		$rule = $this->createMock(originalClassName: CaseMergeRule::class);
		$rule->method('relinkDeclarations')->willReturn(
			[
				['schema' => 'role', 'field' => 'case'],
				['schema' => 'unconfigured', 'field' => 'case'],
			]
		);

		$store = $this->createMock(originalClassName: CaseMergeStore::class);
		$store->method('schemaId')->willReturnCallback(
			static fn (string $slug): string => ['role' => 'role-id'][$slug] ?? ''
		);
		$store->expects($this->once())
			->method('patchRow')
			->with('role-id', 'r-1', ['case' => 'old-case'])
			->willReturn(true);

		(new CaseMergeRelink(rule: $rule, store: $store))->moveBack(
			moves: [
				'not a move',
				['schema' => 'role', 'id' => ''],
				['schema' => 'undeclared', 'id' => 'x-1'],
				['schema' => 'unconfigured', 'id' => 'u-1'],
				['schema' => 'role', 'id' => 'r-1'],
			],
			toId: 'old-case',
		);
	}//end testAReversalWritesBackOnlyWellFormedMoves()
}//end class
