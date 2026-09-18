<?php

/**
 * A deelzaak inherits its parent's grants, and dossiq no longer resolves it.
 *
 * Competitor gap register row Q13.23. This suite used to pin dossiq's OWN walk
 * up the `parentCase` chain: the depth cap, the cycle guard, the provenance and
 * the levels it reached. openregister#3873 resolves a per-object grant over the
 * declared `x-openregister-hierarchy` edge, so the walk is gone and those cases
 * went with it — they now pin behaviour that lives in the other app, and a test
 * that asserts somebody else's rule from the outside is a copy that drifts.
 *
 * WHAT IS PINNED HERE INSTEAD is the seam, which is dossiq's:
 *
 *  - the platform's answer is CONSULTED, so an inherited grant actually admits
 *    somebody who is on no case at all. A consumer that declared the edge and
 *    then never asked would look exactly like a working one, because the only
 *    visible symptom is a colleague who cannot open a deelzaak;
 *  - 🔴 it is consulted for READS ONLY. The verb must not widen, and it now has
 *    an app boundary to widen across: a mutation path that asked the platform
 *    would inherit the write this whole rule refuses;
 *  - a platform that cannot be asked answers NOT GRANTED. Absent app, missing
 *    method, throwing resolver: all deny, which is both fail-closed and exactly
 *    the behaviour dossiq had before inheritance existed;
 *  - 🔴 and the guard does NOT defer wholesale to the case resolving. That was
 *    the tempting shape: `ObjectService::find()` applies the SCHEMA's read
 *    rule, and a case schema whose rule is `authenticated` resolves every case
 *    for every logged-in user, so deferring would have turned this guard into
 *    one that everybody passes with nothing looking different.
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
 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
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
 * Unit tests for the seam between dossiq's guard and the platform's grants.
 *
 * @covers \OCA\Dossiq\Service\CaseAccessGuard
 */
class CaseAccessGuardInheritanceTest extends TestCase {

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
	 * Give the guard a register of cases and a platform grant answer.
	 *
	 * The grant resolver double answers from a set of (user, object) pairs,
	 * which is the shape OpenRegister's own resolver answers in: it does not
	 * know or care whether a grant was written on the object or inherited from
	 * an ancestor, and neither does dossiq. That is the point of the seam, and
	 * a double that modelled the chain here would be dossiq re-implementing the
	 * rule it just deleted.
	 *
	 * @param array<string, array<string, mixed>> $cases The cases, by id.
	 * @param array<int, string> $grants Granted pairs, as `uid|objectUuid`.
	 * @param bool $resolverPresent Whether OpenRegister answers at all.
	 *
	 * @return void
	 */
	private function given(array $cases, array $grants = [], bool $resolverPresent = true): void {
		$objectService = new class($cases) {

			/**
			 * @param array<string, array<string, mixed>> $cases The cases, by id.
			 */
			public function __construct(
				private readonly array $cases,
			) {
			}

			/**
			 * Mimic ObjectService::find().
			 *
			 * @param string $id The object id.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 *
			 * @return array<string, mixed>|null The object, or null when absent.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				return ($this->cases[$id] ?? null);
			}
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', '14'],
				['case_schema', '', '24'],
			]
		);

		if ($resolverPresent === false) {
			$this->settingsService->method('getObjectGrantResolver')->willReturn(null);
			return;
		}

		$resolver = new class($grants) {

			/**
			 * @param array<int, string> $grants Granted `uid|objectUuid` pairs.
			 */
			public function __construct(
				private readonly array $grants,
			) {
			}

			/**
			 * Mimic ObjectGrantResolver::isGranted().
			 *
			 * @param string|null $userId The caller.
			 * @param string|null $objectUuid The object.
			 * @param string $action The action.
			 *
			 * @return bool Whether a grant carries it.
			 */
			public function isGranted(?string $userId, ?string $objectUuid, string $action = 'read'): bool {
				return in_array($userId . '|' . $objectUuid . '|' . $action, $this->grants, true);
			}
		};

		$this->settingsService->method('getObjectGrantResolver')->willReturn($resolver);
	}//end given()

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
	 * A parent, its deelzaak and that deelzaak's deelzaak.
	 *
	 * The chain is still here, and nothing in this file walks it. It is the
	 * fixture an inherited grant is ABOUT, so a reader can see what the
	 * platform's answer refers to.
	 *
	 * @return array<string, array<string, mixed>> The three cases.
	 */
	private function threeDeep(): array {
		return [
			'parent' => ['id' => 'parent', 'assignee' => 'alice'],
			'child' => ['id' => 'child', 'assignee' => 'bob', 'parentCase' => 'parent'],
			'grandchild' => [
				'id' => 'grandchild',
				'assignee' => 'carol',
				'parentCase' => 'child',
			],
		];
	}//end threeDeep()

