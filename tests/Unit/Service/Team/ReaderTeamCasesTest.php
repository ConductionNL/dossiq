<?php

/**
 * Which cases a reader sees when the list is narrowed to their teams.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Team
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

namespace OCA\Dossiq\Tests\Unit\Service\Team;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Team\PrimaryTeamService;
use OCA\Dossiq\Service\Team\ReaderTeamCases;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cases in the reader's teams, and the cases where no narrowing applies.
 *
 * @covers \OCA\Dossiq\Service\Team\ReaderTeamCases
 * @uses \OCA\Dossiq\Service\Team\PrimaryTeamService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 */
class ReaderTeamCasesTest extends TestCase {

	private const CASES = [
		['id' => 'a', 'assignedGroup' => 'vergunningen'],
		['id' => 'b', 'assignedGroup' => 'handhaving'],
		['id' => 'c'],
		'not a case',
	];

	/**
	 * @param list<string> $groups The reader's Nextcloud groups.
	 */
	private function reader(?array $groups): ReaderTeamCases {
		$roles = [
			['id' => 'r-1', 'roleName' => 'Vergunningen', 'ncGroupId' => 'vergunningen'],
			['id' => 'r-2', 'roleName' => 'Handhaving', 'ncGroupId' => 'handhaving'],
		];
		$objects = new class ($roles) {
			public function __construct(private array $roles) {
			}

			public function searchObjects(array $query, mixed ...$scope): array {
				return $this->roles;
			}
		};

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => '5', 'organisatie_rol_schema' => '8'][$key] ?? $default)
		);
		$appConfig->method('hasKey')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$logger = $this->createMock(LoggerInterface::class);

		$user  = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('ann')->willReturn($groups === null ? null : $user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups ?? []);

		$teams = new PrimaryTeamService(new SettingsService($appConfig, $apps, $container, $logger), $groupManager, $logger);

		return new ReaderTeamCases($teams, $users);
	}

	public function testAReaderSeesTheCasesOfTheirTeams(): void {
		$this->assertSame([['id' => 'a', 'assignedGroup' => 'vergunningen']], $this->reader(['vergunningen', 'staff'])->casesOf('ann', self::CASES));
		$this->assertSame(['a', 'b'], array_column($this->reader(['vergunningen', 'handhaving'])->casesOf('ann', self::CASES), 'id'));
	}

	public function testAReaderInNoTeamOrAnUnknownReaderIsNotNarrowed(): void {
		$this->assertSame(self::CASES, $this->reader(['staff'])->casesOf('ann', self::CASES));
		$this->assertSame(self::CASES, $this->reader(null)->casesOf('ann', self::CASES));
	}

	public function testInTeamsFiltersOnTheAssignedGroupAndReindexes(): void {
		$this->assertSame([['id' => 'b', 'assignedGroup' => 'handhaving']], ReaderTeamCases::inTeams(self::CASES, ['handhaving']));
		$this->assertSame(self::CASES, ReaderTeamCases::inTeams(self::CASES, []));
		$this->assertSame([], ReaderTeamCases::inTeams(self::CASES, ['other']));
	}
}
