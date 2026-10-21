<?php

/**
 * Dossiq Woo review reports controller.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Woo\WooReportReadLog;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCA\Dossiq\Woo\WooThroughputReport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The Woo review reports: who may read them is decided here, in the body.
 *
 * `#[NoAdminRequired]` lets any signed-in user reach the method, and the body
 * then refuses everyone the organisation did not name. The throughput report
 * is read only by members of the named reader group, and an administrator
 * outside it is refused like anyone else (REQ-WRR-002). While a switch is off
 * its report answers 403 with the not-switched-on sentence (REQ-WRR-001).
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md
 */
class WooReportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param WooReportSwitches $switches The organisation switches.
	 * @param WooThroughputReport $throughputReport The throughput report.
	 * @param WooReportReadLog $readLog The read log on the audit trail.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The translations.
	 * @param CaseDateNormaliser|null $dates Reads a moment as the tenant's calendar date.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly WooReportSwitches $switches,
		private readonly WooThroughputReport $throughputReport,
		private readonly WooReportReadLog $readLog,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly ?CaseDateNormaliser $dates = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Which reports this user is offered: `{throughput, parties}`, the same
	 * answer the menu reads from the initial state. The reports page shows a
	 * widget only when its report is offered; the report endpoints still
	 * refuse anyone else on every read.
	 *
	 * @return JSONResponse The offer, or 401 without a session.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 */
	#[NoAdminRequired]
	public function offered(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->switches->offeredToCurrentUser());
	}//end offered()

	/**
	 * Throughput per reviewer per day, for one case (`?case=`) or a period (`?from=&to=`).
	 *
	 * `?format=csv` answers the same rows as a CSV download. Every read that
	 * counted a case is recorded on that case's audit trail before it is
	 * answered; a read that cannot be recorded is refused.
	 *
	 * @return JSONResponse|DataDownloadResponse The rows, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	#[NoAdminRequired]
	public function throughput(): JSONResponse|DataDownloadResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->switches->isOn(switch: WooReportSwitches::THROUGHPUT) === false) {
			return $this->notSwitchedOn();
		}

		// The group is the only way in: no admin bypass (REQ-WRR-002).
		if ($this->switches->maySeeThroughput(userId: $user->getUID()) === false) {
			return new JSONResponse(
				[
					'error' => 'woo-throughput-not-a-reader',
					'message' => $this->l10n->t('Only the reader group the organisation named may read the throughput report.'),
				],
				Http::STATUS_FORBIDDEN
			);
		}

		$caseId = trim((string)$this->request->getParam('case', ''));
		$from = $this->calendarDate(value: trim((string)$this->request->getParam('from', '')));
		$to = $this->calendarDate(value: trim((string)$this->request->getParam('to', '')));
		$scope = ['from' => $from, 'to' => $to];
		if ($caseId !== '') {
			$scope = ['case' => $caseId];
		}

		try {
			$report = $this->read(caseId: $caseId, from: $from, to: $to);
			$this->readLog->recordThroughputRead(readerId: $user->getUID(), scope: $scope, caseIds: $report['cases']);
		} catch (RefusedException $e) {
			return new JSONResponse(
				['error' => $e->getRule(), 'message' => $this->sentence(rule: $e->getRule())],
				$e->getStatus()
			);
		}

		if ($this->request->getParam('format', '') === 'csv') {
			return new DataDownloadResponse(
				$this->throughputReport->toCsv(rows: $report['rows']),
				'woo-throughput.csv',
				'text/csv; charset=utf-8'
			);
		}

		return new JSONResponse(['scope' => $scope] + $report);
	}//end throughput()

	/**
	 * A period bound as the tenant's calendar date. A dashboard date range
	 * sends moments (`2026-10-01T22:00:00.000Z`); the report counts days, so
	 * a moment is read as the day it falls on in the tenant's zone. A plain
	 * date, or anything else, passes as given and the report judges it.
	 *
	 * @param string $value The bound as it arrived.
	 *
	 * @return string The bound as `Y-m-d`, or the value unchanged.
	 */
	private function calendarDate(string $value): string {
		if ($this->dates === null || preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1) {
			return $value;
		}

		return ($this->dates->toCalendarDateOrNull(value: $value) ?? $value);
	}//end calendarDate()

	/**
	 * Read one case, or a period when no case is named.
	 *
	 * @param string $caseId The case, or ''.
	 * @param string $from The first day of the period.
	 * @param string $to The last day of the period.
	 *
	 * @return array{rows: list<array<string, int|string>>, cases: list<string>, truncated: bool} The report.
	 *
	 * @throws RefusedException When the report cannot be made.
	 */
	private function read(string $caseId, string $from, string $to): array {
		if ($caseId !== '') {
			return $this->throughputReport->forCase(caseId: $caseId);
		}

		return $this->throughputReport->forPeriod(from: $from, to: $to);
	}//end read()

	/**
	 * The 403 of a report the organisation has not switched on.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 */
	private function notSwitchedOn(): JSONResponse {
		return new JSONResponse(
			['error' => 'woo-report-off', 'message' => $this->l10n->t('Your organisation has not switched this report on.')],
			Http::STATUS_FORBIDDEN
		);
	}//end notSwitchedOn()

	/**
	 * The translated sentence of a refusal rule.
	 *
	 * @param string $rule The refusal rule.
	 *
	 * @return string The sentence.
	 */
	private function sentence(string $rule): string {
		return match ($rule) {
			'woo-throughput-period' => $this->l10n->t('Give the period as two dates, from and to.'),
			'woo-throughput-not-recorded' => $this->l10n->t('This read cannot be recorded, so the report is not shown.'),
			default => $this->l10n->t('The Woo assessments cannot be read, so the report cannot be made.'),
		};
	}//end sentence()

	/**
	 * throughput() on its old address.
	 *
	 * Deprecated alias of /api/document-review/reports/throughput; remove after the next major.
	 *
	 * @return JSONResponse|DataDownloadResponse
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 *
	 * @contract exclude Deprecated path alias for throughput(); behaviour covered by the throughput() tests.
	 */
	#[NoAdminRequired]
	public function throughputLegacyPath(): JSONResponse|DataDownloadResponse {
		return $this->throughput();
	}//end throughputLegacyPath()
}//end class
