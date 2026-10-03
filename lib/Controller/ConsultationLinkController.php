<?php

/**
 * Dossiq consultation link controller.
 *
 * How an advisory body outside the organisation is asked for advice and how
 * that advice comes back: one endpoint mints the case access link and names
 * the body on the share, the other collects the comment the body wrote.
 *
 * Split from {@see ConsultationController} along the same seam the deleted
 * token surface sat on. That surface was two endpoints reading a token nothing
 * ever minted, so it could not be entered; these two replace it, and keeping
 * them together is what makes the replacement legible.
 *
 * Both carry `@NoAdminRequired` and run the same ConsultationAccessGuard as
 * every other consultation endpoint, so only the applicant, the assignee or an
 * admin can publish a case or record an answer on it.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Consultation\ConsultationAccessGuard;
use OCA\Dossiq\Service\Consultation\ExternalConsultationLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Invites an external advisory body over a case link, and collects its advice.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */
class ConsultationLinkController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param ConsultationAccessGuard $accessGuard The authorization and body-decoding guard
	 * @param ExternalConsultationLinkService $externalLinks The external advisory body's access link
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConsultationAccessGuard $accessGuard,
		private readonly ExternalConsultationLinkService $externalLinks,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Invite the advisory body over a case access link.
	 *
	 * @param string $id The consultation UUID
	 *
	 * @return JSONResponse The share and the address to send
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	public function externalLink(string $id): JSONResponse {
		$access = $this->accessGuard->authorize(consultationId: $id);
		if ($access->error !== null) {
			return $access->error;
		}

		try {
			$data = $this->accessGuard->requestBody();
			$share = $this->externalLinks->invite(
				consultationId: $id,
				userId: $this->accessGuard->currentUid(),
				password: $this->optionalPassword(value: ($data['password'] ?? null)),
			);
			return new JSONResponse($share, Http::STATUS_CREATED);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end externalLink()

	/**
	 * Collect the advisory body's comment onto the consultation.
	 *
	 * @param string $id The consultation UUID
	 *
	 * @return JSONResponse What was recorded
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	public function collectAdvice(string $id): JSONResponse {
		$access = $this->accessGuard->authorize(consultationId: $id);
		if ($access->error !== null) {
			return $access->error;
		}

		try {
			$data = $this->accessGuard->requestBody();
			$result = $this->externalLinks->collect(
				consultationId: $id,
				advice: (string)($data['advice'] ?? ''),
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end collectAdvice()

	/**
	 * The password on an invitation, or null when none was given.
	 *
	 * @param mixed $value What the request body carried
	 *
	 * @return string|null The password, or null
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	private function optionalPassword(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$password = (string)$value;
		if ($password === '') {
			return null;
		}

		return $password;
	}//end optionalPassword()
}//end class
