<?php

/**
 * Unit test for WooDecisionSubject.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\WooDecisionSubject;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\WooDecisionSubject
 */
class WooDecisionSubjectTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'caseId-value';
		$register = 'register-value';
		$record = ['record' => 2];

		$value = new WooDecisionSubject(
			caseId: $caseId,
			register: $register,
			record: $record,
		);

		$this->assertSame(expected: $caseId, actual: $value->caseId);
		$this->assertSame(expected: $register, actual: $value->register);
		$this->assertSame(expected: $record, actual: $value->record);
	}//end testEveryPropertyReadsBack()
}//end class
