<?php

/**
 * Curated read tools an assistant may call for dossiq's aggregate figures.
 *
 * Each method is an `#[McpTool]` that OpenRegister's attribute scanner
 * registers as `dossiq.<name>`. The method runs the SAME gate the matching
 * controller runs and then calls the owning service, so an agent sees exactly
 * what the caller's own screens show. No figure is computed here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mcp
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

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Service\Dashboard\DashboardWidgetScope;
use OCA\Dossiq\Service\DeadlineReportingService;
use OCA\Dossiq\Service\DoorlooptijdService;
use OCA\Dossiq\Service\KpiAggregationService;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\WorkQueueService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Aggregate reads: deadlines, lead times, KPIs and workload.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */
class ReportingTools {

	/**
	 * Constructor.
	 *
	 * @param IUserSession             $userSession The caller's session.
	 * @param IGroupManager            $groupManager Answers the coordinator (administrator) check.
	 * @param ReportingAudience        $audience    The gate every fleet-wide report asks.
	 * @param DeadlineReportingService $deadlines   Owns the termijn KPI snapshot.
	 * @param DoorlooptijdService      $leadTimes   Owns the lead-time metrics.
	 * @param KpiAggregationService    $kpis        Owns the personal KPI overview.
	 * @param DashboardWidgetScope     $widgetScope Narrows KPIs to the widgets this reader may see.
	 * @param WorkQueueService         $workQueue   Owns the per-handler workload.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ReportingAudience $audience,
		private readonly DeadlineReportingService $deadlines,
		private readonly DoorlooptijdService $leadTimes,
		private readonly KpiAggregationService $kpis,
		private readonly DashboardWidgetScope $widgetScope,
		private readonly WorkQueueService $workQueue,
	) {
	}//end __construct()

	/**
	 * Statutory deadline figures across the organisation: open, at risk and breached.
	 *
	 * @return array<string, mixed> The termijn KPI snapshot, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
	 */
	#[McpTool(
		name: 'getDeadlineDashboard',
		description: 'Statutory deadline figures for the organisation: cases running, at risk or past their deadline. Counts only, no case rows.',
		readOnlyHint: true,
		scope: 'read',
		reach: 'user',
		subject: 'deadline',
		action: 'report',
		annotations: ['outsideAgent' => true]
	)]
	public function getDeadlineDashboard(): array {
		$refusal = $this->refuseOutsideReportingAudience();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->deadlines->getTermijnKpi();
	}//end getDeadlineDashboard()

	/**
	 * Lead times of closed cases against their statutory deadline.
	 *
	 * @param string|null $caseType   Limit to one case type (UUID); all case types when empty.
	 * @param string      $period     The window in months, written like 12m.
	 * @param int         $atRiskDays Days before the deadline a case counts as at risk.
	 *
	 * @return array<string, mixed> The lead-time metrics, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
	 */
	#[McpTool(
		name: 'getDoorlooptijdMetrics',
		description: 'Lead times (doorlooptijden) of cases against their statutory deadline, per month and per case type. Aggregates only.',
		readOnlyHint: true,
		scope: 'read',
		reach: 'user',
		subject: 'leadTime',
		action: 'report',
		annotations: ['outsideAgent' => true]
	)]
	public function getDoorlooptijdMetrics(?string $caseType = null, string $period = '12m', int $atRiskDays = 5): array {
		$refusal = $this->refuseOutsideReportingAudience();
		if ($refusal !== null) {
			return $refusal;
		}

		// The same argument rules DoorlooptijdController::metrics() applies.
		if (preg_match('/^\d+m$/', $period) !== 1) {
			return $this->error(code: 'invalid_argument', message: 'period must look like 12m.');
		}

		if ($atRiskDays < 0) {
			return $this->error(code: 'invalid_argument', message: 'atRiskDays cannot be negative.');
		}

		$params = ['period' => $period, 'atRiskDays' => $atRiskDays];
		if ($caseType !== null && $caseType !== '') {
			$params['caseType'] = $caseType;
		}

		return $this->leadTimes->getMetrics(params: $params);
	}//end getDoorlooptijdMetrics()

	/**
	 * The caller's own KPI overview, narrowed to the widgets they may see.
	 *
	 * @return array<string, mixed> The KPI figures, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
	 */
	#[McpTool(
		name: 'getKpiOverview',
		description: 'The current user\'s case figures as the dashboard shows them: open, overdue, due this week and completed. Counts only.',
		readOnlyHint: true,
		scope: 'read',
		reach: 'user',
		subject: 'kpi',
		action: 'report',
		annotations: ['outsideAgent' => true]
	)]
	public function getKpiOverview(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		$userId = $user->getUID();
		return $this->widgetScope->narrowFor(payload: $this->kpis->computeKpis($userId), userId: $userId);
	}//end getKpiOverview()

	/**
	 * Open cases per handler, for a coordinator.
	 *
	 * @return array<string, mixed> The handlers with their counts, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
	 */
	#[McpTool(
		name: 'getWorkload',
		description: 'Open cases per handler, for a coordinator who divides the work. Counts only.',
		readOnlyHint: true,
		scope: 'read',
		reach: 'user',
		subject: 'workload',
		action: 'report',
		annotations: ['outsideAgent' => true]
	)]
	public function getWorkload(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		// The same check WorkQueueController::workload() runs: the coordinator
		// is the Nextcloud administrator.
		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return $this->error(code: 'forbidden', message: 'This needs the coordinator role.');
		}

		return ['handlers' => $this->workQueue->computeWorkload()];
	}//end getWorkload()

	/**
	 * The refusal for a caller outside the reporting audience, or null.
	 *
	 * @return array<string, string>|null
	 */
	private function refuseOutsideReportingAudience(): ?array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		if ($this->audience->isInAudience(user: $user) === false) {
			return $this->error(code: 'forbidden', message: ReportingAudience::REFUSAL);
		}

		return null;
	}//end refuseOutsideReportingAudience()

	/**
	 * An error envelope the assistant can read back.
	 *
	 * @param string $code    A stable machine code.
	 * @param string $message A sentence for the person.
	 *
	 * @return array{error: string, message: string}
	 */
	private function error(string $code, string $message): array {
		return ['error' => $code, 'message' => $message];
	}//end error()
}//end class
