<?php

/**
 * The write half of CaseFieldRoleProjector: what lands on the case schema.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseFieldRoleProjector;
use OCA\Dossiq\Service\Access\FieldRoleLedger;
use OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Publishing writes a case type's rules into the ledger and the property
 * blocks in one schema update; nothing changed, no schema or no OpenRegister
 * writes nothing; a failing write is logged and answered false.
 *
 * @covers \OCA\Dossiq\Service\Access\CaseFieldRoleProjector
 * @uses   \OCA\Dossiq\Service\Access\FieldRoleLedger
 * @uses   \OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class CaseFieldRoleProjectorWriteTest extends TestCase {

	/**
	 * Every logger call: level, message, context.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * The case types readCaseType was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $read = [];

	/**
	 * A case schema as OpenRegister's entity carries it.
	 *
	 * @param mixed $configuration What getConfiguration answers.
	 * @param mixed $properties    What getProperties answers.
	 *
	 * @return object The schema.
	 */
	private static function schema(mixed $configuration, mixed $properties): object {
		return new class ($configuration, $properties) {

			/**
			 * Constructor.
			 *
			 * @param mixed $configuration The configuration.
			 * @param mixed $properties    The properties.
			 */
			public function __construct(public mixed $configuration, public mixed $properties) {
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
			 * The properties.
			 *
			 * @return mixed
			 */
			public function getProperties(): mixed {
				return $this->properties;
			}//end getProperties()

			/**
			 * Set the configuration.
			 *
			 * @param array<string, mixed> $configuration The configuration.
			 *
			 * @return void
			 */
			public function setConfiguration(array $configuration): void {
				$this->configuration = $configuration;
			}//end setConfiguration()

			/**
			 * Set the properties.
			 *
			 * @param array<string, mixed> $properties The properties.
			 *
			 * @return void
			 */
			public function setProperties(array $properties): void {
				$this->properties = $properties;
			}//end setProperties()
		};

	}//end schema()

	/**
	 * A schema mapper that records its updates, or throws on them.
	 *
	 * @param \Throwable|null $fails What update throws.
	 *
	 * @return object The mapper.
	 */
	private static function mapper(?\Throwable $fails = null): object {
		return new class ($fails) {

			/**
			 * The schemas updated.
			 *
			 * @var array<int, object>
			 */
			public array $updated = [];

			/**
			 * Constructor.
			 *
			 * @param \Throwable|null $fails What update throws.
			 */
			public function __construct(private readonly ?\Throwable $fails) {
			}//end __construct()

			/**
			 * Store the schema.
			 *
			 * @param object $schema The schema.
			 *
			 * @return object
			 */
			public function update(object $schema): object {
				if ($this->fails !== null) {
					throw $this->fails;
				}

				$this->updated[] = $schema;
				return $schema;
			}//end update()
		};

	}//end mapper()

	/**
	 * The projector over a container holding $mapper and a resolver finding $schema.
	 *
	 * @param mixed                $mapper   What the container holds; false = no SchemaMapper.
	 * @param object|null          $schema   What the slug resolver finds.
	 * @param array<string, mixed> $caseType The stored case type.
	 *
	 * @return CaseFieldRoleProjector The projector.
	 */
	private function projector(mixed $mapper, ?object $schema, array $caseType = []): CaseFieldRoleProjector {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('readCaseType')->willReturnCallback(
			function (string $caseTypeId) use ($caseType): array {
				$this->read[] = $caseTypeId;
				return $caseType;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper !== false);
		$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);
		$slugs = $this->createMock(SchemaSlugResolver::class);
		$slugs->method('resolve')->willReturnCallback(
			static function (object $schemaMapper, string $slug) use ($mapper, $schema): ?object {
				return ($schemaMapper === $mapper && $slug === 'case') ? $schema : null;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$declaration = new FieldRoleRuleDeclaration();

		return new CaseFieldRoleProjector(
			store: $store,
			declaration: $declaration,
			ledger: new FieldRoleLedger(declaration: $declaration),
			slugs: $slugs,
			fragments: new RegisterFragmentMerger(),
			container: $container,
			logger: $logger,
		);

	}//end projector()

	/**
	 * A case type hiding one field from all but one group.
	 *
	 * @return array<string, mixed> The case type.
	 */
	private static function hidingCaseType(): array {
		return [
			'id' => 'ct-1',
			'fieldRoleRules' => [['field' => 'zzTestNotes', 'rule' => 'hidden', 'heldBy' => ['dossiq-quality']]],
		];

	}//end hidingCaseType()

	/**
	 * A publish writes the case type's block into the ledger and onto the
	 * property, in one update, keeping the other configuration.
	 *
	 * @return void
	 */
	public function testAPublishWritesTheLedgerAndThePropertyInOneUpdate(): void {
		$mapper = self::mapper();
		$schema = self::schema(configuration: ['other' => 'kept'], properties: ['zzTestNotes' => ['type' => 'string']]);

		$this->assertTrue($this->projector(mapper: $mapper, schema: $schema, caseType: self::hidingCaseType())->publish(caseTypeId: 'ct-1'));

		$block = ['update' => [['group' => 'dossiq-quality']], 'read' => [['group' => 'dossiq-quality']]];
		$this->assertSame(['ct-1'], $this->read);
		$this->assertSame([$schema], $mapper->updated);
		$this->assertSame(['other' => 'kept', 'x-dossiq-field-roles' => ['ct-1' => ['zzTestNotes' => $block]]], $schema->configuration);
		$this->assertSame(['type' => 'string', 'authorization' => $block], $schema->properties['zzTestNotes']);
		$this->assertSame([], $this->logged);
	}//end testAPublishWritesTheLedgerAndThePropertyInOneUpdate()

	/**
	 * Publishing what is already published writes nothing and answers false.
	 *
	 * @return void
	 */
	public function testARepeatedPublishWritesNothing(): void {
		$mapper = self::mapper();
		$schema = self::schema(configuration: [], properties: ['zzTestNotes' => ['type' => 'string']]);
		$projector = $this->projector(mapper: $mapper, schema: $schema, caseType: self::hidingCaseType());

		$this->assertTrue($projector->publish(caseTypeId: 'ct-1'));
		$this->assertFalse($projector->publish(caseTypeId: 'ct-1'));
		$this->assertCount(1, $mapper->updated);
	}//end testARepeatedPublishWritesNothing()

	/**
	 * Withdrawing the last rule drops the ledger key from the configuration.
	 *
	 * @return void
	 */
	public function testWithdrawingTheLastRuleDropsTheLedgerKey(): void {
		$block = ['update' => [['group' => 'dossiq-quality']], 'read' => [['group' => 'dossiq-quality']]];
		$mapper = self::mapper();
		$schema = self::schema(
			configuration: ['other' => 'kept', 'x-dossiq-field-roles' => ['ct-1' => ['zzTestNotes' => $block]]],
			properties: ['zzTestNotes' => ['type' => 'string', 'authorization' => $block]]
		);

		$this->assertTrue($this->projector(mapper: $mapper, schema: $schema, caseType: ['id' => 'ct-1'])->publish(caseTypeId: 'ct-1'));

		$this->assertSame(['other' => 'kept'], $schema->configuration);
		$this->assertSame(['type' => 'string'], $schema->properties['zzTestNotes']);
	}//end testWithdrawingTheLastRuleDropsTheLedgerKey()

	/**
	 * A reapply republishes the stored ledger without reading a case type,
	 * restoring a block somebody removed from the property.
	 *
	 * @return void
	 */
	public function testAReapplyRestoresTheLedgerWithoutReadingACaseType(): void {
		$block = ['update' => [['group' => 'dossiq-quality']]];
		$mapper = self::mapper();
		$schema = self::schema(
			configuration: ['x-dossiq-field-roles' => ['ct-1' => ['zzTestNotes' => $block]]],
			properties: ['zzTestNotes' => ['type' => 'string']]
		);

		$this->assertTrue($this->projector(mapper: $mapper, schema: $schema)->reapply());

		$this->assertSame([], $this->read);
		$this->assertSame(['x-dossiq-field-roles' => ['ct-1' => ['zzTestNotes' => $block]]], $schema->configuration);
		$this->assertSame(['type' => 'string', 'authorization' => $block], $schema->properties['zzTestNotes']);
	}//end testAReapplyRestoresTheLedgerWithoutReadingACaseType()

	/**
	 * A schema whose configuration and properties are not arrays reads as empty.
	 *
	 * @return void
	 */
	public function testNonArrayConfigurationAndPropertiesReadAsEmpty(): void {
		$mapper = self::mapper();
		$schema = self::schema(configuration: null, properties: 'broken');

		$this->assertFalse($this->projector(mapper: $mapper, schema: $schema)->reapply());
		$this->assertSame([], $mapper->updated);
	}//end testNonArrayConfigurationAndPropertiesReadAsEmpty()

	/**
	 * An empty case type id is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnEmptyCaseTypeIdPublishesNothing(): void {
		$mapper = self::mapper();

		$this->assertFalse($this->projector(mapper: $mapper, schema: self::schema(configuration: [], properties: []))->publish(caseTypeId: ''));
		$this->assertSame([], $this->read);
		$this->assertSame([], $mapper->updated);
	}//end testAnEmptyCaseTypeIdPublishesNothing()

	/**
	 * Without OpenRegister's SchemaMapper nothing is written and that is
	 * logged as information, not as an error.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsPublished(): void {
		$this->assertFalse($this->projector(mapper: false, schema: null, caseType: self::hidingCaseType())->publish(caseTypeId: 'ct-1'));

		$this->assertSame(
			[['info', 'Dossiq: no OpenRegister SchemaMapper, so the per-role field rules were not published', []]],
			$this->logged
		);
	}//end testWithoutOpenRegisterNothingIsPublished()

	/**
	 * A container entry that is not an object, or no case schema, writes nothing.
	 *
	 * @return void
	 */
	public function testNoMapperObjectOrNoCaseSchemaWritesNothing(): void {
		$this->assertFalse($this->projector(mapper: 'not a mapper', schema: null, caseType: self::hidingCaseType())->publish(caseTypeId: 'ct-1'));

		$mapper = self::mapper();
		$this->assertFalse($this->projector(mapper: $mapper, schema: null, caseType: self::hidingCaseType())->publish(caseTypeId: 'ct-1'));
		$this->assertSame([], $mapper->updated);
		$this->assertSame([], $this->logged);
	}//end testNoMapperObjectOrNoCaseSchemaWritesNothing()

	/**
	 * A failing update is logged as an error naming the case type and answers false.
	 *
	 * @return void
	 */
	public function testAFailingUpdateIsLoggedAndFalse(): void {
		$schema = self::schema(configuration: [], properties: ['zzTestNotes' => ['type' => 'string']]);

		$this->assertFalse($this->projector(mapper: self::mapper(fails: new RuntimeException('read-only db')), schema: $schema, caseType: self::hidingCaseType())->publish(caseTypeId: 'ct-1'));

		$this->assertSame(
			[['error', 'Dossiq: could not publish the per-role field rules onto the case schema', ['caseType' => 'ct-1', 'exception' => 'read-only db']]],
			$this->logged
		);
	}//end testAFailingUpdateIsLoggedAndFalse()
}//end class
