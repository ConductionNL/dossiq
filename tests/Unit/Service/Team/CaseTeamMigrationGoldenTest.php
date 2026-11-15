<?php

/**
 * Characterisation tests for CaseTeamMigration::run: the whole report of a
 * mixed caseload, the skip without OpenRegister, the warning of a failed
 * write, the case id read from uuid or @self, and an operation that answers
 * no report.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Team;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Team\CaseTeamMigration;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class CaseTeamMigrationGoldenTest extends TestCase {

	private const ROLE_BOUND = '11111111-1111-4111-8111-111111111111';

	private const ROLE_UNBOUND = '22222222-2222-4222-8222-222222222222';

	/**
	 * Every line logged.
	 *
	 * @var array<int, array>
	 */
	private array $lines = [];

	private function migration(?object $store, array $config = ['register' => 'dossiq', 'case_schema' => 'case', 'organisatie_rol_schema' => 'organisatieRol']): CaseTeamMigration {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, ['vergunningen', 'handhaving'], true));
		$groups->method('search')->willReturnCallback(
			function (string $search): array {
				$group = $this->createMock(IGroup::class);
				$group->method('getGID')->willReturn('handhaving');
				$group->method('getDisplayName')->willReturn('Team Handhaving');

				return [$group];
			}
		);

		$sink = &$this->lines;
		$logger = new class($sink) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->sink[] = [(string)$level, (string)$message, $context];
			}
		};

		return new CaseTeamMigration(settingsService: $settings, groups: $groups, logger: $logger);
	}//end migration()

	private function rows(): array {
		return [
			'case' => [
				'c-1' => ['uuid' => 'u-1', 'title' => ' By uuid ', 'assignedGroup' => self::ROLE_BOUND],
				'c-2' => ['@self' => 's-2', 'assignedGroup' => self::ROLE_UNBOUND],
				'c-3' => ['id' => 'c-3', 'title' => 'Group', 'assignedGroup' => 'handhaving'],
				'c-4' => ['id' => 'c-4', 'assignedGroup' => '  '],
			],
			'organisatieRol' => [
				self::ROLE_BOUND => ['id' => self::ROLE_BOUND, 'roleName' => 'Team Vergunningen', 'ncGroupId' => ' vergunningen '],
				self::ROLE_UNBOUND => ['id' => self::ROLE_UNBOUND, 'roleName' => ' Team Handhaving '],
			],
		];
	}//end rows()

	public function testTheWholeReportOfADryRun(): void {
		$report = $this->migration($this->store($this->rows()))->run(apply: false);

		self::assertSame(
			[
				'ran' => true,
				'skippedBecause' => '',
				'converted' => 1,
				'alreadyGroup' => 1,
				'failed' => 0,
				'unmapped' => [
					[
						'case' => 's-2',
						'title' => '',
						'value' => self::ROLE_UNBOUND,
						'reason' => CaseTeamMigration::REASON_NO_GROUP,
						'roleName' => 'Team Handhaving',
						'hints' => ['handhaving'],
					],
				],
			],
			$report
		);
		self::assertSame([], $this->lines);
	}//end testTheWholeReportOfADryRun()

	public function testAWriteGoesToTheCaseIdAndAFailureIsLogged(): void {
		$store = $this->store($this->rows());
		$store->failOn = ['u-1'];
		$report = $this->migration($store)->run(apply: true);

		self::assertSame(1, $report['failed']);
		self::assertSame(0, $report['converted']);
		self::assertSame(
			[['warning', 'Dossiq team migration: case {case} could not be moved to group {group}', ['case' => 'u-1', 'group' => 'vergunningen', 'exception' => 'refused']]],
			$this->lines
		);
	}//end testAWriteGoesToTheCaseIdAndAFailureIsLogged()

	public function testNothingRunsWithoutOpenRegister(): void {
		self::assertSame(
			['ran' => false, 'skippedBecause' => 'OpenRegister is not available', 'converted' => 0, 'alreadyGroup' => 0, 'failed' => 0, 'unmapped' => []],
			$this->migration(null)->run(apply: true)
		);
		self::assertSame(
			'the case schema is not configured',
			$this->migration($this->store($this->rows()), ['register' => '', 'case_schema' => 'case'])->run(apply: true)['skippedBecause']
		);
	}//end testNothingRunsWithoutOpenRegister()

	public function testAnOperationThatAnswersNoReportReportsNothingRan(): void {
		$store = $this->store($this->rows());
		$store->systemAnswer = 'not a report';

		self::assertSame(
			['ran' => false, 'skippedBecause' => '', 'converted' => 0, 'alreadyGroup' => 0, 'failed' => 0, 'unmapped' => []],
			$this->migration($store)->run(apply: false)
		);
	}//end testAnOperationThatAnswersNoReportReportsNothingRan()

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
			public mixed $systemAnswer = null;

			public function runAsSystem(callable $operation): mixed {
				$answer = $operation();

				return $this->systemAnswer ?? $answer;
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
