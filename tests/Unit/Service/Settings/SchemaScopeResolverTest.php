<?php

/**
 * Dossiq Schema Scope Resolver Test
 *
 * Pins how a guard decides whether an object payload belongs to the schema it
 * guards: by the configured schema id when there is one, else by the live
 * schema resolved from the slug, else by the slug itself, and "undecided"
 * when the payload names no schema at all. Also pins the one-time warning for
 * a missing config key and that the live lookup is made once per slug.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\SchemaScopeResolver;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SchemaScopeResolver.
 */
#[CoversClass(SchemaScopeResolver::class)]
final class SchemaScopeResolverTest extends TestCase {
	/**
	 * Mock settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Mock slug resolver.
	 *
	 * @var SchemaSlugResolver&MockObject
	 */
	private SchemaSlugResolver&MockObject $slugs;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Resolver under test.
	 *
	 * @var SchemaScopeResolver
	 */
	private SchemaScopeResolver $resolver;

	/**
	 * Set up mocks and the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settings  = $this->createMock(SettingsService::class);
		$this->slugs     = $this->createMock(SchemaSlugResolver::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);

		$this->resolver = new SchemaScopeResolver(
			settingsService: $this->settings,
			slugResolver: $this->slugs,
			container: $this->container,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A schema double with the given id.
	 *
	 * @param int|string $id The id.
	 *
	 * @return object
	 */
	private static function schema(int|string $id): object {
		return new class ($id) {
			/**
			 * Build the double.
			 *
			 * @param int|string $id The id.
			 */
			public function __construct(private readonly int|string $id) {
			}

			/**
			 * The id.
			 *
			 * @return int|string
			 */
			public function getId(): int|string {
				return $this->id;
			}
		};
	}//end schema()

	/**
	 * Payloads that name a schema, and the classification under configured id "12".
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: string}>
	 */
	public static function configuredCases(): array {
		return [
			'@self schema id'        => [['@self' => ['schema' => '12']], SchemaScopeResolver::IN_SCOPE],
			'top-level schema id'    => [['schema' => 12], SchemaScopeResolver::IN_SCOPE],
			'@self wins over top'    => [['@self' => ['schema' => '99'], 'schema' => '12'], SchemaScopeResolver::OUT_OF_SCOPE],
			'schema path suffix'     => [['schema' => '/api/schemas/12'], SchemaScopeResolver::IN_SCOPE],
			'suffix without slash'   => [['schema' => '112'], SchemaScopeResolver::OUT_OF_SCOPE],
			'object form, by uuid'   => [['schema' => ['id' => 7, 'uuid' => '12', 'slug' => 'case']], SchemaScopeResolver::IN_SCOPE],
			'other schema'           => [['schema' => ' 13 '], SchemaScopeResolver::OUT_OF_SCOPE],
		];
	}//end configuredCases()

	/**
	 * With a configured schema id, a payload is in scope exactly when one of its schema
	 * identifiers is that id or ends in "/<id>", and the live schema is never looked up.
	 *
	 * @param array<string,mixed> $payload  The object payload.
	 * @param string              $expected The classification.
	 *
	 * @return void
	 */
	#[DataProvider('configuredCases')]
	public function testConfiguredIdDecides(array $payload, string $expected): void {
		$this->settings->expects(self::once())->method('getConfigValue')->with('case_schema')->willReturn(' 12 ');
		$this->container->expects(self::never())->method('get');
		$this->logger->expects(self::never())->method('warning');

		self::assertSame(expected: $expected, actual: $this->resolver->classify(payload: $payload, configKey: 'case_schema', slug: 'case'));
	}//end testConfiguredIdDecides()

	/**
	 * A payload naming no usable schema is undecided, without reading the config.
	 *
	 * @return void
	 */
	public function testPayloadWithoutSchemaIsUndecided(): void {
		$this->settings->expects(self::never())->method('getConfigValue');

		foreach ([[], ['schema' => ''], ['schema' => '   '], ['schema' => ['id' => null, 'nested' => ['x']]], ['@self' => ['schema' => null]]] as $payload) {
			self::assertSame(
				expected: SchemaScopeResolver::UNDECIDED,
				actual: $this->resolver->classify(payload: $payload, configKey: 'case_schema', slug: 'case')
			);
		}
	}//end testPayloadWithoutSchemaIsUndecided()

