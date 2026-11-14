<?php

/**
 * Dossiq Schema Annotation Reconciler Test
 *
 * Proves that the declarative `x-openregister-*` blocks of the shipped
 * register (base JSON plus register.d fragments) are merged onto the live
 * schema configuration additively and idempotently, and that a missing mapper
 * or a failing write never escapes.
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Declarative annotation blocks onto live OpenRegister schemas.
 *
 * @covers \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 */
final class SchemaAnnotationReconcilerTest extends TestCase {
	/**
	 * The declared annotation blocks per slug, from the shipped register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function declaredBlocks(): array {
		$settings = dirname(__DIR__, 4) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');

		$blocks = [];
		foreach ($merged['components']['schemas'] as $key => $schema) {
			$configuration = ($schema['configuration'] ?? []);
			$own = array_intersect_key($configuration, array_flip(SchemaSlugMap::SCHEMA_ANNOTATION_KEYS));
			if ($own !== []) {
				$blocks[(string)($schema['slug'] ?? $key)] = $own;
			}
		}

		return $blocks;
	}

	/**
	 * A live schema holding a configuration.
	 *
	 * @param mixed $configuration The live configuration.
	 *
	 * @return object The schema-like object.
	 */
	private static function liveSchema(mixed $configuration): object {
		return new class($configuration) {
			/**
			 * @param mixed $configuration The live configuration.
			 */
			public function __construct(public mixed $configuration) {
			}

			/**
			 * @return mixed The configuration.
			 */
			public function getConfiguration(): mixed {
				return $this->configuration;
			}

			/**
			 * @param array $configuration The new configuration.
			 *
			 * @return void
			 */
			public function setConfiguration(array $configuration): void {
				$this->configuration = $configuration;
			}
		};
	}

	/**
	 * A schema mapper that knows the given live schemas by slug and records updates.
	 *
	 * @param array<string, object> $schemas Live schemas by slug.
	 * @param bool $failUpdates Whether update() throws.
	 *
	 * @return object The mapper.
	 */
	private static function mapper(array $schemas, bool $failUpdates = false): object {
		return new class($schemas, $failUpdates) {
			/**
			 * The slugs update() was called for, in order.
			 *
			 * @var list<object>
			 */
			public array $updated = [];

			/**
			 * @param array<string, object> $schemas Live schemas by slug.
			 * @param bool $failUpdates Whether update() throws.
			 */
			public function __construct(private array $schemas, private bool $failUpdates) {
			}

			/**
			 * Unscoped slug lookup, mirroring SchemaMapper::find().
			 *
			 * @param string $id The slug.
			 * @param array $extend Unused.
			 * @param bool $rbac Unused.
			 * @param bool $multitenancy Unused.
			 *
			 * @return object The schema.
			 *
			 * @throws \RuntimeException When the slug is unknown.
			 */
			public function find(string $id, array $extend = [], bool $rbac = true, bool $multitenancy = true): object {
				if (isset($this->schemas[$id]) === false) {
					throw new \RuntimeException('no such schema: ' . $id);
				}

				return $this->schemas[$id];
			}

			/**
			 * @param object $schema The schema to store.
			 *
			 * @return object The stored schema.
			 *
			 * @throws \RuntimeException When updates fail.
			 */
			public function update(object $schema): object {
				if ($this->failUpdates === true) {
					throw new \RuntimeException('database is read-only');
				}

				$this->updated[] = $schema;
				return $schema;
			}
		};
	}

