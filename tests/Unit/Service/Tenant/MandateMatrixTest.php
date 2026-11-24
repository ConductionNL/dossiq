<?php

/**
 * Unit tests for reading a mandate matrix.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Tenant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Tenant;

use OCA\Dossiq\Service\Tenant\MandateMatrix;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Tenant\MandateMatrix
 */
class MandateMatrixTest extends TestCase {

	/**
	 * A role's own grant, a wildcard role and a wildcard action each allow; anything else denies.
	 *
	 * @return void
	 */
	public function testGrantsAndWildcards(): void {
		$matrices = new MandateMatrix();
		$matrix = ['case_handler' => ['edit' => true], '*' => ['view' => true], 'tenant_admin' => ['*' => true]];

		self::assertTrue(condition: $matrices->allows(matrix: $matrix, role: 'case_handler', action: 'edit'));
		self::assertTrue(condition: $matrices->allows(matrix: $matrix, role: 'case_handler', action: 'view'));
		self::assertTrue(condition: $matrices->allows(matrix: $matrix, role: 'tenant_admin', action: 'delete'));
		self::assertFalse(condition: $matrices->allows(matrix: $matrix, role: 'case_handler', action: 'delete'));
	}//end testGrantsAndWildcards()

	/**
	 * The row in force today is chosen; a stored JSON matrix is decoded and unreadable JSON denies everything.
	 *
	 * @return void
	 */
	public function testTheRowInForceAndItsMatrix(): void {
		$matrices = new MandateMatrix();
		$past = ['effectiveFrom' => '2001-01-01', 'effectiveTo' => '2002-01-01'];
		$current = ['effectiveFrom' => '2001-01-01', 'effectiveTo' => '2099-12-31'];

		self::assertSame(expected: $current, actual: $matrices->activeRow(rows: [$past, $current]));
		self::assertNull(actual: $matrices->activeRow(rows: [$past]));
		self::assertSame(expected: ['x' => ['y' => true]], actual: $matrices->normalise(matrixField: '{"x":{"y":true}}'));
		self::assertSame(expected: [], actual: $matrices->normalise(matrixField: 'not json'));
	}//end testTheRowInForceAndItsMatrix()
}//end class
