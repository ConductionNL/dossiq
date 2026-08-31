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

use OCA\Dossiq\Repair\MigrateParafeerroutesToDecidiq;
use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the parafeerroute migration repair step.
 *
 * The identity test is the one that matters. A repair step runs during
 * `occ upgrade`, where there is no session; without a system identity
 * OpenRegister resolves the actor as Anonymous and refuses every write — and
 * `$output->warning()` does NOT fail an upgrade. So a step that quietly carried
 * on would do nothing while the upgrade reported success, which is exactly the
 * failure the spec forbids and exactly the one nobody would notice.
 */
class MigrateParafeerroutesToDecidiqTest extends TestCase {

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
	 * @param ParaferingDelegationService|null $delegation      The delegation double.
	 * @param object|null                     $objectService   The register, or null for none.
	 * @param boolean                         $configured      Whether register/schema resolve.
	 *
	 * @return MigrateParafeerroutesToDecidiq The step.
	 */
	private function step(
		?ParaferingDelegationService $delegation = null,
		?object $objectService = null,
		bool $configured = true,
	): MigrateParafeerroutesToDecidiq {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn($configured === true ? 'configured' : '');

		return new MigrateParafeerroutesToDecidiq(
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
	 * @return ParaferingDelegationService The double.
	 */
	private function availableDelegation(string $returns = 'ar-1'): ParaferingDelegationService {
		$delegation = $this->createMock(ParaferingDelegationService::class);
		$delegation->method('isAvailable')->willReturn(true);
		$delegation->method('holdRoute')->willReturn($returns);

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
	 * A route is raised and its id recorded.
	 *
	 * @return void
	 */
	public function testItRaisesAndRecordsTheMapping(): void {
		$this->rows = [['id' => 'pr-1', 'name' => 'Collegeadvies', 'active' => true, 'approvalRouteId' => '']];

		$this->step(objectService: $this->objectService())->run($this->recordingOutput());

		$this->assertCount(1, $this->saved);
		$this->assertSame('ar-1', $this->saved[0]['approvalRouteId']);
		$this->assertStringContainsString('1 held', $this->said());

	}//end testItRaisesAndRecordsTheMapping()

	/**
	 * An already-mapped route is skipped, so a re-run mints nothing.
	 *
	 * @return void
	 */
	public function testAnAlreadyMappedRouteIsSkipped(): void {
		$this->rows = [['id' => 'pr-1', 'name' => 'Collegeadvies', 'approvalRouteId' => 'ar-existing']];

		$delegation = $this->createMock(ParaferingDelegationService::class);
		$delegation->method('isAvailable')->willReturn(true);
		$delegation->expects($this->never())->method('holdRoute');

		$this->step($delegation, $this->objectService())->run($this->recordingOutput());

		$this->assertSame([], $this->saved);
		$this->assertStringContainsString('1 already mapped', $this->said());

	}//end testAnAlreadyMappedRouteIsSkipped()

	/**
	 * 🔴 No system identity means the step FAILS, not warns.
	 *
	 * @return void
	 */
	public function testItRefusesToRunWithoutASystemIdentity(): void {
		$this->rows = [['id' => 'pr-1', 'name' => 'Collegeadvies']];

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
		$this->rows = [['id' => 'pr-1', 'name' => 'Collegeadvies']];

		$delegation = $this->createMock(ParaferingDelegationService::class);
		$delegation->method('isAvailable')->willReturn(false);
		$delegation->expects($this->never())->method('holdRoute');

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
	 * One route that cannot be raised does not abandon the rest, and the
	 * summary reports the run as partial rather than clean.
	 *
	 * @return void
	 */
	public function testOneFailureDoesNotAbandonTheRest(): void {
		$this->rows = [
			['id' => 'pr-1', 'name' => 'Breaks'],
			['id' => 'pr-2', 'name' => 'Works'],
		];

		$delegation = $this->createMock(ParaferingDelegationService::class);
		$delegation->method('isAvailable')->willReturn(true);
		$delegation->method('holdRoute')->willReturnCallback(
			static function (array $route): string {
				if ($route['id'] === 'pr-1') {
					throw new RuntimeException('refused');
				}

				return 'ar-2';
			}
		);

		$this->step($delegation, $this->objectService())->run($this->recordingOutput());

		$this->assertCount(1, $this->saved);
		$this->assertSame('pr-2', $this->saved[0]['id']);
		$this->assertStringContainsString('1 held', $this->said());
		$this->assertStringContainsString('1 failed', $this->said());

	}//end testOneFailureDoesNotAbandonTheRest()

	/**
	 * A route with no id is counted as failed rather than skipped silently.
	 *
	 * @return void
	 */
	public function testARouteWithNoIdIsCountedAsFailed(): void {
		$this->rows = [['name' => 'No id here']];

		$this->step(objectService: $this->objectService())->run($this->recordingOutput());

		$this->assertSame([], $this->saved);
		$this->assertStringContainsString('1 failed', $this->said());

	}//end testARouteWithNoIdIsCountedAsFailed()

}//end class
