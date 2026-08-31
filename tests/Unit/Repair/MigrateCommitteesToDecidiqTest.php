<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateCommitteesToDecidiq;
use OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the committee migration repair step.
 *
 * The identity test is the one that matters. A repair step runs during
 * `occ upgrade`, where there is no session; without a system identity
 * OpenRegister resolves the actor as Anonymous and refuses every write — and
 * `$output->warning()` does NOT fail an upgrade. So a step that quietly carried
 * on would do nothing while the upgrade reported success, which is exactly the
 * failure the spec forbids and exactly the one nobody would notice.
 */
class MigrateCommitteesToDecidiqTest extends TestCase {

	/**
	 * Rows the fake register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Rows the step wrote back.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Lines the migration reported.
	 *
	 * @var array<int, string>
	 */
	private array $reported = [];

	/**
	 * Build a fake object service.
	 *
	 * @param boolean $withRunAsSystem Whether it exposes runAsSystem().
	 *
	 * @return object The fake.
	 */
	private function objectService(bool $withRunAsSystem = true): object {
		$rows = &$this->rows;
		$saved = &$this->saved;

		if ($withRunAsSystem === false) {
			return new class($rows, $saved) {
				/**
				 * @param array<int, array<string, mixed>> $rows  Rows.
				 * @param array<int, array<string, mixed>> $saved Saves.
				 */
				public function __construct(private array &$rows, private array &$saved) {
				}

				/**
				 * @param array<string, mixed> $config The query.
				 *
				 * @return array<int, array<string, mixed>> The rows.
				 */
				public function findAll(array $config = []): array {
					return $this->rows;
				}

				/**
				 * @param array<string, mixed> $object   The object.
				 * @param string               $register The register.
				 * @param string               $schema   The schema.
				 * @param string|null          $uuid     The uuid.
				 *
				 * @return array<string, mixed> The stored row.
				 */
				public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): array {
					$this->saved[] = $object;

					return $object;
				}
			};
		}

