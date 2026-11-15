<?php

/**
 * TermijnRungFire unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TermijnRungFire;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TermijnRungFire.
 *
 * @covers \OCA\Dossiq\Listener\TermijnRungFire
 */
class TermijnRungFireTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new TermijnRungFire(
			rungKey: 'preBreach:5:businessDays',
			instance: ['status' => 'running'],
			instanceId: 'instance-1'
		);

		$this->assertSame(expected: 'preBreach:5:businessDays', actual: $value->rungKey);
		$this->assertSame(expected: ['status' => 'running'], actual: $value->instance);
		$this->assertSame(expected: 'instance-1', actual: $value->instanceId);
	}//end testPropertiesReadBack()
}//end class
