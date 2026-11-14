<?php

/**
 * SeedStaffGroup when the group cannot be made, a read fails, configuration is missing or people are already in the group.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Kernel\Contracts\CaseTeamAccessProjectorInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\SeedStaffGroup;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An ObjectService double serving case rows, or failing.
 */
class StaffGroupCaseObjectService {

	/**
	 * Construct.
	 *
	 * @param array<int, array<string, mixed>> $rows The cases.
	 * @param boolean                          $fail Whether a read throws.
	 */
	public function __construct(
		public array $rows=[],
		public bool $fail=false,
	) {
	}//end __construct()

	/**
	 * Mimic ObjectService::searchObjects().
	 *
	 * @param array<string, mixed> $query The query.
	 * @param mixed ...$scope Scope arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query, mixed ...$scope): array {
		if ($this->fail === true) {
			throw new RuntimeException('cases unreadable');
		}

		return $this->rows;
	}//end searchObjects()
}//end class

/**
 * @covers \OCA\Dossiq\Repair\SeedStaffGroup
 */
class SeedStaffGroupEdgesTest extends TestCase {

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groups;

	/**
	 * The user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $users;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The case-type teams the projector reports.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $caseTypeTeams = [];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groups    = $this->createMock(IGroupManager::class);
		$this->users     = $this->createMock(IUserManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The step over an object service and config values.
	 *
	 * @param object|null           $objects The object service.
	 * @param array<string, string> $config  The config values.
	 *
	 * @return SeedStaffGroup
	 */
	private function step(?object $objects, array $config=['register' => '20', 'case_schema' => '30']): SeedStaffGroup {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);
		$teams = $this->createMock(CaseTeamAccessProjectorInterface::class);
		$teams->method('caseTypeTeams')->willReturn($this->caseTypeTeams);

		return new SeedStaffGroup(
			settingsService: $settings,
			teamAccess: $teams,
			groupManager: $this->groups,
			userManager: $this->users,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}//end step()

	/**
	 * A user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		self::assertSame(
			'Put the current handling teams and assignees into the dossiq staff group',
			$this->step(objects: null)->getName()
		);
	}//end testTheStepNamesWhatItDoes()

	/**
	 * A group that cannot be created leaves the seeding, unrecorded, for the next upgrade.
	 *
	 * @return void
	 */
	public function testAGroupThatCannotBeCreatedIsLeftForTheNextUpgrade(): void {
		$this->groups->method('get')->willReturn(null);
		$this->groups->expects($this->once())->method('createGroup')->with('dossiq-medewerkers')->willReturn(null);
		$this->appConfig->expects($this->never())->method('setValueBool');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with('Staff group: "dossiq-medewerkers" could not be created, seeding left for the next upgrade.');

		$this->step(objects: new StaffGroupCaseObjectService())->run($output);
	}//end testAGroupThatCannotBeCreatedIsLeftForTheNextUpgrade()

	/**
	 * A failed read is logged, warned and not recorded, and nobody is added.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLeftForTheNextUpgrade(): void {
		$staff = $this->createMock(IGroup::class);
		$staff->expects($this->never())->method('addUser');
		$this->groups->method('get')->willReturn($staff);
		$this->appConfig->expects($this->never())->method('setValueBool');
		$this->logger->expects($this->once())
			->method('warning')
			->with('Dossiq: the staff group could not be seeded', ['app' => 'dossiq', 'error' => 'cases unreadable']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with('Staff group: the case types or cases could not be read, seeding left for the next upgrade.');

		$this->step(objects: new StaffGroupCaseObjectService(fail: true))->run($output);
	}//end testAFailedReadIsLeftForTheNextUpgrade()

	/**
	 * A newly created group gets the members of the handling teams that exist; a missing team is skipped,
	 * and without a case schema no assignees are read.
	 *
	 * @return void
	 */
	public function testANewGroupGetsTheMembersOfTheTeamsThatExist(): void {
		$this->caseTypeTeams = [
			['teams' => ['team-a', 'team-gone']],
			['teams' => ['team-a']],
		];
		$staff = $this->createMock(IGroup::class);
		$team  = $this->createMock(IGroup::class);
		$anna  = $this->user(uid: 'anna');
		$team->method('getUsers')->willReturn([$anna]);
		$this->groups->method('get')->willReturnMap(
			[
				['dossiq-medewerkers', null],
				['team-a', $team],
				['team-gone', null],
			]
		);
		$this->groups->expects($this->once())->method('createGroup')->with('dossiq-medewerkers')->willReturn($staff);
		$this->users->method('get')->willReturnMap([['anna', $anna]]);
		$staff->method('inGroup')->willReturn(false);
		$staff->expects($this->once())->method('addUser')->with($anna);
		$this->appConfig->expects($this->once())->method('setValueBool')->with('dossiq', 'staff_group_seeded', true);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Staff group: 1 people added to "dossiq-medewerkers".');

		$cases = new StaffGroupCaseObjectService(rows: [['assignee' => 'bert']]);
		$this->step(objects: $cases, config: ['register' => '20'])->run($output);
	}//end testANewGroupGetsTheMembersOfTheTeamsThatExist()

	/**
	 * Unknown users and people already in the group are not added or counted.
	 *
	 * @return void
	 */
	public function testUnknownUsersAndExistingMembersAreSkipped(): void {
		$staff = $this->createMock(IGroup::class);
		$this->groups->method('get')->willReturn($staff);
		$member = $this->user(uid: 'member');
		$fresh  = $this->user(uid: 'fresh');
		$this->users->method('get')->willReturnMap(
			[
				['member', $member],
				['ghost', null],
				['fresh', $fresh],
			]
		);
		$staff->method('inGroup')->willReturnCallback(static fn (IUser $user): bool => $user === $member);
		$staff->expects($this->once())->method('addUser')->with($fresh);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Staff group: 1 people added to "dossiq-medewerkers".');

		$cases = new StaffGroupCaseObjectService(
			rows: [
				['assignee' => ' member '],
				['assignee' => 'ghost'],
				['assignee' => 'fresh'],
				['assignee' => '   '],
				['assignee' => 42],
			]
		);
		$this->step(objects: $cases)->run($output);
	}//end testUnknownUsersAndExistingMembersAreSkipped()
}//end class
