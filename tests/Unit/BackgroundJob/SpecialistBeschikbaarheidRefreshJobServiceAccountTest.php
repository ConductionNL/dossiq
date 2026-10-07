<?php

/**
 * SpecialistBeschikbaarheidRefreshJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the job's age-out patch as
 * Anonymous and a stale specialist stayed "beschikbaar" for ever. These tests
 * drive the REAL job into a register that refuses a write from nobody.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\SpecialistBeschikbaarheidRefreshJob;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The age-out patch runs as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\SpecialistBeschikbaarheidRefreshJob
 */
class SpecialistBeschikbaarheidRefreshJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The specialistBeschikbaarheid schema id.
	 */
	private const SCHEMA = '14';

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * Seed one stale specialist.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: self::SCHEMA,
			id: 'spec-1',
			row: [
				'name' => 'Jan de Vries',
				'status' => 'beschikbaar',
				'lastUpdate' => date('c', (time() - 3600)),
			]
		);
	}//end setUp()

	/**
	 * One run marks the stale specialist afwezig, as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: [self::SCHEMA]);
		$row = $this->register->row(schema: self::SCHEMA, id: 'spec-1');
		$this->assertSame('afwezig', $row['status']);
		$this->assertSame('Jan de Vries', $row['name'], 'The patch wiped a field it does not own.');
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is attempted and the admins are told.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame('beschikbaar', $this->register->row(schema: self::SCHEMA, id: 'spec-1')['status']);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the refusing register.
	 *
	 * @return SpecialistBeschikbaarheidRefreshJob The job.
	 */
	private function job(): SpecialistBeschikbaarheidRefreshJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => '5',
				'specialist_beschikbaarheid_schema' => self::SCHEMA,
				default => $default,
			}
		);
		$settings->method('getKccConfigValue')->willReturn('30');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		return $this->buildWith(
			SpecialistBeschikbaarheidRefreshJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'settingsService' => $settings,
				'appManager' => $apps,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
