<?php

/**
 * Example data integrity: every date the Zuiddrecht fragment writes is
 * relative to the install day, so a deadline or contract end never passes
 * while the set stays installed for a demo.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * No fixed calendar dates in the fragment.
 */
class ZuiddrechtDatesAreRelativeTest extends TestCase {

	/**
	 * No value anywhere in the fragment is a fixed date; dates are {{date:n}} or {{datetime:n hh:mm}}.
	 *
	 * @return void
	 */
	public function testNoValueIsAFixedDate(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$fixed = [];
		array_walk_recursive(
			$set,
			static function (mixed $value, int|string $key) use (&$fixed): void {
				if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
					$fixed[] = $key.' = '.$value;
				}
			}
		);

		$this->assertSame(expected: [], actual: $fixed);
	}//end testNoValueIsAFixedDate()
}//end class
