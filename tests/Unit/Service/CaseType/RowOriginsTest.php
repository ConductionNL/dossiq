<?php

/**
 * Unit tests for RowOrigins: which blueprint rows a parent gave and which the type overrides.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-blueprint-counts-inherited-and-overridden-fields
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\RowOrigins;
use OCA\Dossiq\Service\CaseTypeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RowOrigins.
 *
 * @covers \OCA\Dossiq\Service\CaseType\RowOrigins
 */
class RowOriginsTest extends TestCase {

	/**
	 * The type's own rows are own, an ancestor's rows are inherited.
	 *
	 * @return void
	 */
	public function testOriginForTellsOwnFromInherited(): void {
		$origins = new RowOrigins();

		$this->assertSame(CaseTypeResolver::ORIGIN_OWN, $origins->originFor(isOwn: true));
		$this->assertSame(CaseTypeResolver::ORIGIN_INHERITED, $origins->originFor(isOwn: false));
	}//end testOriginForTellsOwnFromInherited()

	/**
	 * An own row replacing a row already merged under its key is marked as an override.
	 *
	 * @return void
	 */
	public function testAnOwnRowOverAnAncestorsRowOverrides(): void {
		$row = ['key' => 'naam', 'origin' => CaseTypeResolver::ORIGIN_OWN];

		$this->assertSame(
			['key' => 'naam', 'origin' => CaseTypeResolver::ORIGIN_OWN, 'overrides' => true],
			(new RowOrigins())->markOverride(row: $row, isOwn: true, merged: ['naam' => ['key' => 'naam']], key: 'naam')
		);
	}//end testAnOwnRowOverAnAncestorsRowOverrides()

	/**
	 * A new own row, or an ancestor's row over another, is not an override.
	 *
	 * @return void
	 */
	public function testOnlyAnOwnRowOverAnExistingKeyOverrides(): void {
		$origins = new RowOrigins();
		$row = ['key' => 'naam'];

		$this->assertSame($row, $origins->markOverride(row: $row, isOwn: true, merged: ['adres' => ['key' => 'adres']], key: 'naam'));
		$this->assertSame($row, $origins->markOverride(row: $row, isOwn: false, merged: ['naam' => ['key' => 'naam']], key: 'naam'));
	}//end testOnlyAnOwnRowOverAnExistingKeyOverrides()

	/**
	 * The counts are all rows, the rows of the inherited origin, and the rows that override.
	 *
	 * @return void
	 */
	public function testCountsTotalInheritedAndOverridden(): void {
		$rows = [
			['key' => 'a', 'origin' => CaseTypeResolver::ORIGIN_INHERITED],
			['key' => 'b', 'origin' => CaseTypeResolver::ORIGIN_INHERITED],
			['key' => 'c', 'origin' => CaseTypeResolver::ORIGIN_OWN, 'overrides' => true],
			['key' => 'd', 'origin' => CaseTypeResolver::ORIGIN_OWN, 'overrides' => 'yes'],
			['key' => 'e'],
		];

		$this->assertSame(
			['total' => 5, 'inherited' => 2, 'overridden' => 1],
			(new RowOrigins())->counts(rows: $rows, inheritedOrigin: CaseTypeResolver::ORIGIN_INHERITED)
		);
	}//end testCountsTotalInheritedAndOverridden()

	/**
	 * The inherited origin is the caller's: shared rows count when shared is asked for.
	 *
	 * @return void
	 */
	public function testCountsUseTheGivenInheritedOrigin(): void {
		$rows = [
			['origin' => CaseTypeResolver::ORIGIN_SHARED],
			['origin' => CaseTypeResolver::ORIGIN_INHERITED],
		];

		$this->assertSame(
			['total' => 2, 'inherited' => 1, 'overridden' => 0],
			(new RowOrigins())->counts(rows: $rows, inheritedOrigin: CaseTypeResolver::ORIGIN_SHARED)
		);
		$this->assertSame(
			['total' => 0, 'inherited' => 0, 'overridden' => 0],
			(new RowOrigins())->counts(rows: [], inheritedOrigin: CaseTypeResolver::ORIGIN_INHERITED)
		);
	}//end testCountsUseTheGivenInheritedOrigin()
}//end class
