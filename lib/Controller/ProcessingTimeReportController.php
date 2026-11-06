<?php

/**
 * REST read for the processing-time report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Reporting\ProcessingTimeReportService;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `GET /api/reports/processing-time?period&caseType&view&threshold`.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ProcessingTimeReportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request     Inbound request.
	 * @param ProcessingTimeReportService $reports     Builds the report.
	 * @param ReportPeriod                $periods     Knows the period presets.
	 * @param IUserSession                $userSession Current user session.
	 * @param ReportingAudience           $audience    Who may read a figure about every record.
	 * @param LoggerInterface             $logger      Records a failed build.
	 */
	public function __construct(
		IRequest $request,
		private readonly ProcessingTimeReportService $reports,
		private readonly ReportPeriod $periods,
		private readonly IUserSession $userSession,
		private readonly ReportingAudience $audience,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the report, or one block of it.
	 *
	 * @return JSONResponse The report, 400 on a malformed query, 401/403 on refusal.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// An aggregate over every record: OpenRegister's per-object refusal
		// never sees it, so the audience is checked here.
		if ($this->audience->isInAudience(user: $user) === false) {
			return new JSONResponse(['message' => ReportingAudience::REFUSAL], Http::STATUS_FORBIDDEN);
		}

		$period    = $this->request->getParam('period', ReportPeriod::DEFAULT_PRESET);
		$caseType  = $this->request->getParam('caseType');
		$view      = $this->request->getParam('view');
		$threshold = $this->request->getParam('threshold', ProcessingTimeReportService::DEFAULT_THRESHOLD);

		$refusal = $this->badRequest(period: $period, caseType: $caseType, view: $view, threshold: $threshold);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse(
				$this->reports->getReport(
					period: (string)$period,
					caseType: $this->emptyToNull(value: $caseType),
					view: $this->emptyToNull(value: $view),
					threshold: (float)$threshold,
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: processing-time report failed', ['exception' => $e->getMessage()]);

			return new JSONResponse(['message' => 'Report generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end show()

	/**
	 * The refusal a malformed query earns, or null when it reads.
	 *
	 * @param mixed $period    The period preset as it arrived.
	 * @param mixed $caseType  The record type filter as it arrived.
	 * @param mixed $view      The block name as it arrived.
	 * @param mixed $threshold The at-risk threshold as it arrived.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function badRequest(mixed $period, mixed $caseType, mixed $view, mixed $threshold): ?JSONResponse {
		$message = null;
		if ($this->periods->isPreset(preset: $period) === false) {
			$message = 'period must be one of '.implode(', ', ReportPeriod::PRESETS);
		} else if ($caseType !== null && is_string($caseType) === false) {
			$message = 'caseType must be a string';
		} else if ($this->isKnownView(view: $view) === false) {
			$message = 'view must be one of '.implode(', ', ProcessingTimeReportService::VIEWS);
		} else if (is_numeric($threshold) === false || (float)$threshold < 0 || (float)$threshold > 1) {
			$message = 'threshold must be a number from 0 to 1';
		}

		if ($message === null) {
			return null;
		}

		return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * Whether a view is absent or names a report block.
	 *
	 * @param mixed $view The block name as it arrived.
	 *
	 * @return bool True when absent, empty or known.
	 */
	private function isKnownView(mixed $view): bool {
		return $this->emptyToNull(value: $view) === null
			|| in_array($view, ProcessingTimeReportService::VIEWS, true) === true;
	}//end isKnownView()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return string|null The value, or null when absent or empty.
	 */
	private function emptyToNull(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end emptyToNull()
}//end class
