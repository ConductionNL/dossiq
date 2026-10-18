<?php

/**
 * Dossiq Object Type Catalogue Test
 *
 * The catalogue lists the schemas dossiq's own register references, sorted
 * by title, and nothing from elsewhere on the instance.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
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
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Catalogue;

use OCA\Dossiq\Service\Catalogue\ObjectTypeCatalogue;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Object types from dossiq's own register.
 */
final class ObjectTypeCatalogueTest extends TestCase {
	/**
	 * The ids the fake SchemaMapper was asked for.
	 *
	 * @var int[]|null
	 */
	private ?array $requestedIds = null;

	/**
	 * Only the schemas the register references are asked for, and each becomes
	 * a row with its property count, sorted by title.
	 *
	 * @return void
	 */
	public function testListsTheSchemasOfOurRegisterSortedByTitle(): void {
		$catalogue = $this->catalogue(
			registerId: '23',
			registerSchemaIds: [173, '165', 'not-an-id', 0],
			schemas: [
				173 => ['slug' => 'caseTask', 'title' => 'Taak', 'description' => 'Werk binnen een zaak', 'version' => '1.2.0', 'properties' => ['a' => [], 'b' => []]],
				165 => ['slug' => 'boom', 'title' => 'Boom', 'description' => null, 'version' => null, 'properties' => ['a' => []]],
				52 => ['slug' => 'nc-user', 'title' => 'nc-user', 'description' => '', 'version' => '0.0.1', 'properties' => []],
			],
		);

		$rows = $catalogue->list();

		$this->assertSame([173, 165], $this->requestedIds, 'Only the register\'s own numeric schema references are read.');
		$this->assertSame(
			[
				['id' => '165', 'slug' => 'boom', 'title' => 'Boom', 'description' => '', 'version' => '', 'properties' => 1],
				['id' => '173', 'slug' => 'caseTask', 'title' => 'Taak', 'description' => 'Werk binnen een zaak', 'version' => '1.2.0', 'properties' => 2],
			],
			$rows
		);
	}

	/**
	 * A schema without a title is listed by its slug.
	 *
	 * @return void
	 */
	public function testAnUntitledSchemaReadsAsItsSlug(): void {
		$catalogue = $this->catalogue(
			registerId: '23',
			registerSchemaIds: [7],
			schemas: [7 => ['slug' => 'lantaarnpaal', 'title' => '', 'description' => '', 'version' => '', 'properties' => []]],
		);

		$this->assertSame('lantaarnpaal', $catalogue->list()[0]['title']);
	}

	/**
	 * With no register configured nothing is read and nothing is listed.
	 *
	 * @return void
	 */
	public function testNoRegisterListsNothing(): void {
		$catalogue = $this->catalogue(registerId: '', registerSchemaIds: [173], schemas: []);

		$this->assertSame([], $catalogue->list());
		$this->assertNull($this->requestedIds);
	}

	/**
	 * A register OpenRegister cannot find throws, so the caller can tell a
	 * failed read from an empty register.
	 *
	 * @return void
	 */
	public function testAnUnreachableRegisterThrows(): void {
		$catalogue = $this->catalogue(registerId: '99', registerSchemaIds: null, schemas: []);

		$this->expectException(RuntimeException::class);
		$catalogue->list();
	}

	/**
	 * Build the catalogue against fake OpenRegister mappers.
	 *
	 * @param string                           $registerId        The configured register id, '' when unset.
	 * @param array<int, int|string>|null      $registerSchemaIds The register's schema references, null when the register is missing.
	 * @param array<int, array<string, mixed>> $schemas           The schemas on the instance, by id.
	 *
	 * @return ObjectTypeCatalogue The catalogue under test.
	 */
	private function catalogue(string $registerId, ?array $registerSchemaIds, array $schemas): ObjectTypeCatalogue {
		$registerMapper = new class($registerSchemaIds) {
			/**
			 * @param array<int, int|string>|null $schemaIds The register's schema references.
			 */
			public function __construct(private ?array $schemaIds) {
			}

			/**
			 * @param string  $id           The register id.
			 * @param boolean $rbac         Unused.
			 * @param boolean $multitenancy Unused.
			 *
			 * @return object The register-like object.
			 *
			 * @throws RuntimeException When the register is missing, as the real mapper does.
			 */
			public function find(string $id, bool $rbac = true, bool $multitenancy = true): object {
				if ($this->schemaIds === null) {
					throw new RuntimeException('no such register: ' . $id);
				}

				return new class($this->schemaIds) {
					/**
					 * @param array<int, int|string> $schemaIds The schema references.
					 */
					public function __construct(private array $schemaIds) {
					}

					/**
					 * @return array<int, int|string> The schema references.
					 */
					public function getSchemas(): array {
						return $this->schemaIds;
					}
				};
			}
		};

		$test = $this;
		$schemaMapper = new class($schemas, $test) {
			/**
			 * @param array<int, array<string, mixed>> $schemas The schemas by id.
			 * @param ObjectTypeCatalogueTest          $test    The test, told which ids were asked for.
			 */
			public function __construct(private array $schemas, private ObjectTypeCatalogueTest $test) {
			}

			/**
			 * Mirrors SchemaMapper::findMultiple().
			 *
			 * @param int[]   $ids          The schema ids.
			 * @param boolean $rbac         Unused.
			 * @param boolean $multitenancy Unused.
			 *
			 * @return object[] The schema-like objects that exist.
			 */
			public function findMultiple(array $ids, bool $rbac = true, bool $multitenancy = true): array {
				$this->test->recordRequestedIds($ids);
				$found = [];
				foreach ($ids as $id) {
					if (isset($this->schemas[$id]) === true) {
						$found[] = ObjectTypeCatalogueTest::schema($id, $this->schemas[$id]);
					}
				}

				return $found;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === 'OCA\OpenRegister\Db\RegisterMapper' ? $registerMapper : $schemaMapper)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '', bool $lazy = false): string => ($key === 'register' ? $registerId : $default)
		);

		return new ObjectTypeCatalogue($appConfig, $container);
	}

	/**
	 * Note the ids the fake SchemaMapper was asked for.
	 *
	 * @param int[] $ids The ids.
	 *
	 * @return void
	 */
	public function recordRequestedIds(array $ids): void {
		$this->requestedIds = $ids;
	}

	/**
	 * A schema-like object with the getters the catalogue reads.
	 *
	 * @param integer              $id     The schema id.
	 * @param array<string, mixed> $fields The slug, title, description, version and properties.
	 *
	 * @return object The schema-like object.
	 */
	public static function schema(int $id, array $fields): object {
		return new class($id, $fields) {
			/**
			 * @param integer              $id     The schema id.
			 * @param array<string, mixed> $fields The schema fields.
			 */
			public function __construct(private int $id, private array $fields) {
			}

			/**
			 * @return integer The id.
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return string|null The slug.
			 */
			public function getSlug(): ?string {
				return $this->fields['slug'];
			}

			/**
			 * @return string|null The title.
			 */
			public function getTitle(): ?string {
				return $this->fields['title'];
			}

			/**
			 * @return string|null The description.
			 */
			public function getDescription(): ?string {
				return $this->fields['description'];
			}

			/**
			 * @return string|null The version.
			 */
			public function getVersion(): ?string {
				return $this->fields['version'];
			}

			/**
			 * @return array<string, mixed> The properties.
			 */
			public function getProperties(): array {
				return $this->fields['properties'];
			}
		};
	}
}
