<?php

/**
 * PortalCaseScope unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
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

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalCaseScope;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for PortalCaseScope.
 *
 * @covers \OCA\Dossiq\Portal\PortalCaseScope
 */
class PortalCaseScopeTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$value = new PortalCaseScope(
			objectService: $objectService,
			register: '5',
			caseId: 'case-1'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: '5', actual: $value->register);
		$this->assertSame(expected: 'case-1', actual: $value->caseId);
	}//end testPropertiesReadBack()
}//end class
