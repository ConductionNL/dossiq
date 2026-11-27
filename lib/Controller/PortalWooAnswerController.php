<?php

/**
 * Dossiq Portal Woo Answer Controller
 *
 * The receiving end of the portal row action `beantwoordVraag` on the
 * collection `vragenAanU` (woo-dossier-shared-with-the-requester REQ-WDS-001).
 * portaliq proves the row is the resident's, then forwards the answer here
 * server-to-server with a signed `X-Portal-Subject` assertion. The route is
 * public and CSRF-free because the caller is portaliq's backend: the assertion
 * is the authentication, and there is no session fallback.
 *
 * Order, fail-closed: verify (401), audience (403), then PortalWooAnswer,
 * which validates (400) and checks the request is the resident's and open
 * (404). The resident comes from the verified `sub` claim only.
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\PortalAnswerRead;
use OCA\Dossiq\Controller\Support\VerifiesPortalAssertions;
use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Dossiq\Kernel\Contracts\PortalWooAnswerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * Records the answer the resident portaliq vouches for.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 */
class PortalWooAnswerController extends Controller {
	use VerifiesPortalAssertions;

	/**
	 * The brute-force action a rejected assertion counts against.
	 */
	private const THROTTLE_ACTION = 'dossiq_portal_assertion';

	/**
	 * The portal audiences this action is offered to (DigiD arrives as client).
	 */
	private const AUDIENCES = ['citizen', 'client'];

	/**
	 * Reads the answer, and the files with it, from the forwarded request.
	 *
	 * @var PortalAnswerRead
	 */
	private readonly PortalAnswerRead $submissions;

	/**
	 * Constructor.
	 *
	 * @param string                  $appName   The app name.
	 * @param IRequest                $request   The request.
	 * @param PortalAssertionVerifierInterface $verifier  Verifies portaliq's assertion.
	 * @param PortalWooAnswerInterface         $answers   Records the answer.
	 * @param IThrottler              $throttler Counts rejected assertions.
	 * @param LoggerInterface         $logger    Logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalWooAnswerInterface $answers,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->submissions = new PortalAnswerRead(request: $request);
	}//end __construct()

	/**
	 * Record the requester's answer on their open request.
	 *
	 * @return JSONResponse 200 `{requestId, state}`; 401, 403, 400, 404 or 503 `{error}`.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function answer(): JSONResponse {
		$claims = $this->portalClaims();
		if ($claims instanceof JSONResponse) {
			return $claims;
		}

		$submission = $this->submissions->read(subjectRef: (string)($claims['sub'] ?? ''));
		if ($submission === null) {
			return new JSONResponse(
				['error' => 'invalid', 'message' => 'Send at most five files of at most 10 MB each.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$result = $this->answers->submit(submission: $submission);

		return new JSONResponse($result['body'], $result['status']);
	}//end answer()
}//end class
