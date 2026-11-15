<?php

/**
 * Publishing per-status field rules onto the live case schema.
 *
 * `publish()` reads the case schema's lifecycle block, replaces the states of
 * the one case type it was asked about, keeps every other key and every other
 * case type's states, and writes only when the block actually changed. A
 * missing OpenRegister, a missing schema or a refusing mapper answers false
 * and leaves the schema as it was.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector;
use OCA\Dossiq\Service\Status\StatusFieldRuleDeclaration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector
 * @uses \OCA\Dossiq\Service\Status\StatusFieldRuleDeclaration
 * @uses \OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseStateFieldRuleProjectorPublishTest extends TestCase {

	/**
	 * The OpenRegister schema mapper class the projector asks the container for.
	 *
	 * @var string
	 */
	private const MAPPER_CLASS = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * The status types the case type `ct` declares.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const STATUS_TYPES = [
		['id' => 'intake-uuid', 'fieldRules' => [['rule' => 'required', 'field' => 'motivering']]],
		['id' => 'closed-uuid'],
	];

	/**
	 * The recording schema mapper.
	 *
	 * @var object
	 */
	private object $mapper;

	/**
	 * The logger the projector reports to.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the recording mapper and the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = new class {
			/**
			 * The schemas handed to update(), in order.
			 *
			 * @var array<int, object>
			 */
			public array $updated = [];

			/**
			 * Whether update() throws.
			 *
			 * @var bool
			 */
			public bool $refuse = false;

			/**
			 * Record or refuse an update.
			 *
			 * @param object $schema The schema to write.
			 *
			 * @return object
			 */
			public function update(object $schema): object {
				if ($this->refuse === true) {
					throw new RuntimeException('the database refused');
				}

				$this->updated[] = $schema;
				return $schema;
			}//end update()
		};
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * A schema stand-in holding a configuration.
	 *
	 * @param mixed $configuration What getConfiguration() answers.
	 *
	 * @return object
	 */
	private function schema(mixed $configuration): object {
		return new class($configuration) {
			/**
			 * Build the stand-in.
			 *
			 * @param mixed $configuration The configuration it carries.
			 */
			public function __construct(public mixed $configuration) {
			}//end __construct()

			/**
			 * The configuration.
			 *
			 * @return mixed
			 */
			public function getConfiguration(): mixed {
				return $this->configuration;
			}//end getConfiguration()

			/**
			 * Replace the configuration.
			 *
			 * @param array<string, mixed> $configuration The new configuration.
			 *
			 * @return void
			 */
			public function setConfiguration(array $configuration): void {
				$this->configuration = $configuration;
			}//end setConfiguration()
		};
	}//end schema()

	/**
	 * A projector over `ct`'s status types and the given live schema.
	 *
	 * @param object|null     $schema    What the slug resolver answers.
	 * @param \Throwable|null $container A failure the container throws instead of the mapper.
	 *
	 * @return CaseStateFieldRuleProjector
	 */
	private function projector(?object $schema, ?\Throwable $container = null): CaseStateFieldRuleProjector {
		$statusTypes = self::STATUS_TYPES;

		$store = $this->createMock(originalClassName: CaseTypeStore::class);
		$store->method('readCaseType')->willReturn([]);
		$store->method('rowsOfType')->willReturnCallback(
			static fn (string $schemaKey, string $caseTypeId): array => match ($schemaKey) {
				'status_type_schema' => $statusTypes,
				default => [],
			}
		);
		$store->method('rowId')->willReturnCallback(
			static fn (array $row): string => (string)($row['id'] ?? '')
		);

		$containerMock = $this->createMock(originalClassName: ContainerInterface::class);
		if ($container !== null) {
			$containerMock->method('get')->willThrowException($container);
		} else {
			$containerMock->method('get')->with(self::MAPPER_CLASS)->willReturn($this->mapper);
		}

		$slugs = $this->createMock(originalClassName: SchemaSlugResolver::class);
		$slugs->method('resolve')
			->with($this->mapper, CaseStateFieldRuleProjector::CASE_SCHEMA_SLUG)
			->willReturn($schema);

		return new CaseStateFieldRuleProjector(
			store: $store,
			declaration: new StatusFieldRuleDeclaration(),
			roles: new FieldRoleRuleDeclaration(),
			slugs: $slugs,
			container: $containerMock,
			logger: $this->logger,
		);
	}//end projector()

	/**
	 * The case type's states are written beside another case type's, and
	 * every other key of the configuration and the lifecycle is kept.
	 *
	 * @return void
	 */
	public function testTheStatesAreWrittenAndTheRestIsKept(): void {
		$schema = $this->schema(configuration: [
			'other' => 'kept',
			CaseStateFieldRuleProjector::LIFECYCLE_KEY => [
				'initial' => 'intake-uuid',
				'states' => ['foreign-uuid' => ['fields' => ['hidden' => [['fields' => ['x']]]]]],
			],
		]);

		self::assertTrue(condition: $this->projector(schema: $schema)->publish(caseTypeId: 'ct'));

		self::assertSame(expected: [$schema], actual: $this->mapper->updated);
		self::assertSame(
			expected: [
				'other' => 'kept',
				CaseStateFieldRuleProjector::LIFECYCLE_KEY => [
					'initial' => 'intake-uuid',
					'states' => [
						'foreign-uuid' => ['fields' => ['hidden' => [['fields' => ['x']]]]],
						'intake-uuid' => ['fields' => ['required' => [['fields' => ['motivering']]]]],
					],
				],
			],
			actual: $schema->configuration,
		);
	}//end testTheStatesAreWrittenAndTheRestIsKept()

	/**
	 * A schema that already carries exactly these states is not written again.
	 *
	 * @return void
	 */
	public function testAnUnchangedLifecycleIsNotWritten(): void {
		$configuration = [
			CaseStateFieldRuleProjector::LIFECYCLE_KEY => [
				'states' => ['intake-uuid' => ['fields' => ['required' => [['fields' => ['motivering']]]]]],
			],
		];
		$schema = $this->schema(configuration: $configuration);

		self::assertFalse(condition: $this->projector(schema: $schema)->publish(caseTypeId: 'ct'));

		self::assertSame(expected: [], actual: $this->mapper->updated);
		self::assertSame(expected: $configuration, actual: $schema->configuration);
	}//end testAnUnchangedLifecycleIsNotWritten()

	/**
	 * A stale state of this case type is dropped, and an emptied states block
	 * is removed rather than written as an empty list.
	 *
	 * @return void
	 */
	public function testAStaleStateIsDroppedAndAnEmptyBlockRemoved(): void {
		$schema = $this->schema(configuration: [
			CaseStateFieldRuleProjector::LIFECYCLE_KEY => [
				'initial' => 'intake-uuid',
				'states' => [
					'intake-uuid' => ['fields' => ['required' => [['fields' => ['motivering']]]]],
					'closed-uuid' => ['fields' => ['readOnly' => [['fields' => ['besluit']]]]],
				],
			],
		]);

		self::assertTrue(condition: $this->projector(schema: $schema)->publish(caseTypeId: 'ct'));
		self::assertSame(
			expected: ['intake-uuid'],
			actual: array_keys($schema->configuration[CaseStateFieldRuleProjector::LIFECYCLE_KEY]['states']),
		);

		$emptied = $this->schema(configuration: [
			CaseStateFieldRuleProjector::LIFECYCLE_KEY => [
				'initial' => 'closed-uuid',
				'states' => ['closed-uuid' => ['fields' => ['readOnly' => [['fields' => ['besluit']]]]]],
			],
		]);
		$store = $this->createMock(originalClassName: CaseTypeStore::class);
		$store->method('readCaseType')->willReturn([]);
		$store->method('rowsOfType')->willReturnCallback(
			static fn (string $schemaKey, string $caseTypeId): array => match ($schemaKey) {
				'status_type_schema' => [['id' => 'closed-uuid']],
				default => [],
			}
		);
		$store->method('rowId')->willReturnCallback(
			static fn (array $row): string => (string)($row['id'] ?? '')
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($this->mapper);
		$slugs = $this->createMock(originalClassName: SchemaSlugResolver::class);
		$slugs->method('resolve')->willReturn($emptied);

		$projector = new CaseStateFieldRuleProjector(
			store: $store,
			declaration: new StatusFieldRuleDeclaration(),
			roles: new FieldRoleRuleDeclaration(),
			slugs: $slugs,
			container: $container,
			logger: $this->logger,
		);

		self::assertTrue(condition: $projector->publish(caseTypeId: 'ct'));
		self::assertSame(
			expected: [CaseStateFieldRuleProjector::LIFECYCLE_KEY => ['initial' => 'closed-uuid']],
			actual: $emptied->configuration,
		);
	}//end testAStaleStateIsDroppedAndAnEmptyBlockRemoved()

	/**
	 * A configuration or lifecycle that is not an array is read as empty.
	 *
	 * @return void
	 */
	public function testANonArrayConfigurationIsReadAsEmpty(): void {
		$schema = $this->schema(configuration: null);
		self::assertTrue(condition: $this->projector(schema: $schema)->publish(caseTypeId: 'ct'));
		self::assertSame(
			expected: [
				CaseStateFieldRuleProjector::LIFECYCLE_KEY => [
					'states' => ['intake-uuid' => ['fields' => ['required' => [['fields' => ['motivering']]]]]],
				],
			],
			actual: $schema->configuration,
		);

		$broken = $this->schema(configuration: [
			CaseStateFieldRuleProjector::LIFECYCLE_KEY => ['states' => 'not-a-list'],
		]);
		self::assertTrue(condition: $this->projector(schema: $broken)->publish(caseTypeId: 'ct'));
		self::assertSame(
			expected: ['intake-uuid'],
			actual: array_keys($broken->configuration[CaseStateFieldRuleProjector::LIFECYCLE_KEY]['states']),
		);
	}//end testANonArrayConfigurationIsReadAsEmpty()

	/**
	 * No case type, no OpenRegister or no case schema answers false and writes nothing.
	 *
	 * @return void
	 */
	public function testNothingToPublishOntoAnswersFalse(): void {
		self::assertFalse(condition: $this->projector(schema: $this->schema(configuration: []))->publish(caseTypeId: ''));
		self::assertFalse(condition: $this->projector(schema: null)->publish(caseTypeId: 'ct'));

		$this->logger->expects($this->once())->method('info');
		self::assertFalse(
			condition: $this->projector(
				schema: $this->schema(configuration: []),
				container: new RuntimeException('no OpenRegister'),
			)->publish(caseTypeId: 'ct'),
		);

		self::assertSame(expected: [], actual: $this->mapper->updated);
	}//end testNothingToPublishOntoAnswersFalse()

	/**
	 * A refusing mapper answers false and is logged as an error with the case type.
	 *
	 * @return void
	 */
	public function testARefusingMapperAnswersFalseAndLogs(): void {
		$this->mapper->refuse = true;
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Dossiq: could not publish per-status field rules onto the case schema',
				['caseType' => 'ct', 'exception' => 'the database refused'],
			);

		self::assertFalse(condition: $this->projector(schema: $this->schema(configuration: []))->publish(caseTypeId: 'ct'));
	}//end testARefusingMapperAnswersFalseAndLogs()

	/**
	 * A configuration that is a string, and a lifecycle that is a scalar, are
	 * both read as empty and replaced by the projected states.
	 *
	 * @return void
	 */
	public function testAScalarConfigurationOrLifecycleIsReadAsEmpty(): void {
		$expected = [
			CaseStateFieldRuleProjector::LIFECYCLE_KEY => [
				'states' => ['intake-uuid' => ['fields' => ['required' => [['fields' => ['motivering']]]]]],
			],
		];

		$stringy = $this->schema(configuration: 'not-a-configuration');
		self::assertTrue(condition: $this->projector(schema: $stringy)->publish(caseTypeId: 'ct'));
		self::assertSame(expected: $expected, actual: $stringy->configuration);

		$scalar = $this->schema(configuration: [CaseStateFieldRuleProjector::LIFECYCLE_KEY => 'broken']);
		self::assertTrue(condition: $this->projector(schema: $scalar)->publish(caseTypeId: 'ct'));
		self::assertSame(expected: $expected, actual: $scalar->configuration);
	}//end testAScalarConfigurationOrLifecycleIsReadAsEmpty()
}//end class
