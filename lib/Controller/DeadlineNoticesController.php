<?php

/**
 * Dossiq DeadlineNoticesController.
 *
 * The notices of default by case, with the penalty each started and where
 * that money stands (board DqTermijnen "Ingebrekestellingen en dwangsom").
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
 * @spec openspec/changes/notices-of-default-by-case/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\ReportingAudienceGuard;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\Termijn\NoticeOfDefaultRows;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notices of default REST surface.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/notices-of-default-by-case/specs/termijn-reporting/spec.md
 */
class DeadlineNoticesController extends Controller {
	use ReportingAudienceGuard;

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     App id.
	 * @param IRequest                 $request     Request.
	 * @param IUserSession             $userSession User session.
	 * @param LoggerInterface          $logger      Logger.
	 * @param ReportingAudience        $audience    Who may read a figure about every case.
	 * @param NoticeOfDefaultRows|null $notices     The notices of default by case, with their penalty.
	 *        Nullable so an instance that never wired it answers "not available".
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ReportingAudience $audience,
		private readonly ?NoticeOfDefaultRows $notices = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The notices of default by case, behind the same audience gate as the
	 * deadline reports.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notices-of-default-by-case/specs/termijn-reporting/spec.md
	 */
	public function notices(): JSONResponse {
		$denied = $this->refuseOutsideReportingAudience(userSession: $this->userSession, audience: $this->audience);
		if ($denied !== null) {
			return $denied;
		}

		if ($this->notices === null) {
			return new JSONResponse(['message' => 'Not available'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			return new JSONResponse($this->notices->list());
		} catch (Throwable $e) {
			$this->logger->error('Notice of default list failed', ['error' => $e->getMessage()]);

			return new JSONResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end notices()
}//end class
