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

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\ProcessingTimeReportServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ReportingAudienceInterface;
use OCA\Dossiq\Kernel\Contracts\ReportPeriodInterface;
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
	 * @param ProcessingTimeReportServiceInterface $reports     Builds the report.
	 * @param ReportPeriodInterface                $periods     Knows the period presets.
	 * @param IUserSession                $userSession Current user session.
	 * @param ReportingAudienceInterface           $audience    Who may read a figure about every record.
	 * @param LoggerInterface             $logger      Records a failed build.
	 */
	public function __construct(
		IRequest $request,
		private readonly ProcessingTimeReportServiceInterface $reports,
		private readonly ReportPeriodInterface $periods,
		private readonly IUserSession $userSession,
		private readonly ReportingAudienceInterface $audience,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
		$denied = $this->audienceRefusal();
		if ($denied !== null) {
			return $denied;
		}

		$query = [
			'period' => $this->request->getParam('period', ReportPeriodInterface::DEFAULT_PRESET),
			'caseType' => $this->request->getParam('caseType'),
			'view' => $this->request->getParam('view'),
			'threshold' => $this->request->getParam('threshold', ProcessingTimeReportServiceInterface::DEFAULT_THRESHOLD),
		];

		$refusal = $this->badRequest(query: $query);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->report(query: $query);
	}//end show()

	/**
	 * The report for a well-formed query, or 500 when it could not be made.
	 *
	 * @param array{period: mixed, caseType: mixed, view: mixed, threshold: mixed} $query The period, case type, view and
	 *                                                                             threshold parameters.
	 *
	 * @return JSONResponse The report, or 500.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function report(array $query): JSONResponse {
		try {
			return new JSONResponse(
				$this->reports->getReport(
					period: (string)$query['period'],
					caseType: $this->emptyToNull(value: $query['caseType']),
					view: $this->emptyToNull(value: $query['view']),
					threshold: (float)$query['threshold'],
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: processing-time report failed', ['exception' => $e->getMessage()]);

			return new JSONResponse(['message' => 'Report generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end report()

	/**
	 * The 401/403 refusal for a caller who is not signed in or not in the reporting audience, else null.
	 *
	 * An aggregate over every record: OpenRegister's per-object refusal
	 * never sees it, so the audience is checked here.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function audienceRefusal(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->audience->isInAudience(user: $user) === false) {
			return new JSONResponse(['message' => ReportingAudienceInterface::REFUSAL], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end audienceRefusal()

	/**
	 * The refusal a malformed query earns, or null when it reads.
	 *
	 * @param array{period: mixed, caseType: mixed, view: mixed, threshold: mixed} $query The period preset, record type filter,
	 *                                                                             block name and at-risk threshold as they arrived.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function badRequest(array $query): ?JSONResponse {
		$period    = $query['period'];
		$caseType  = $query['caseType'];
		$view      = $query['view'];
		$threshold = $query['threshold'];
		$message = null;
		if ($this->periods->isPreset(preset: $period) === false) {
			$message = 'period must be one of '.implode(', ', ReportPeriodInterface::PRESETS);
		} else if ($caseType !== null && is_string($caseType) === false) {
			$message = 'caseType must be a string';
		} else if ($this->isKnownView(view: $view) === false) {
			$message = 'view must be one of '.implode(', ', ProcessingTimeReportServiceInterface::VIEWS);
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
			|| in_array($view, ProcessingTimeReportServiceInterface::VIEWS, true) === true;
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
