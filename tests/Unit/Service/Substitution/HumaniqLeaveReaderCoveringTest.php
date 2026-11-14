<?php

/**
 * The humaniq leave that covers a day: which approved request is chosen,
 * how its dates are read, what is asked of the register, and the per-run
 * cache.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Substitution
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

namespace OCA\Dossiq\Tests\Unit\Service\Substitution;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\HumaniqLeaveReader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for HumaniqLeaveReader::approvedLeaveCovering() when humaniq answers.
 *
 * @covers \OCA\Dossiq\Service\Substitution\HumaniqLeaveReader
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
final class HumaniqLeaveReaderCoveringTest extends TestCase {

	/**
	 * The fake object service: answers rows per schema and records every search.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The register setting answered; null answers the default passed in.
	 *
	 * @var string|null
	 */
	private ?string $register = null;

	/**
	 * Set up an object service holding one employee for `jan`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new class {
			/** @var array<string, list<array<string, mixed>>> Rows per schema. */
			public array $rows = [];

			/** @var list<array{0:string,1:string,2:array<string,mixed>}> Every search as register, schema, filters. */
			public array $searches = [];

			/**
			 * Search a schema by slug.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->searches[] = [$register, $schema, $filters];
				return ($this->rows[$schema] ?? []);
			}
		};
		$this->store->rows['Employee'] = [['id' => 'emp-1', 'nextcloudUserId' => 'jan']];
	}//end setUp()

	/**
	 * The reader under test, with humaniq installed.
	 *
	 * @param bool $withObjectService Whether OpenRegister is available.
	 *
	 * @return HumaniqLeaveReader
	 */
	private function reader(bool $withObjectService = true): HumaniqLeaveReader {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('humaniq')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withObjectService === true ? $this->store : null);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ($key === 'humaniq_register' ? ($this->register ?? $default) : $default)
		);

		return new HumaniqLeaveReader(appManager: $apps, settingsService: $settings, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * The leave requests humaniq answers.
	 *
	 * @param list<array<string, mixed>> $requests The requests.
	 *
	 * @return void
	 */
	private function leave(array $requests): void {
		$this->store->rows['LeaveRequest'] = $requests;
	}//end leave()

	/**
	 * Days and whether a 10–14 July leave covers them.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function days(): array {
		return [
			'the day before'  => ['2026-07-09', false],
			'the first day'   => ['2026-07-10', true],
			'a middle day'    => ['2026-07-12', true],
			'the last day'    => ['2026-07-14', true],
			'the day after'   => ['2026-07-15', false],
		];
	}//end days()

	/**
	 * An approved leave covers its first and last day and every day between, and answers its dates.
	 *
	 * @param string $day     The day.
	 * @param bool   $covered Whether the leave covers it.
	 *
	 * @return void
	 *
	 * @dataProvider days
	 */
	public function testAnApprovedLeaveCoversItsDaysInclusive(string $day, bool $covered): void {
		$this->leave([['status' => 'approved', 'startDate' => '2026-07-10', 'endDate' => '2026-07-14']]);

		$expected = null;
		if ($covered === true) {
			$expected = ['startDate' => '2026-07-10', 'endDate' => '2026-07-14'];
		}

		$this->assertSame($expected, $this->reader()->approvedLeaveCovering(userId: 'jan', day: $day));
	}//end testAnApprovedLeaveCoversItsDaysInclusive()

	/**
	 * Date-times are read by their date; requests that are not approved or lack a date are passed over.
	 *
	 * @return void
	 */
	public function testOnlyACompleteApprovedRequestCounts(): void {
		$this->leave(
			[
				['status' => 'pending', 'startDate' => '2026-07-01', 'endDate' => '2026-07-31'],
				['status' => 'approved', 'startDate' => '2026-07-01'],
				['status' => 'approved', 'startDate' => '', 'endDate' => '2026-07-31'],
				['startDate' => '2026-07-01', 'endDate' => '2026-07-31'],
				['status' => 'approved', 'startDate' => '2026-07-05T08:00:00+02:00', 'endDate' => '2026-07-20T17:00:00+02:00'],
				['status' => 'approved', 'startDate' => '2026-07-01', 'endDate' => '2026-07-31'],
			]
		);

		$this->assertSame(
			['startDate' => '2026-07-05', 'endDate' => '2026-07-20'],
			$this->reader()->approvedLeaveCovering(userId: 'jan', day: '2026-07-10')
		);
	}//end testOnlyACompleteApprovedRequestCounts()

	/**
	 * The employee is looked up by Nextcloud user in the configured register, and only their approved leave is asked.
	 *
	 * @return void
	 */
	public function testTheRegisterIsAskedForTheEmployeesApprovedLeave(): void {
		$this->register = 'hr';
		$this->store->rows['Employee'] = [['uuid' => 'emp-uuid', 'nextcloudUserId' => 'jan']];
		$this->leave([]);

		$this->assertNull($this->reader()->approvedLeaveCovering(userId: '  jan ', day: '2026-07-10'));

		$this->assertSame(
			[
				['hr', 'Employee', ['nextcloudUserId' => 'jan', '_limit' => 1]],
				['hr', 'LeaveRequest', ['employeeId' => 'emp-uuid', 'status' => 'approved', '_limit' => 50]],
			],
			$this->store->searches
		);
	}//end testTheRegisterIsAskedForTheEmployeesApprovedLeave()

	/**
	 * Without a configured register the default `humaniq` register is read.
	 *
	 * @return void
	 */
	public function testTheDefaultRegisterIsHumaniq(): void {
		$this->leave([]);

		$this->reader()->approvedLeaveCovering(userId: 'jan', day: '2026-07-10');

		$this->assertSame(['humaniq', 'humaniq'], array_column($this->store->searches, 0));
	}//end testTheDefaultRegisterIsHumaniq()

	/**
	 * An answer per user and day is read once; another day or user is read again.
	 *
	 * @return void
	 */
	public function testEachUserAndDayIsReadOnce(): void {
		$this->leave([['status' => 'approved', 'startDate' => '2026-07-10', 'endDate' => '2026-07-14']]);
		$reader = $this->reader();

		$first = $reader->approvedLeaveCovering(userId: 'jan', day: '2026-07-10');
		$this->leave([]);
		$again = $reader->approvedLeaveCovering(userId: ' jan ', day: '2026-07-10');
		$other = $reader->approvedLeaveCovering(userId: 'jan', day: '2026-07-11');

		$this->assertSame($first, $again);
		$this->assertSame(['startDate' => '2026-07-10', 'endDate' => '2026-07-14'], $again);
		$this->assertNull($other);
		$this->assertCount(4, $this->store->searches);
	}//end testEachUserAndDayIsReadOnce()

	/**
	 * A blank user or day, or no object service, answers no leave without a search.
	 *
	 * @return void
	 */
	public function testBlankInputOrNoObjectServiceIsNoLeave(): void {
		$this->leave([['status' => 'approved', 'startDate' => '2026-07-01', 'endDate' => '2026-07-31']]);

		$this->assertNull($this->reader()->approvedLeaveCovering(userId: '   ', day: '2026-07-10'));
		$this->assertNull($this->reader()->approvedLeaveCovering(userId: 'jan', day: ''));
		$this->assertNull($this->reader(withObjectService: false)->approvedLeaveCovering(userId: 'jan', day: '2026-07-10'));

		$this->assertSame([], $this->store->searches);
	}//end testBlankInputOrNoObjectServiceIsNoLeave()
}//end class
