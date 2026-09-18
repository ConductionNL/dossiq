<?php

/**
 * Counting open reports across cases.
 *
 * The spec's scenario is five cases holding nine open reports between them, and
 * both halves of that answer matter: WHICH cases, and HOW MANY. A total on its
 * own cannot be taken apart again, so the reader answers per case and the
 * caller adds up.
 *
 * The other thing worth a test: `in-behandeling` counts as open. A count that
 * excluded it would tell a coordinator the work is done while an inspector is
 * out looking at it, which is the most expensive way for a number to be wrong.
 *
 * MUTATION-CHECKED 2026-09-18: narrowing `OPEN_STATES` to `open` alone reddens
 * testAReportSomebodyIsWorkingStillCounts on the count, and dropping the
 * `in_array($caseId, $wanted)` filter reddens testOnlyTheCasesAskedAboutAreCounted.
 * Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Incidents\IncidentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which cases hold open reports, and how many.
 *
 * @covers \OCA\Dossiq\Service\Incidents\IncidentService::openCountsFor
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentQueueCountTest extends TestCase {

	/**
	 * The store the service reads.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * An empty register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * Five cases, nine open reports between them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function testFiveCasesHoldNineOpenReports(): void {
		$spread = ['case-1' => 1, 'case-2' => 2, 'case-3' => 2, 'case-4' => 3, 'case-5' => 1];
		$this->seedOpen(spread: $spread);

		$counts = $this->incidents()->openCountsFor(caseIds: array_keys($spread));

		self::assertSame($spread, $counts, 'Per case, because a total cannot be taken apart again.');
		self::assertSame(9, array_sum($counts));
		self::assertCount(5, $counts);
	}//end testFiveCasesHoldNineOpenReports()

	/**
	 * A settled report is not counted, and a case with only settled ones is left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function testACaseWithNothingOpenIsLeftOut(): void {
		$this->seedOpen(spread: ['case-1' => 2]);
		$this->store->seed(
			schema: 'incident',
			uuid: 'settled-1',
			row: ['case' => 'case-2', 'state' => IncidentService::STATE_SETTLED, 'eventDate' => '2026-03-01'],
		);

		$counts = $this->incidents()->openCountsFor(caseIds: ['case-1', 'case-2']);

		self::assertSame(['case-1' => 2], $counts, 'A zero in the answer would put an idle case on a work list.');
	}//end testACaseWithNothingOpenIsLeftOut()

	/**
	 * A report somebody is working still counts as open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function testAReportSomebodyIsWorkingStillCounts(): void {
		$this->store->seed(
			schema: 'incident',
			uuid: 'working-1',
			row: ['case' => 'case-1', 'state' => IncidentService::STATE_WORKING, 'eventDate' => '2026-03-01'],
		);

		self::assertSame(
			['case-1' => 1],
			$this->incidents()->openCountsFor(caseIds: ['case-1']),
			'Excluding it tells a coordinator the work is done while an inspector is out looking at it.',
		);
	}//end testAReportSomebodyIsWorkingStillCounts()

	/**
	 * Only the cases asked about are counted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function testOnlyTheCasesAskedAboutAreCounted(): void {
		$this->seedOpen(spread: ['case-1' => 1, 'case-9' => 4]);

		self::assertSame(['case-1' => 1], $this->incidents()->openCountsFor(caseIds: ['case-1']));
		self::assertSame([], $this->incidents()->openCountsFor(caseIds: []), 'Asking about nothing answers nothing.');
	}//end testOnlyTheCasesAskedAboutAreCounted()

	/**
	 * Seed open incidents, so many per case.
	 *
	 * @param array<string, int> $spread How many per case.
	 *
	 * @return void
	 */
	private function seedOpen(array $spread): void {
		$n = 0;
		foreach ($spread as $caseId => $count) {
			for ($i = 0; $i < $count; $i++) {
				$n++;
				$this->store->seed(
					schema: 'incident',
					uuid: 'incident-' . $n,
					row: [
						'case' => $caseId,
						'state' => IncidentService::STATE_OPEN,
						'eventDate' => '2026-03-0' . (($i % 9) + 1),
						'description' => 'Melding ' . $n,
					],
				);
			}
		}
	}//end seedOpen()

	/**
	 * The service under test.
	 *
	 * @return IncidentService The service.
	 */
	private function incidents(): IncidentService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = ['register' => 'dossiq', 'incident_schema' => 'incident'];

				return ($map[$key] ?? $default);
			}
		);

		return new IncidentService(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end incidents()
}//end class
