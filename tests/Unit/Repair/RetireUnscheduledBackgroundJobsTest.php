<?php

/**
 * A job taken out of info.xml leaves the instance's job list too.
 *
 * Nextcloud adds the jobs info.xml names on upgrade and never removes one, so
 * a job that is parked or deleted would keep running on every instance that
 * already had it. This drives the repair step against a recording job list.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RetireUnscheduledBackgroundJobs;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The parked and removed jobs are taken off the job list, and only they.
 *
 * @covers \OCA\Dossiq\Repair\RetireUnscheduledBackgroundJobs
 */
final class RetireUnscheduledBackgroundJobsTest extends TestCase {

	/**
	 * Every class the step removes from the job list, and nothing else.
	 *
	 * @return void
	 */
	public function testTheParkedAndRemovedJobsLeaveTheJobList(): void {
		$removed = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('remove')->willReturnCallback(
			function (string $class, mixed $argument = null) use (&$removed): void {
				$this->assertNull($argument, 'Every instance of the job goes, whatever its argument.');
				$removed[] = $class;
			}
		);

		(new RetireUnscheduledBackgroundJobs(jobList: $jobs))->run($this->createMock(IOutput::class));

		sort($removed);
		$this->assertSame(
			[
				'OCA\Dossiq\BackgroundJob\AppointmentReminderJob',
				'OCA\Dossiq\BackgroundJob\BerichtenboxReadStatusJob',
				'OCA\Dossiq\BackgroundJob\EmailPdfRetryJob',
			],
			$removed
		);
	}//end testTheParkedAndRemovedJobsLeaveTheJobList()

	/**
	 * A job the step retires is not scheduled by info.xml any more.
	 *
	 * @return void
	 */
	public function testNoRetiredJobIsStillInInfoXml(): void {
		$manifest = (string)file_get_contents(__DIR__.'/../../../appinfo/info.xml');
		foreach (RetireUnscheduledBackgroundJobs::RETIRED as $class) {
			$this->assertStringNotContainsString('<job>'.$class.'</job>', $manifest, $class.' is still scheduled.');
		}
	}//end testNoRetiredJobIsStillInInfoXml()
}//end class
