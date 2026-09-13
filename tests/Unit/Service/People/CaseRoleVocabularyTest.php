<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The instance's role types become the roles a person can hold on a case.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
 */
class CaseRoleVocabularyTest extends TestCase {

	/**
	 * The configured register and schemas.
	 */
	private const CONFIG = [
		'register' => 'dossiq',
		'case_schema' => 'case',
		'role_type_schema' => 'roleType',
	];

	/**
	 * The doubled object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The doubled case schema.
	 *
	 * @var object
	 */
	private object $schema;

	/**
	 * Schemas the mapper updated.
	 *
	 * @var array<int, object>
	 */
	private array $updated = [];

	/**
	 * The service under test.
	 *
	 * @var CaseRoleVocabulary
	 */
	private CaseRoleVocabulary $vocabulary;

	/**
	 * Build the service on a doubled object service and schema mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/**
			 * Search answers per schema.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $answers = [];

			/**
			 * Rows matching the filters.
			 *
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return ($this->answers[$schema] ?? []);
			}
		};

		$this->schema = new class {
			/**
			 * The stored configuration.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $configuration = null;

			/**
			 * Whether this schema's OpenRegister knows the key at all.
			 *
			 * @var bool
			 */
			public bool $dropsLinkRoles = false;

			/**
			 * The configuration.
			 *
			 * @return array<string, mixed>|null The configuration.
			 */
			public function getConfiguration(): ?array {
				return $this->configuration;
			}

			/**
			 * Store the configuration.
			 *
			 * @param array<string, mixed>|null $configuration The configuration.
			 *
			 * @return void
			 */
			public function setConfiguration(?array $configuration): void {
				if ($this->dropsLinkRoles === true && is_array($configuration) === true) {
					unset($configuration['linkRoles']);
				}

				$this->configuration = $configuration;
			}
		};

		$mapper = new class($this->schema, $this->updated) {
			/**
			 * @param object $schema The case schema.
			 * @param array<int, object> $updated Where updates are recorded.
			 */
			public function __construct(private object $schema, public array &$updated) {
			}

			/**
			 * One schema.
			 *
			 * @param string|int $id The schema.
			 *
			 * @return object The schema.
			 */
			public function find(string|int $id): object {
				return $this->schema;
			}

			/**
			 * Store a schema.
			 *
			 * @param object $schema The schema.
			 *
			 * @return object The schema.
			 */
			public function update(object $schema): object {
				$this->updated[] = $schema;
				return $schema;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);

		$settings->method('getOpenRegisterClass')->willReturn($mapper);

		$this->vocabulary = new CaseRoleVocabulary(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Every published role type becomes one entry, keyed by uuid and ordered by label.
	 *
	 * @return void
	 */
	public function testTheRoleTypesBecomeTheVocabulary(): void {
		$this->objects->answers['roleType'] = [
			['@self' => ['id' => 'rt-2'], 'name' => 'Behandelaar', 'description' => 'Doet het werk'],
			['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur'],
			['@self' => ['id' => 'rt-3'], 'name' => ''],
			['name' => 'No uuid, no entry'],
		];

		$count = $this->vocabulary->sync();

		$this->assertSame(expected: 3, actual: $count);
		$stored = $this->schema->configuration['linkRoles'];
		$this->assertSame(expected: ['rt-1', 'rt-2', 'rt-3'], actual: array_column($stored, 'key'));
		$this->assertSame(expected: 'Adviseur', actual: $stored[0]['label']);
		$this->assertSame(expected: 'Doet het werk', actual: $stored[1]['description']);
		// A role type with no name labels itself by its uuid rather than vanishing.
		$this->assertSame(expected: 'rt-3', actual: $stored[2]['label']);
	}//end testTheRoleTypesBecomeTheVocabulary()

	/**
	 * A vocabulary that has not changed is not written again.
	 *
	 * @return void
	 */
	public function testAnUnchangedVocabularyIsNotWritten(): void {
		$this->objects->answers['roleType'] = [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']];

		$this->vocabulary->sync();
		$writes = count($this->updated);
		$this->vocabulary->sync();

		$this->assertSame(expected: $writes, actual: count($this->updated), message: 'the second sync wrote nothing');
	}//end testAnUnchangedVocabularyIsNotWritten()

	/**
	 * The rest of the schema's configuration survives the write.
	 *
	 * @return void
	 */
	public function testTheRestOfTheConfigurationSurvives(): void {
		$this->schema->configuration = ['allowFiles' => true, 'linkedTypes' => ['contacts']];
		$this->objects->answers['roleType'] = [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']];

		$this->vocabulary->sync();

		$this->assertTrue(condition: $this->schema->configuration['allowFiles']);
		$this->assertSame(expected: ['contacts'], actual: $this->schema->configuration['linkedTypes']);
		$this->assertCount(expectedCount: 1, haystack: $this->schema->configuration['linkRoles']);
	}//end testTheRestOfTheConfigurationSurvives()

	/**
	 * No OpenRegister, no vocabulary, and no exception either.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSyncSaysSo(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$vocabulary = new CaseRoleVocabulary(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: -1, actual: $vocabulary->sync());
	}//end testWithoutOpenRegisterTheSyncSaysSo()

	/**
	 * A schema that silently drops the key is reported, not called a success.
	 *
	 * OpenRegister drops a configuration key its own vocabulary does not know,
	 * which is exactly how the documented `x-contactRoles` never did anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 */
	public function testAVocabularyTheSchemaDropsIsReported(): void {
		$this->objects->answers['roleType'] = [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']];
		// An OpenRegister that does not know the key keeps everything else and
		// loses this one.
		$this->schema->dropsLinkRoles = true;

		$this->assertSame(expected: -1, actual: $this->vocabulary->sync());
	}//end testAVocabularyTheSchemaDropsIsReported()
}
