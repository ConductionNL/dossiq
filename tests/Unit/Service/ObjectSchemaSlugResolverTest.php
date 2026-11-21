<?php

/**
 * ObjectSchemaSlugResolver Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turning the schema value of an OpenRegister payload into a slug.
 *
 * @covers \OCA\Dossiq\Service\ObjectSchemaSlugResolver
 */
class ObjectSchemaSlugResolverTest extends TestCase {

	/**
	 * The mocked DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * A schema mapper whose find() answers the given slug and records its calls.
	 *
	 * @param string $slug The slug every schema resolves to.
	 *
	 * @return object The mapper.
	 */
	private function mapperAnswering(string $slug): object {
		return new class($slug) {
			/**
			 * Every find() call, as its arguments.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param string $slug The slug to answer.
			 */
			public function __construct(private string $slug) {
			}

			/**
			 * Find a schema.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return object The schema.
			 */
			public function find(mixed ...$args): object {
				$this->calls[] = $args;
				$slug = $this->slug;

				return new class($slug) {
					/**
					 * Constructor.
					 *
					 * @param string $slug The slug.
					 */
					public function __construct(private string $slug) {
					}

					/**
					 * The slug.
					 *
					 * @return string The slug.
					 */
					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		};
	}//end mapperAnswering()

	/**
	 * Empty and whitespace values resolve to nothing without touching the container.
	 *
	 * @return void
	 */
	public function testEmptySchemaResolvesToEmptyString(): void {
		$this->container->expects($this->never())->method('get');
		$resolver = new ObjectSchemaSlugResolver(container: $this->container, logger: $this->logger);

		$this->assertSame(expected: '', actual: $resolver->resolve(schema: ''));
		$this->assertSame(expected: '', actual: $resolver->resolve(schema: '   '));
	}//end testEmptySchemaResolvesToEmptyString()

	/**
	 * A non-numeric value is already a slug and is passed through trimmed.
	 *
	 * @return void
	 */
	public function testSlugPassesStraightThrough(): void {
		$this->container->expects($this->never())->method('get');
		$resolver = new ObjectSchemaSlugResolver(container: $this->container, logger: $this->logger);

		$this->assertSame(expected: 'bezwaar', actual: $resolver->resolve(schema: ' bezwaar '));
	}//end testSlugPassesStraightThrough()

	/**
	 * A numeric id is looked up once, without RBAC or multitenancy, then memoised.
	 *
	 * @return void
	 */
	public function testNumericIdIsLookedUpOnceAndMemoised(): void {
		$mapper = $this->mapperAnswering(slug: 'case');
		$this->container->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Db\SchemaMapper')
			->willReturn($mapper);
		$resolver = new ObjectSchemaSlugResolver(container: $this->container, logger: $this->logger);

		$this->assertSame(expected: 'case', actual: $resolver->resolve(schema: '12'));
		$this->assertSame(expected: 'case', actual: $resolver->resolve(schema: '12'));
		$this->assertSame(expected: [['12', [], false, false]], actual: $mapper->calls);
	}//end testNumericIdIsLookedUpOnceAndMemoised()

	/**
	 * A failed lookup logs at debug, answers empty and memoises the miss.
	 *
	 * @return void
	 */
	public function testFailedLookupAnswersEmptyAndMemoisesTheMiss(): void {
		$this->container->expects($this->once())
			->method('get')
			->willThrowException(new \RuntimeException('no openregister'));
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'Dossiq: could not resolve schema slug for id 7',
				['exception' => 'no openregister']
			);
		$resolver = new ObjectSchemaSlugResolver(container: $this->container, logger: $this->logger);

		$this->assertSame(expected: '', actual: $resolver->resolve(schema: '7'));
		$this->assertSame(expected: '', actual: $resolver->resolve(schema: '7'));
	}//end testFailedLookupAnswersEmptyAndMemoisesTheMiss()

	/**
	 * An extended payload's schema array answers its slug, else its id.
	 *
	 * @return void
	 */
	public function testPayloadWithExtendedSchemaArray(): void {
		$this->container->method('get')->willReturn($this->mapperAnswering(slug: 'from-id'));
		$resolver = new ObjectSchemaSlugResolver(container: $this->container, logger: $this->logger);

		$this->assertSame(expected: 'advice', actual: $resolver->resolveFromPayload(payload: ['@self' => ['schema' => ['slug' => 'advice', 'id' => 3]]]));
		$this->assertSame(expected: 'from-id', actual: $resolver->resolveFromPayload(payload: ['@self' => ['schema' => ['id' => 3]]]));
		$this->assertSame(expected: '', actual: $resolver->resolveFromPayload(payload: ['@self' => ['schema' => []]]));
	}//end testPayloadWithExtendedSchemaArray()

	/**
	 * A scalar schema in @self wins over a top-level schema key.
	 *
	 * @return void
	 */
	public function testPayloadWithScalarSelfSchema(): void {
		$resolver = new ObjectSchemaSlugResolver(container: $this->container, logger: $this->logger);

		$this->assertSame(expected: 'decision', actual: $resolver->resolveFromPayload(payload: ['@self' => ['schema' => 'decision'], 'schema' => 'other']));
	}//end testPayloadWithScalarSelfSchema()

	/**
	 * Without a usable @self the top-level schema key is read; anything else answers empty.
	 *
	 * @return void
	 */
	public function testPayloadFallsBackToTopLevelSchema(): void {
		$resolver = new ObjectSchemaSlugResolver(container: $this->container, logger: $this->logger);

		$this->assertSame(expected: 'task', actual: $resolver->resolveFromPayload(payload: ['schema' => 'task']));
		$this->assertSame(expected: 'task', actual: $resolver->resolveFromPayload(payload: ['@self' => ['schema' => null], 'schema' => 'task']));
		$this->assertSame(expected: 'task', actual: $resolver->resolveFromPayload(payload: ['@self' => 'flat', 'schema' => 'task']));
		$this->assertSame(expected: '', actual: $resolver->resolveFromPayload(payload: ['schema' => ['nested']]));
		$this->assertSame(expected: '', actual: $resolver->resolveFromPayload(payload: []));
	}//end testPayloadFallsBackToTopLevelSchema()
}//end class
