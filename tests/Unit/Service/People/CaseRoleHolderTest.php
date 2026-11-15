<?php

/**
 * Who holds a generic role on a case, and binding a person to one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/specs/case-access-purpose/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\CaseRoleHolder;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see CaseRoleHolder}.
 */
class CaseRoleHolderTest extends TestCase {

	/**
	 * The objects saved through the double, as [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $saved = [];

	/**
	 * The holder over role types and role bindings held in memory.
	 *
	 * @param array<int, array<string, mixed>> $roleTypes The role types.
	 * @param array<int, array<string, mixed>> $roles     The role bindings.
	 *
	 * @return CaseRoleHolder The holder.
	 */
	private function holder(array $roleTypes, array $roles): CaseRoleHolder {
		$test = $this;
		$objectService = new class($roleTypes, $roles, $test) {
			/**
			 * @param array<int, array<string, mixed>> $roleTypes The role types.
			 * @param array<int, array<string, mixed>> $roles     The role bindings.
			 * @param CaseRoleHolderTest               $test      Collects the saves.
			 */
			public function __construct(private array $roleTypes, private array $roles, private CaseRoleHolderTest $test) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				if ($schema === 'roleType') {
					return $this->roleTypes;
				}

				return array_values(array_filter($this->roles, static fn (array $row): bool => ($row['case'] ?? '') === ($filters['case'] ?? '')));
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid=null): array {
				$this->test->saved[] = [$schema, $object];
				return $object + ['id' => 'role-new'];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['role_schema', '', 'role'],
			['role_type_schema', '', 'roleType'],
		]);

		return new CaseRoleHolder(settingsService: $settings, logger: new NullLogger());
	}//end holder()

	/**
	 * Role types: a handler shared by every case type, one of the Jeugdwet type, an advisor.
	 *
	 * @return array<int, array<string, mixed>> The role types.
	 */
	private function roleTypes(): array {
		return [
			['id' => 'rt-handler', 'name' => 'Behandelaar', 'genericRole' => 'handler'],
			['id' => 'rt-jw-handler', 'name' => 'Jeugdconsulent', 'genericRole' => 'handler', 'caseType' => 'ct-jw'],
			['id' => 'rt-advisor', 'name' => 'Adviseur', 'genericRole' => 'advisor'],
		];
	}//end roleTypes()

	/**
	 * The assignee and a person bound with a handler role type hold the handler role; an advisor does not.
	 *
	 * @return void
	 */
	public function testTheAssigneeAndAHandlerBindingHoldTheHandlerRole(): void {
		$roles = [
			['id' => 'r1', 'case' => 'case-1', 'roleType' => 'rt-jw-handler', 'participant' => 'user:fatma'],
			['id' => 'r2', 'case' => 'case-1', 'roleType' => 'rt-advisor', 'participant' => 'user:sanne'],
		];
		$holder = $this->holder(roleTypes: $this->roleTypes(), roles: $roles);
		$case = ['id' => 'case-1', 'caseType' => 'ct-jw', 'assignee' => 'pieter'];

		$this->assertTrue($holder->holdsAny(case: $case, uid: 'pieter', genericRoles: ['handler']));
		$this->assertTrue($holder->holdsAny(case: $case, uid: 'fatma', genericRoles: ['handler']));
		$this->assertFalse($holder->holdsAny(case: $case, uid: 'sanne', genericRoles: ['handler']));
		$this->assertTrue($holder->holdsAny(case: $case, uid: 'sanne', genericRoles: ['advisor']));
		$this->assertFalse($holder->holdsAny(case: $case, uid: 'pieter', genericRoles: []));
	}//end testTheAssigneeAndAHandlerBindingHoldTheHandlerRole()

	/**
	 * Binding uses the case type's own role type first, and does not bind twice.
	 *
	 * @return void
	 */
	public function testBindingUsesTheCaseTypesOwnRoleTypeAndNotTwice(): void {
		$holder = $this->holder(roleTypes: $this->roleTypes(), roles: []);
		$case = ['id' => 'case-1', 'caseType' => 'ct-jw', 'assignee' => 'pieter'];

		$this->assertTrue($holder->bind(case: $case, uid: 'fatma', genericRole: 'handler', displayName: 'Fatma Yilmaz'));
		$this->assertSame('role', $this->saved[0][0]);
		$this->assertSame(['name' => 'Fatma Yilmaz', 'roleType' => 'rt-jw-handler', 'case' => 'case-1', 'participant' => 'user:fatma'], $this->saved[0][1]);

		$this->assertFalse($holder->bind(case: $case, uid: 'pieter', genericRole: 'handler', displayName: 'Pieter'));
		$this->assertCount(1, $this->saved);
	}//end testBindingUsesTheCaseTypesOwnRoleTypeAndNotTwice()

	/**
	 * Another case type falls back to the shared role type; no role type with the generic role binds nothing.
	 *
	 * @return void
	 */
	public function testBindingFallsBackToTheSharedRoleTypeOrBindsNothing(): void {
		$holder = $this->holder(roleTypes: $this->roleTypes(), roles: []);
		$this->assertTrue($holder->bind(case: ['id' => 'case-2', 'caseType' => 'ct-ov'], uid: 'fatma', genericRole: 'handler', displayName: ''));
		$this->assertSame('rt-handler', $this->saved[0][1]['roleType']);
		$this->assertSame('fatma', $this->saved[0][1]['name']);

		$this->assertFalse($holder->bind(case: ['id' => 'case-2', 'caseType' => 'ct-ov'], uid: 'fatma', genericRole: 'decision_maker', displayName: ''));
		$this->assertCount(1, $this->saved);
	}//end testBindingFallsBackToTheSharedRoleTypeOrBindsNothing()
}//end class
