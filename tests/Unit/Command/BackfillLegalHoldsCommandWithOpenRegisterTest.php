<?php

/**
 * BackfillLegalHoldsCommand with OpenRegister present: the services are taken
 * from the container, the scan's errors are shown, an empty scan ends early,
 * and a scan with candidates is handed to the applier with both flags.
 *
 * OpenRegister's ObjectService, MagicMapper and LegalHoldService are declared
 * here, empty, so `class_exists()` holds. Each test runs in its own process:
 * declared in the shared one, they would make every "without OpenRegister"
 * test in the suite see an OpenRegister that is not there.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
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

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\Backfill\AwbProceedingScanner;
use OCA\Dossiq\Command\Backfill\LegalHoldApplier;
use OCA\Dossiq\Command\BackfillLegalHoldsCommand;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\BackfillLegalHoldsCommand
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BackfillLegalHoldsCommandWithOpenRegisterTest extends TestCase {

	private const SERVICES = [
		'OCA\OpenRegister\Service\ObjectService',
		'OCA\OpenRegister\Db\MagicMapper',
		'OCA\OpenRegister\Service\Archival\LegalHoldService',
	];

	private AwbProceedingScanner&MockObject $scanner;

	private LegalHoldApplier&MockObject $applier;

	/**
	 * The service the container answers per class; a missing entry throws.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = [];

	protected function setUp(): void {
		foreach (self::SERVICES as $fqn) {
			if (class_exists($fqn) === false) {
				$namespace = substr($fqn, 0, (int)strrpos($fqn, '\\'));
				$class = substr($fqn, ((int)strrpos($fqn, '\\') + 1));
				eval('namespace ' . $namespace . '; class ' . $class . ' {}');
			}

			$this->resolved[$fqn] = new $fqn();
		}

		$this->scanner = $this->createMock(AwbProceedingScanner::class);
		$this->applier = $this->createMock(LegalHoldApplier::class);
	}

	/**
	 * Run the command signed in.
	 *
	 * @param array<string, mixed> $input The options.
	 *
	 * @return CommandTester
	 */
	private function runBackfill(array $input = []): CommandTester {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id): mixed {
			if (array_key_exists($id, $this->resolved) === false) {
				throw new RuntimeException('not registered: ' . $id);
			}

			return $this->resolved[$id];
		});

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$tester = new CommandTester(
			new BackfillLegalHoldsCommand(
				container: $container,
				userSession: $session,
				groupManager: $this->createMock(IGroupManager::class),
				scanner: $this->scanner,
				applier: $this->applier
			)
		);
		$tester->execute($input);

		return $tester;
	}

	public function testAnEmptyScanEndsWithoutApplyingAnything(): void {
		$this->scanner->expects($this->once())
			->method('scan')
			->with($this->identicalTo($this->resolved[self::SERVICES[0]]), false, $this->anything())
			->willReturn([]);
		$this->scanner->method('getScanErrors')->willReturn([]);
		$this->applier->expects($this->never())->method('reportAndApply');

		$tester = $this->runBackfill();

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertSame("No cases with an open Awb proceeding were found. Nothing to backfill.\n", $tester->getDisplay());
	}

	public function testScanErrorsAreShownBeforeTheOutcome(): void {
		$this->scanner->method('scan')->willReturn([]);
		$this->scanner->method('getScanErrors')->willReturn(['bezwaar register unreadable', 'beroep schema missing']);

		$display = $this->runBackfill()->getDisplay();

		$this->assertSame(
			"  [scan failed] bezwaar register unreadable\n  [scan failed] beroep schema missing\n"
				. "No cases with an open Awb proceeding were found. Nothing to backfill.\n",
			$display
		);
	}

	public function testCandidatesGoToTheApplierWithTheServicesAndBothFlags(): void {
		$candidates = [['case' => 'case-1', 'proceeding' => 'bz-1']];
		$this->scanner->expects($this->once())->method('scan')->with($this->anything(), true, $this->anything())->willReturn($candidates);
		$this->scanner->method('getScanErrors')->willReturn([]);
		$this->applier->expects($this->once())
			->method('reportAndApply')
			->with(
				$candidates,
				$this->identicalTo($this->resolved[self::SERVICES[1]]),
				$this->identicalTo($this->resolved[self::SERVICES[2]]),
				true,
				true,
				$this->anything()
			)
			->willReturn(Command::SUCCESS);

		$this->assertSame(Command::SUCCESS, $this->runBackfill(['--apply' => true, '--include-deleted' => true])->getStatusCode());
	}

	public function testTheApplierDecidesTheExitCode(): void {
		$this->scanner->method('scan')->willReturn([['case' => 'case-1']]);
		$this->scanner->method('getScanErrors')->willReturn([]);
		$this->applier->method('reportAndApply')->willReturn(Command::FAILURE);

		$this->assertSame(Command::FAILURE, $this->runBackfill()->getStatusCode());
	}

	/**
	 * A service the container cannot give, or gives as something other than an
	 * object, counts as OpenRegister being absent.
	 *
	 * @return void
	 */
	public function testAServiceTheContainerCannotGiveRefusesTheBackfill(): void {
		$this->scanner->expects($this->never())->method('scan');

		unset($this->resolved[self::SERVICES[2]]);
		$tester = $this->runBackfill();
		$this->assertSame(Command::FAILURE, $tester->getStatusCode());
		$this->assertSame("OpenRegister is not available; cannot backfill legal holds.\n", $tester->getDisplay());

		$this->resolved[self::SERVICES[2]] = 'not an object';
		$this->assertSame(Command::FAILURE, $this->runBackfill()->getStatusCode());
	}
}
