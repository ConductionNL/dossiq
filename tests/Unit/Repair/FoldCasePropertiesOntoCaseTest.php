<?php

/**
 * Folding caseProperty rows onto the case that owns them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use JsonSerializable;
use OCA\Dossiq\Repair\FoldCasePropertiesOntoCase;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A case without properties gets its caseProperty answers, named after their
 * definitions; a case that already has properties, cannot be read or cannot
 * be saved is counted, not overwritten.
 *
 * @covers \OCA\Dossiq\Repair\FoldCasePropertiesOntoCase
 */
final class FoldCasePropertiesOntoCaseTest extends TestCase {
	/** @var array<string, string> */
	private array $config = ['register' => 'reg', 'case_schema' => 'case', 'case_property_schema' => 'caseProperty', 'property_definition_schema' => 'propertyDefinition'];

	/** @var array<int, string> */
	private array $lines = [];

	/** @var array<int, array> */
	private array $warnings = [];

	/**
	 * An object store with these rows per schema and these cases.
	 *
	 * @param array<string, array> $rows  Rows findAll returns, per schema.
	 * @param array<string, mixed> $cases What find returns, per case id.
	 */
	private function store(array $rows, array $cases): object {
		return new class($rows, $cases) {
			/** @var array<int, array> */
			public array $saved = [];

			/** @var array<int, array> */
			public array $queries = [];

			/** @var array<int, array> */
			public array $finds = [];

			public bool $asSystem = false;

			/**
			 * @param array<string, array> $rows  Rows per schema.
			 * @param array<string, mixed> $cases Cases per id.
			 */
			public function __construct(private array $rows, private array $cases) {
			}

			public function runAsSystem(callable $operation): mixed {
				$this->asSystem = true;
				return $operation();
			}

			public function findAll(array $query): mixed {
				$this->queries[] = $query;
				return ($this->rows[$query['filters']['schema']] ?? []);
			}

			public function find(string $id, array $options): mixed {
				$this->finds[] = [$id, $options];
				$case = ($this->cases[$id] ?? null);
				if ($case instanceof \Throwable) {
					throw $case;
				}

				return $case;
			}

			public function saveObject(string $schema, array $object, array $options): void {
				if (($object['id'] ?? '') === 'c-unsaveable') {
					throw new RuntimeException('validation failed');
				}

				$this->saved[] = [$schema, $object, $options];
			}
		};
	}//end store()