	/**
	 * The reconciler over a container that serves `$mapper` (or fails), with no register configured.
	 *
	 * @param object|null $mapper The SchemaMapper, or null for a container that cannot serve it.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return SchemaAnnotationReconciler The reconciler under test.
	 */
	private function reconciler(?object $mapper, ?LoggerInterface $logger = null): SchemaAnnotationReconciler {
		$container = $this->createMock(ContainerInterface::class);
		if ($mapper === null) {
			$container->method('get')->willThrowException(new \RuntimeException('OpenRegister is not installed'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$logger = $logger ?? $this->createMock(LoggerInterface::class);

		return new SchemaAnnotationReconciler(
			container: $container,
			fragments: new RegisterFragmentMerger(),
			logger: $logger,
			slugResolver: new SchemaSlugResolver(appConfig: $appConfig, container: $container, logger: $logger),
		);
	}

	/**
	 * The shipped register declares annotation blocks for case and caseTask.
	 *
	 * @return void
	 */
	public function testTheShippedRegisterDeclaresTheBlocksTheEnginesRead(): void {
		$blocks = self::declaredBlocks();

		$this->assertArrayHasKey('x-openregister-calculations', $blocks['case']);
		$this->assertArrayHasKey('x-openregister-lifecycle', $blocks['caseTask']);
	}

	/**
	 * Missing blocks are merged onto the live configuration, keeping its own keys.
	 *
	 * @return void
	 */
	public function testMissingBlocksAreMergedAndExistingKeysKept(): void {
		$case = self::liveSchema(['objectNameField' => 'title']);
		$task = self::liveSchema(null);
		$mapper = self::mapper(['case' => $case, 'caseTask' => $task]);

		$written = $this->reconciler($mapper)->reconcile();

		$blocks = self::declaredBlocks();
		$this->assertSame(2, $written);
		$this->assertEquals(['objectNameField' => 'title'] + $blocks['case'], $case->configuration);
		$this->assertEquals($blocks['caseTask'], $task->configuration);
		$this->assertSame([$case, $task], $mapper->updated);
	}

	/**
	 * A live configuration that already carries the declared blocks is not written again.
	 *
	 * @return void
	 */
	public function testAnAlreadyReconciledSchemaIsLeftUntouched(): void {
		$blocks = self::declaredBlocks();
		$case = self::liveSchema(['objectNameField' => 'title'] + $blocks['case']);
		$mapper = self::mapper(['case' => $case]);

		$this->assertSame(0, $this->reconciler($mapper)->reconcile());
		$this->assertSame([], $mapper->updated);
	}

	/**
	 * A changed declared block replaces the live one; other live blocks stay.
	 *
	 * @return void
	 */
	public function testAStaleBlockIsReplaced(): void {
		$blocks = self::declaredBlocks();
		$stale = $blocks['caseTask'];
		$stale['x-openregister-lifecycle'] = ['initial' => 'outdated'];
		$task = self::liveSchema($stale + ['x-openregister-aggregations' => ['kept' => true]]);
		$mapper = self::mapper(['caseTask' => $task]);

		$this->assertSame(1, $this->reconciler($mapper)->reconcile());
		$this->assertSame($blocks['caseTask']['x-openregister-lifecycle'], $task->configuration['x-openregister-lifecycle']);
		$this->assertSame(['kept' => true], $task->configuration['x-openregister-aggregations']);
	}

	/**
	 * Without OpenRegister's SchemaMapper nothing is reconciled and the failure is logged.
	 *
	 * @return void
	 */
	public function testAnUnavailableMapperReconcilesNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: Could not access OpenRegister SchemaMapper for declarative reconcile',
			['exception' => 'OpenRegister is not installed']
		);

		$this->assertSame(0, $this->reconciler(null, $logger)->reconcile());
	}

	/**
	 * A write that fails is logged per schema and not counted.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedAndNotCounted(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: Failed to reconcile declarative configuration for schema case',
			['exception' => 'database is read-only']
		);
		$logger->expects($this->once())->method('info')->with(
			'Dossiq: Reconciled declarative schema configuration from register JSON',
			['written' => 0]
		);
		$mapper = self::mapper(['case' => self::liveSchema([])], failUpdates: true);

		$this->assertSame(0, $this->reconciler($mapper, $logger)->reconcile());
	}
}
