<?php

/**
 * CofinancieringValidator Unit Tests.
 *
 * Exercises co-financing reconciliation, EU-source detection and the
 * structured validation result (REQ-SUB-008).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\Subsidie\CofinancieringValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Subsidie\CofinancieringValidator
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-26
 */
class CofinancieringValidatorTest extends TestCase {

	private CofinancieringValidator $validator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->validator = new CofinancieringValidator();
	}//end setUp()

	/**
	 * @return void
	 */
	public function testReconciliation(): void {
		$cofin = [
			['partij' => 'Provincie', 'amount' => 50000],
			['partij' => 'Eigen bijdrage', 'amount' => 25000],
		];
		// subsidy 75000 + cofin 75000 = project 150000.
		$this->assertTrue($this->validator->reconciles(75000.0, $cofin, 150000.0));
		$this->assertFalse($this->validator->reconciles(75000.0, $cofin, 200000.0));
	}//end testReconciliation()

	/**
	 * @return void
	 */
	public function testEuDetection(): void {
		$this->assertTrue($this->validator->hasEuCofinanciering([['partij' => 'EFRO West', 'amount' => 1]]));
		$this->assertTrue($this->validator->hasEuCofinanciering([['partij' => 'Interreg', 'amount' => 1]]));
		$this->assertFalse($this->validator->hasEuCofinanciering([['partij' => 'Provincie Utrecht', 'amount' => 1]]));
	}//end testEuDetection()

	/**
	 * REQ-SUB-008: validation returns a machine-readable error code on
	 * mismatch and blocks beschikking creation.
	 *
	 * @return void
	 */
	public function testValidateResult(): void {
		$cofin = [['partij' => 'ESF', 'amount' => 25000]];

		$ok = $this->validator->validate(75000.0, $cofin, 100000.0);
		$this->assertTrue($ok['valid']);
		$this->assertNull($ok['error']);
		$this->assertTrue($ok['euCofinanciering']);

		$mismatch = $this->validator->validate(75000.0, $cofin, 200000.0);
		$this->assertFalse($mismatch['valid']);
		$this->assertSame('COFIN_SUM_MISMATCH', $mismatch['error']);

		$badTotal = $this->validator->validate(75000.0, $cofin, 0.0);
		$this->assertFalse($badTotal['valid']);
		$this->assertSame('COFIN_PROJECT_TOTAL_INVALID', $badTotal['error']);
	}//end testValidateResult()
}//end class
