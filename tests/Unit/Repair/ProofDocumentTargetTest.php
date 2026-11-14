<?php

/**
 * ProofDocumentTarget unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ProofDocumentTarget;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for ProofDocumentTarget.
 *
 * @covers \OCA\Dossiq\Repair\ProofDocumentTarget
 */
class ProofDocumentTargetTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$value = new ProofDocumentTarget(
			objectService: $objectService,
			register: 'dossiq',
			docSchema: 'caseDocument'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: 'dossiq', actual: $value->register);
		$this->assertSame(expected: 'caseDocument', actual: $value->docSchema);
	}//end testPropertiesReadBack()
}//end class
