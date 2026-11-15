<?php

/**
 * ReportingTools runs each report's own gate before it calls the owning service.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
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
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use OCA\Dossiq\Service\Dashboard\DashboardWidgetScope;
use OCA\Dossiq\Service\DeadlineReportingService;
use OCA\Dossiq\Service\DoorlooptijdService;
use OCA\Dossiq\Service\KpiAggregationService;
use OCA\Dossiq\Service\Mcp\ReportingTools;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\WorkQueueService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The gates and the delegation of the curated aggregate reads.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */
class ReportingToolsTest extends TestCase {

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Group manager mock, read by the REAL ReportingAudience.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groups;

	/**
	 * Deadline service mock.
	 *
	 * @var DeadlineReportingService&MockObject
	 */
	private DeadlineReportingService $deadlines;

	/**
	 * Lead-time service mock.
	 *
	 * @var DoorlooptijdService&MockObject
	 */
	private DoorlooptijdService $leadTimes;

	/**
	 * KPI service mock.
	 *
	 * @var KpiAggregationService&MockObject
	 */
	private KpiAggregationService $kpis;

	/**
	 * Widget scope mock.
	 *
	 * @var DashboardWidgetScope&MockObject
	 */
	private DashboardWidgetScope $widgetScope;

	/**
	 * Work queue mock.
	 *
	 * @var WorkQueueService&MockObject
	 */
	private WorkQueueService $workQueue;

	/**
	 * The subject.
	 *
	 * @var ReportingTools
	 */
	private ReportingTools $tools;

	/**
	 * Build the subject over the real audience gate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->deadlines = $this->createMock(DeadlineReportingService::class);
		$this->leadTimes = $this->createMock(DoorlooptijdService::class);
		$this->kpis = $this->createMock(KpiAggregationService::class);
		$this->widgetScope = $this->createMock(DashboardWidgetScope::class);
		$this->workQueue = $this->createMock(WorkQueueService::class);

		$this->tools = new ReportingTools(
			userSession: $this->session,
			groupManager: $this->groups,
			audience: new ReportingAudience(groupManager: $this->groups),
			deadlines: $this->deadlines,
			leadTimes: $this->leadTimes,
			kpis: $this->kpis,
			widgetScope: $this->widgetScope,
			workQueue: $this->workQueue,
		);
	}//end setUp()

	/**
	 * Without a session no report runs.
	 *
	 * @return void
	 */
	public function testNoSessionRunsNoReport(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->deadlines->expects($this->never())->method('getTermijnKpi');
		$this->leadTimes->expects($this->never())->method('getMetrics');
		$this->kpis->expects($this->never())->method('computeKpis');
		$this->workQueue->expects($this->never())->method('computeWorkload');

		foreach (['getDeadlineDashboard', 'getDoorlooptijdMetrics', 'getKpiOverview', 'getWorkload'] as $tool) {
			$this->assertSame('not_authenticated', $this->tools->$tool()['error'] ?? null, $tool);
		}
	}//end testNoSessionRunsNoReport()

	/**
	 * A handler outside the reporting roles gets no organisation-wide figure.
	 *
	 * @return void
	 */
	public function testAHandlerOutsideTheReportingRolesIsRefused(): void {
		$this->signIn(uid: 'henk', groups: []);
		$this->deadlines->expects($this->never())->method('getTermijnKpi');
		$this->leadTimes->expects($this->never())->method('getMetrics');

		$this->assertSame(
			['error' => 'forbidden', 'message' => ReportingAudience::REFUSAL],
			$this->tools->getDeadlineDashboard()
		);
		$this->assertSame('forbidden', $this->tools->getDoorlooptijdMetrics()['error']);
	}//end testAHandlerOutsideTheReportingRolesIsRefused()

	/**
	 * A controller gets the figures the owning services compute.
	 *
	 * @return void
	 */
	public function testAControllerGetsTheOwningServicesFigures(): void {
		$this->signIn(uid: 'fatima', groups: ['controllers']);
		$this->deadlines->expects($this->once())->method('getTermijnKpi')->willReturn(['breached' => 3]);
		$this->leadTimes->expects($this->once())->method('getMetrics')
			->with(['period' => '6m', 'atRiskDays' => 2, 'caseType' => 'ct-1'])
			->willReturn(['kpi' => ['median' => 12]]);

		$this->assertSame(['breached' => 3], $this->tools->getDeadlineDashboard());
		$this->assertSame(
			['kpi' => ['median' => 12]],
			$this->tools->getDoorlooptijdMetrics(caseType: 'ct-1', period: '6m', atRiskDays: 2)
		);
	}//end testAControllerGetsTheOwningServicesFigures()

	/**
	 * The lead-time arguments follow the controller's rules.
	 *
	 * @return void
	 */
	public function testABadLeadTimeArgumentIsRefusedBeforeTheService(): void {
		$this->signIn(uid: 'fatima', groups: ['controllers']);
		$this->leadTimes->expects($this->never())->method('getMetrics');

		$this->assertSame('invalid_argument', $this->tools->getDoorlooptijdMetrics(period: 'last year')['error']);
		$this->assertSame('invalid_argument', $this->tools->getDoorlooptijdMetrics(atRiskDays: -1)['error']);
	}//end testABadLeadTimeArgumentIsRefusedBeforeTheService()

	/**
	 * The KPI overview is the caller's own, narrowed to the widgets they may see.
	 *
	 * @return void
	 */
	public function testTheKpiOverviewIsTheCallersOwnAndNarrowed(): void {
		$this->signIn(uid: 'henk', groups: []);
		$this->kpis->expects($this->once())->method('computeKpis')->with('henk')->willReturn(['open' => 4, 'secret' => 9]);
		$this->widgetScope->expects($this->once())->method('narrowFor')
			->with(['open' => 4, 'secret' => 9], 'henk')
			->willReturn(['open' => 4]);

		$this->assertSame(['open' => 4], $this->tools->getKpiOverview());
	}//end testTheKpiOverviewIsTheCallersOwnAndNarrowed()

	/**
	 * The workload is for the coordinator only.
	 *
	 * @return void
	 */
	public function testTheWorkloadIsForTheCoordinatorOnly(): void {
		$this->signIn(uid: 'henk', groups: []);
		$this->workQueue->expects($this->never())->method('computeWorkload');
		$this->assertSame('forbidden', $this->tools->getWorkload()['error']);
	}//end testTheWorkloadIsForTheCoordinatorOnly()

	/**
	 * The coordinator gets the handlers the work queue counts.
	 *
	 * @return void
	 */
	public function testTheCoordinatorGetsTheHandlers(): void {
		$this->signIn(uid: 'admin', groups: [], admin: true);
		$this->workQueue->expects($this->once())->method('computeWorkload')->willReturn([['uid' => 'henk', 'open' => 7]]);
		$this->assertSame(['handlers' => [['uid' => 'henk', 'open' => 7]]], $this->tools->getWorkload());
	}//end testTheCoordinatorGetsTheHandlers()

	/**
	 * Put a user in the session with these groups.
	 *
	 * @param string        $uid    The user id.
	 * @param array<string> $groups The groups the user is in.
	 * @param bool          $admin  Whether the user is an administrator.
	 *
	 * @return void
	 */
	private function signIn(string $uid, array $groups, bool $admin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => $who === $uid && in_array($group, $groups, true)
		);
		$this->groups->method('isAdmin')->willReturn($admin);
	}//end signIn()
}//end class
