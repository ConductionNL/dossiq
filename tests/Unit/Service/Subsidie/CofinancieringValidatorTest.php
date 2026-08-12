<?php

/**
 * CofinancieringValidator Unit Tests.
 *
 * Exercises co-financing reconciliation, EU-source detection and the
 * structured validation result (REQ-SUB-008).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Subsidie
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Subsidie;

use OCA\Procest\Service\Subsidie\CofinancieringValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\Subsidie\CofinancieringValidator
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
			['partij' => 'Provincie', 'bedrag' => 50000],
			['partij' => 'Eigen bijdrage', 'bedrag' => 25000],
		];
		// subsidy 75000 + cofin 75000 = project 150000.
		$this->assertTrue($this->validator->reconciles(75000.0, $cofin, 150000.0));
		$this->assertFalse($this->validator->reconciles(75000.0, $cofin, 200000.0));
	}//end testReconciliation()

	/**
	 * @return void
	 */
	public function testEuDetection(): void {
		$this->assertTrue($this->validator->hasEuCofinanciering([['partij' => 'EFRO West', 'bedrag' => 1]]));
		$this->assertTrue($this->validator->hasEuCofinanciering([['partij' => 'Interreg', 'bedrag' => 1]]));
		$this->assertFalse($this->validator->hasEuCofinanciering([['partij' => 'Provincie Utrecht', 'bedrag' => 1]]));
	}//end testEuDetection()

	/**
	 * REQ-SUB-008: validation returns a machine-readable error code on
	 * mismatch and blocks beschikking creation.
	 *
	 * @return void
	 */
	public function testValidateResult(): void {
		$cofin = [['partij' => 'ESF', 'bedrag' => 25000]];

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
