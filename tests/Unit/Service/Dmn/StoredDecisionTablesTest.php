<?php

/**
 * StoredDecisionTables tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Dmn
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
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dmn;

use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryDecisionTableStore;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\Dossiq\Service\Dmn\StoredDecisionTables
 */
class StoredDecisionTablesTest extends TestCase {

	/**
	 * A fake OpenRegister object service over one register, recording saves.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows; `@register` reads `<register>/<schema>`.
	 *
	 * @return object The fake.
	 */
	public static function objectService(array $rows = []): object {
		return new class($rows) {
			/** @var list<array{object: array<string, mixed>, register: string, schema: string, uuid: ?string}> */
			public array $saved = [];

			/**
			 * @param array<int, array<string, mixed>> $rows Rows.
			 */
			public function __construct(public array $rows) {
			}

			/** @var bool Whether a system identity is in place right now. */
			public bool $asSystem = false;

			/**
			 * @param callable $work The work.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $work): mixed {
				$this->asSystem = true;
				try {
					return $work();
				} finally {
					$this->asSystem = false;
				}
			}

			/**
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $filters Filters.
			 * @param bool $_rbac RBAC.
			 * @param bool $_multitenancy Multitenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($_rbac !== false || $_multitenancy !== false) {
					throw new RuntimeException('dossiq must read these registers unscoped');
				}

				$found = [];
				foreach ($this->rows as $row) {
					if (($row['@register'] ?? '') !== $register . '/' . $schema) {
						continue;
					}

					if (isset($filters['key']) === true && ($row['key'] ?? '') !== $filters['key']) {
						continue;
					}

					$found[] = $row;
				}

				return $found;
			}

			/**
			 * @param array<string, mixed> $object Object.
			 * @param string $register Register.
			 * @param string $schema Schema.
			 * @param string|null $uuid Uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'asSystem' => $this->asSystem];
				return $object + ['id' => ($uuid ?? 'new-' . count($this->saved))];
			}
		};
	}//end objectService()

	/**
	 * Whether a row fits OpenRegister's own decision-table schema (no extra properties).
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return bool Whether it validates.
	 */
	public static function validAgainstOpenRegister(array $row): bool {
		$fixture = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/openregister/decision-table.schema.json'), true);
		$schema = $fixture['schema'];
		$schema['additionalProperties'] = false;

		return (new Validator())->validate(json_decode((string)json_encode($row)), json_decode((string)json_encode($schema)))->isValid();
	}//end validAgainstOpenRegister()

	/**
	 * The client over a fake object service and a container answering with a store.
	 *
	 * @param object $objects The object service.
	 * @param object|null $store The store, or null when OpenRegister has none.
	 *
	 * @return StoredDecisionTables The client.
	 */
	public function tables(object $objects, ?object $store = null): StoredDecisionTables {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$container = $this->createMock(ContainerInterface::class);
		if ($store === null) {
			$container->method('get')->willThrowException(new RuntimeException('no store'));
		} else {
			$container->method('get')->willReturn($store);
		}

		return new StoredDecisionTables($settings, $container);
	}//end tables()

	/**
	 * Free-text dossiq keys become OpenRegister keys under the dossiq prefix.
	 *
	 * @return void
	 */
	public function testKeysFollowOpenRegistersPattern(): void {
		$tables = $this->tables(self::objectService());
		self::assertSame('dossiq.lhs-matrix-2', $tables->keyFor('LHS Matrix 2'));
		self::assertSame('dossiq.toets', $tables->keyFor(' toets '));
		self::assertSame('dossiq.subsidie_hoogte', $tables->keyFor('Subsidie_Hoogte'));
		self::assertSame('dossiq.toets', $tables->keyFor('dossiq.toets'));
		self::assertSame('', $tables->keyFor('  '));
		self::assertSame('', $tables->keyFor('***'));
		self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._-]*$/', $tables->keyFor('Ünïcode Tafel!'));
	}//end testKeysFollowOpenRegistersPattern()

	/**
	 * A save writes a row OpenRegister's schema accepts, owned by dossiq, and
	 * replaces the row with the same key instead of adding a second.
	 *
	 * @return void
	 */
	public function testASaveCreatesThenReplacesByKey(): void {
		$objects = self::objectService(
			[['@register' => 'decision-tables/decision-table', 'id' => 'uuid-9', 'key' => 'dossiq.bestaand']]
		);
		$tables = $this->tables($objects);
		$table = [
			'key' => 'Toets', 'name' => 'Toets', 'hitPolicy' => 'first', 'enabled' => true, '@self' => ['id' => 'x'],
			'inputs' => [['name' => 'bedrag', 'type' => 'number']],
			'outputs' => [['name' => 'uitkomst', 'type' => 'string']],
			'rules' => [['id' => 'r1', 'inputEntries' => ['>100'], 'outputEntries' => ['hoog']]],
		];

		$row = $tables->save($table);
		self::assertSame('dossiq.toets', $row['key']);
		self::assertSame('FIRST', $row['hitPolicy']);
		self::assertSame('dossiq', $row['owner']);
		self::assertTrue(self::validAgainstOpenRegister($row), 'the row must fit OpenRegister\'s decision-table schema');
		self::assertSame(['decision-tables', 'decision-table', null], [$objects->saved[0]['register'], $objects->saved[0]['schema'], $objects->saved[0]['uuid']]);

		$tables->save(['key' => 'dossiq.bestaand', 'name' => 'Bestaand'] + $table);
		self::assertSame('uuid-9', $objects->saved[1]['uuid']);
	}//end testASaveCreatesThenReplacesByKey()

	/**
	 * Control: dossiq's `enabled` flag or a free-text key does not fit
	 * OpenRegister's schema, so the validation above can fail.
	 *
	 * @return void
	 */
	public function testTheSchemaCheckCanFail(): void {
		$base = ['key' => 'dossiq.x', 'name' => 'X', 'hitPolicy' => 'UNIQUE', 'inputs' => [], 'outputs' => [], 'rules' => []];
		self::assertTrue(self::validAgainstOpenRegister($base));
		self::assertFalse(self::validAgainstOpenRegister($base + ['enabled' => true]));
		self::assertFalse(self::validAgainstOpenRegister(['key' => 'Toets'] + $base));
	}//end testTheSchemaCheckCanFail()

	/**
	 * Evaluation goes through OpenRegister's store; no store answers null, a
	 * missing table throws.
	 *
	 * @return void
	 */
	public function testEvaluationGoesThroughTheStore(): void {
		$store = new InMemoryDecisionTableStore(
			['dossiq.toets' => [
				'key' => 'dossiq.toets', 'name' => 'Toets', 'hitPolicy' => 'UNIQUE',
				'inputs' => [['name' => 'bedrag', 'type' => 'number']],
				'outputs' => [['name' => 'uitkomst', 'type' => 'string']],
				'rules' => [['id' => 'r1', 'inputEntries' => ['>100'], 'outputEntries' => ['hoog']]],
			],
			]
		);

		self::assertSame('hoog', $this->tables(self::objectService(), $store)->evaluate('dossiq.toets', ['bedrag' => 150])['outputs']['uitkomst']);
		self::assertNull($this->tables(self::objectService())->evaluate('dossiq.toets', ['bedrag' => 150]));
		self::assertNull($this->tables(self::objectService())->find('dossiq.toets'));

		$this->expectException(UnexpectedValueException::class);
		$this->tables(self::objectService(), $store)->find('dossiq.weg');
	}//end testEvaluationGoesThroughTheStore()
}//end class
