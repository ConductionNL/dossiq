<?php

/**
 * The holding a custody move opens, and reading stored instants.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Service\Custody\CustodyMove;
use OCA\Dossiq\Service\Custody\StoredInstant;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Custody\CustodyMove
 * @covers \OCA\Dossiq\Service\Custody\StoredInstant
 */
class CustodyMoveTest extends TestCase {

	public function testOpenHoldingTrimsTheMoveAndOpensFromTheGivenMoment(): void {
		$move = new CustodyMove();
		$move->caseId = ' case-1 ';
		$move->organisationUnit = "ou-vergunningen\n";
		$move->handler = ' jan ';
		$move->reason = '  herverdeling  ';
		$move->movedBy = 'admin ';
		$move->at = 'ignored here';

		self::assertSame(
			[
				'caseId' => 'case-1',
				'organisationUnit' => 'ou-vergunningen',
				'handler' => 'jan',
				'from' => '2026-05-01T09:00:00+00:00',
				'until' => '',
				'open' => true,
				'reason' => 'herverdeling',
				'movedBy' => 'admin',
				'sequence' => 3,
			],
			$move->openHolding(from: '2026-05-01T09:00:00+00:00', sequence: 3)
		);
	}//end testOpenHoldingTrimsTheMoveAndOpensFromTheGivenMoment()

	public function testAnEmptyMoveOpensAnEmptyHolding(): void {
		$holding = (new CustodyMove())->openHolding(from: '', sequence: 0);

		self::assertSame('', $holding['handler']);
		self::assertTrue($holding['open']);
		self::assertSame(0, $holding['sequence']);
	}//end testAnEmptyMoveOpensAnEmptyHolding()

	public function testAStoredInstantParsesOrAnswersNull(): void {
		$instant = new StoredInstant();

		self::assertSame('2026-05-01 09:00', $instant->parse(value: ' 2026-05-01T09:00:00Z ')->format('Y-m-d H:i'));
		self::assertNull($instant->parse(value: '  '));
		self::assertNull($instant->parse(value: 'garbage'));
	}//end testAStoredInstantParsesOrAnswersNull()
}//end class
