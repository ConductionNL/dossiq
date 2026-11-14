<?php

/**
 * Dossiq TermijnController.
 *
 * REST surface for TermijnInstance lifecycle (create, get, pauze,
 * hervat, verleng, voltooi). Defers all business logic to
 * {@see TermijnService}, {@see DeadlinePauseService} and
 * {@see DeadlineExtensionService} (ADR-022).
 *
 * Auth: @NoAdminRequired — handler / caseworker calls only. Per-object
 * IDOR guard is enforced by re-fetching the instance and verifying the
 * caller's case-access through the existing CaseSharingService is
 * outside the chain scope; for now we rely on NC SecurityMiddleware's
 * authenticated-user default + the case-bound zaakId on the row.
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\Support\ReadsJsonRequests;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTypeSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlineExtensionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlinePauseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for TermijnInstance lifecycle.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class TermijnController extends Controller {
	use ReadsJsonRequests;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param TermijnServiceInterface $term Termijn service.
	 * @param DeadlinePauseServiceInterface $pause Pause service.
	 * @param DeadlineExtensionServiceInterface $extension Extension service.
	 * @param CaseDateNormaliserInterface $dates The one date write path.
	 * @param CaseTypeSlugResolverInterface $caseTypeSlugs Case-type uuid-to-slug resolver.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TermijnServiceInterface $term,
		private readonly DeadlinePauseServiceInterface $pause,
		private readonly DeadlineExtensionServiceInterface $extension,
		private readonly CaseDateNormaliserInterface $dates,
		private readonly CaseTypeSlugResolverInterface $caseTypeSlugs,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Per-object authorization guard.
	 *
	 * Returns a Http::STATUS_FORBIDDEN response when no user is logged in.
	 * Per-object IDOR enforcement (the user must have access to the
	 * specific zaak) is delegated to {@see TermijnService} which only
	 * returns instances bound to a zaak the caller can see — the NC
	 * SecurityMiddleware enforces base auth + we re-check the session
	 * here so the controller cannot be reached anonymously even if
	 * route attributes are misconfigured.
	 *
	 * @return JSONResponse|null
	 */
	private function ensureAuthenticated(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end ensureAuthenticated()

	/**
	 * Create a TermijnInstance for a zaak.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function create(): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$caseId = (string)($body['caseId'] ?? '');
		$caseTypeRef = (string)($body['caseType'] ?? '');
		if ($caseId === '' || $caseTypeRef === '') {
			return $this->badRequest(msg: 'zaakId and zaaktype are required');
		}

		// A caller holding a case reads its `caseType` as a uuid, while a
		// deadlineDefinition binds by slug. Both are accepted here and resolve
		// to the slug, so this endpoint and the created-case listener agree.
		$caseType = $this->caseTypeSlugs->toSlug(reference: $caseTypeRef);
		if ($caseType === '') {
			return $this->badRequest(msg: 'zaaktype could not be resolved');
		}

		try {
			$row = $this->term->createTermijnInstance($caseId, $caseType);
			return new JSONResponse($row, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Termijn create failed');
		}
	}//end create()

	/**
	 * Get a TermijnInstance by id.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function show(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$row = $this->term->getTermijnInstance($id);
		if ($row === null) {
			return $this->notFound(msg: 'TermijnInstance not found: ' . $id);
		}

		return new JSONResponse($row);
	}//end show()

	/**
	 * Pause a TermijnInstance.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function pauze(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$durationDays = (int)($body['duurDagen'] ?? 0);
		$rationale = (string)($body['rationale'] ?? '');
		$documentLink = (string)($body['documentLink'] ?? '');

		try {
			// The declared reason reaches this path too. Without it a pause
			// registered here could never carry one, and a case suspended
			// through the term endpoint would be the one case in the app that
			// silently chases nobody.
			$row = $this->pause->registerPauze(
				$id,
				$durationDays,
				$rationale,
				$documentLink,
				(string)($body['pauseReason'] ?? '')
			);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Pauze failed');
		}
	}//end pauze()

	/**
	 * Resume after pauze.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function hervat(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$when = (string)($body['aanvullingDatum'] ?? '');
		$resumeAt = null;
		if ($when !== '') {
			try {
				$resumeAt = $this->dates->parse($when, 'aanvullingDatum');
			} catch (InvalidArgumentException $e) {
				return $this->badRequest(msg: $e->getMessage());
			}
		}

		try {
			$row = $this->pause->resumeAfterPauze($id, $resumeAt);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Hervat failed');
		}
	}//end hervat()

	/**
	 * Request a verlenging.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function verleng(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$newEndDate = $this->newEndDate(value: (string)($body['newEinddatum'] ?? ''));
		if ($newEndDate instanceof JSONResponse) {
			return $newEndDate;
		}

		$body['newEinddatum'] = $newEndDate;
		try {
			return new JSONResponse($this->extend(id: $id, body: $body));
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Verleng failed');
		}
	}//end verleng()

	/**
	 * The requested new end date as a calendar date, or the 400 refusal.
	 *
	 * The extension service used to take this as a raw string and parse it
	 * its own way. It is normalised here, once, before it reaches any
	 * arithmetic. An absent value stays absent: the service owns the
	 * "a new deadline is required" rule and states it in Awb terms.
	 *
	 * @param string $value The posted `newEinddatum`, empty when absent.
	 *
	 * @return string|JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	private function newEndDate(string $value): string|JSONResponse {
		if ($value === '') {
			return $value;
		}

		try {
			return $this->dates->toCalendarDate($value, 'newEinddatum');
		} catch (InvalidArgumentException $e) {
			return $this->badRequest(msg: $e->getMessage());
		}
	}//end newEndDate()

	/**
	 * Ask for the extension: the supervisor path only when the body explicitly asks for it.
	 *
	 * @param string               $id   The termijn instance id.
	 * @param array<string, mixed> $body The request body, its `newEinddatum` normalised.
	 *
	 * @return array<string, mixed> The updated instance.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	private function extend(string $id, array $body): array {
		$rationale = (string)($body['rationale'] ?? '');
		$newEndDate = (string)($body['newEinddatum'] ?? '');
		$documentLink = (string)($body['documentLink'] ?? '');
		if ((bool)($body['supervisorOverride'] ?? false) === true) {
			return $this->extension->requestSupervisorExtension($id, $rationale, $newEndDate, $documentLink);
		}

		return $this->extension->requestExtension($id, $rationale, $newEndDate, $documentLink);
	}//end extend()

	/**
	 * Mark a TermijnInstance as voltooid.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function voltooi(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$when = (string)($body['voltooiDatum'] ?? '');
		$documentLink = (string)($body['documentLink'] ?? '');
		$completedAt = null;
		if ($when !== '') {
			try {
				$completedAt = $this->dates->parse($when, 'voltooiDatum');
			} catch (InvalidArgumentException $e) {
				return $this->badRequest(msg: $e->getMessage());
			}
		}

		try {
			$row = $this->term->markTermijnCompleted(
				$id,
				$completedAt,
				$documentLink
			);
			if ($row === null) {
				return $this->notFound(msg: 'TermijnInstance not found: ' . $id);
			}

			return new JSONResponse($row);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Voltooi failed');
		}
	}//end voltooi()

	/**
	 * Build a 404 Not Found response.
	 *
	 * @param string $msg Message.
	 *
	 * @return JSONResponse
	 */
	private function notFound(string $msg): JSONResponse {
		return new JSONResponse(['message' => $msg], Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * Build a 400 response from a caught exception.
	 *
	 * @param Throwable $e Exception.
	 * @param string $log Log prefix.
	 *
	 * @return JSONResponse
	 */
	private function error(Throwable $e, string $log): JSONResponse {
		$this->logger->info($log . ': ' . $e->getMessage());
		return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
	}//end error()
}//end class
