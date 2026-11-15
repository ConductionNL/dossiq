<?php

/**
 * OrdersRuleBlocks — a rule block in OpenRegister's kind order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\OrdersRuleBlocks;
use PHPUnit\Framework\TestCase;

/**
 * The trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Service\Support\OrdersRuleBlocks
 */
final class OrdersRuleBlocksTest extends TestCase {

	/**
	 * Kinds come out in the declared order, empty kinds are dropped.
	 *
	 * @return void
	 */
	public function testOrder(): void {
		$host = new class {
			use OrdersRuleBlocks;

			public const RULES = ['required', 'readOnly', 'hidden'];

			/**
			 * Expose the ordering.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $block The block.
			 *
			 * @return array<string, array<int, array<string, mixed>>>
			 */
			public function order(array $block): array {
				return $this->inPublishedOrder(block: $block);
			}//end order()
		};

		$ordered = $host->order(block: ['hidden' => [['field' => 'b']], 'readOnly' => [], 'required' => [['field' => 'a']]]);

		self::assertSame(['required', 'hidden'], array_keys($ordered));
	}//end testOrder()
}//end class
