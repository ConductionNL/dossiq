<?php

/**
 * TermEventLine unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Timeline
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

namespace OCA\Dossiq\Tests\Unit\Service\Timeline;

use OCA\Dossiq\Service\Timeline\TermEventLine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TermEventLine.
 *
 * @covers \OCA\Dossiq\Service\Timeline\TermEventLine
 */
class TermEventLineTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new TermEventLine(
			caseId: 'case-1',
			message: 'Termijn gestart',
			instance: ['kind' => 'statutory'],
			event: 'start',
			basis: 'Awb 4:13',
			occurredAt: '2026-11-14T12:00:00+00:00'
		);

		$this->assertSame(expected: 'case-1', actual: $value->caseId);
		$this->assertSame(expected: 'Termijn gestart', actual: $value->message);
		$this->assertSame(expected: ['kind' => 'statutory'], actual: $value->instance);
		$this->assertSame(expected: 'start', actual: $value->event);
		$this->assertSame(expected: 'Awb 4:13', actual: $value->basis);
		$this->assertSame(expected: '2026-11-14T12:00:00+00:00', actual: $value->occurredAt);
	}//end testPropertiesReadBack()
}//end class