	/**
	 * 🔴 Inherited access still holds, because the platform is asked.
	 *
	 * Dave is on no case at all. OpenRegister answers that he holds a read
	 * grant on the grandchild, which is what it does when a grant written on
	 * the parent is expanded over the declared hierarchy, and the guard admits
	 * him. A consumer that declared the edge and never asked would fail exactly
	 * here and nowhere else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testInheritedAccessHoldsThroughThePlatform(): void {
		$this->given($this->threeDeep(), ['dave|grandchild|read']);

		$this->assertTrue($this->guard()->hasCaseReadAccess('grandchild', $this->user('dave')));
	}//end testInheritedAccessHoldsThroughThePlatform()

	/**
	 * 🔴 The verb does not widen, and now it has an app boundary to widen across.
	 *
	 * The test this change was told to keep. Dave reads the deelzaak through a
	 * grant; he may change nothing. A mutation path that consulted the platform
	 * would inherit the write, which is the measured half of the competitor's
	 * behaviour and the one that is easy to lose in a refactor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testReadDoesNotBecomeWrite(): void {
		$this->given(
			$this->threeDeep(),
			['dave|grandchild|read', 'dave|child|read', 'dave|child|update']
		);
		$guard = $this->guard();
		$dave = $this->user('dave');

		$this->assertTrue($guard->hasCaseReadAccess('grandchild', $dave));
		$this->assertFalse(
			$guard->hasCaseMutationAccess('child', $dave),
			'the mutation path must not ask the platform, even for a grant that carries update'
		);
		$this->assertFalse($guard->hasCaseMutationAccess('grandchild', $dave));
	}//end testReadDoesNotBecomeWrite()

	/**
	 * The per-case relationship still admits, with no grant anywhere.
	 *
	 * dossiq's own rule, unchanged since the gate-7 remediation. Deleting the
	 * walk must not delete the reason this guard exists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testTheAssigneeStillReadsTheirOwnCase(): void {
		$this->given($this->threeDeep(), []);
		$guard = $this->guard();

		$this->assertTrue($guard->hasCaseReadAccess('child', $this->user('bob')));
		$this->assertTrue($guard->hasCaseReadAccess('grandchild', $this->user('carol')));
	}//end testTheAssigneeStillReadsTheirOwnCase()

	/**
	 * A member of `assignees` still reads the case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testAnAssigneesMemberStillReads(): void {
		$this->given(
			['child' => ['id' => 'child', 'assignee' => 'bob', 'assignees' => ['erin']]],
			[]
		);

		$this->assertTrue($this->guard()->hasCaseReadAccess('child', $this->user('erin')));
	}//end testAnAssigneesMemberStillReads()

	/**
	 * 🔴 The least privileged principal: on no case, holding no grant.
	 *
	 * The control, and the one that would catch a guard that deferred wholesale
	 * to `loadCase()` resolving. Mallory resolves every case in this fixture,
	 * because the double answers them all; the guard must still refuse her.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAStrangerWithNoGrantIsRefused(): void {
		$this->given($this->threeDeep(), ['dave|grandchild|read']);
		$guard = $this->guard();
		$mallory = $this->user('mallory');

		$this->assertFalse($guard->hasCaseReadAccess('parent', $mallory));
		$this->assertFalse($guard->hasCaseReadAccess('child', $mallory));
		$this->assertFalse($guard->hasCaseReadAccess('grandchild', $mallory));
		$this->assertFalse($guard->hasCaseMutationAccess('grandchild', $mallory));
	}//end testAStrangerWithNoGrantIsRefused()

	/**
	 * Somebody else's grant is not this caller's.
	 *
	 * The narrower control: the platform IS answering, and it answers about a
	 * principal. A seam that passed the wrong uid, or none, would admit
	 * everybody the moment anybody held a grant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAnotherPersonsGrantAdmitsNobodyElse(): void {
		$this->given($this->threeDeep(), ['dave|grandchild|read']);

		$this->assertFalse($this->guard()->hasCaseReadAccess('grandchild', $this->user('erin')));
	}//end testAnotherPersonsGrantAdmitsNobodyElse()

	/**
	 * A platform that cannot be asked answers NOT GRANTED.
	 *
	 * Fail-closed, and also exactly the behaviour dossiq had before any of this
	 * existed: an instance that cannot ask loses inheritance rather than
	 * gaining access.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAnAbsentResolverDenies(): void {
		$this->given($this->threeDeep(), [], false);

		$this->assertFalse($this->guard()->hasCaseReadAccess('grandchild', $this->user('dave')));
		// And the relationship path still works, so an instance without the
		// resolver is not an instance without a case guard.
		$this->assertTrue($this->guard()->hasCaseReadAccess('grandchild', $this->user('carol')));
	}//end testAnAbsentResolverDenies()

	/**
	 * An older OpenRegister without the method denies rather than fataling.
	 *
	 * The duck-typed lookup returns whatever the container holds. A resolver
	 * from before openregister#3873 has no `isGranted()`, and calling it would
	 * be a fatal on every case read rather than a missing feature.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAResolverWithoutTheMethodDenies(): void {
		$this->settingsService->method('getObjectService')->willReturn(
			new class {

				/**
				 * Mimic ObjectService::find().
				 *
				 * @param string $id The object id.
				 * @param mixed $register The register.
				 * @param mixed $schema The schema.
				 *
				 * @return array<string, mixed> The object.
				 */
				public function find(string $id, mixed $register = null, mixed $schema = null): array {
					return ['id' => $id, 'assignee' => 'carol'];
				}
			}
		);
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', '14'],
				['case_schema', '', '24'],
			]
		);
		$this->settingsService->method('getObjectGrantResolver')->willReturn(new \stdClass());

		$this->assertFalse($this->guard()->hasCaseReadAccess('grandchild', $this->user('dave')));
	}//end testAResolverWithoutTheMethodDenies()

	/**
	 * A resolver that throws denies rather than taking the page down.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAThrowingResolverDenies(): void {
		$this->settingsService->method('getObjectService')->willReturn(
			new class {

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
					return null;
				}
			}
		);
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', '14'],
				['case_schema', '', '24'],
			]
		);
		$this->settingsService->method('getObjectGrantResolver')->willReturn(
			new class {

				/**
				 * Mimic a resolver whose backend is down.
				 *
				 * @param string|null $userId The caller.
				 * @param string|null $objectUuid The object.
				 * @param string $action The action.
				 *
				 * @return bool Never returns.
				 *
				 * @throws \RuntimeException Always.
				 */
				public function isGranted(?string $userId, ?string $objectUuid, string $action = 'read'): bool {
					throw new \RuntimeException('share backend down');
				}
			}
		);

		$this->assertFalse($this->guard()->hasCaseReadAccess('grandchild', $this->user('dave')));
	}//end testAThrowingResolverDenies()

	/**
	 * An empty case id or an empty uid denies before anything is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testEmptyIdentifiersDeny(): void {
		$this->given($this->threeDeep(), ['dave|grandchild|read']);
		$guard = $this->guard();

		$this->assertFalse($guard->hasCaseReadAccess('', $this->user('dave')));
		$this->assertFalse($guard->hasCaseReadAccess('grandchild', $this->user('')));
	}//end testEmptyIdentifiersDeny()
}//end class