	/**
	 * Without a configured id, the live schema resolved from the slug decides, and the slug
	 * itself also counts as in scope.
	 *
	 * @return void
	 */
	public function testLiveSchemaDecidesWithoutConfiguredId(): void {
		$mapper = new \stdClass();
		$this->settings->method('getConfigValue')->willReturn('');
		$this->container->expects(self::once())->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);
		$this->slugs->expects(self::once())->method('resolve')->with($mapper, 'case')->willReturn(self::schema(id: 40));

		self::assertSame(expected: SchemaScopeResolver::IN_SCOPE, actual: $this->resolver->classify(payload: ['schema' => '40'], configKey: 'case_schema', slug: 'case'));
		self::assertSame(expected: SchemaScopeResolver::IN_SCOPE, actual: $this->resolver->classify(payload: ['schema' => 'case'], configKey: 'case_schema', slug: 'case'));
		self::assertSame(expected: SchemaScopeResolver::OUT_OF_SCOPE, actual: $this->resolver->classify(payload: ['schema' => '41'], configKey: 'case_schema', slug: 'case'));
	}//end testLiveSchemaDecidesWithoutConfiguredId()

	/**
	 * Without a configured id or live schema, only the slug itself puts a payload in scope;
	 * anything else stays undecided.
	 *
	 * @return void
	 */
	public function testSlugAloneDecidesWhenNothingResolves(): void {
		$this->settings->method('getConfigValue')->willReturn('');
		$this->container->method('get')->willReturn(new \stdClass());
		$this->slugs->method('resolve')->willReturn(null);

		self::assertSame(expected: SchemaScopeResolver::IN_SCOPE, actual: $this->resolver->classify(payload: ['schema' => ['slug' => 'case']], configKey: 'case_schema', slug: 'case'));
		self::assertSame(expected: SchemaScopeResolver::UNDECIDED, actual: $this->resolver->classify(payload: ['schema' => '40'], configKey: 'case_schema', slug: 'case'));
	}//end testSlugAloneDecidesWhenNothingResolves()

	/**
	 * A failing live lookup is logged at debug level and treated as "no live schema".
	 *
	 * @return void
	 */
	public function testFailingLiveLookupFallsBackToTheSlug(): void {
		$this->settings->method('getConfigValue')->willReturn('');
		$this->container->method('get')->willThrowException(new RuntimeException(message: 'no OpenRegister'));
		$this->logger->expects(self::once())
			->method('debug')
			->with('Dossiq: could not resolve a schema slug while scoping a guard', ['slug' => 'case', 'exception' => 'no OpenRegister']);

		self::assertSame(expected: SchemaScopeResolver::UNDECIDED, actual: $this->resolver->classify(payload: ['schema' => '40'], configKey: 'case_schema', slug: 'case'));
	}//end testFailingLiveLookupFallsBackToTheSlug()

	/**
	 * The live schema is looked up once per slug, and the missing key is warned about once per key.
	 *
	 * @return void
	 */
	public function testLookupAndWarningHappenOncePerSlugAndKey(): void {
		$this->settings->method('getConfigValue')->willReturn('');
		$this->container->method('get')->willReturn(new \stdClass());
		$this->slugs->expects(self::exactly(2))
			->method('resolve')
			->willReturnCallback(static fn (object $mapper, string $slug): object => self::schema(id: ($slug === 'case' ? 1 : 2)));
		$warned = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warned): void {
				$warned[] = $context;
			}
		);

		$this->resolver->classify(payload: ['schema' => '1'], configKey: 'case_schema', slug: 'case');
		$this->resolver->classify(payload: ['schema' => '1'], configKey: 'case_schema', slug: 'case');
		$this->resolver->classify(payload: ['schema' => '2'], configKey: 'task_schema', slug: 'task');
		$this->resolver->classify(payload: ['schema' => '2'], configKey: 'task_schema', slug: 'task');

		self::assertSame(
			expected: [['configKey' => 'case_schema', 'slug' => 'case'], ['configKey' => 'task_schema', 'slug' => 'task']],
			actual: $warned
		);
	}//end testLookupAndWarningHappenOncePerSlugAndKey()
}//end class
