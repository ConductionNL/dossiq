<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Controller\SupervisionPlanController;
use OCA\Dossiq\Service\Inspection\SupervisionPlanProgress;
use OCA\Dossiq\Service\Inspection\SupervisionPlanReader;
use OCA\Dossiq\Service\Inspection\SupervisionScheduleProposal;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The plan's figures and its proposal answer what the reader read, and refuse with its status.
 */
class SupervisionPlanControllerTest extends TestCase {

	/**
	 * The controller over a reader that answers the given read.
	 *
	 * @param array<string, mixed> $read The reader's answer.
	 * @param string               $from The `from` parameter.
	 *
	 * @return SupervisionPlanController The controller.
	 */
	private function controller(array $read, string $from=''): SupervisionPlanController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default=null) use ($from): mixed {
				if ($key === 'from') {
					return $from;
				}

				return $default;
			}
		);

		$reader = $this->createMock(originalClassName: SupervisionPlanReader::class);
		$reader->method('read')->willReturn($read);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-24T10:00:00+01:00'));

		$progress = new SupervisionPlanProgress();

		return new SupervisionPlanController(
			request: $request,
			reader: $reader,
			progress: $progress,
			proposal: new SupervisionScheduleProposal(progress: $progress, time: $time)
		);
	}//end controller()

	/**
	 * A found plan with two categories and one completed inspection.
	 *
	 * @return array<string, mixed> The read.
	 */
	private function found(): array {
		return [
			'status'      => SupervisionPlanReader::FOUND,
			'plan'        => ['id' => 'plan-2026', 'year' => 2026, 'capacityHours' => 1600],
			'categories'  => [
				['id' => 'hos', 'name' => 'Hospitality', 'planned' => 40, 'order' => 1, 'hoursPerInspection' => 6],
				['id' => 'con', 'name' => 'Construction', 'planned' => 60, 'order' => 2, 'hoursPerInspection' => 12],
			],
			'inspections' => [['planCategory' => 'hos', 'scheduledAt' => '2026-03-02', 'completedAt' => '2026-03-02']],
		];
	}//end found()

	/**
	 * The figures are the measure of what was read.
	 *
	 * @return void
	 */
	public function testProgressAnswersTheFigures(): void {
		$response = $this->controller(read: $this->found())->progress(id: 'plan-2026');
		$data     = $response->getData();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 100, actual: $data['scheduled']);
		$this->assertSame(expected: 1, actual: $data['completed']);
		$this->assertSame(expected: ['1 of 40', '0 of 60'], actual: array_column($data['categories'], 'label'));
	}//end testProgressAnswersTheFigures()

	/**
	 * The proposal starts on the `from` day, or today, and says nothing was written.
	 *
	 * @return void
	 */
	public function testTheProposalStartsOnTheGivenDayOrToday(): void {
		$given = $this->controller(read: $this->found(), from: '2026-01-05')->scheduleProposal(id: 'plan-2026')->getData();
		$today = $this->controller(read: $this->found())->scheduleProposal(id: 'plan-2026')->getData();

		$this->assertFalse(condition: $given['written']);
		$this->assertSame(expected: '2026-01-05', actual: $given['categories'][0]['visits'][0]['date']);
		$this->assertSame(expected: '2026-11-24', actual: $today['categories'][0]['visits'][0]['date']);
	}//end testTheProposalStartsOnTheGivenDayOrToday()

	/**
	 * A refusal of the reader is answered with its status and a sentence.
	 *
	 * @return void
	 */
	public function testRefusalsKeepTheReadersStatus(): void {
		foreach ([Http::STATUS_NOT_FOUND, Http::STATUS_SERVICE_UNAVAILABLE, Http::STATUS_INTERNAL_SERVER_ERROR] as $status) {
			$progress = $this->controller(read: ['status' => $status])->progress(id: 'plan-2026');
			$proposal = $this->controller(read: ['status' => $status])->scheduleProposal(id: 'plan-2026');

			$this->assertSame(expected: $status, actual: $progress->getStatus());
			$this->assertSame(expected: $status, actual: $proposal->getStatus());
			$this->assertNotSame(expected: '', actual: $progress->getData()['error']);
		}
	}//end testRefusalsKeepTheReadersStatus()
}//end class
