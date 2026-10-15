<?php

/**
 * CaseAccessGuard::hasCaseReadAccess() Unit Tests
 *
 * The read predicate added for the gate-7 IDOR remediation. Written so the BAD
 * path is the thing under test: every branch that cannot establish a real
 * relationship between the caller and the case must DENY, and in particular the
 * three branches on which `Sharing\CaseAccessPolicy::canUserAccessCase()` grants
 * access (OpenRegister absent, schema unconfigured, lookup throws) must deny
 * here.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the per-case read predicate.
 *
 * @covers \OCA\Dossiq\Service\CaseAccessGuard
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 */
class CaseAccessGuardReadAccessTest extends TestCase {

	private SettingsService $settingsService;

	private IGroupManager $groupManager;

	private LoggerInterface $logger;

	/**
	 * Set up shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}//end setUp()

	/**
	 * Build a user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser The user double.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Configure the settings double to return a working OR object service that
	 * resolves the case to the given payload.
	 *
	 * `find()` is what `SearchesObjects::findObjectAsArray()` calls; returning
	 * a plain array mirrors the array branch of that helper.
	 *
	 * @param array<string, mixed>|null $case The case payload, or null for "not found".
	 *
	 * @return void
	 */
	private function givenCase(?array $case): void {
		$objectService = new class($case) {

			/**
			 * @param array<string, mixed>|null $case The case payload.
			 */
			public function __construct(
				private readonly ?array $case,
			) {
			}

			/**
			 * Mimic ObjectService::find().
			 *
			 * @param string $id The object id.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 *
			 * @return array<string, mixed>|null The object.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				return $this->case;
			}
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', '14'],
				['case_schema', '', '24'],
			]
		);
	}//end givenCase()

	/**
	 * Build the guard under test.
	 *
	 * @return CaseAccessGuard The guard.
	 */
	private function guard(): CaseAccessGuard {
		return new CaseAccessGuard(
			settingsService: $this->settingsService,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}//end guard()

	/**
	 * The named assignee may read the case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testAssigneeMayRead(): void {
		$this->givenCase(['id' => 'case-1', 'assignee' => 'alice']);

		$this->assertTrue($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
	}//end testAssigneeMayRead()

	/**
	 * A member of the `assignees` array may read the case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testAssigneesArrayMemberMayRead(): void {
		$this->givenCase(['id' => 'case-1', 'assignee' => 'alice', 'assignees' => ['bob', 'carol']]);

		$this->assertTrue($this->guard()->hasCaseReadAccess('case-1', $this->user('bob')));
	}//end testAssigneesArrayMemberMayRead()

	/**
	 * An unrelated authenticated user may not read the case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testUnrelatedUserMayNotRead(): void {
		$this->givenCase(['id' => 'case-1', 'assignee' => 'alice', 'assignees' => ['bob']]);

		$this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('mallory')));
	}//end testUnrelatedUserMayNotRead()

	/**
	 * A case that cannot be resolved denies rather than skipping the check.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testUnresolvableCaseDenies(): void {
		$this->givenCase(null);

		$this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
	}//end testUnresolvableCaseDenies()

	/**
	 * An absent OpenRegister denies — this is the first of the three branches
	 * on which `CaseAccessPolicy::canUserAccessCase()` returns TRUE.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testAbsentOpenRegisterDenies(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
	}//end testAbsentOpenRegisterDenies()

	/**
	 * An unconfigured case schema denies — the second fail-open branch of
	 * `CaseAccessPolicy`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testUnconfiguredSchemaDenies(): void {
		$objectService = new \stdClass();
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturn('');

		$this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
	}//end testUnconfiguredSchemaDenies()

	/**
	 * A lookup that throws denies — the third fail-open branch of
	 * `CaseAccessPolicy`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testThrowingLookupDenies(): void {
		$objectService = new class {

			/**
			 * Mimic an ObjectService whose backend is down.
			 *
			 * @param string $id The object id.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 *
			 * @return array<string, mixed> Never returns.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				throw new \RuntimeException('backend down');
			}
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', '14'],
				['case_schema', '', '24'],
			]
		);

		$this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
	}//end testThrowingLookupDenies()

	/**
	 * An empty case id or an empty uid denies before anything is loaded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testEmptyIdentifiersDeny(): void {
		$this->settingsService->expects($this->never())->method('getObjectService');

		$guard = $this->guard();

		$this->assertFalse($guard->hasCaseReadAccess('', $this->user('alice')));
		$this->assertFalse($guard->hasCaseReadAccess('case-1', $this->user('')));
	}//end testEmptyIdentifiersDeny()

	/**
	 * A Nextcloud admin may read any case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testAdminMayRead(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$guard = new CaseAccessGuard(
			settingsService: $this->settingsService,
			groupManager: $groupManager,
			logger: $this->logger,
		);

		$this->assertTrue($guard->hasCaseReadAccess('case-1', $this->user('admin')));
	}//end testAdminMayRead()

	/**
	 * A guard over an unclaimed case of a case type whose handling teams are given.
	 *
	 * @param array<string, mixed> $handling The case type's `handling` block.
	 * @param list<string>         $groups   The groups `alice` is in.
	 *
	 * @return CaseAccessGuard
	 */
	private function guardForTeamCase(array $handling, array $groups): CaseAccessGuard {
		$objectService = new class($handling) {

			/**
			 * @param array<string, mixed> $handling The case type's handling block.
			 */
			public function __construct(
				private readonly array $handling,
			) {
			}

			/**
			 * Mimic ObjectService::find() over one case and one case type.
			 *
			 * @param string $id The object id.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 * @return array<string, mixed>|null The object.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				if ($schema === 'case') {
					return ['id' => $id, 'assignee' => null, 'assignedGroup' => 'elsewhere', 'caseType' => 'type-1'];
				}

				return ['id' => 'type-1', 'handling' => $this->handling];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'][$key] ?? $default
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $groups, true)
		);

		return new CaseAccessGuard(settingsService: $settings, groupManager: $groupManager, logger: $this->logger);
	}//end guardForTeamCase()

	/**
	 * A member of the case type's handling team reads an unclaimed case of it, and cannot change it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-handling-team-opens-its-unclaimed-cases/specs/case-access-control/spec.md#requirement-a-handling-team-member-opens-a-case-of-its-case-type-before-it-is-claimed-req-acc-11
	 */
	public function testAHandlingTeamMemberReadsAnUnclaimedCase(): void {
		$guard = $this->guardForTeamCase(handling: ['defaultGroup' => 'behandelaars'], groups: ['behandelaars']);

		$this->assertTrue($guard->hasCaseReadAccess('case-1', $this->user('alice')));
		$this->assertFalse($guard->hasCaseMutationAccess('case-1', $this->user('alice')), 'reading is not acting: only the handler acts');
	}//end testAHandlingTeamMemberReadsAnUnclaimedCase()

	/**
	 * A declared team beside the default group reads too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-handling-team-opens-its-unclaimed-cases/specs/case-access-control/spec.md#requirement-a-handling-team-member-opens-a-case-of-its-case-type-before-it-is-claimed-req-acc-11
	 */
	public function testADeclaredHandlingTeamReads(): void {
		$guard = $this->guardForTeamCase(handling: ['defaultGroup' => 'behandelaars', 'teams' => ['toezicht']], groups: ['toezicht']);

		$this->assertTrue($guard->hasCaseReadAccess('case-1', $this->user('alice')));
	}//end testADeclaredHandlingTeamReads()

	/**
	 * Being in the case's own assignedGroup, or in no handling team, reads nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-handling-team-opens-its-unclaimed-cases/specs/case-access-control/spec.md#requirement-a-handling-team-member-opens-a-case-of-its-case-type-before-it-is-claimed-req-acc-11
	 */
	public function testSomeoneOutsideTheHandlingTeamsReadsNothing(): void {
		$guard = $this->guardForTeamCase(handling: ['defaultGroup' => 'behandelaars'], groups: ['elsewhere', 'toezicht']);
		$this->assertFalse($guard->hasCaseReadAccess('case-1', $this->user('alice')));

		$noTeams = $this->guardForTeamCase(handling: [], groups: ['behandelaars']);
		$this->assertFalse($noTeams->hasCaseReadAccess('case-1', $this->user('alice')), 'a case type without teams grants nobody');
	}//end testSomeoneOutsideTheHandlingTeamsReadsNothing()
}//end class
