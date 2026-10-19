<?php

/**
 * The catalogue reads a linked object's schema only from an OpenRegister URL.
 *
 * Each case pairs what is resolved with what is not, because a resolver that
 * answers for every URL would put a schema on a free-text object type.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Catalogue
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
 * @spec openspec/changes/screens-round15-catalogue-schema-columns/specs/catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Catalogue;

use OCA\Dossiq\Service\Catalogue\ObjectTypeSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the schema a linked object's URL names.
 *
 * @covers \OCA\Dossiq\Service\Catalogue\ObjectTypeSchemaResolver
 */
class ObjectTypeSchemaResolverTest extends TestCase {

	/**
	 * Only an OpenRegister objects URL names a schema.
	 *
	 * @return void
	 */
	public function testReadsTheSchemaFromAnOpenRegisterUrlOnly(): void {
		$this->assertSame('trees', ObjectTypeSchemaResolver::schemaNamedIn(objectUrl: '/index.php/apps/openregister/api/objects/7/trees/abc'));
		$this->assertSame('42', ObjectTypeSchemaResolver::schemaNamedIn(objectUrl: 'https://x.example/apps/openregister/api/objects/dossiq/42'));
		$this->assertSame('', ObjectTypeSchemaResolver::schemaNamedIn(objectUrl: 'https://elsewhere.example/objects/7/trees/abc'));
		$this->assertSame('', ObjectTypeSchemaResolver::schemaNamedIn(objectUrl: ''));
	}//end testReadsTheSchemaFromAnOpenRegisterUrlOnly()

	/**
	 * A geo property puts the objects on a map; every schema has a table.
	 *
	 * @return void
	 */
	public function testAGeoPropertyAddsTheMapView(): void {
		$this->assertSame(['map', 'table'], ObjectTypeSchemaResolver::viewsOf(properties: ['location' => ['type' => 'geo'], 'name' => ['type' => 'string']]));
		$this->assertSame(['table'], ObjectTypeSchemaResolver::viewsOf(properties: ['name' => ['type' => 'string']]));
	}//end testAGeoPropertyAddsTheMapView()

	/**
	 * The schema is read once and shaped; an unreadable schema answers null.
	 *
	 * @return void
	 */
	public function testDescribesAReadableSchemaAndNotAnUnreadableOne(): void {
		$schema = new class {
			/**
			 * @return string The title.
			 */
			public function getTitle(): string {
				return 'Bomenregister';
			}

			/**
			 * @return array<string, array<string, string>> The properties.
			 */
			public function getProperties(): array {
				return ['location' => ['type' => 'geo'], 'species' => ['type' => 'string']];
			}
		};
		$mapper = new class($schema) {
			/** @var int */
			public int $reads = 0;

			/**
			 * @param object $schema The schema to answer.
			 */
			public function __construct(private object $schema) {
			}

			/**
			 * @param array<int, string|int> $ids The schema ids or slugs.
			 *
			 * @return array<int, object> The schemas found; a missing one is left out.
			 */
			public function findMultiple(array $ids): array {
				$this->reads++;
				if ($ids === ['gone']) {
					return [];
				}

				return [$this->schema];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($mapper);
		$resolver = new ObjectTypeSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class));

		$expected = ['title' => 'Bomenregister', 'properties' => 2, 'views' => ['map', 'table']];
		$this->assertSame($expected, $resolver->describe(objectUrl: '/apps/openregister/api/objects/7/trees/a'));
		$this->assertSame($expected, $resolver->describe(objectUrl: '/apps/openregister/api/objects/7/trees/b'));
		$this->assertSame(1, $mapper->reads);
		$this->assertNull($resolver->describe(objectUrl: '/apps/openregister/api/objects/7/gone/a'));
		$this->assertNull($resolver->describe(objectUrl: 'https://elsewhere.example/tree/1'));
	}//end testDescribesAReadableSchemaAndNotAnUnreadableOne()

	/**
	 * Without OpenRegister nothing is read and every link stays empty.
	 *
	 * @return void
	 */
	public function testDescribesNothingWithoutOpenRegister(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$container->expects($this->never())->method('get');
		$resolver = new ObjectTypeSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertNull($resolver->describe(objectUrl: '/apps/openregister/api/objects/7/trees/a'));
	}//end testDescribesNothingWithoutOpenRegister()
}//end class
