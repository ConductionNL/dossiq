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
	 * The schema is read once per register and schema, and shaped.
	 *
	 * @return void
	 */
	public function testDescribesAReadableSchemaOncePerRegisterAndSchema(): void {
		$openRegister = $this->openRegister();
		$resolver     = $this->resolverOver(openRegister: $openRegister);

		$expected = ['title' => 'Bomenregister', 'properties' => 2, 'views' => ['map', 'table']];
		$this->assertSame($expected, $resolver->describe(objectUrl: '/apps/openregister/api/objects/7/trees/a'));
		$this->assertSame($expected, $resolver->describe(objectUrl: '/apps/openregister/api/objects/7/trees/b'));
		$this->assertSame([['7', 'trees']], $openRegister->asked);
		$this->assertNull($resolver->describe(objectUrl: 'https://elsewhere.example/tree/1'));
	}//end testDescribesAReadableSchemaOncePerRegisterAndSchema()

	/**
	 * The same slug in another register is that register's schema, read on its own.
	 *
	 * @return void
	 */
	public function testResolvesTheSchemaWithinTheRegisterTheUrlNames(): void {
		$openRegister = $this->openRegister();
		$resolver     = $this->resolverOver(openRegister: $openRegister);

		$this->assertSame('Bomenregister', $resolver->describe(objectUrl: '/apps/openregister/api/objects/7/trees/a')['title']);
		$this->assertSame('Parkbomen', $resolver->describe(objectUrl: '/apps/openregister/api/objects/9/trees/a')['title']);
		$this->assertSame([['7', 'trees'], ['9', 'trees']], $openRegister->asked);
	}//end testResolvesTheSchemaWithinTheRegisterTheUrlNames()

	/**
	 * A schema OpenRegister refuses leaves that row empty and does not stop the next one.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaAnswersNullWithoutThrowing(): void {
		$openRegister = $this->openRegister();
		$resolver     = $this->resolverOver(openRegister: $openRegister);

		$this->assertNull($resolver->describe(objectUrl: '/apps/openregister/api/objects/7/secret/a'));
		$this->assertNull($resolver->describe(objectUrl: '/apps/openregister/api/objects/7/secret/b'));
		$this->assertSame('Bomenregister', $resolver->describe(objectUrl: '/apps/openregister/api/objects/7/trees/a')['title']);
		$this->assertSame([['7', 'secret'], ['7', 'trees']], $openRegister->asked);
	}//end testAnUnreadableSchemaAnswersNullWithoutThrowing()

	/**
	 * The resolver under test, over a container that holds the given OpenRegister double.
	 *
	 * @param object $openRegister The register-scoped schema resolver double.
	 *
	 * @return ObjectTypeSchemaResolver The resolver.
	 */
	private function resolverOver(object $openRegister): ObjectTypeSchemaResolver {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($openRegister);

		return new ObjectTypeSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end resolverOver()

	/**
	 * A double of OpenRegister's register-scoped schema resolver. Register 7
	 * carries "trees" (Bomenregister), register 9 its own "trees" (Parkbomen),
	 * and "secret" is refused the way a read without permission is.
	 *
	 * @return object The double; `asked` lists every register and schema it was asked for.
	 */
	private function openRegister(): object {
		return new class {
			/** @var array<int, array{0: string, 1: string}> */
			public array $asked = [];

			/**
			 * @param string|int $registerRef The register.
			 * @param string|int $schemaRef   The schema.
			 *
			 * @return array{schema: object} The resolved schema.
			 */
			public function resolvePair(string|int $registerRef, string|int $schemaRef): array {
				$this->asked[] = [(string)$registerRef, (string)$schemaRef];
				if ($schemaRef === 'secret') {
					throw new \Exception('Permission denied');
				}

				$title = 'Bomenregister';
				if ($registerRef === '9') {
					$title = 'Parkbomen';
				}

				return ['schema' => new class($title) {
					/**
					 * @param string $title The title.
					 */
					public function __construct(private string $title) {
					}

					/**
					 * @return string The title.
					 */
					public function getTitle(): string {
						return $this->title;
					}

					/**
					 * @return array<string, array<string, string>> The properties.
					 */
					public function getProperties(): array {
						return ['location' => ['type' => 'geo'], 'species' => ['type' => 'string']];
					}
				}];
			}
		};
	}//end openRegister()

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
