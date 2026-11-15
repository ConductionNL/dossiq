<?php

/**
 * KeepsLookupsInSchema unit tests
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\KeepsLookupsInSchema;
use PHPUnit\Framework\TestCase;

/**
 * A host exposing the trait's schema check.
 */
class SchemaLookupHost {

	use KeepsLookupsInSchema;

	/**
	 * @param array<string, mixed> $row    The row found.
	 * @param string               $schema The schema asked under.
	 * @return bool Whether the row is kept.
	 */
	public function keeps(array $row, string $schema): bool {
		return $this->rowInSchema(row: $row, schema: $schema);
	}//end keeps()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Support\KeepsLookupsInSchema
 */
class KeepsLookupsInSchemaTest extends TestCase {

	/**
	 * A row that names no schema is kept.
	 *
	 * @return void
	 */
	public function testARowThatDoesNotSayIsKept(): void {
		$host = new SchemaLookupHost();

		self::assertTrue($host->keeps(row: [], schema: 'case'));
		self::assertTrue($host->keeps(row: ['@self' => ['schema' => '  ']], schema: 'case'));
		self::assertTrue($host->keeps(row: ['@self' => ['schema' => ['nested']]], schema: 'case'));
		self::assertTrue($host->keeps(row: ['@self' => 'not-an-array'], schema: 'case'));
	}//end testARowThatDoesNotSayIsKept()

	/**
	 * The same schema, by slug, path or case, is kept.
	 *
	 * @return void
	 */
	public function testTheSameSchemaIsKept(): void {
		$host = new SchemaLookupHost();

		self::assertTrue($host->keeps(row: ['@self' => ['schema' => 'Case']], schema: 'case'));
		self::assertTrue($host->keeps(row: ['@self' => ['schema' => '/api/schemas/case']], schema: ' case '));
		self::assertTrue($host->keeps(row: ['@self' => ['schema' => 12]], schema: '12'));
	}//end testTheSameSchemaIsKept()

	/**
	 * Another schema named the same way (both slugs, or both ids) is refused.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsRefused(): void {
		$host = new SchemaLookupHost();

		self::assertFalse($host->keeps(row: ['@self' => ['schema' => 'document']], schema: 'case'));
		self::assertFalse($host->keeps(row: ['@self' => ['schema' => '7']], schema: '12'));
	}//end testAnotherSchemaIsRefused()

	/**
	 * An id against a slug cannot be compared, so the row is kept.
	 *
	 * @return void
	 */
	public function testAnIdAgainstASlugIsKept(): void {
		$host = new SchemaLookupHost();

		self::assertTrue($host->keeps(row: ['@self' => ['schema' => '7']], schema: 'case'));
		self::assertTrue($host->keeps(row: ['@self' => ['schema' => 'document']], schema: '12'));
	}//end testAnIdAgainstASlugIsKept()
}//end class
