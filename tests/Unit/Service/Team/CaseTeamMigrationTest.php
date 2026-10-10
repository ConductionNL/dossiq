<?php

/**
 * CaseTeamMigration: a case's team moves from an organisation role to its group.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Team
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Team;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Team\CaseTeamMigration;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The store holds cases and roles as they are stored; the group manager knows
 * a fixed set of groups. A second run reads what the first one patched.
 *
 * @covers \OCA\Dossiq\Service\Team\CaseTeamMigration
 */
class CaseTeamMigrationTest extends TestCase {

	private const ROLE_BOUND = '11111111-1111-4111-8111-111111111111';

	private const ROLE_UNBOUND = '22222222-2222-4222-8222-222222222222';

	private const ROLE_DANGLING = '33333333-3333-4333-8333-333333333333';

	/**
	 * The fake store.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Set up a store with one case of each kind.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->store(
			[
				'case' => [
					'c-bound' => ['id' => 'c-bound', 'title' => 'Bound', 'assignedGroup' => self::ROLE_BOUND],
					'c-unbound' => ['id' => 'c-unbound', 'title' => 'Unbound', 'assignedGroup' => self::ROLE_UNBOUND],
					'c-dangling' => ['id' => 'c-dangling', 'title' => 'Dangling', 'assignedGroup' => self::ROLE_DANGLING],
					'c-group' => ['id' => 'c-group', 'title' => 'Handed over', 'assignedGroup' => 'toezicht'],
					'c-unknown' => ['id' => 'c-unknown', 'title' => 'Typed', 'assignedGroup' => 'Team Nergens'],
					'c-none' => ['id' => 'c-none', 'title' => 'No team'],
					'c-expanded' => ['id' => 'c-expanded', 'title' => 'Expanded', 'assignedGroup' => ['id' => self::ROLE_BOUND, 'roleName' => 'Team Vergunningen']],
				],
				'organisatieRol' => [
					self::ROLE_BOUND => ['id' => self::ROLE_BOUND, 'roleName' => 'Team Vergunningen', 'team' => 'Vergunningen', 'ncGroupId' => 'vergunningen'],
					self::ROLE_UNBOUND => ['id' => self::ROLE_UNBOUND, 'roleName' => 'Team Handhaving', 'team' => 'handhaving'],
					self::ROLE_DANGLING => ['id' => self::ROLE_DANGLING, 'roleName' => 'Team Weg', 'ncGroupId' => 'opgeheven'],
				],
			]
		);
	}//end setUp()

	/**
	 * A case whose role names an existing group is moved to it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function testACaseWhoseRoleNamesAGroupIsConverted(): void {
		$report = $this->migration()->run(apply: true);

		self::assertTrue($report['ran']);
		self::assertSame('vergunningen', $this->store->rows['case']['c-bound']['assignedGroup']);
		self::assertSame('vergunningen', $this->store->rows['case']['c-expanded']['assignedGroup'], 'An expanded reference is read by its id.');
		self::assertSame(2, $report['converted']);
		self::assertSame(1, $report['alreadyGroup'], 'The handed-over case already held a group.');
		self::assertSame('toezicht', $this->store->rows['case']['c-group']['assignedGroup']);
		self::assertArrayNotHasKey('assignedGroup', $this->store->rows['case']['c-none']);
	}//end testACaseWhoseRoleNamesAGroupIsConverted()

	/**
	 * A role without a group is reported with a hint, and the hint is not applied.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function testARoleWithoutAGroupIsReportedNotGuessed(): void {
		$report = $this->migration()->run(apply: true);

		self::assertSame(self::ROLE_UNBOUND, $this->store->rows['case']['c-unbound']['assignedGroup'], 'An unmapped case keeps its value.');
		self::assertSame(self::ROLE_DANGLING, $this->store->rows['case']['c-dangling']['assignedGroup']);
		self::assertSame('Team Nergens', $this->store->rows['case']['c-unknown']['assignedGroup']);

		$byCase = [];
		foreach ($report['unmapped'] as $row) {
			$byCase[$row['case']] = $row;
		}

		self::assertSame(['c-unbound', 'c-dangling', 'c-unknown'], array_keys($byCase));
		self::assertSame(CaseTeamMigration::REASON_NO_GROUP, $byCase['c-unbound']['reason']);
		self::assertSame('Team Handhaving', $byCase['c-unbound']['roleName']);
		self::assertSame(['handhaving'], $byCase['c-unbound']['hints'], 'The group named like the role is a hint.');
		self::assertSame(CaseTeamMigration::REASON_GROUP_MISSING, $byCase['c-dangling']['reason']);
		self::assertSame(CaseTeamMigration::REASON_UNKNOWN, $byCase['c-unknown']['reason']);
		self::assertSame([], $byCase['c-unknown']['hints']);
	}//end testARoleWithoutAGroupIsReportedNotGuessed()

	/**
	 * Running it twice changes nothing the first run did not.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function testRunningItTwiceChangesNothingMore(): void {
		$this->migration()->run(apply: true);
		$patches = count($this->store->patches);

		$second = $this->migration()->run(apply: true);

		self::assertSame($patches, count($this->store->patches), 'The second run writes nothing.');
		self::assertSame(0, $second['converted']);
		self::assertSame(3, $second['alreadyGroup']);
		self::assertCount(3, $second['unmapped']);
	}//end testRunningItTwiceChangesNothingMore()

	/**
	 * A dry run counts and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function testADryRunWritesNothing(): void {
		$report = $this->migration()->run(apply: false);

		self::assertSame(2, $report['converted']);
		self::assertSame([], $this->store->patches);
		self::assertSame(self::ROLE_BOUND, $this->store->rows['case']['c-bound']['assignedGroup']);
	}//end testADryRunWritesNothing()

	/**
	 * A write that fails is counted and does not stop the rest.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsCountedAndTheRestContinue(): void {
		$this->store->failOn = ['c-bound'];

		$report = $this->migration()->run(apply: true);

		self::assertSame(1, $report['failed']);
		self::assertSame(1, $report['converted']);
		self::assertSame('vergunningen', $this->store->rows['case']['c-expanded']['assignedGroup']);
	}//end testAFailedWriteIsCountedAndTheRestContinue()

	/**
	 * Every page is read, not only the first.
	 *
	 * @return void
	 */
	public function testEveryPageIsRead(): void {
		$cases = [];
		for ($i = 0; $i < (CaseTeamMigration::PAGE + 5); $i++) {
			$cases['p-' . $i] = ['id' => 'p-' . $i, 'assignedGroup' => self::ROLE_BOUND];
		}

		$this->store->rows['case'] = $cases;

		$report = $this->migration()->run(apply: false);

		self::assertSame(CaseTeamMigration::PAGE + 5, $report['converted']);
	}//end testEveryPageIsRead()

