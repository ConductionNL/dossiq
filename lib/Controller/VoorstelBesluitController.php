<?php

/**
 * Dossiq Voorstel Besluit Controller
 *
 * Exposes the voorstel→besluit registration node. Instead of authoring a
 * dossiq-local `decision` object, registering a besluit on a voorstel raises a
 * decidesk `report-adoption` Decision via the ADR-019 integration registry
 * (dossiq-delegate-remaining-decisions-to-decidesk, REQ-PDRD-001). dossiq
 * keeps the parafeerroute untouched and records the ZGW `Besluit` as a
 * projection of the decidesk outcome. FAILS CLOSED when decidesk is unavailable
 * (REQ-PDRD-002): no dossiq-local besluit is authored as a fallback.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for the voorstel besluit-registration delegation node.
 */
class VoorstelBesluitController extends Controller {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param AdviceDelegationService $adviceDelegation Decision delegation to decidesk (ADR-019).
	 * @param SettingsService $settingsService Schema/register + ObjectService resolver.
	 * @param IUserSession $userSession Acting identity source.
	 * @param IGroupManager $groupManager Group manager (admin check for the IDOR gate).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdviceDelegationService $adviceDelegation,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Register a besluit on a voorstel by raising a decidesk `report-adoption`
	 * Decision. IDOR-guarded: only the voorstel owner, its author, the linked
	 * case's assignee or an admin may register the besluit. FAILS CLOSED when
	 * decidesk is unavailable.
	 *
	 * @param string $proposalId The voorstel UUID.
	 *
	 * @return JSONResponse The decidesk decisionRef envelope, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	#[NoAdminRequired]
	public function registerBesluit(string $proposalId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
		}

		// Per-object IDOR gate (ADR-005 Rule 3 / OWASP A01:2021): read the
		// voorstel and verify the caller may act on it before raising anything.
		$proposal = $this->loadProposal(proposalId: $proposalId);
		if ($proposal === null) {
			return new JSONResponse(['error' => 'Voorstel niet toegankelijk'], Http::STATUS_NOT_FOUND);
		}

		if ($this->callerMayRegister(proposal: $proposal, uid: $user->getUID()) === false) {
			// Collapse access-denied + not-found to the same response to avoid
			// an existence-probing oracle.
			return new JSONResponse(['error' => 'Voorstel niet toegankelijk'], Http::STATUS_FORBIDDEN);
		}

		$body = $this->getRequestBody();

		$caseId = $this->caseIdOf(proposal: $proposal);
		if ($caseId === '') {
			$caseId = $proposalId;
		}

		try {
			$decisionRef = $this->adviceDelegation->raiseVoorstelBesluit(
				proposalId: $proposalId,
				payload: [
					'externalReference' => $caseId,
					'subjectLabel' => (string)($body['title'] ?? ($proposal['subject'] ?? '')),
					'title' => (string)($body['title'] ?? ''),
					'governingBody' => (string)($body['governingBody'] ?? ''),
					'explanation' => (string)($body['explanation'] ?? ''),
				],
			);
		} catch (RuntimeException $e) {
			// REQ-PDRD-002: fail closed — surface the unavailable error, do NOT
			// author a dossiq-local besluit as a fallback.
			$this->logger->error(
				'Dossiq: voorstel besluit-registration failed closed: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['error' => 'Besluitdienst niet beschikbaar: ' . $e->getMessage()],
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}//end try

		return new JSONResponse(
			['voorstelId' => $proposalId, 'decisionRef' => $decisionRef, 'status' => 'awaiting-decidesk'],
			Http::STATUS_ACCEPTED,
		);
	}//end registerBesluit()

	/**
	 * Load a voorstel via OpenRegister, or null when unavailable / not found.
	 *
	 * @param string $proposalId The voorstel UUID.
	 *
	 * @return array<string,mixed>|null The voorstel, or null.
	 */
	private function loadProposal(string $proposalId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$proposalSchema = $this->settingsService->getConfigValue(key: 'voorstel_schema');
		if ($register === '' || $proposalSchema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $proposalSchema,
				id: $proposalId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: voorstel lookup failed during IDOR gate: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return null;
		}
	}//end loadVoorstel()

	/**
	 * Whether the caller may register a besluit on the voorstel.
	 *
	 * Admins always may. Otherwise the caller must be the voorstel owner
	 * (@self.owner), its author (the steller), or the assignee of the case the
	 * voorstel belongs to. The voorstel schema declares no assignee or handler
	 * of its own — a read of either yields '' for every voorstel, which
	 * silently locked the behandelaar out — so the behandelaar arm reads the
	 * `assignee` the CASE schema actually declares.
	 *
	 * @param array<string,mixed> $proposal The voorstel record.
	 * @param string $uid The caller UID.
	 *
	 * @return bool
	 */
	private function callerMayRegister(array $proposal, string $uid): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		$owner = (string)($proposal['@self']['owner'] ?? '');
		if ($owner !== '' && $owner === $uid) {
			return true;
		}

		$author = (string)($proposal['author'] ?? '');
		if ($author !== '' && $author === $uid) {
			return true;
		}

		$assignee = $this->caseAssigneeOf(proposal: $proposal);

		return ($assignee !== '' && $assignee === $uid);
	}//end callerMayRegister()

	/**
	 * The assignee of the case the voorstel belongs to.
	 *
	 * Fails closed: when the case cannot be resolved there is no behandelaar
	 * to admit, and the owner/author/admin arms still stand.
	 *
	 * @param array<string,mixed> $proposal The voorstel record.
	 *
	 * @return string The case assignee UID, or ''.
	 */
	private function caseAssigneeOf(array $proposal): string {
		$caseId = $this->caseIdOf(proposal: $proposal);
		if ($caseId === '') {
			return '';
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			return '';
		}

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: case lookup failed during the besluit IDOR gate: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return '';
		}

		if ($case === null) {
			return '';
		}

		return (string)($case['assignee'] ?? '');
	}//end caseAssigneeOf()

	/**
	 * The id of the case the voorstel links to, whichever relation shape
	 * OpenRegister returned — a uuid string when unextended, the related
	 * object itself when extended.
	 *
	 * @param array<string,mixed> $proposal The voorstel record.
	 *
	 * @return string The case id, or ''.
	 */
	private function caseIdOf(array $proposal): string {
		$caseRef = ($proposal['case'] ?? null);
		if (is_array($caseRef) === true) {
			return (string)($caseRef['id'] ?? ($caseRef['uuid'] ?? ($caseRef['@self']['id'] ?? '')));
		}

		if (is_scalar($caseRef) === true) {
			return trim((string)$caseRef);
		}

		return '';
	}//end caseIdOf()

	/**
	 * Decode the JSON request body safely.
	 *
	 * @return array<string,mixed>
	 */
	private function getRequestBody(): array {
		$content = $this->request->getContent();
		if ($content === '' || $content === false) {
			return [];
		}

		$decoded = json_decode((string)$content, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end getRequestBody()
}//end class
