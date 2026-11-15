<?php

/**
 * CollectsUniqueNames — trimmed, non-empty, unique names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\CollectsUniqueNames;
use PHPUnit\Framework\TestCase;

/**
 * Trimming, empties, repeats and order.
 *
 * @covers \OCA\Dossiq\Service\Support\CollectsUniqueNames
 */
final class CollectsUniqueNamesTest extends TestCase {

	/**
	 * Names are trimmed, empties dropped, repeats kept once, order kept.
	 *
	 * @return void
	 */
	public function testCollects(): void {
		$subject = new class() {
			use CollectsUniqueNames {
				uniqueNames as public;
			}
		};

		self::assertSame(['b', 'a', '3'], $subject->uniqueNames([' b ', '', 'a', 'b', '  ', 3, 'a']));
		self::assertSame([], $subject->uniqueNames([]));
	}//end testCollects()
}//end class
