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
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Portal\CitizenManifest;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Portal\PortalWooAnswer;
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
use Throwable;

/**
 * Records the answer the resident portaliq vouches for.
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 */
class PortalWooAnswerController extends Controller {

	/**
	 * The brute-force action a rejected assertion counts against.
	 */
	private const THROTTLE_ACTION = 'dossiq_portal_assertion';

	/**
	 * The portal audiences this action is offered to (DigiD arrives as client).
	 */
	private const AUDIENCES = ['citizen', 'client'];

	/**
	 * Constructor.
	 *
	 * @param string                  $appName   The app name.
	 * @param IRequest                $request   The request.
	 * @param PortalAssertionVerifier $verifier  Verifies portaliq's assertion.
	 * @param PortalWooAnswer         $answers   Records the answer.
	 * @param IThrottler              $throttler Counts rejected assertions.
	 * @param LoggerInterface         $logger    Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalWooAnswer $answers,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Record the requester's answer on their open request.
	 *
	 * @return JSONResponse 200 `{requestId, state}`; 401, 403, 400, 404 or 503 `{error}`.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function answer(): JSONResponse {
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$this->registerRejectedAssertion();
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array((string)($claims['audience'] ?? ''), self::AUDIENCES, true) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$attachments = $this->attachments();
		if ($attachments === null) {
			return new JSONResponse(
				['error' => 'invalid', 'message' => 'Send at most five files of at most 10 MB each.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$result = $this->answers->answer(
			subjectRef: (string)($claims['sub'] ?? ''),
			requestId: $this->stringParam(key: 'requestId'),
			answer: $this->stringParam(key: 'antwoord'),
			attachments: $attachments,
		);

		return new JSONResponse($result['body'], $result['status']);
	}//end answer()

	/**
	 * The files portaliq forwarded under `bijlagen` (row-action-carries-files), read into bytes.
	 *
	 * Portaliq already checked the number and size; they are checked again
	 * here because this route trusts the assertion, not the forwarder's checks.
	 *
	 * @return list<array{name: string, content: string}>|null The files, or null when there are too many or one is too large.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function attachments(): ?array {
		$entries = $this->uploadEntries(uploaded: $this->request->getUploadedFile(CitizenManifest::ANSWER_FILES_FIELD));
		if (count($entries) > CitizenManifest::ANSWER_FILES_MAX) {
			return null;
		}

		$files = [];
		foreach ($entries as $entry) {
			if ((int)($entry['size'] ?? 0) > CitizenManifest::ANSWER_FILES_MAX_BYTES) {
				return null;
			}

			$content = $this->readUpload(path: (string)($entry['tmp_name'] ?? ''));
			if ($content !== null) {
				$files[] = ['name' => basename((string)($entry['name'] ?? 'bijlage')), 'content' => $content];
			}
		}

		return $files;
	}//end attachments()

	/**
	 * One upload or PHP's parallel-array shape, as a list of entries.
	 *
	 * @param mixed $uploaded What `IRequest::getUploadedFile()` answered.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function uploadEntries(mixed $uploaded): array {
		if (is_array($uploaded) === false || isset($uploaded['tmp_name']) === false) {
			return [];
		}

		if (is_array($uploaded['tmp_name']) === false) {
			return [$uploaded];
		}

		$entries = [];
		foreach (array_keys($uploaded['tmp_name']) as $key) {
			$entries[] = [
				'name' => ($uploaded['name'][$key] ?? ''),
				'tmp_name' => $uploaded['tmp_name'][$key],
				'size' => ($uploaded['size'][$key] ?? 0),
			];
		}

		return $entries;
	}//end uploadEntries()

	/**
	 * The bytes of an uploaded file, or null when it cannot be read.
	 *
	 * @param string $path The temporary path.
	 *
	 * @return string|null
	 */
	private function readUpload(string $path): ?string {
		if ($path === '' || is_readable($path) === false) {
			return null;
		}

		$content = file_get_contents($path);
		if ($content === false) {
			return null;
		}

		return $content;
	}//end readUpload()

	/**
	 * A request param as a string, or '' when it is absent or not a string.
	 *
	 * @param string $key The param.
	 *
	 * @return string
	 */
	private function stringParam(string $key): string {
		$value = $this->request->getParam($key, '');
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end stringParam()

	/**
	 * Count a rejected assertion; bookkeeping never turns a 401 into a 500.
	 *
	 * @return void
	 */
	private function registerRejectedAssertion(): void {
		try {
			$this->throttler->registerAttempt(action: self::THROTTLE_ACTION, ip: $this->request->getRemoteAddress());
		} catch (Throwable $e) {
			$this->logger->warning('PortalWooAnswerController: registerAttempt failed', ['error' => $e->getMessage()]);
		}
	}//end registerRejectedAssertion()
}//end class
