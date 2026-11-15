<?php

/**
 * TransitionDestination unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\TransitionDestination;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TransitionDestination.
 *
 * @covers \OCA\Dossiq\Service\Transitions\TransitionDestination
 */
class TransitionDestinationTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new TransitionDestination(
			case: ['id' => 'case-1'],
			caseId: 'case-1',
			toStatus: 'status-2',
			toIsClosing: true
		);

		$this->assertSame(expected: ['id' => 'case-1'], actual: $value->case);
		$this->assertSame(expected: 'case-1', actual: $value->caseId);
		$this->assertSame(expected: 'status-2', actual: $value->toStatus);
		$this->assertSame(expected: true, actual: $value->toIsClosing);
	}//end testPropertiesReadBack()
}//end class
