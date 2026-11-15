<?php

/**
 * The case role vocabulary at its edges: what the stored schema kept after the write.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
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

/**
 * Tests for CaseRoleVocabulary at its edges.
 *
 * @covers \OCA\Dossiq\Service\People\CaseRoleVocabulary
 * @uses \OCA\Dossiq\Service\People\PartyVocabulary
 * @uses \OCA\Dossiq\Service\Custody\DossierRegister
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseRoleVocabularyEdgeTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A vocabulary over these role types, whose mapper's update() answers $stored.
	 *
	 * @param array<int, array<string, mixed>> $roleTypes The role type rows.
	 * @param mixed                            $stored    What update() returns; null returns the schema itself.
	 * @param bool                             $dropKinds Whether the schema drops `partyKinds` on write.
	 *
	 * @return CaseRoleVocabulary The service under test.
	 */
	private function vocabulary(array $roleTypes, mixed $stored = null, bool $dropKinds = false): CaseRoleVocabulary {
		$objects = new class ($roleTypes) {
			/**
			 * @param array<int, array<string, mixed>> $rows The role type rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether tenancy applies.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice($this->rows, (int)($filters['_offset'] ?? 0), (int)($filters['_limit'] ?? 20));
			}
		};

		$schema = new class ($dropKinds) {
			/**
			 * The stored configuration.
			 *
			 * @var array<string, mixed>
			 */
			public array $configuration = [];

			/**
			 * @param bool $dropKinds Whether `partyKinds` is dropped on write.
			 */
			public function __construct(private bool $dropKinds) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getConfiguration(): array {
				return $this->configuration;
			}

			/**
			 * @param array<string, mixed> $configuration The configuration.
			 *
			 * @return void
			 */
			public function setConfiguration(array $configuration): void {
				if ($this->dropKinds === true) {
					unset($configuration['partyKinds']);
				}

				$this->configuration = $configuration;
			}
		};

		$mapper = new class ($schema, $stored) {
			/**
			 * @param object $schema The case schema.
			 * @param mixed  $stored What update() answers; null answers the schema.
			 */
			public function __construct(private object $schema, private mixed $stored) {
			}

			/**
			 * @param string|int $id The schema.
			 *
			 * @return object
			 */
			public function find(string|int $id): object {
				return $this->schema;
			}

			/**
			 * @param object $schema The schema.
			 *
			 * @return mixed
			 */
			public function update(object $schema): mixed {
				return ($this->stored ?? $schema);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case', 'role_type_schema' => 'roleType'][$key] ?? $default)
		);
		$settings->method('getOpenRegisterClass')->willReturn($mapper);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new CaseRoleVocabulary(settingsService: $settings, parties: new PartyVocabulary(l10n: $l10n), logger: $this->logger);
	}//end vocabulary()

	/**
	 * A stored schema that kept the roles but not the party kinds still counts the roles, and warns.
	 *
	 * @return void
	 */
	public function testDroppedPartyKindsWarnButKeepTheRoles(): void {
		$vocabulary = $this->vocabulary(roleTypes: [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']], dropKinds: true);
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('did not keep its party kinds'));

		$expected = count($vocabulary->vocabulary());
		self::assertGreaterThan(1, $expected, 'The role type and the generic party roles together.');
		self::assertSame($expected, $vocabulary->sync());
	}//end testDroppedPartyKindsWarnButKeepTheRoles()

	/**
	 * An update that answers no schema object counts as a write that kept nothing.
	 *
	 * @return void
	 */
	public function testAnUpdateAnsweringNoSchemaReportsTheRolesLost(): void {
		$vocabulary = $this->vocabulary(roleTypes: [['@self' => ['id' => 'rt-1'], 'name' => 'Adviseur']], stored: 'not a schema');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('did not keep its link roles'));

		self::assertSame(-1, $vocabulary->sync());
	}//end testAnUpdateAnsweringNoSchemaReportsTheRolesLost()

	/**
	 * An update answering an object without getConfiguration() is treated the same way.
	 *
	 * @return void
	 */
	public function testAnUpdateAnsweringAnObjectWithoutConfigurationReportsTheRolesLost(): void {
		$vocabulary = $this->vocabulary(roleTypes: [], stored: new \stdClass());
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('did not keep its link roles'));

		self::assertSame(-1, $vocabulary->sync(), 'The generic party roles alone are still a vocabulary to lose.');
	}//end testAnUpdateAnsweringAnObjectWithoutConfigurationReportsTheRolesLost()

	/**
	 * A role type without a name is labelled by its key; one without any id is skipped.
	 *
	 * @return void
	 */
	public function testARoleTypeWithoutANameIsLabelledByItsKey(): void {
		$vocabulary = $this->vocabulary(roleTypes: [
			['@self' => ['id' => 'rt-unnamed']],
			['name' => 'No id at all'],
			['id' => ' rt-top ', 'name' => 'Zaakcoördinator', 'description' => 'Bewaakt de termijn'],
		]);

		self::assertSame(
			[
				['key' => 'rt-unnamed', 'label' => 'rt-unnamed'],
				['key' => 'rt-top', 'label' => 'Zaakcoördinator', 'description' => 'Bewaakt de termijn'],
			],
			$vocabulary->roleEntries()
		);
	}//end testARoleTypeWithoutANameIsLabelledByItsKey()
}//end class