	/**
	 * Run the step over this store.
	 *
	 * @param object|null $store The object store, or null when OpenRegister is absent.
	 */
	private function fold(?object $store): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'dossiq' ? ($this->config[$key] ?? $default) : $default)
		);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $line): void {
				$this->lines[] = $line;
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->warnings[] = [$message, $context['case']];
			}
		);

		(new FoldCasePropertiesOntoCase(settingsService: $settings, appConfig: $appConfig, logger: $logger))->run($output);
	}//end fold()

	/**
	 * A row that serialises to this data, as an entity does.
	 *
	 * @param array $data The data.
	 */
	private static function entity(array $data): JsonSerializable {
		return new class($data) implements JsonSerializable {
			/** @param array $data The data. */
			public function __construct(private array $data) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end entity()

	public function testABareCaseGetsItsAnswersNamedAfterTheirDefinitions(): void {
		$store = $this->store(
			rows: [
				'caseProperty' => [
					['case' => 'c-1', 'propertyDefinition' => 'd-area', 'value' => 120],
					self::entity(['case' => 'c-1', 'propertyDefinition' => 'd-unknown']),
					['case' => '', 'propertyDefinition' => 'd-area', 'value' => 'orphan'],
					['case' => 'c-1', 'propertyDefinition' => '', 'value' => 'no definition'],
					'not a row',
				],
				'propertyDefinition' => [['id' => 'd-area', 'name' => 'Oppervlakte'], self::entity(['uuid' => 'd-other', 'name' => 'Ander']), ['name' => 'no id']],
			],
			cases: ['c-1' => self::entity(['id' => 'c-1', 'title' => 'Aanvraag', 'properties' => []])]
		);

		$this->fold(store: $store);

		$this->assertTrue($store->asSystem);
		$this->assertSame(
			[
				['filters' => ['register' => 'reg', 'schema' => 'caseProperty'], '_rbac' => false, '_multitenancy' => false],
				['filters' => ['register' => 'reg', 'schema' => 'propertyDefinition'], '_rbac' => false, '_multitenancy' => false],
			],
			$store->queries
		);
		$this->assertSame([['c-1', ['_rbac' => false, '_multitenancy' => false]]], $store->finds);
		$this->assertSame(
			[[
				'case',
				[
					'id' => 'c-1',
					'title' => 'Aanvraag',
					'properties' => [
						['propertyDefinition' => 'd-area', 'name' => 'Oppervlakte', 'value' => '120'],
						['propertyDefinition' => 'd-unknown', 'name' => '', 'value' => ''],
					],
				],
				['_rbac' => false, '_multitenancy' => false],
			]],
			$store->saved
		);
		$this->assertSame(['Case properties folded: 1 written, 0 skipped, 0 failed.'], $this->lines);
	}//end testABareCaseGetsItsAnswersNamedAfterTheirDefinitions()

	public function testExistingPropertiesUnreadableAndUnsaveableCasesAreCounted(): void {
		$answer = static fn (string $case): array => ['case' => $case, 'propertyDefinition' => 'd-1', 'value' => 'x'];
		$store  = $this->store(
			rows: ['caseProperty' => [$answer('c-done'), $answer('c-gone'), $answer('c-broken'), $answer('c-unsaveable'), $answer('c-new')], 'propertyDefinition' => []],
			cases: [
				'c-done' => ['id' => 'c-done', 'properties' => [['name' => 'kept']]],
				'c-broken' => new RuntimeException('store down'),
				'c-unsaveable' => ['id' => 'c-unsaveable'],
				'c-new' => ['id' => 'c-new'],
			]
		);

		$this->fold(store: $store);

		$this->assertSame(['c-new'], array_map(static fn (array $save): string => $save[1]['id'], $store->saved));
		$this->assertSame(
			[['Dossiq: could not fold case properties onto a case', 'c-broken'], ['Dossiq: could not fold case properties onto a case', 'c-unsaveable']],
			$this->warnings
		);
		$this->assertSame(['Case properties folded: 1 written, 2 skipped, 2 failed.'], $this->lines);
	}//end testExistingPropertiesUnreadableAndUnsaveableCasesAreCounted()

	public function testWithoutAnswersNoDefinitionIsRead(): void {
		$store = $this->store(rows: ['caseProperty' => []], cases: []);

		$this->fold(store: $store);

		$this->assertCount(1, $store->queries);
		$this->assertSame(['No caseProperty rows to fold.'], $this->lines);
	}//end testWithoutAnswersNoDefinitionIsRead()

	public function testANonListAnswerIsNoAnswers(): void {
		$store = new class {
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			public function findAll(array $query): mixed {
				return null;
			}
		};

		$this->fold(store: $store);

		$this->assertSame(['No caseProperty rows to fold.'], $this->lines);
	}//end testANonListAnswerIsNoAnswers()

	public function testNothingRunsWithoutOpenRegister(): void {
		$this->fold(store: null);

		$this->assertSame(['OpenRegister unavailable, skipping case-properties backfill.'], $this->lines);
	}//end testNothingRunsWithoutOpenRegister()

	public function testNothingRunsWhenAnySchemaIsMissing(): void {
		foreach (array_keys($this->config) as $key) {
			$config        = $this->config;
			$this->config[$key] = '';
			$store         = $this->store(rows: [], cases: []);

			$this->fold(store: $store);

			$this->assertFalse($store->asSystem, $key);
			$this->config = $config;
		}

		$this->assertSame(array_fill(0, 4, 'Case-property schemas not configured, skipping backfill.'), $this->lines);
	}//end testNothingRunsWhenAnySchemaIsMissing()

	public function testItIsNamed(): void {
		$step = new FoldCasePropertiesOntoCase(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame('Fold caseProperty answers onto the case that owns them', $step->getName());
	}//end testItIsNamed()
}//end class
