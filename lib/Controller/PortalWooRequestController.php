<?php

/**
 * Dossiq Portal Woo Request Controller
 *
 * The receiving end of the portal action `startWooVerzoek` (hydra
 * woo-citizen-journey C5). portaliq forwards the resident's form here
 * server-to-server with a signed `X-Portal-Subject` assertion. The route is
 * public and CSRF-free because the caller is portaliq's backend, not a
 * browser: the assertion IS the authentication, and there is no Nextcloud
 * session fallback, so there is one auth path.
 *
 * Order, fail-closed: verify (401), audience (403), then the intake, which
 * validates (400) and checks the dossier is the resident's (404). The
 * resident comes from the verified `sub` claim only; a `subjectRef` or
 * `origin` in the body is never read.
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\Dossiq\Woo\WooRequestRefused;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts a Woo request for the resident portaliq vouches for.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
 */
class PortalWooRequestController extends Controller {

	/**
	 * The brute-force action a rejected assertion counts against.
	 */
	private const THROTTLE_ACTION = 'dossiq_portal_assertion';

	/**
	 * The portal audiences this action is offered to (DigiD arrives as client).
	 */
	private const AUDIENCES = ['citizen', 'client', 'business'];

	/**
	 * The form fields the action forwards.
	 */
	private const FIELDS = [
		'collectionId',
		'onderwerp',
		'omschrijving',
		'periodeVan',
		'periodeTot',
		// The answers of steps 2 and 3 (site-woo-request-in-steps). A field
		// that is not on this list never reaches the intake, whatever the
		// browser sends, which is why the new ones have to be named here as
		// well as in the action.
		'documentSoorten',
		'toelichting',
		'verzoekerNaam',
		'verzoekerEmail',
		'verzoekerType',
	];

	/**
	 * The status each refusal answers with.
	 */
	private const STATUS = [
		WooRequestRefused::INVALID => Http::STATUS_BAD_REQUEST,
		WooRequestRefused::NOT_FOUND => Http::STATUS_NOT_FOUND,
		WooRequestRefused::UNAVAILABLE => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Constructor.
	 *
	 * @param string                  $appName   The app name.
	 * @param IRequest                $request   The request.
	 * @param PortalAssertionVerifier $verifier  Verifies portaliq's assertion.
	 * @param WooRequestIntake        $intake    The one Woo request creation path.
	 * @param IThrottler              $throttler Counts rejected assertions.
	 * @param LoggerInterface         $logger    Logger.
	 * @param IFactory|null           $l10n      Gives the translator a refusal is worded in.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly WooRequestIntake $intake,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
		private readonly ?IFactory $l10n = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Start a Woo request from the portal.
	 *
	 * @return JSONResponse 201 `{caseId, caseUrl}`; 401, 403, 400, 404 or 503 `{error, message}`.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function start(): JSONResponse {
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$this->registerRejectedAssertion();
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array((string)($claims['audience'] ?? ''), self::AUDIENCES, true) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$request = ['subjectRef' => (string)$claims['sub']];
		// The branch a company session is restricted to comes from the signed
		// assertion only, like the subject; a branch in the body is never read
		// (portal-case-list-declarations D3).
		$branch = trim((string)($claims['branch'] ?? ''));
		if ($branch !== '') {
			$request['branch'] = $branch;
		}

		foreach (self::FIELDS as $field) {
			$request[$field] = $this->request->getParam($field);
		}

		$request['origin'] = 'portal';
		$request['originReference'] = null;

		try {
			return new JSONResponse($this->intake->start($request), Http::STATUS_CREATED);
		} catch (WooRequestRefused $refused) {
			return new JSONResponse(
				$this->refusalBody(refused: $refused),
				(self::STATUS[$refused->getReason()] ?? Http::STATUS_BAD_REQUEST)
			);
		}
	}//end start()

	/**
	 * The answer to a refused request: the reason as a code, the sentence in
	 * the resident's language, and that sentence under the field it is about.
	 *
	 * Portaliq puts an `errors` entry on the field of that name and lists it
	 * in the form's error summary (site-action-forms), so the resident reads
	 * which answer to change instead of "Niet alles is goed ingevuld".
	 *
	 * @param WooRequestRefused $refused The refusal.
	 *
	 * @return array<string, mixed> The body.
	 *
	 * @spec openspec/changes/portal-woo-request-form-takes-dutch-input/specs/woo-request-intake/spec.md#requirement-a-refused-woo-request-names-the-field-in-the-residents-language-req-wdi-002
	 */
	private function refusalBody(WooRequestRefused $refused): array {
		$sentence = $refused->getDetail();
		if ($sentence !== '' && $this->l10n !== null) {
			$sentence = (string)$this->l10n->get($this->appName, $this->readerLanguage())->t($sentence);
		}

		$body = ['error' => $refused->getReason(), 'message' => $sentence];
		if ($refused->getField() !== '') {
			$body['errors'] = [$refused->getField() => $sentence];
		}

		return $body;
	}//end refusalBody()

	/**
	 * The language a refusal is worded in.
	 *
	 * The route has no signed-in user, so there is no user language to
	 * follow. English when the forwarded request asks for it first, Dutch
	 * otherwise: the portal a resident files through speaks Dutch.
	 *
	 * @return string The language code.
	 */
	private function readerLanguage(): string {
		$asked = strtolower(trim((string)$this->request->getHeader('Accept-Language')));
		if (str_starts_with($asked, 'en') === true) {
			return 'en';
		}

		return 'nl';
	}//end readerLanguage()

	/**
	 * Count a rejected assertion; bookkeeping never turns a 401 into a 500.
	 *
	 * @return void
	 */
	private function registerRejectedAssertion(): void {
		try {
			$this->throttler->registerAttempt(action: self::THROTTLE_ACTION, ip: $this->request->getRemoteAddress());
		} catch (Throwable $e) {
			$this->logger->warning('PortalWooRequestController: registerAttempt failed', ['error' => $e->getMessage()]);
		}
	}//end registerRejectedAssertion()
}//end class
