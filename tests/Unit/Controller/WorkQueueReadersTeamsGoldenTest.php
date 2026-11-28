<?php

/**
 * Golden record of narrowing the workload to the reader's teams.
 *
 * Records, per world, the handler rows the workload keeps and every log line,
 * so a refactor cannot change whose workload a reader sees.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\Support\WorkloadScope;
use OCA\Dossiq\Service\Team\PrimaryTeamService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the workload's narrowing to the reader's teams (WorkloadScope: teamGroupIds, then ofTeams).
 *
 * @covers \OCA\Dossiq\Controller\Support\WorkloadScope
 */
class WorkQueueReadersTeamsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Log lines of the current world.
	 *
	 * @var list<string>
	 */
	private array $logLines = [];

	/**
	 * Every world the narrowing distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testNarrowingAcrossWorlds(): void {
		$handlers = [
			['handler' => 'alice', 'openCaseCount' => 4],
			['handler' => 'bob', 'openCaseCount' => 2],
			['handler' => 'carol', 'openCaseCount' => 1],
		];
		$groups = ['team-a' => ['alice' => 'Alice', 'dave' => 'Dave'], 'team-b' => ['bob' => 'Bob', 'alice' => 'Alice'], 'team-empty' => [], 'team-numbers' => [42 => 'Forty-two']];
		$worlds = [
			'teams cannot be read' => ['teams' => 'throw'],
			'in no team' => ['teams' => []],
			'one team' => ['teams' => [['groupId' => 'team-a']]],
			'two teams sharing a member' => ['teams' => [['groupId' => 'team-a'], ['groupId' => 'team-b']]],
			'an empty team' => ['teams' => [['groupId' => 'team-empty']]],
			'a team whose member uid reads as a number' => ['teams' => [['groupId' => 'team-numbers']]],
			'a team row without a group' => ['teams' => [['name' => 'no group'], ['groupId' => 'team-b']]],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->logLines = [];
			$scope = $this->scopeFor(teams: $world['teams'], groups: $groups);
			$kept  = $scope->ofTeams(teamGroupIds: $scope->teamGroupIds(user: $this->createMock(originalClassName: IUser::class)), handlers: $handlers);
			$record[$name] = ['kept' => $kept, 'log' => $this->logLines];
		}

		$this->assertMatchesGolden(name: 'work-queue-readers-teams', value: $record);
	}//end testNarrowingAcrossWorlds()

	/**
	 * The workload scope of a reader who is in $teams.
	 *
	 * @param array<mixed>|string                $teams  The reader's teams, or 'throw'.
	 * @param array<string, array<mixed, string>> $groups Members per group.
	 *
	 * @return WorkloadScope
	 */
	private function scopeFor(array|string $teams, array $groups): WorkloadScope {
		$teamService = $this->createMock(PrimaryTeamService::class);
		if ($teams === 'throw') {
			$teamService->method('teamsOf')->willThrowException(new RuntimeException('teams offline'));
		} else {
			$teamService->method('teamsOf')->willReturn($teams);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('displayNamesInGroup')->willReturnCallback(
			static fn (string $gid): array => ($groups[$gid] ?? [])
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logLines[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new WorkloadScope(
			groupManager: $groupManager,
			userManager: $this->createMock(originalClassName: IUserManager::class),
			teams: $teamService,
			logger: $logger,
		);
	}//end scopeFor()
}//end class
