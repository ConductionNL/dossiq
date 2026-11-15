<?php
/**
 * ObjectSearch Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
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

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ObjectSearch;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\Dossiq\Service\Support\ObjectSearch
 */
class ObjectSearchTest extends TestCase {


	/**
	 * The search carries what it was built with.
	 *
	 * @return void
	 */
	public function testCarriesItsInputs(): void {
		$service = new stdClass();
		$search  = new ObjectSearch(objectService: $service, register: '5', schema: 'case', filters: ['a' => 1], scope: ['_rbac' => false]);

		self::assertSame($service, $search->objectService);
		self::assertSame('5', $search->register);
		self::assertSame('case', $search->schema);
		self::assertSame(['a' => 1], $search->filters);
		self::assertSame(['_rbac' => false], $search->scope);
	}//end testCarriesItsInputs()


	/**
	 * Only numeric register and schema take the numeric-ID path.
	 *
	 * @return void
	 */
	public function testNumericIdsOnlyWhenBothAreNumeric(): void {
		$service = new stdClass();

		self::assertTrue((new ObjectSearch(objectService: $service, register: 5, schema: '7', filters: [], scope: []))->byNumericIds());
		self::assertFalse((new ObjectSearch(objectService: $service, register: 'dossiq', schema: 7, filters: [], scope: []))->byNumericIds());
		self::assertFalse((new ObjectSearch(objectService: $service, register: 5, schema: 'case', filters: [], scope: []))->byNumericIds());
	}//end testNumericIdsOnlyWhenBothAreNumeric()
}//end class
