<?php

/**
 * CaseAccessGuard — a deelzaak inherits its parent's grants.
 *
 * Competitor gap register row Q13.23. Before this, somebody given a case could
 * not open its deelzaken, so the deelzaken were granted separately and the two
 * grants drifted: the deelzaak stayed open to a person taken off the parent a
 * year earlier.
 *
 * Every test here is written so the BAD path is the thing under test. The
 * inheritance is the feature, and each of these is a way it could be wrong
 * while looking right:
 *
 *  - the read widening into a write on the way down (D-3), which would hand
 *    every reader of a parent an editor's rights on its children;
 *  - the walk following `relatedCases`, which is a peer link, so access would
 *    travel sideways along every relation a handler ever made;
 *  - a cycle written by an import making the question never return;
 *  - an unresolvable ancestor being read as "no obstacle" rather than as a
 *    refusal.
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
 * Unit tests for the grant that travels down the deelzaak chain.
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
	 * Give the guard a whole register of cases, keyed by id.
	 *
	 * A chain needs a store and not one payload: a double that answers the
	 * same case for every id would make a walk that never moved look exactly
	 * like a walk that reached the root.
	 *
	 * @param array<string, array<string, mixed>> $cases The cases, by id.
	 *
	 * @return void
	 */
	private function givenCases(array $cases): void {
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
	}//end givenCases()

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
	 * A parent, its deelzaak and that deelzaak's deelzaak, worked by alice at
	 * the top only.
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
	 * A grant on the case reaches its deelzaak, and the deelzaak of that.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testReadReachesTheDeepestDeelzaak(): void {
		$this->givenCases($this->threeDeep());
		$guard = $this->guard();

		$this->assertTrue($guard->hasCaseReadAccess('child', $this->user('alice')));
		$this->assertTrue($guard->hasCaseReadAccess('grandchild', $this->user('alice')));
	}//end testReadReachesTheDeepestDeelzaak()

	/**
	 * 🔴 The read does not widen into a write on the way down (D-3).
	 *
	 * This is the assertion the whole change turns on. Alice works the parent
	 * and may read every deelzaak under it; she may change none of them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testReadDoesNotBecomeWrite(): void {
		$this->givenCases($this->threeDeep());
		$guard = $this->guard();
		$alice = $this->user('alice');

		$this->assertTrue($guard->hasCaseReadAccess('grandchild', $alice));
		$this->assertFalse($guard->hasCaseMutationAccess('child', $alice));
		$this->assertFalse($guard->hasCaseMutationAccess('grandchild', $alice));
	}//end testReadDoesNotBecomeWrite()

	/**
	 * The grant travels down, never up. Carol works the grandchild and has no
	 * business reading the parent it hangs under.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAccessDoesNotTravelUpwards(): void {
		$this->givenCases($this->threeDeep());
		$guard = $this->guard();

		$this->assertTrue($guard->hasCaseReadAccess('grandchild', $this->user('carol')));
		$this->assertFalse($guard->hasCaseReadAccess('parent', $this->user('carol')));
		$this->assertFalse($guard->hasCaseReadAccess('child', $this->user('carol')));
	}//end testAccessDoesNotTravelUpwards()

	/**
	 * A related case is not a parent, so nothing travels along it.
	 *
	 * `relatedCases` is a typed PEER relation written symmetrically, so a walk
	 * that followed it would carry a grant sideways across every link a
	 * handler ever made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testARelatedCaseIsNotAParent(): void {
		$this->givenCases(
			[
				'granted' => ['id' => 'granted', 'assignee' => 'alice'],
				'peer' => [
					'id' => 'peer',
					'assignee' => 'bob',
					'relatedCases' => '[{"caseId":"granted","aardRelatie":"vervolg"}]',
				],
			]
		);

		$this->assertFalse($this->guard()->hasCaseReadAccess('peer', $this->user('alice')));
	}//end testARelatedCaseIsNotAParent()

	/**
	 * A cycle refuses rather than resolving forever.
	 *
	 * Nothing validates the chain on write, so an import can file a case under
	 * its own descendant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testACycleRefusesRatherThanHanging(): void {
		$this->givenCases(
			[
				'a' => ['id' => 'a', 'assignee' => 'bob', 'parentCase' => 'b'],
				'b' => ['id' => 'b', 'assignee' => 'bob', 'parentCase' => 'a'],
			]
		);

		$this->assertFalse($this->guard()->hasCaseReadAccess('a', $this->user('mallory')));
	}//end testACycleRefusesRatherThanHanging()

	/**
	 * A chain longer than the declared cap stops at the cap.
	 *
	 * The grant is at the very top of a chain of twelve, past
	 * `HIERARCHY_MAX_DEPTH`, so it does not reach the bottom. A cap that was
	 * not enforced would make this pass, which is why it is asserted as a
	 * refusal and not skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testTheDepthCapIsEnforced(): void {
		$cases = ['case-0' => ['id' => 'case-0', 'assignee' => 'alice']];
		for ($level = 1; $level <= 12; $level++) {
			$cases['case-' . $level] = [
				'id' => 'case-' . $level,
				'assignee' => 'bob',
				'parentCase' => 'case-' . ($level - 1),
			];
		}

		$this->givenCases($cases);
		$guard = $this->guard();
		$alice = $this->user('alice');

		// Within the cap: nine hops up from case-9 reaches case-0.
		$this->assertTrue($guard->hasCaseReadAccess('case-9', $alice));
		// Past it: twelve hops is more than the chain is allowed to carry.
		$this->assertFalse($guard->hasCaseReadAccess('case-12', $alice));
	}//end testTheDepthCapIsEnforced()

	/**
	 * An ancestor nobody can resolve refuses, rather than being stepped over.
	 *
	 * The fail-closed rule the rest of this guard already follows, applied to
	 * every level of the walk and not only to the first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAnUnresolvableAncestorDenies(): void {
		$this->givenCases(
			[
				'child' => ['id' => 'child', 'assignee' => 'bob', 'parentCase' => 'gone'],
			]
		);

		$this->assertFalse($this->guard()->hasCaseReadAccess('child', $this->user('alice')));
	}//end testAnUnresolvableAncestorDenies()

	/**
	 * The provenance names the case that granted the read, not the case read.
	 *
	 * A handler looking at a colleague on a deelzaak cannot remove the grant
	 * from the case in front of them, so the page has to say which case to go
	 * to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testTheProvenanceNamesTheGrantingCase(): void {
		$this->givenCases($this->threeDeep());
		$guard = $this->guard();

		$this->assertSame('parent', $guard->readAccessSource('grandchild', $this->user('alice')));
		$this->assertSame('child', $guard->readAccessSource('child', $this->user('bob')));
		$this->assertNull($guard->readAccessSource('parent', $this->user('carol')));
	}//end testTheProvenanceNamesTheGrantingCase()

	/**
	 * A member of `assignees` on an ancestor inherits too.
	 *
	 * A case is worked by more people than the one it is filed to, and the
	 * direct read already honours the array. An inheritance that honoured only
	 * `assignee` would be a second, narrower rule nobody declared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAnAssigneesMemberOnTheParentInherits(): void {
		$this->givenCases(
			[
				'parent' => [
					'id' => 'parent',
					'assignee' => 'alice',
					'assignees' => ['dave'],
				],
				'child' => ['id' => 'child', 'assignee' => 'bob', 'parentCase' => 'parent'],
			]
		);

		$this->assertTrue($this->guard()->hasCaseReadAccess('child', $this->user('dave')));
	}//end testAnAssigneesMemberOnTheParentInherits()

	/**
	 * A stranger is refused at every depth.
	 *
	 * The control: without it, a walk that granted on any resolvable case
	 * would pass every test above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function testAStrangerIsRefusedAtEveryDepth(): void {
		$this->givenCases($this->threeDeep());
		$guard = $this->guard();
		$mallory = $this->user('mallory');

		$this->assertFalse($guard->hasCaseReadAccess('parent', $mallory));
		$this->assertFalse($guard->hasCaseReadAccess('child', $mallory));
		$this->assertFalse($guard->hasCaseReadAccess('grandchild', $mallory));
		$this->assertNull($guard->readAccessSource('grandchild', $mallory));
	}//end testAStrangerIsRefusedAtEveryDepth()
}//end class