	/**
	 * Without a case schema nothing runs, and the report says why.
	 *
	 * @return void
	 */
	public function testNothingRunsWithoutACaseSchema(): void {
		$report = $this->migration(config: ['register' => 'dossiq', 'case_schema' => ''])->run(apply: true);

		self::assertFalse($report['ran']);
		self::assertSame('the case schema is not configured', $report['skippedBecause']);
		self::assertSame([], $this->store->patches);
	}//end testNothingRunsWithoutACaseSchema()

	/**
	 * The migration over the fake store and a fixed set of groups.
	 *
	 * @param array<string, string>|null $config The app config, or null for the default.
	 *
	 * @return CaseTeamMigration The migration.
	 */
	private function migration(?array $config = null): CaseTeamMigration {
		$config ??= ['register' => 'dossiq', 'case_schema' => 'case', 'organisatie_rol_schema' => 'organisatieRol'];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$known = ['vergunningen', 'toezicht', 'handhaving'];
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, $known, true));
		$groups->method('search')->willReturnCallback(
			function (string $search): array {
				$group = $this->createMock(IGroup::class);
				$group->method('getGID')->willReturn('handhaving');
				$group->method('getDisplayName')->willReturn('Team Handhaving');

				return [$group];
			}
		);

		return new CaseTeamMigration(settingsService: $settings, groups: $groups, logger: new NullLogger());
	}//end migration()

	/**
	 * A store that pages, patches and remembers.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $rows Rows by schema, then by id.
	 *
	 * @return object The fake.
	 */
	private function store(array $rows): object {
		return new class($rows) {
			/**
			 * The patches written.
			 *
			 * @var array<int, array{id: string, data: array<string, mixed>}>
			 */
			public array $patches = [];

			/**
			 * Ids a patch fails on.
			 *
			 * @var array<int, string>
			 */
			public array $failOn = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, array<string, mixed>>> $rows The rows.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * Run as the system.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * The slug path.
			 *
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>> The page.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$all = array_values($this->rows[$schema] ?? []);

				return array_slice($all, (int)($filters['_offset'] ?? 0), (int)($filters['_limit'] ?? 100));
			}

			/**
			 * Apply a partial change.
			 *
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 *
			 * @return array<string, mixed> The row as it now stands.
			 *
			 * @throws RuntimeException When the id is on the fail list.
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				if (in_array($objectId, $this->failOn, true) === true) {
					throw new RuntimeException('refused');
				}

				$this->patches[] = ['id' => $objectId, 'data' => $data];
				$this->rows[(string)$schema][$objectId] = array_merge($this->rows[(string)$schema][$objectId], $data);

				return $this->rows[(string)$schema][$objectId];
			}
		};
	}//end store()
}//end class
