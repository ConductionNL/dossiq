<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\People\PartyVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The instance's role types become the roles a person can hold on a case.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
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
			parties: $this->partyVocabulary(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The party vocabulary on an IL10N that answers its own source string, so
	 * the assertions below name the English labels the app ships.
	 *
	 * @return PartyVocabulary The vocabulary.
	 */
	private function partyVocabulary(): PartyVocabulary {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new PartyVocabulary(l10n: $l10n);
	}//end partyVocabulary()

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

		// Three role types, then the six generic party roles every case type
		// offers. The role types come FIRST: an organisation's own seats are
		// the first question on a case, and the six the law names follow.
		$this->assertSame(expected: 9, actual: $count);
		$stored = $this->schema->configuration['linkRoles'];
		$this->assertSame(
			expected: [
				'rt-1',
				'rt-2',
				'rt-3',
				'aanvrager',
				'gemachtigde',
				'belanghebbende',
				'afzender',
				'geadresseerde',
				'locatie',
			],
			actual: array_column($stored, 'key')
		);
		$this->assertSame(expected: 'Adviseur', actual: $stored[0]['label']);
		$this->assertSame(expected: 'Doet het werk', actual: $stored[1]['description']);
		// A role type with no name labels itself by its uuid rather than vanishing.
		$this->assertSame(expected: 'rt-3', actual: $stored[2]['label']);
		$this->assertSame(expected: 'Authorised representative', actual: $stored[4]['label']);
	}//end testTheRoleTypesBecomeTheVocabulary()

	/**
	 * A role type already claiming a generic key is not listed twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-the-generic-party-roles-req-role-012
	 */
	public function testAGenericRoleIsNotListedTwice(): void {
		$this->objects->answers['roleType'] = [
			['@self' => ['id' => 'gemachtigde'], 'name' => 'Gemachtigde bezwaar'],
		];

		$this->vocabulary->sync();

		$keys = array_column($this->schema->configuration['linkRoles'], 'key');
		$this->assertSame(expected: 1, actual: count(array_keys($keys, 'gemachtigde', true)));
		$this->assertSame(
			expected: 'Gemachtigde bezwaar',
			actual: $this->schema->configuration['linkRoles'][0]['label'],
			message: "the instance's own role type wins the key it claims"
		);
	}//end testAGenericRoleIsNotListedTwice()

	/**
	 * The case schema declares which kinds of party it accepts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-the-case-declares-the-kinds-of-party-it-takes-req-role-011
	 */
	public function testTheCaseDeclaresTheKindsOfPartyItTakes(): void {
		$this->objects->answers['roleType'] = [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']];

		$this->vocabulary->sync();

		$kinds = $this->schema->configuration['partyKinds'];
		$this->assertSame(expected: ['person', 'organisation', 'address'], actual: array_column($kinds, 'key'));
		// Person and organisation name NO roles, on purpose: a kind naming
		// roles holds only those, and a person link carries a role type uuid
		// as its role, so binding a list to them would refuse every role type
		// this instance declares.
		$this->assertArrayNotHasKey(key: 'roles', array: $kinds[0]);
		$this->assertArrayNotHasKey(key: 'roles', array: $kinds[1]);
		$this->assertSame(expected: ['locatie'], actual: $kinds[2]['roles']);
	}//end testTheCaseDeclaresTheKindsOfPartyItTakes()

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
		$this->assertCount(expectedCount: 7, haystack: $this->schema->configuration['linkRoles']);
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
			parties: $this->partyVocabulary(),
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
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 */
	public function testAVocabularyTheSchemaDropsIsReported(): void {
		$this->objects->answers['roleType'] = [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']];
		// An OpenRegister that does not know the key keeps everything else and
		// loses this one.
		$this->schema->dropsLinkRoles = true;

		$this->assertSame(expected: -1, actual: $this->vocabulary->sync());
	}//end testAVocabularyTheSchemaDropsIsReported()

	/**
	 * A register that is configured but a schema that is not: the sync says so
	 * rather than writing a vocabulary against nothing.
	 *
	 * @return void
	 */
	public function testAMissingSchemaStopsTheSync(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				// The register is there; the role type schema is not.
				if ($key === 'register') {
					return 'dossiq';
				}

				return $default;
			}
		);
		$vocabulary = new CaseRoleVocabulary(
			settingsService: $settings,
			parties: $this->partyVocabulary(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: -1, actual: $vocabulary->sync());
	}//end testAMissingSchemaStopsTheSync()

	/**
	 * The entries are readable on their own, so a caller can offer them
	 * without writing anything.
	 *
	 * @return void
	 */
	public function testTheEntriesAreReadableWithoutWriting(): void {
		$this->objects->answers['roleType'] = [
			['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur', 'description' => 'Kijkt mee'],
		];

		$entries = $this->vocabulary->roleEntries();

		$this->assertSame(
			expected: [['key' => 'rt-1', 'label' => 'Adviseur', 'description' => 'Kijkt mee']],
			actual: $entries,
		);
		$this->assertSame(expected: [], actual: $this->updated, message: 'reading the entries writes nothing');
	}//end testTheEntriesAreReadableWithoutWriting()

	/**
	 * A schema mapper that cannot find the case schema leaves the vocabulary
	 * unwritten, and says why in the log rather than throwing at the upgrade.
	 *
	 * @return void
	 */
	public function testACaseSchemaThatCannotBeReadStopsTheSync(): void {
		$this->objects->answers['roleType'] = [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']];
		$blindMapper = new class {
			/**
			 * No schema here.
			 *
			 * @param string|int $id The schema.
			 *
			 * @return object Never.
			 */
			public function find(string|int $id): object {
				throw new RuntimeException('no such schema');
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getOpenRegisterClass')->willReturn($blindMapper);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);
		$vocabulary = new CaseRoleVocabulary(
			settingsService: $settings,
			parties: $this->partyVocabulary(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: -1, actual: $vocabulary->sync());
	}//end testACaseSchemaThatCannotBeReadStopsTheSync()

	/**
	 * Without OpenRegister's schema mapper the sync writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutASchemaMapperTheSyncWritesNothing(): void {
		$this->objects->answers['roleType'] = [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);
		$vocabulary = new CaseRoleVocabulary(
			settingsService: $settings,
			parties: $this->partyVocabulary(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: -1, actual: $vocabulary->sync());
	}//end testWithoutASchemaMapperTheSyncWritesNothing()

	/**
	 * A register that is not configured stops the read of the role types
	 * before it starts.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterStopsTheSync(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturn('');
		$vocabulary = new CaseRoleVocabulary(
			settingsService: $settings,
			parties: $this->partyVocabulary(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: -1, actual: $vocabulary->sync());
	}//end testAnUnconfiguredRegisterStopsTheSync()
}//end class
