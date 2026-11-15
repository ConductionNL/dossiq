<?php

/**
 * CaseRoleHolder where binding or reading cannot go the ordinary way.
 *
 * A save OpenRegister refuses binds nothing, a reader bound without a given
 * name is named after their Nextcloud account or their uid, a register that is
 * not configured holds no roles, an absent OpenRegister is reported rather than
 * read as "nobody", and a binding to a group is not a binding to a user of the
 * same name.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-purpose/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\CaseRoleHolder;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CaseRoleHolder at its edges.
 */
class CaseRoleHolderEdgeTest extends TestCase {

	/**
	 * The role bindings the register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $roles = [];

	/**
	 * Objects saved, by schema.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $saved = [];

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A binding OpenRegister refuses to save binds nothing and says so.
	 *
	 * @return void
	 */
	public function testARefusedSaveBindsNothing(): void {
		$holder = $this->holder(objectService: $this->objectService(refusesSaves: true));

		self::assertFalse($holder->bind(case: ['id' => 'case-1'], uid: 'sofie', genericRole: 'advisor', displayName: 'Sofie'));
		self::assertSame(['Dossiq: the reader could not be bound to the case'], $this->warnings);
	}//end testARefusedSaveBindsNothing()

	/**
	 * Without a given name the binding is named after the Nextcloud account,
	 * and after the uid when the account has no display name.
	 *
	 * @return void
	 */
	public function testABindingWithoutANameIsNamedAfterTheAccount(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['sofie', 'Sofie de Groot'], ['karim', '']]);
		$holder = $this->holder(objectService: $this->objectService(), users: $users);

		self::assertTrue($holder->bind(case: ['id' => 'case-1'], uid: ' sofie ', genericRole: 'advisor', displayName: ' '));
		self::assertTrue($holder->bind(case: ['id' => 'case-2'], uid: 'karim', genericRole: 'advisor', displayName: ''));

		self::assertSame(
			[
				['role', ['name' => 'Sofie de Groot', 'roleType' => 'rt-advisor', 'case' => 'case-1', 'participant' => 'user:sofie']],
				['role', ['name' => 'karim', 'roleType' => 'rt-advisor', 'case' => 'case-2', 'participant' => 'user:karim']],
			],
			$this->saved
		);
	}//end testABindingWithoutANameIsNamedAfterTheAccount()

	/**
	 * A register that is not configured holds no roles, so nobody holds one
	 * beyond the assignee.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterHoldsNoRoles(): void {
		$this->roles[] = ['case' => 'case-1', 'roleType' => 'rt-advisor', 'participant' => 'user:sofie'];
		$holder = $this->holder(objectService: $this->objectService(), register: '');

		self::assertFalse($holder->holdsAny(case: ['id' => 'case-1'], uid: 'sofie', genericRoles: ['advisor']));
		self::assertTrue($holder->holdsAny(case: ['id' => 'case-1', 'assignee' => 'jan'], uid: 'jan', genericRoles: ['handler']));
	}//end testAnUnconfiguredRegisterHoldsNoRoles()

	/**
	 * An absent OpenRegister is reported, not read as "nobody holds the role".
	 *
	 * @return void
	 */
	public function testAnAbsentOpenRegisterIsReported(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->holder(objectService: null)->holdsAny(case: ['id' => 'case-1'], uid: 'sofie', genericRoles: ['advisor']);
	}//end testAnAbsentOpenRegisterIsReported()

	/**
	 * A binding to a group is not a binding to a user of the same name.
	 *
	 * @return void
	 */
	public function testAGroupBindingIsNotAUserBinding(): void {
		$this->roles[] = ['case' => 'case-1', 'roleType' => 'rt-advisor', 'participant' => 'group:sofie'];
		$holder = $this->holder(objectService: $this->objectService());

		self::assertFalse($holder->holdsAny(case: ['id' => 'case-1'], uid: 'sofie', genericRoles: ['advisor']));
		self::assertTrue($holder->holdsAny(case: ['id' => 'case-1'], uid: 'group:sofie', genericRoles: ['advisor']));
	}//end testAGroupBindingIsNotAUserBinding()

	/**
	 * An ObjectService over the role types and $this->roles.
	 *
	 * @param bool $refusesSaves Whether saveObject throws.
	 *
	 * @return object
	 */
	private function objectService(bool $refusesSaves = false): object {
		return new class ($this, $refusesSaves) {
			/**
			 * Constructor.
			 *
			 * @param CaseRoleHolderEdgeTest $test         Holds the roles and collects saves.
			 * @param bool                   $refusesSaves Whether saveObject throws.
			 */
			public function __construct(private CaseRoleHolderEdgeTest $test, private bool $refusesSaves) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				if ($schema === 'roleType') {
					return [['id' => 'rt-handler', 'genericRole' => 'handler'], ['id' => 'rt-advisor', 'genericRole' => 'advisor']];
				}

				return array_values(array_filter($this->test->roles(), static fn (array $row): bool => ($row['case'] ?? '') === ($filters['case'] ?? '')));
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				if ($this->refusesSaves === true) {
					throw new RuntimeException('save refused');
				}

				$this->test->saved[] = [$schema, $object];
				return $object + ['id' => 'role-new'];
			}
		};
	}//end objectService()

	/**
	 * The role bindings the stand-in register holds.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function roles(): array {
		return $this->roles;
	}//end roles()

	/**
	 * The holder over an ObjectService.
	 *
	 * @param object|null       $objectService The ObjectService, or null without OpenRegister.
	 * @param IUserManager|null $users         The user manager.
	 * @param string            $register      The configured register.
	 *
	 * @return CaseRoleHolder
	 */
	private function holder(?object $objectService, ?IUserManager $users = null, string $register = 'dossiq'): CaseRoleHolder {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', $register],
			['role_schema', '', 'role'],
			['role_type_schema', '', 'roleType'],
		]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new CaseRoleHolder(settingsService: $settings, logger: $logger, userManager: $users);
	}//end holder()
}//end class
