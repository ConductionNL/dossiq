<?php

/**
 * SchemaAnnotationReconciler Unit Tests
 *
 * Pins the reconcile of the declarative annotation blocks onto the live
 * schemas: written once when the live configuration differs, kept when it
 * already matches, skipped for a slug the instance does not hold, and a failed
 * write or an unavailable SchemaMapper answering zero with an error logged.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SchemaAnnotationReconciler.
 */
class SchemaAnnotationReconcilerTest extends TestCase {
	/**
	 * Error messages logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * A live schema double holding a configuration.
	 *
	 * @param array<string, mixed> $configuration The live configuration.
	 *
	 * @return object The schema.
	 */
	private function schema(array $configuration): object {
		return new class ($configuration) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $configuration The live configuration.
			 */
			public function __construct(public array $configuration) {
			}//end __construct()

			/**
			 * @return array<string, mixed> The configuration.
			 */
			public function getConfiguration(): array {
				return $this->configuration;
			}//end getConfiguration()

			/**
			 * @param array<string, mixed> $configuration The configuration.
			 *
			 * @return void
			 */
			public function setConfiguration(array $configuration): void {
				$this->configuration = $configuration;
			}//end setConfiguration()
		};
	}//end schema()

	/**
	 * A SchemaMapper double counting updates, failing them when told to.
	 *
	 * @param boolean $fails Whether update() throws.
	 *
	 * @return object The mapper.
	 */
	private function mapper(bool $fails = false): object {
		return new class ($fails) {
			/** @var integer */
			public int $updates = 0;

			/**
			 * Constructor.
			 *
			 * @param boolean $fails Whether update() throws.
			 */
			public function __construct(private bool $fails) {
			}//end __construct()

			/**
			 * @param object $schema The schema.
			 *
			 * @return object The schema.
			 */
			public function update(object $schema): object {
				if ($this->fails === true) {
					throw new RuntimeException('write refused');
				}

				$this->updates++;
				return $schema;
			}//end update()
		};
	}//end mapper()

	/**
	 * The reconciler, resolving only the `case` slug to the given schema.
	 *
	 * @param object|null $mapper The SchemaMapper, or null when the container cannot build it.
	 * @param object      $schema The live `case` schema.
	 *
	 * @return SchemaAnnotationReconciler
	 */
	private function reconciler(?object $mapper, object $schema): SchemaAnnotationReconciler {
		$container = $this->createMock(ContainerInterface::class);
		if ($mapper === null) {
			$container->method('get')->willThrowException(new RuntimeException('no mapper'));
		} else {
			$container->method('get')->willReturn($mapper);
		}

		$fragments = $this->createMock(RegisterFragmentMerger::class);
		$fragments->method('merge')->willReturnCallback(static fn (array $base, string $fragmentDir): array => [$base, []]);

		$slugResolver = $this->createMock(SchemaSlugResolver::class);
		$slugResolver->method('resolve')->willReturnCallback(
			static fn (object $schemaMapper, string $slug): ?object => ($slug === 'case' ? $schema : null)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new SchemaAnnotationReconciler($container, $fragments, $logger, $slugResolver);
	}//end reconciler()

	/**
	 * A live schema missing its blocks is written once and keeps its other keys; a second run writes nothing.
	 *
	 * @return void
	 */
	public function testWritesTheDeclaredBlocksOnceAndKeepsTheRest(): void {
		$schema = $this->schema(['own' => 'kept']);
		$mapper = $this->mapper();
		$reconciler = $this->reconciler($mapper, $schema);

		self::assertSame(1, $reconciler->reconcile());
		self::assertSame(1, $mapper->updates);
		self::assertSame('kept', $schema->configuration['own']);
		self::assertGreaterThan(1, count($schema->configuration));
		self::assertSame('own', array_key_first($schema->configuration));

		self::assertSame(0, $reconciler->reconcile());
		self::assertSame(1, $mapper->updates);
		self::assertSame([], $this->errors);
	}//end testWritesTheDeclaredBlocksOnceAndKeepsTheRest()

	/**
	 * A failed write answers zero and logs the schema it failed for.
	 *
	 * @return void
	 */
	public function testAFailedWriteAnswersZeroAndIsLogged(): void {
		$schema = $this->schema([]);

		self::assertSame(0, $this->reconciler($this->mapper(fails: true), $schema)->reconcile());
		self::assertSame(['Dossiq: Failed to reconcile declarative configuration for schema case'], $this->errors);
	}//end testAFailedWriteAnswersZeroAndIsLogged()

	/**
	 * Without a SchemaMapper nothing is reconciled.
	 *
	 * @return void
	 */
	public function testNoSchemaMapperAnswersZero(): void {
		self::assertSame(0, $this->reconciler(null, $this->schema([]))->reconcile());
		self::assertSame(['Dossiq: Could not access OpenRegister SchemaMapper for declarative reconcile'], $this->errors);
	}//end testNoSchemaMapperAnswersZero()
}//end class
