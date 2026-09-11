<?php

/**
 * A timed job either runs, or says why it does not.
 *
 * WHAT THIS GUARDS, and it is an ambiguity rather than a crash. A `TimedJob`
 * runs only when `appinfo/info.xml` carries a `<job>` entry naming it;
 * Nextcloud schedules nothing it was not told about. An UNREGISTERED timed job
 * and a registered one that runs every day and finds nothing to do produce
 * exactly the same evidence: an empty log, and a domain record that never
 * changes. Telling them apart means opening `info.xml`, which nobody does
 * while reading a job class that looks complete.
 *
 * That is the same shape as the defect this test shipped alongside.
 * `WOORedactionService` reported documents `queued` while making no call, and
 * it was invisible for exactly the same reason: the successful-looking output
 * of a thing that did nothing is indistinguishable from the output of a thing
 * that worked. So this test does not demand that every timed job be
 * registered. Some should not be. It demands that an unregistered one SAY SO,
 * in the class, where the next reader is already looking.
 *
 * It deliberately never blocks a registration. Adding the `<job>` entry
 * satisfies this test with no test edit, so the day a real Berichtenbox
 * transport lands, wiring its poller costs nothing here.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use PHPUnit\Framework\TestCase;

/**
 * Every timed job is scheduled, or documented as not scheduled.
 *
 * @coversNothing This asserts a repository invariant, not one class's behaviour.
 */
class TimedJobRegistrationTest extends TestCase {

	/**
	 * The phrase an unregistered timed job must carry in its class docblock.
	 *
	 * @var string
	 */
	private const MARKER = 'DELIBERATELY NOT REGISTERED';

	/**
	 * Timed jobs that are unregistered and NOT yet explained.
	 *
	 * Each entry is a debt, not a dispensation, and the value is the whole
	 * point of the entry: it records that nobody has established why the job
	 * does not run, which is a different and worse state than a job whose
	 * absence is a decision.
	 *
	 * Both were found by this test on the day it was written. Neither is
	 * referenced anywhere in `lib/`, so neither has ever run on any instance,
	 * and the features behind them have therefore never fired. Resolving an
	 * entry means either registering the job or writing the marker with a real
	 * reason; deleting an entry without doing one of those two re-hides it.
	 *
	 * @var array<string, string>
	 */
	private const UNEXPLAINED = [
		'AppointmentReminderJob' => 'never registered and never referenced, so no appointment reminder '
			. 'has ever been sent; needs an owner to decide register-or-retire',
		'ShareMaintenanceJob' => 'never registered and never referenced, so share maintenance has never '
			. 'run; needs an owner to decide register-or-retire',
	];

	/**
	 * Every `TimedJob` is registered in info.xml or explains why it is not.
	 *
	 * @return void
	 */
	public function testEveryTimedJobIsScheduledOrSaysWhyItIsNot(): void {
		$manifest = file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertIsString($manifest, 'appinfo/info.xml must be readable');

		$found = 0;
		foreach (glob(__DIR__ . '/../../../lib/BackgroundJob/*.php') as $path) {
			$source = file_get_contents($path);
			if ($source === false || str_contains($source, 'extends TimedJob') === false) {
				continue;
			}

			$found++;
			$class = basename($path, '.php');
			if (str_contains($manifest, 'BackgroundJob\\' . $class . '<') === true) {
				$this->assertArrayNotHasKey(
					$class,
					self::UNEXPLAINED,
					$class . ' is registered now, so remove its UNEXPLAINED entry'
				);
				continue;
			}

			if (isset(self::UNEXPLAINED[$class]) === true) {
				continue;
			}

			$this->assertStringContainsString(
				self::MARKER,
				$source,
				$class . ' is a TimedJob with no <job> entry in appinfo/info.xml, so Nextcloud never '
				. 'schedules it and it has never run. Either register it, or write "' . self::MARKER
				. '" in its class docblock with the reason, so an empty log is not mistaken for a '
				. 'job that ran and found nothing.'
			);
		}//end foreach

		$this->assertGreaterThan(0, $found, 'the scan found no TimedJob at all, so it proved nothing');
	}//end testEveryTimedJobIsScheduledOrSaysWhyItIsNot()
}//end class
