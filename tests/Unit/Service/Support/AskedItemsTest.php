<?php

/**
 * Unit tests for the asked items' rows and summary.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\AskedItems;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\AskedItems
 */
class AskedItemsTest extends TestCase {

	/**
	 * Blank lines are dropped and each item is a row not yet received.
	 *
	 * @return void
	 */
	public function testRowsDropBlankLines(): void {
		self::assertSame(
			expected: [['item' => 'Bouwtekening', 'received' => false], ['item' => 'Foto', 'received' => false]],
			actual: (new AskedItems())->rows(items: [' Bouwtekening ', '', '  ', 'Foto'])
		);
	}//end testRowsDropBlankLines()

	/**
	 * One item names the request, more add a count, none falls back to the default title.
	 *
	 * @return void
	 */
	public function testTheSummaryNamesTheFirstItemAndCountsTheRest(): void {
		$items = new AskedItems();

		self::assertSame(expected: 'Foto', actual: $items->summary(items: ['', 'Foto']));
		self::assertSame(expected: 'Foto and 2 more', actual: $items->summary(items: ['Foto', 'Tekening', 'Brief']));
		self::assertSame(expected: 'Aanvullingsverzoek', actual: $items->summary(items: [' ']));
	}//end testTheSummaryNamesTheFirstItemAndCountsTheRest()
}//end class
