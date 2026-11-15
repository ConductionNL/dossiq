<?php

/**
 * Dossiq Doorlooptijd (throughput-time) Controller
 *
 * Thin REST entry-point for the throughput-time dashboard. Reads query
 * parameters, validates types, delegates to {@see DoorlooptijdService}.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/archive/2026-06-13-doorlooptijd-dashboard/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\DeadlineComplianceCalculatorInterface;
use OCA\Dossiq\Kernel\Contracts\DoorlooptijdServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ReportingAudienceInterface;
use OCA\Dossiq\Service\Csv\CsvWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the throughput-time dashboard.
 *
 * @spec openspec/changes/archive/2026-06-13-doorlooptijd-dashboard/tasks.md#T02
 */
class DoorlooptijdController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Inbound request.
	 * @param DoorlooptijdServiceInterface $leadTimeService Metrics service.
	 * @param IUserSession $userSession Current user session.
	 * @param ReportingAudienceInterface $audience Who may read a figure about every case.
	 * @param IL10N $l10n The translations, for the download's column labels.
	 * @param CsvWriter $csvWriter Writes the download.
	 */
	public function __construct(
		IRequest $request,
		private readonly DoorlooptijdServiceInterface $leadTimeService,
		private readonly IUserSession $userSession,
		private readonly ReportingAudienceInterface $audience,
		private readonly IL10N $l10n,
		private readonly CsvWriter $csvWriter=new CsvWriter(),
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the metrics payload, or with `format=csv` the figures per case
	 * type and month as a download.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse|DataDownloadResponse Metrics body, the CSV, or 400 on invalid parameters.
	 *
	 * @spec openspec/changes/archive/2026-06-13-doorlooptijd-dashboard/tasks.md#T02
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 */
	public function metrics(): JSONResponse|DataDownloadResponse {
		$denied = $this->refuseReader();
		if ($denied !== null) {
			return $denied;
		}

		$query = [
			'caseType' => $this->request->getParam('caseType'),
			'period' => $this->request->getParam('period', '12m'),
			'granularity' => $this->request->getParam('granularity', 'month'),
			'atRiskDays' => $this->request->getParam('atRiskDays', 5),
			'format' => $this->request->getParam('format', 'json'),
		];

		$refusal = (
			$this->badRequest(query: $query)
			?? $this->refuseChoice(format: $query['format'], granularity: $query['granularity'])
		);

		if ($refusal !== null) {
			return $refusal;
		}

		if ($query['format'] === 'csv') {
			return $this->csv(caseType: $query['caseType'], period: $query['period']);
		}

		return new JSONResponse($this->leadTimeService->getMetrics(params: $this->metricsParams(query: $query)));
	}//end metrics()

	/**
	 * Refuse a reader who may not see the organisation's processing times.
	 *
	 * @return JSONResponse|null A 401 or 403 response, or null when the reader may see them.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 */
	private function refuseReader(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// 🔴 MEASURED 2026-09-18: `@NoAdminRequired` and no group check, so every
		// authenticated account read the organisation's processing times, its
		// cases at risk and its performance per case type. It is an AGGREGATE
		// over cases the caller was never granted, so OpenRegister's per-object
		// refusal never gets a chance to speak.
		if ($this->audience->isInAudience(user: $user) === false) {
			return new JSONResponse(['message' => ReportingAudienceInterface::REFUSAL], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseReader()

	/**
	 * Refuse a download format or a grouping the metrics do not offer.
	 *
	 * @param mixed $format      The requested format.
	 * @param mixed $granularity The requested grouping.
	 *
	 * @return JSONResponse|null A 400 response, or null when both are valid.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 */
	private function refuseChoice(mixed $format, mixed $granularity): ?JSONResponse {
		if (in_array($format, ['json', 'csv'], true) === false) {
			return new JSONResponse(['message' => 'format must be json or csv'], Http::STATUS_BAD_REQUEST);
		}

		if (in_array($granularity, DeadlineComplianceCalculatorInterface::GRANULARITIES, true) === false) {
			return new JSONResponse(['message' => 'granularity must be week, month, quarter or year'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end refuseChoice()

	/**
	 * The parameters the metrics service reads, the case type only when one is chosen.
	 *
	 * @param array<string, mixed> $query The case type filter, period, grouping and at-risk horizon in days, already validated.
	 *
	 * @return array<string, mixed> The parameters.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 */
	private function metricsParams(array $query): array {
		$caseType = $query['caseType'];
		$params = [
			'period' => $query['period'],
			'granularity' => $query['granularity'],
			'atRiskDays' => (int)$query['atRiskDays'],
		];
		if (is_string($caseType) === true && $caseType !== '') {
			$params['caseType'] = $caseType;
		}

		return $params;
	}//end metricsParams()

	/**
	 * The figures per case type and month as a CSV download, for the same
	 * case type filter and period the page reads.
	 *
	 * @param mixed $caseType The case type filter, already validated.
	 * @param string $period The period, already validated.
	 *
	 * @return DataDownloadResponse The CSV, with a byte order mark so a spreadsheet reads it as UTF-8.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 */
	private function csv(mixed $caseType, string $period): DataDownloadResponse {
		$params = ['period' => $period];
		if (is_string($caseType) === true && $caseType !== '') {
			$params['caseType'] = $caseType;
		}

		$rows = [];
		foreach ($this->leadTimeService->getMonthlyRows(params: $params) as $row) {
			$rows[] = [$row['caseType'], $row['month'], $row['closed'], $row['avgDays'], $row['withinNormPercent']];
		}

		$header = [
			$this->l10n->t('Case type'),
			$this->l10n->t('Month'),
			$this->l10n->t('Closed cases'),
			$this->l10n->t('Average processing time (days)'),
			$this->l10n->t('Within the norm (%%)'),
		];

		return new DataDownloadResponse(
			"\u{FEFF}" . $this->csvWriter->document(header: $header, rows: $rows),
			'doorlooptijd.csv',
			'text/csv; charset=utf-8'
		);
	}//end csv()

	/**
	 * The refusal a malformed query earns, or null when it reads.
	 *
	 * @param array<string, mixed> $query The case type filter, the period and the at-risk window as they arrived.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/changes/archive/2026-06-13-doorlooptijd-dashboard/tasks.md#T02
	 */
	private function badRequest(array $query): ?JSONResponse {
		$caseType = $query['caseType'];
		$period = $query['period'];
		if ($caseType !== null && is_string($caseType) === false) {
			return new JSONResponse(['message' => 'caseType must be a string'], Http::STATUS_BAD_REQUEST);
		}

		if (is_string($period) === false || preg_match('/^\d+m$/', $period) !== 1) {
			return new JSONResponse(['message' => 'period must look like 12m'], Http::STATUS_BAD_REQUEST);
		}

		if (is_numeric($query['atRiskDays']) === false) {
			return new JSONResponse(['message' => 'atRiskDays must be a number'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end badRequest()
}//end class
