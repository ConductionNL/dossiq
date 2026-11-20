<?php

/**
 * PrimaryTeamService: the organisation roles are read from the configured store.
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
 *
 * @spec openspec/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Team;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Team\PrimaryTeamService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * teamOf() / teamsOf() read the roles through the configured register and schema.
 *
 * @covers \OCA\Dossiq\Service\Team\PrimaryTeamService
 */
class PrimaryTeamServiceReadTest extends TestCase {

	/**
	 * A role tree: municipality, a domain, a team under it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function roles(): array {
		return [
			['id' => 'gemeente', 'roleName' => 'Gemeente', 'ncGroupId' => 'medewerkers'],
			['@self' => ['id' => 'domein'], 'roleName' => 'Domein', 'ncGroupId' => 'domein', 'parentRoleId' => 'gemeente'],
			['id' => 'team', 'roleName' => 'Team', 'ncGroupId' => 'team', 'parentRoleId' => 'domein'],
			['id' => 'ander', 'roleName' => 'Ander team', 'ncGroupId' => 'ander', 'parentRoleId' => 'gemeente'],
		];
	}//end roles()

	/**
	 * An object service that records its queries and answers with fixed rows.
	 *
	 * @param array<int, mixed>|RuntimeException $answer The rows, or an exception to throw.
	 *
	 * @return object The stub.
	 */
	private static function objectService(array|RuntimeException $answer): object {
		return new class($answer) {
			/**
			 * Every query received.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed>|RuntimeException $answer The rows or the failure.
			 */
			public function __construct(private readonly array|RuntimeException $answer) {
			}

			/**
			 * Answer a numeric-id search.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjects(array $query): array {
				$this->queries[] = $query;
				if ($this->answer instanceof RuntimeException) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end objectService()

	/**
	 * A settings service that hands out the given object service and config.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $schema        The organisation-role schema id.
	 *
	 * @return SettingsService The mock.
	 */
	private function settings(?object $objectService, string $schema = '12'): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '3',
				'organisatie_rol_schema' => $schema,
				default => '',
			}
		);

		return $settings;
	}//end settings()

	/**
	 * A group manager that reports the given groups.
	 *
	 * @param array<int, string> $groupIds The user's groups.
	 *
	 * @return IGroupManager The mock.
	 */
	private function groupManager(array $groupIds): IGroupManager {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($groupIds);

		return $groups;
	}//end groupManager()

	/**
	 * teamOf() reads the roles from the configured register and schema, limited, and picks the deepest.
	 *
	 * @return void
	 */
	public function testTeamOfReadsTheConfiguredRolesAndPicksTheDeepest(): void {
		$store = self::objectService(answer: self::roles());
		$service = new PrimaryTeamService(
			settingsService: $this->settings(objectService: $store),
			groupManager: $this->groupManager(groupIds: ['medewerkers', 'team']),
			logger: new NullLogger()
		);

		$team = $service->teamOf(user: $this->createMock(originalClassName: IUser::class));

		$this->assertSame(expected: ['groupId' => 'team', 'name' => 'Team'], actual: $team);
		$this->assertCount(expectedCount: 1, haystack: $store->queries);
		$this->assertSame(expected: 3, actual: $store->queries[0]['@self']['register']);
		$this->assertSame(expected: 12, actual: $store->queries[0]['@self']['schema']);
		$this->assertSame(expected: 500, actual: $store->queries[0]['_limit']);
	}//end testTeamOfReadsTheConfiguredRolesAndPicksTheDeepest()

	/**
	 * Two teams of equal depth are listed by name.
	 *
	 * @return void
	 */
	public function testTeamsOfEqualDepthAreListedByName(): void {
		$service = new PrimaryTeamService(
			settingsService: $this->settings(objectService: self::objectService(answer: self::roles())),
			groupManager: $this->groupManager(groupIds: ['domein', 'ander']),
			logger: new NullLogger()
		);

		$teams = $service->teamsOf(user: $this->createMock(originalClassName: IUser::class));

		// Both roles sit one level below the municipality, so name order decides.
		$this->assertSame(
			expected: [
				['groupId' => 'ander', 'name' => 'Ander team'],
				['groupId' => 'domein', 'name' => 'Domein'],
			],
			actual: $teams
		);
	}//end testTeamsOfEqualDepthAreListedByName()

	/**
	 * teamsOf() drops a bound role that is the ancestor of another bound role, also when its id sits only in `@self`.
	 *
	 * @return void
	 */
	public function testTeamsOfDropsTheDomainAboveTheTeam(): void {
		$service = new PrimaryTeamService(
			settingsService: $this->settings(objectService: self::objectService(answer: self::roles())),
			groupManager: $this->groupManager(groupIds: ['domein', 'team', 'medewerkers']),
			logger: new NullLogger()
		);

		$this->assertSame(
			expected: [['groupId' => 'team', 'name' => 'Team']],
			actual: $service->teamsOf(user: $this->createMock(originalClassName: IUser::class))
		);
	}//end testTeamsOfDropsTheDomainAboveTheTeam()

	/**
	 * teamsOf() of a user in no group reads nothing and answers an empty list.
	 *
	 * @return void
	 */
	public function testTeamsOfAUserInNoGroupIsEmptyWithoutARead(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$service = new PrimaryTeamService(
			settingsService: $settings,
			groupManager: $this->groupManager(groupIds: []),
			logger: new NullLogger()
		);

		$this->assertSame(expected: [], actual: $service->teamsOf(user: $this->createMock(originalClassName: IUser::class)));
	}//end testTeamsOfAUserInNoGroupIsEmptyWithoutARead()

	/**
	 * Without a configured role schema there are no roles, so no team.
	 *
	 * @return void
	 */
	public function testNoConfiguredSchemaMeansNoTeam(): void {
		$store = self::objectService(answer: self::roles());
		$service = new PrimaryTeamService(
			settingsService: $this->settings(objectService: $store, schema: ''),
			groupManager: $this->groupManager(groupIds: ['team']),
			logger: new NullLogger()
		);

		$this->assertNull(actual: $service->teamOf(user: $this->createMock(originalClassName: IUser::class)));
		$this->assertSame(expected: [], actual: $service->teamsOf(user: $this->createMock(originalClassName: IUser::class)));
		$this->assertSame(expected: [], actual: $store->queries);
	}//end testNoConfiguredSchemaMeansNoTeam()

	/**
	 * A failing read is logged as a warning and rethrown, never read as "no team".
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndRethrown(): void {
		$failure = new RuntimeException('store down');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq primary team: the organisation roles could not be read',
				['exception' => 'store down']
			);

		$service = new PrimaryTeamService(
			settingsService: $this->settings(objectService: self::objectService(answer: $failure)),
			groupManager: $this->groupManager(groupIds: ['team']),
			logger: $logger
		);

		$this->expectExceptionObject(exception: $failure);
		$service->teamOf(user: $this->createMock(originalClassName: IUser::class));
	}//end testAFailingReadIsLoggedAndRethrown()
}//end class
