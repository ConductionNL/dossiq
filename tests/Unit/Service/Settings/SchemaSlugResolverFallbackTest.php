<?php

/**
 * Dossiq Schema Slug Resolver Fallback Test
 *
 * Covers the failure branches of the register-scoped slug lookup: a scoped
 * lookup that throws, a register that cannot be read, register entries that
 * are not schema ids, a mapper without the scoped lookup, and the one-time
 * read of the register's schema list.
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

use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure branches of register-scoped schema slug resolution.
 */
#[CoversClass(SchemaSlugResolver::class)]
final class SchemaSlugResolverFallbackTest extends TestCase {
	/**
	 * A schema-like object exposing only an id.
	 *
	 * @param integer $id The schema id.
	 *
	 * @return object The schema.
	 */
	private static function schema(int $id): object {
		return new class ($id) {
			/**
			 * @param integer $id The schema id.
			 */
			public function __construct(public int $id) {
			}
		};
	}

	/**
	 * A SchemaMapper double recording every call it receives.
	 *
	 * @param \Closure|null $scoped  The scoped lookup, or null for a mapper without one.
	 * @param array<string, int> $global Instance-wide slug to id answers.
	 *
	 * @return object The mapper.
	 */
	private function mapper(?\Closure $scoped, array $global): object {
		if ($scoped === null) {
			return new class ($global) {
				/** @var array<int, array<int, mixed>> */
				public array $findCalls = [];

				/**
				 * @param array<string, int> $global Instance-wide answers.
				 */
				public function __construct(private array $global) {
				}

				/**
				 * @param string  $id           The slug.
				 * @param array   $extend       Extend list.
				 * @param boolean $rbac         RBAC flag.
				 * @param boolean $multitenancy Multitenancy flag.
				 *
				 * @return object The schema.
				 */
				public function find(string $id, array $extend = [], bool $rbac = true, bool $multitenancy = true): object {
					$this->findCalls[] = [$id, $extend, $rbac, $multitenancy];
					if (isset($this->global[$id]) === false) {
						throw new RuntimeException('no such schema');
					}

					return new class ($this->global[$id]) {
						/**
						 * @param integer $id The schema id.
						 */
						public function __construct(public int $id) {
						}
					};
				}
			};
		}

		return new class ($scoped, $global) {
			/** @var array<int, array<int, mixed>> */
			public array $findCalls = [];

			/** @var array<int, array<int, mixed>> */
			public array $scopedCalls = [];

			/**
			 * @param \Closure           $scoped The scoped lookup.
			 * @param array<string, int> $global Instance-wide answers.
			 */
			public function __construct(private \Closure $scoped, private array $global) {
			}

			/**
			 * @param string  $id           The slug.
			 * @param array   $extend       Extend list.
			 * @param boolean $rbac         RBAC flag.
			 * @param boolean $multitenancy Multitenancy flag.
			 *
			 * @return object The schema.
			 */
			public function find(string $id, array $extend = [], bool $rbac = true, bool $multitenancy = true): object {
				$this->findCalls[] = [$id, $extend, $rbac, $multitenancy];
				if (isset($this->global[$id]) === false) {
					throw new RuntimeException('no such schema');
				}

				return new class ($this->global[$id]) {
					/**
					 * @param integer $id The schema id.
					 */
					public function __construct(public int $id) {
					}
				};
			}

			/**
			 * @param string $slug      The slug.
			 * @param array  $schemaIds The candidate ids.
			 *
			 * @return object|null The schema, or null.
			 */
			public function findBySlugInIds(string $slug, array $schemaIds): ?object {
				$this->scopedCalls[] = [$slug, $schemaIds];
				return ($this->scoped)($slug, $schemaIds);
			}
		};
	}

