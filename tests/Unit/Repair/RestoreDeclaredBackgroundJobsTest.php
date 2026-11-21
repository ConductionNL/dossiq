<?php

/**
 * The background jobs info.xml declares are put back on the job list when they are missing.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/background-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RestoreDeclaredBackgroundJobs;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Only the missing declared jobs are added; a job already listed keeps its schedule.
 *
 * @covers \OCA\Dossiq\Repair\RestoreDeclaredBackgroundJobs
 */
final class RestoreDeclaredBackgroundJobsTest extends TestCase {

	/**
	 * The step over a job list that already holds the given classes.
	 *
	 * @param array<int, string> $declared The jobs info.xml declares.
	 * @param array<int, string> $listed   The jobs already on the job list.
	 * @param array<int, string> $added    Receives every class the step adds.
	 *
	 * @return RestoreDeclaredBackgroundJobs
	 */
	private function step(array $declared, array $listed, array &$added): RestoreDeclaredBackgroundJobs {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getAppInfo')->with('dossiq')->willReturn(['id' => 'dossiq', 'background-jobs' => $declared]);

		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->method('has')->willReturnCallback(
			static fn (string $class, mixed $argument): bool => ($argument === null && in_array($class, $listed, true) === true)
		);
		$jobs->method('add')->willReturnCallback(
			function (string $class, mixed $argument = null) use (&$added): void {
				$this->assertNull(actual: $argument, message: 'A declared job is listed without an argument, as Nextcloud lists it.');
				$added[] = $class;
			}
		);

		return new RestoreDeclaredBackgroundJobs(jobList: $jobs, appManager: $apps);
	}//end step()

	/**
	 * The declared jobs missing from the job list are added; the listed ones are left alone.
	 *
	 * @return void
	 */
	public function testOnlyTheMissingDeclaredJobsAreAdded(): void {
		$added = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with('Put 2 missing Dossiq background jobs back on the job list.');

		$this->step(declared: ['A\\Listed', 'A\\Missing', 'B\\Missing'], listed: ['A\\Listed'], added: $added)->run(output: $output);

		self::assertSame(expected: ['A\\Missing', 'B\\Missing'], actual: $added);
	}//end testOnlyTheMissingDeclaredJobsAreAdded()

	/**
	 * Nothing declared, or everything listed, adds nothing.
	 *
	 * @return void
	 */
	public function testNothingMissingAddsNothing(): void {
		$added = [];
		$this->step(declared: ['A\\Listed'], listed: ['A\\Listed'], added: $added)->run(output: $this->createMock(originalClassName: IOutput::class));
		$this->step(declared: [], listed: [], added: $added)->run(output: $this->createMock(originalClassName: IOutput::class));

		self::assertSame(expected: [], actual: $added);
	}//end testNothingMissingAddsNothing()

	/**
	 * Every job info.xml declares is a class the app ships, so the job list never drops one as unknown.
	 *
	 * @return void
	 */
	public function testEveryDeclaredJobIsAShippedClass(): void {
		$manifest = simplexml_load_file(filename: __DIR__ . '/../../../appinfo/info.xml');
		$declared = array_map('strval', iterator_to_array($manifest->{'background-jobs'}->job, false));

		self::assertNotSame(expected: [], actual: $declared);
		foreach ($declared as $class) {
			self::assertTrue(condition: class_exists(class: $class), message: $class . ' is declared but not shipped.');
		}
	}//end testEveryDeclaredJobIsAShippedClass()
}//end class
