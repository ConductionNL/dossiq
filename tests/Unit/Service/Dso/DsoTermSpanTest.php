<?php

/**
 * DsoTermSpan unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Dso
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

namespace OCA\Dossiq\Tests\Unit\Service\Dso;

use DateTimeImmutable;
use OCA\Dossiq\Service\Dso\DsoTermSpan;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DsoTermSpan.
 *
 * @covers \OCA\Dossiq\Service\Dso\DsoTermSpan
 */
class DsoTermSpanTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$anchor = new DateTimeImmutable('2026-11-14 00:00:00');
		$value = new DsoTermSpan(
			case: ['identifier' => 'Z-1'],
			caseId: 'case-1',
			anchor: $anchor,
			slaDays: 21
		);

		$this->assertSame(expected: ['identifier' => 'Z-1'], actual: $value->case);
		$this->assertSame(expected: 'case-1', actual: $value->caseId);
		$this->assertSame(expected: $anchor, actual: $value->anchor);
		$this->assertSame(expected: 21, actual: $value->slaDays);
	}//end testPropertiesReadBack()
}//end class