		return new class($rows, $saved) {
			/**
			 * @param array<int, array<string, mixed>> $rows  Rows.
			 * @param array<int, array<string, mixed>> $saved Saves.
			 */
			public function __construct(private array &$rows, private array &$saved) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->rows;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): array {
				$this->saved[] = $object;

				return $object;
			}
		};

	}//end objectService()

	/**
	 * Build the step.
	 *
	 * @param CommitteeDelegationService|null $delegation      The delegation double.
	 * @param object|null                     $objectService   The register, or null for none.
	 * @param boolean                         $configured      Whether register/schema resolve.
	 *
	 * @return MigrateCommitteesToDecidiq The step.
	 */
	private function step(
		?CommitteeDelegationService $delegation = null,
		?object $objectService = null,
		bool $configured = true,
	): MigrateCommitteesToDecidiq {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn($configured === true ? 'configured' : '');

		return new MigrateCommitteesToDecidiq(
			($delegation ?? $this->availableDelegation()),
			$settings,
			$this->createMock(LoggerInterface::class),
		);

	}//end step()

	/**
	 * A delegation that reports the decision app installed.
	 *
	 * @param string $returns The body id it hands back.
	 *
	 * @return CommitteeDelegationService The double.
	 */
	private function availableDelegation(string $returns = 'gb-1'): CommitteeDelegationService {
		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->method('isAvailable')->willReturn(true);
		$delegation->method('ensureGovernanceBody')->willReturn($returns);

		return $delegation;

	}//end availableDelegation()

	/**
	 * An output that records what the step said.
	 *
	 * @return IOutput The double.
	 */
	private function recordingOutput(): IOutput {
		$reported = &$this->reported;
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $m) use (&$reported): void {
				$reported[] = $m;
			}
		);
		$output->method('warning')->willReturnCallback(
			static function (string $m) use (&$reported): void {
				$reported[] = $m;
			}
		);

		return $output;

	}//end recordingOutput()

	/**
	 * Everything the step reported, joined.
	 *
	 * @return string The text.
	 */
	private function said(): string {
		return implode("\n", $this->reported);
	}//end said()

	/**
	 * The step names itself for the upgrade log.
	 *
	 * @return void
	 */
	public function testItNamesItself(): void {
		$this->assertNotSame('', $this->step()->getName());

	}//end testItNamesItself()

	/**
	 * A committee is raised and its id recorded.
	 *
	 * @return void
	 */
	public function testItRaisesAndRecordsTheMapping(): void {
		$this->rows = [['id' => 'cmte-1', 'name' => 'BAC', 'active' => true, 'governanceBodyId' => '']];

		$this->step(objectService: $this->objectService())->run($this->recordingOutput());

		$this->assertCount(1, $this->saved);
		$this->assertSame('gb-1', $this->saved[0]['governanceBodyId']);
		$this->assertStringContainsString('1 migrated', $this->said());

	}//end testItRaisesAndRecordsTheMapping()

	/**
	 * An already-mapped committee is skipped, so a re-run mints nothing.
	 *
	 * @return void
	 */
	public function testAnAlreadyMappedCommitteeIsSkipped(): void {
		$this->rows = [['id' => 'cmte-1', 'name' => 'BAC', 'governanceBodyId' => 'gb-existing']];

		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->method('isAvailable')->willReturn(true);
		$delegation->expects($this->never())->method('ensureGovernanceBody');

		$this->step($delegation, $this->objectService())->run($this->recordingOutput());

		$this->assertSame([], $this->saved);
		$this->assertStringContainsString('1 already mapped', $this->said());

	}//end testAnAlreadyMappedCommitteeIsSkipped()

	/**
	 * 🔴 No system identity means the step FAILS, not warns.
	 *
	 * @return void
	 */
	public function testItRefusesToRunWithoutASystemIdentity(): void {
		$this->rows = [['id' => 'cmte-1', 'name' => 'BAC']];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/runAsSystem/');

		$this->step(objectService: $this->objectService(withRunAsSystem: false))->run($this->recordingOutput());

	}//end testItRefusesToRunWithoutASystemIdentity()

	/**
	 * Without the decision app the step reports a SKIP and changes nothing.
	 *
	 * @return void
	 */
	public function testWithoutTheDecisionAppItSkipsCleanly(): void {
		$this->rows = [['id' => 'cmte-1', 'name' => 'BAC']];

		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->method('isAvailable')->willReturn(false);
		$delegation->expects($this->never())->method('ensureGovernanceBody');

		$this->step($delegation, $this->objectService())->run($this->recordingOutput());

		$this->assertSame([], $this->saved);
		$this->assertStringContainsString('not installed', $this->said());

	}//end testWithoutTheDecisionAppItSkipsCleanly()

	/**
	 * Without OpenRegister it warns and changes nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItWarns(): void {
		$this->step(objectService: null)->run($this->recordingOutput());

		$this->assertStringContainsString('OpenRegister is not available', $this->said());

	}//end testWithoutOpenRegisterItWarns()

	/**
	 * An unconfigured register/schema warns and changes nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterWarns(): void {
		$this->step(objectService: $this->objectService(), configured: false)->run($this->recordingOutput());

		$this->assertStringContainsString('not configured', $this->said());

	}//end testAnUnconfiguredRegisterWarns()

	/**
	 * One committee that cannot be raised does not abandon the rest, and the
	 * summary reports the run as partial rather than clean.
	 *
	 * @return void
	 */
	public function testOneFailureDoesNotAbandonTheRest(): void {
		$this->rows = [
			['id' => 'cmte-1', 'name' => 'Breaks'],
			['id' => 'cmte-2', 'name' => 'Works'],
		];

		$delegation = $this->createMock(CommitteeDelegationService::class);
		$delegation->method('isAvailable')->willReturn(true);
		$delegation->method('ensureGovernanceBody')->willReturnCallback(
			static function (array $committee): string {
				if ($committee['id'] === 'cmte-1') {
					throw new RuntimeException('refused');
				}

				return 'gb-2';
			}
		);

		$this->step($delegation, $this->objectService())->run($this->recordingOutput());

		$this->assertCount(1, $this->saved);
		$this->assertSame('cmte-2', $this->saved[0]['id']);
		$this->assertStringContainsString('1 migrated', $this->said());
		$this->assertStringContainsString('1 failed', $this->said());

	}//end testOneFailureDoesNotAbandonTheRest()

	/**
	 * A committee with no id is counted as failed rather than skipped silently.
	 *
	 * @return void
	 */
	public function testACommitteeWithNoIdIsCountedAsFailed(): void {
		$this->rows = [['name' => 'No id here']];

		$this->step(objectService: $this->objectService())->run($this->recordingOutput());

		$this->assertSame([], $this->saved);
		$this->assertStringContainsString('1 failed', $this->said());

	}//end testACommitteeWithNoIdIsCountedAsFailed()

	/**
	 * A read that throws is warned about, and the run still completes.
	 *
	 * @return void
	 */
	public function testAFailingListIsWarnedAbout(): void {
		$rows = &$this->rows;
		$saved = &$this->saved;
		$objectService = new class($rows, $saved) {
			/**
			 * @param array<int, array<string, mixed>> $rows  Rows.
			 * @param array<int, array<string, mixed>> $saved Saves.
			 */
			public function __construct(private array &$rows, private array &$saved) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				throw new RuntimeException('register unavailable');
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): array {
				$this->saved[] = $object;

				return $object;
			}
		};

		$this->step(objectService: $objectService)->run($this->recordingOutput());

		$this->assertStringContainsString('could not list committees', $this->said());
		$this->assertSame([], $this->saved);

	}//end testAFailingListIsWarnedAbout()

	/**
	 * A body raised but not recorded locally is counted as FAILED, so the
	 * summary reports a partial run as partial.
	 *
	 * Harmless to retry: the other side resolves on
	 * (sourceApp, externalReference) and matches the body it already has.
	 *
	 * @return void
	 */
	public function testABodyRaisedButNotRecordedCountsAsFailed(): void {
		$this->rows = [['id' => 'cmte-1', 'name' => 'BAC', 'active' => true]];

		$rows = &$this->rows;
		$objectService = new class($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows Rows.
			 */
			public function __construct(private array &$rows) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->rows;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): array {
				throw new RuntimeException('local write refused');
			}
		};

		$this->step(objectService: $objectService)->run($this->recordingOutput());

		$this->assertStringContainsString('but could not record it', $this->said());
		$this->assertStringContainsString('1 failed', $this->said());

	}//end testABodyRaisedButNotRecordedCountsAsFailed()

}//end class
