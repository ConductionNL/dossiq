<?php

/**
 * The upgrade step that imports and reconciles the Dossiq configuration.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\InitializeSettings;
use OCA\Dossiq\Service\Access\CaseFieldRoleProjector;
use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The order of the import and reconciliation calls, what each reports, and
 * how an absent OpenRegister or a failing step is handled.
 *
 * @covers \OCA\Dossiq\Repair\InitializeSettings
 */
final class InitializeSettingsStepTest extends TestCase {
	/** @var array<int, array{0: string, 1: string}> */
	private array $out = [];

	/** @var array<int, string> */
	private array $calls = [];

	/**
	 * Run the step.
	 *
	 * @param bool                   $openRegister Whether OpenRegister is available.
	 * @param array|RuntimeException $import       What loadConfiguration answers or throws.
	 * @param bool                   $fieldRoles   What the field-role projector answers.
	 * @param bool                   $teamAccess   What the team-access projector answers.
	 * @param LoggerInterface|null   $logger       The logger, a silent one when null.
	 */
	private function initialise(bool $openRegister, array|RuntimeException $import, bool $fieldRoles = false, bool $teamAccess = false, ?LoggerInterface $logger = null): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegister);
		$settings->method('loadConfiguration')->willReturnCallback(
			function () use ($import): array {
				$this->calls[] = 'load';
				if ($import instanceof RuntimeException) {
					throw $import;
				}

				return $import;
			}
		);
		$settings->method('reconcileSchemaConfig')->willReturnCallback(
			function (): int {
				$this->calls[] = 'keys';
				return 3;
			}
		);
		$settings->method('reconcileSchemaDeclarativeConfig')->willReturnCallback(
			function (): int {
				$this->calls[] = 'declarative';
				return 5;
			}
		);
		$roles = $this->createMock(originalClassName: CaseFieldRoleProjector::class);
		$roles->method('reapply')->willReturnCallback(
			function () use ($fieldRoles): bool {
				$this->calls[] = 'roles';
				return $fieldRoles;
			}
		);
		$teams = $this->createMock(originalClassName: CaseTeamAccessProjector::class);
		$teams->method('reapply')->willReturnCallback(
			function () use ($teamAccess): bool {
				$this->calls[] = 'teams';
				return $teamAccess;
			}
		);
		if ($logger === null) {
			$logger = $this->createMock(originalClassName: LoggerInterface::class);
			$logger->expects($this->never())->method('error');
		}

		$output = $this->createMock(originalClassName: IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $line) use ($level): void {
					$this->out[] = [$level, $line];
				}
			);
		}

		(new InitializeSettings(settingsService: $settings, fieldRoles: $roles, teamAccess: $teams, logger: $logger))->run($output);
	}//end initialise()

	public function testASuccessfulImportReconcilesEverythingInOrder(): void {
		$this->initialise(openRegister: true, import: ['success' => true, 'version' => '1.9.0'], fieldRoles: true, teamAccess: true);

		$this->assertSame(['load', 'keys', 'declarative', 'roles', 'teams'], $this->calls);
		$this->assertSame(
			[
				['info', 'Initializing Dossiq configuration...'],
				['info', 'Dossiq schema config keys reconciled (3 written)'],
				['info', 'Dossiq declarative schema configuration reconciled (5 written)'],
				['info', 'Dossiq per-role field rules re-applied to the case schema'],
				['info', 'Dossiq handling team rules written to the case schema'],
				['info', 'Dossiq configuration imported successfully (version: 1.9.0)'],
			],
			$this->out
		);
	}//end testASuccessfulImportReconcilesEverythingInOrder()

	public function testUnchangedRulesAreNotReportedAndAMissingVersionIsUnknown(): void {
		$this->initialise(openRegister: true, import: ['success' => true]);

		$this->assertSame(['load', 'keys', 'declarative', 'roles', 'teams'], $this->calls);
		$this->assertCount(4, $this->out);
		$this->assertSame(['info', 'Dossiq configuration imported successfully (version: unknown)'], $this->out[3]);
	}//end testUnchangedRulesAreNotReportedAndAMissingVersionIsUnknown()

	public function testAFailedImportStillReconcilesAndWarns(): void {
		$this->initialise(openRegister: true, import: ['success' => false, 'message' => 'file unreadable']);
		$this->initialise(openRegister: true, import: ['success' => false]);

		$this->assertSame(['info', 'Dossiq declarative schema configuration reconciled (5 written)'], $this->out[2]);
		$this->assertSame(['warning', 'Dossiq configuration import issue: file unreadable'], $this->out[3]);
		$this->assertSame(['warning', 'Dossiq configuration import issue: unknown error'], $this->out[7]);
		$this->assertCount(8, $this->out);
	}//end testAFailedImportStillReconcilesAndWarns()

	public function testAThrowingImportStopsTheRestAndIsLogged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Dossiq initialization failed', ['exception' => 'register locked']);

		$this->initialise(openRegister: true, import: new RuntimeException('register locked'), logger: $logger);

		$this->assertSame(['load'], $this->calls);
		$this->assertSame([['info', 'Initializing Dossiq configuration...'], ['warning', 'Could not auto-configure Dossiq: register locked']], $this->out);
	}//end testAThrowingImportStopsTheRestAndIsLogged()

	public function testWithoutOpenRegisterNothingIsImported(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Dossiq: OpenRegister not available, skipping register initialization');

		$this->initialise(openRegister: false, import: ['success' => true], logger: $logger);

		$this->assertSame([], $this->calls);
		$this->assertSame([['info', 'Initializing Dossiq configuration...'], ['warning', 'OpenRegister is not installed or enabled. Skipping auto-configuration.']], $this->out);
	}//end testWithoutOpenRegisterNothingIsImported()
}//end class
