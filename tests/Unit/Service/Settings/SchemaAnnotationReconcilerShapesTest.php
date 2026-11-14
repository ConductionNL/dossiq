<?php
/**
 * SchemaAnnotationReconciler Shape Tests
 *
 * The reconciler reads the register JSON with its fragments folded in, so a
 * fragment can hand it shapes the monolith never has: a `schemas` section that
 * is not a map, a schema entry that is not an object, a schema without a slug
 * or with a configuration that is not an object, and a live schema whose
 * stored configuration is not an array. Each is skipped or read as empty
 * rather than breaking the run. Inside OpenRegister the write is elevated
 * through its SystemOperationContext, and a refusal there answers zero.
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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the shapes SchemaAnnotationReconciler skips or reads as empty.
 */
class SchemaAnnotationReconcilerShapesTest extends TestCase {

	/**
	 * The annotation blocks a schema declares in these tests.
	 *
	 * @var array<string, mixed>
	 */
	private const DECLARED = ['x-openregister-calculations' => ['daysOpen' => ['expression' => 'now - created']]];

	/**
	 * Slugs the resolver was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $resolved = [];

	/**
	 * Error messages logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * A live schema double whose stored configuration may be anything.
	 *
	 * @param mixed $configuration The stored configuration.
	 *
	 * @return object The schema.
	 */
	private function schema(mixed $configuration): object {
		return new class ($configuration) {
			/**
			 * Constructor.
			 *
			 * @param mixed $configuration The stored configuration.
			 */
			public function __construct(public mixed $configuration) {
			}//end __construct()

			/**
			 * @return mixed The configuration.
			 */
			public function getConfiguration(): mixed {
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
	 * A SchemaMapper double counting updates.
	 *
	 * @return object The mapper.
	 */
	private function mapper(): object {
		return new class {
			/** @var integer */
			public int $updates = 0;

			/**
			 * @param object $schema The schema.
			 *
			 * @return object The schema.
			 */
			public function update(object $schema): object {
				$this->updates++;
				return $schema;
			}//end update()
		};
	}//end mapper()

	/**
	 * The reconciler over a merged register whose `schemas` section is given,
	 * resolving every slug to the given live schema.
	 *
	 * @param mixed  $schemas The merged `components.schemas` section.
	 * @param object $mapper  The SchemaMapper.
	 * @param object $schema  The live schema every slug resolves to.
	 *
	 * @return SchemaAnnotationReconciler
	 */
	private function reconciler(mixed $schemas, object $mapper, object $schema): SchemaAnnotationReconciler {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$fragments = $this->createMock(RegisterFragmentMerger::class);
		$fragments->method('merge')->willReturn([['components' => ['schemas' => $schemas]], '']);

		$slugResolver = $this->createMock(SchemaSlugResolver::class);
		$slugResolver->method('resolve')->willReturnCallback(
			function (object $schemaMapper, string $slug) use ($schema): object {
				$this->resolved[] = $slug;
				return $schema;
			}
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
	 * A `schemas` section that is not a map reconciles nothing.
	 *
	 * @return void
	 */
	public function testASchemasSectionThatIsNotAMapReconcilesNothing(): void {
		$mapper = $this->mapper();

		self::assertSame(0, $this->reconciler('not a map', $mapper, $this->schema([]))->reconcile());
		self::assertSame([], $this->resolved);
		self::assertSame(0, $mapper->updates);
	}//end testASchemasSectionThatIsNotAMapReconcilesNothing()

	/**
	 * Entries that are not objects, have no slug, have no configuration
	 * object or declare no annotation block are skipped before any lookup;
	 * a keyed entry without a slug takes its key.
	 *
	 * @return void
	 */
	public function testMalformedEntriesAreSkippedBeforeAnyLookup(): void {
		$mapper = $this->mapper();
		$schemas = [
			'notAnObject' => 'case',
			0 => ['configuration' => self::DECLARED],
			'badConfig' => ['slug' => 'badConfig', 'configuration' => 'calculations'],
			'noBlocks' => ['configuration' => ['other' => true]],
			'keyed' => ['configuration' => self::DECLARED],
		];

		self::assertSame(1, $this->reconciler($schemas, $mapper, $this->schema([]))->reconcile());
		self::assertSame(['keyed'], $this->resolved);
		self::assertSame(1, $mapper->updates);
	}//end testMalformedEntriesAreSkippedBeforeAnyLookup()

	/**
	 * A live configuration that is not an array is read as empty, so the
	 * declared blocks are written as the whole configuration.
	 *
	 * @return void
	 */
	public function testAStoredConfigurationThatIsNotAnArrayIsReadAsEmpty(): void {
		$schema = $this->schema('corrupt');
		$mapper = $this->mapper();

		self::assertSame(1, $this->reconciler(['case' => ['configuration' => self::DECLARED]], $mapper, $schema)->reconcile());
		self::assertSame(self::DECLARED, $schema->configuration);
	}//end testAStoredConfigurationThatIsNotAnArrayIsReadAsEmpty()

	/**
	 * Inside OpenRegister the write runs through its SystemOperationContext.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testInsideOpenRegisterTheWriteIsElevated(): void {
		self::defineSystemOperationContext(refuses: false);
		$schema = $this->schema([]);
		$mapper = $this->mapper();

		self::assertSame(1, $this->reconciler(['case' => ['configuration' => self::DECLARED]], $mapper, $schema)->reconcile());
		self::assertSame(1, \OCA\OpenRegister\Service\SystemOperationContext::$runs);
		self::assertSame(1, $mapper->updates);
		self::assertSame(self::DECLARED, $schema->configuration);
	}//end testInsideOpenRegisterTheWriteIsElevated()

	/**
	 * A refusal inside the elevated context answers zero and names the schema.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testARefusedElevatedWriteAnswersZero(): void {
		self::defineSystemOperationContext(refuses: true);
		$mapper = $this->mapper();

		self::assertSame(0, $this->reconciler(['case' => ['configuration' => self::DECLARED]], $mapper, $this->schema([]))->reconcile());
		self::assertSame(0, $mapper->updates);
		self::assertSame(['Dossiq: Failed to reconcile declarative configuration for schema case'], $this->errors);
	}//end testARefusedElevatedWriteAnswersZero()

	/**
	 * Define a stand-in for OpenRegister's SystemOperationContext in this process.
	 *
	 * @param boolean $refuses Whether run() throws instead of running the write.
	 *
	 * @return void
	 */
	private static function defineSystemOperationContext(bool $refuses): void {
		self::assertFalse(class_exists('OCA\\OpenRegister\\Service\\SystemOperationContext'), 'this process must start without it');
		eval(
			'namespace OCA\\OpenRegister\\Service;'
			. 'final class SystemOperationContext {'
			. 'public static int $runs = 0;'
			. 'public static bool $refuses = false;'
			. 'public static function run(callable $write): mixed {'
			. 'if (self::$refuses === true) { throw new \\RuntimeException("refused"); }'
			. 'self::$runs++; return $write(); } }'
		);
		\OCA\OpenRegister\Service\SystemOperationContext::$refuses = $refuses;
	}//end defineSystemOperationContext()
}//end class