	/**
	 * An app config answering `register` with the given id.
	 *
	 * @param string $registerId The register id.
	 *
	 * @return IAppConfig The config.
	 */
	private function appConfig(string $registerId): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register' ? $registerId : $default)
		);
		return $appConfig;
	}

	/**
	 * A container whose RegisterMapper returns a register with these schemas.
	 *
	 * @param array<int, mixed> $schemas The register's schema entries.
	 *
	 * @return ContainerInterface The container.
	 */
	private function containerWithRegister(array $schemas): ContainerInterface {
		$registerMapper = new class ($schemas) {
			/**
			 * @param array<int, mixed> $schemas The register's schema entries.
			 */
			public function __construct(private array $schemas) {
			}

			/**
			 * @param string  $id           The register id.
			 * @param boolean $rbac         RBAC flag.
			 * @param boolean $multitenancy Multitenancy flag.
			 *
			 * @return object The register.
			 */
			public function find(string $id, bool $rbac = true, bool $multitenancy = true): object {
				return new class ($this->schemas) {
					/**
					 * @param array<int, mixed> $schemas The schema entries.
					 */
					public function __construct(private array $schemas) {
					}

					/**
					 * @return array<int, mixed> The schema entries.
					 */
					public function getSchemas(): array {
						return $this->schemas;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Db\RegisterMapper')
			->willReturn($registerMapper);
		return $container;
	}

	/**
	 * A scoped lookup that throws falls back to the instance-wide lookup and logs why.
	 *
	 * @return void
	 */
	public function testAThrowingScopedLookupFallsBackAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with(
				'Dossiq: Register-scoped schema lookup failed, falling back',
				['slug' => 'case', 'exception' => 'scoped boom']
			);

		$resolver = new SchemaSlugResolver($this->appConfig('23'), $this->containerWithRegister([173]), $logger);
		$mapper   = $this->mapper(
			static function (): ?object {
				throw new RuntimeException('scoped boom');
			},
			['case' => 52]
		);

		$schema = $resolver->resolve($mapper, 'case');

		$this->assertNotNull($schema);
		$this->assertSame(52, $schema->id);
		$this->assertSame([['case', [], false, false]], $mapper->findCalls);
	}

	/**
	 * A register that cannot be read leaves the lookup unscoped and is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterLeavesTheLookupUnscoped(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->willThrowException(new RuntimeException('no openregister'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with(
				'Dossiq: Could not read the register schema list for scoping',
				['register' => '23', 'exception' => 'no openregister']
			);

		$resolver = new SchemaSlugResolver($this->appConfig('23'), $container, $logger);
		$mapper   = $this->mapper(static fn (): ?object => self::schema(173), ['case' => 52]);

		$schema = $resolver->resolve($mapper, 'case');

		$this->assertSame(52, $schema->id);
		$this->assertSame([], $mapper->scopedCalls, 'Without register ids the scoped lookup must not run.');
		// The failed read is remembered: a second resolve does not ask the container again.
		$this->assertSame(52, $resolver->resolve($mapper, 'case')->id);
	}

	/**
	 * Only positive numeric register entries are passed on as schema ids, as integers.
	 *
	 * @return void
	 */
	public function testOnlyPositiveNumericEntriesBecomeSchemaIds(): void {
		$resolver = new SchemaSlugResolver(
			$this->appConfig('23'),
			$this->containerWithRegister(['173', 'task', 0, '-4', 166, '0', null, '12']),
			$this->createMock(LoggerInterface::class)
		);
		$mapper = $this->mapper(static fn (): ?object => self::schema(173), []);

		$schema = $resolver->resolve($mapper, 'caseTask');

		$this->assertSame(173, $schema->id);
		$this->assertSame([['caseTask', [173, 166, 12]]], $mapper->scopedCalls);
		$this->assertSame([], $mapper->findCalls, 'A scoped hit must not consult the instance-wide lookup.');
	}

	/**
	 * A register with no usable schema ids skips the scoped lookup altogether.
	 *
	 * @return void
	 */
	public function testARegisterWithoutUsableIdsSkipsTheScopedLookup(): void {
		$resolver = new SchemaSlugResolver(
			$this->appConfig('23'),
			$this->containerWithRegister(['task', 0]),
			$this->createMock(LoggerInterface::class)
		);
		$mapper = $this->mapper(static fn (): ?object => self::schema(173), ['caseTask' => 52]);

		$this->assertSame(52, $resolver->resolve($mapper, 'caseTask')->id);
		$this->assertSame([], $mapper->scopedCalls);
	}

	/**
	 * The register's schema list is read once per resolver, however many slugs resolve.
	 *
	 * @return void
	 */
	public function testTheRegisterIsReadOncePerResolver(): void {
		$resolver = new SchemaSlugResolver(
			$this->appConfig('23'),
			$this->containerWithRegister([173, 174]),
			$this->createMock(LoggerInterface::class)
		);
		$mapper = $this->mapper(
			static fn (string $slug): ?object => ($slug === 'case' ? self::schema(174) : null),
			['status' => 9]
		);

		$this->assertSame(174, $resolver->resolve($mapper, 'case')->id);
		$this->assertSame(9, $resolver->resolve($mapper, 'status')->id);
		$this->assertNull($resolver->resolve($mapper, 'missing'));
		$this->assertCount(3, $mapper->scopedCalls);
	}

	/**
	 * A mapper without the scoped lookup uses the instance-wide one without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testAMapperWithoutTheScopedLookupResolvesInstanceWide(): void {
		$resolver = new SchemaSlugResolver(
			$this->appConfig('23'),
			$this->containerWithRegister([173]),
			$this->createMock(LoggerInterface::class)
		);
		$mapper = $this->mapper(null, ['caseTask' => 52]);

		$this->assertSame(52, $resolver->resolve($mapper, 'caseTask')->id);
		$this->assertSame([['caseTask', [], false, false]], $mapper->findCalls);
	}
}
