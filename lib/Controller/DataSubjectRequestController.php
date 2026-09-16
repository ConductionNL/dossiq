<?php

/**
 * Dossiq Data Subject Request Controller.
 *
 * The four acts a handler takes on an AVG request from the case page: take the
 * erasure preview, run the approved erasure, ask for the subject's own export,
 * and read whether that export can still be taken.
 *
 * Every one of them is a call into OpenRegister behind
 * {@see DataSubjectRequestCase}. There is no endpoint here that erases
 * anything, and that is the point of the change this controller belongs to:
 * the platform owns the destruction and dossiq owns the handling.
 *
 * 🔑 AUTHORIZATION IS THE CASE'S, NOT A ROLE'S. These endpoints are
 * `#[NoAdminRequired]` because a privacy officer is not a Nextcloud
 * administrator, and they are guarded per case by `CaseAccessGuard` because
 * the subject of the request is on somebody's case file, not on the instance
 * at large. An endpoint that only checked "is authenticated" would let any
 * account on the instance count what we hold about any person by iterating
 * case ids, which is the shape of the finding `CitizenLookupGuard` exists for.
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
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Gdpr\DataSubjectRequestCase;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The AVG acts a handler takes on a data subject request case.
 *
 * @psalm-suppress UnusedClass Registered through appinfo/routes.php.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */
class DataSubjectRequestController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string                 $appName     The app name.
	 * @param IRequest               $request     The request.
	 * @param DataSubjectRequestCase $requests    The case side of a data subject request.
	 * @param CaseAccessGuard        $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession           $userSession The session.
	 * @param LoggerInterface        $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DataSubjectRequestCase $requests,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Take the erasure preview for this case's data subject.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return JSONResponse The preview with its counts and protected items, or the refusal.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#requirement-a-data-subject-request-is-a-case-and-the-platform-does-the-erasing-req-avg-dsr-01
	 */
	#[NoAdminRequired]
	public function preview(string $caseId): JSONResponse {
		if ($this->writerOf(caseId: $caseId) === null) {
			return $this->notYours();
		}

		try {
			return new JSONResponse($this->requests->preview(caseId: $caseId));
		} catch (RefusedException $e) {
			return $this->refused(op: 'preview the erasure', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'preview', e: $e);
		}
	}//end preview()

	/**
	 * Run the erasure this case has approved.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return JSONResponse What the platform destroyed, pseudonymised and withheld, or the refusal.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#requirement-an-erasure-runs-only-after-a-second-person-approves-it-req-avg-dsr-02
	 */
	#[NoAdminRequired]
	public function run(string $caseId): JSONResponse {
		if ($this->writerOf(caseId: $caseId) === null) {
			return $this->notYours();
		}

		try {
			return new JSONResponse($this->requests->run(caseId: $caseId));
		} catch (RefusedException $e) {
			return $this->refused(op: 'run the erasure', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'run', e: $e);
		}
	}//end run()

	/**
	 * Ask the platform for this subject's own export.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return JSONResponse The export record, or the refusal.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#requirement-a-data-subject-request-is-a-case-and-the-platform-does-the-erasing-req-avg-dsr-01
	 */
	#[NoAdminRequired]
	public function requestExport(string $caseId): JSONResponse {
		if ($this->writerOf(caseId: $caseId) === null) {
			return $this->notYours();
		}

		try {
			return new JSONResponse($this->requests->requestExport(caseId: $caseId));
		} catch (RefusedException $e) {
			return $this->refused(op: 'ask for the subject export', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'requestExport', e: $e);
		}
	}//end requestExport()

	/**
	 * Whether this case's export can still be taken, asked of the platform.
	 *
	 * A read rather than a write, and it still needs mutation access: knowing
	 * that an export of a named person exists is itself something only this
	 * case's handlers should learn.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return JSONResponse `{exportId, downloadable, expiresAt, expired}`, or the refusal.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#requirement-an-incomplete-erasure-keeps-the-case-open-and-says-what-is-left-req-avg-dsr-03
	 */
	#[NoAdminRequired]
	public function exportState(string $caseId): JSONResponse {
		if ($this->writerOf(caseId: $caseId) === null) {
			return $this->notYours();
		}

		try {
			return new JSONResponse($this->requests->exportState(caseId: $caseId));
		} catch (RefusedException $e) {
			return $this->refused(op: 'read the subject export', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'exportState', e: $e);
		}
	}//end exportState()

	/**
	 * The caller, when they may write to this case, and null when they may not.
	 *
	 * @param string $caseId The case.
	 *
	 * @return IUser|null The caller, or null.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
	 */
	private function writerOf(string $caseId): ?IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return null;
		}

		return $user;
	}//end writerOf()

	/**
	 * The refusal for a caller who may not act on this case.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot act on this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()

	/**
	 * The answer for something that broke rather than refused.
	 *
	 * @param string    $op The endpoint, for the log line.
	 * @param Throwable $e  What went wrong.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
	 */
	private function broke(string $op, Throwable $e): JSONResponse {
		$this->logger->error(
			'DataSubjectRequestController: ' . $op . ' failed',
			['exception' => $e->getMessage()]
		);

		return new JSONResponse(
			['message' => 'This could not be completed. Nothing was erased.', 'error' => 'data-subject-request-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end broke()
}//end class
