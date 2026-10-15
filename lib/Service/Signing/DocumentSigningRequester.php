<?php

/**
 * Dossiq Document Signing Requester
 *
 * Asks filinq to have a document signed. Filinq owns signing (its providers,
 * LibreSign among them, its signer rails and its expiry); dossiq raises
 * filinq's DocumentSigningRequestedEvent and hears the outcome later as
 * SigningConcludedEvent (see SigningConcludedListener). Nothing here waits for
 * a signature: the answer is whether filinq took the request, and its id.
 *
 * Filinq is optional at runtime, so its classes are named by string and
 * looked up when a request is made. Without filinq, or when filinq does not
 * take the request (an unknown signer, a signature level its provider does
 * not offer), the answer says so and nothing is pretended.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Signing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-document-is-signed-through-filinq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Signing;

use OCA\Dossiq\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises filinq's signing request for a document.
 *
 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-document-is-signed-through-filinq
 */
class DocumentSigningRequester {
	/**
	 * Filinq's request event.
	 */
	public const REQUESTED_EVENT = 'OCA\\Filinq\\Event\\DocumentSigningRequestedEvent';

	/**
	 * Filinq's provenance envelope, which the request event carries.
	 */
	public const PROVENANCE = 'OCA\\Filinq\\Event\\SigningProvenance';

	/**
	 * The app config key naming the signature level asked for when a caller names none.
	 */
	public const LEVEL_KEY = 'signing.level';

	/**
	 * The levels filinq knows.
	 */
	public const LEVELS = ['SES', 'AdES', 'QES'];

	/**
	 * The level asked for when nothing else is configured.
	 */
	public const DEFAULT_LEVEL = 'SES';

	/**
	 * Filinq is not installed (its event class does not exist).
	 */
	public const REASON_NO_FILINQ = 'filinq-not-installed';

	/**
	 * Filinq is installed and did not take the request.
	 */
	public const REASON_NOT_TAKEN = 'not-taken';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 * @param IAppConfig $appConfig Holds the configured signature level.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask filinq to have a document signed.
	 *
	 * @param string $subjectId The id of the object the signature is for; filinq hands it back on the conclusion.
	 * @param string $subjectLabel A name for the document, shown to the signer.
	 * @param string $fileId The Nextcloud file id of the document.
	 * @param list<string> $signerUserIds The Nextcloud users who sign, in order.
	 * @param string $signatureLevel SES, AdES or QES (see level()).
	 *
	 * @return array{requested:bool,requestId:string,reason:string}
	 *
	 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-document-is-signed-through-filinq
	 */
	public function request(
		string $subjectId,
		string $subjectLabel,
		string $fileId,
		array $signerUserIds,
		string $signatureLevel,
	): array {
		$eventClass = self::REQUESTED_EVENT;
		$provenanceClass = self::PROVENANCE;
		if (class_exists($eventClass) === false || class_exists($provenanceClass) === false) {
			return self::notRequested(reason: self::REASON_NO_FILINQ);
		}

		$signers = [];
		foreach ($signerUserIds as $userId) {
			$signers[] = ['userId' => $userId];
		}

		try {
			$event = new $eventClass(
				provenance: new $provenanceClass(
					sourceApp: Application::APP_ID,
					subjectId: $subjectId,
					externalReference: $subjectId,
					correlationId: bin2hex(random_bytes(16)),
				),
				subjectLabel: $subjectLabel,
				documentReference: $fileId,
				signers: $signers,
				signatureLevel: $signatureLevel,
			);
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the signing request could not be raised', ['exception' => $e::class, 'message' => $e->getMessage()]);

			return self::notRequested(reason: self::REASON_NOT_TAKEN);
		}

		$requestId = (string)($event->getSigningRequestId() ?? '');
		if ($event->isHandled() === false || $requestId === '') {
			$this->logger->warning('Dossiq: filinq did not take the signing request', ['subjectId' => $subjectId, 'level' => $signatureLevel]);

			return self::notRequested(reason: self::REASON_NOT_TAKEN);
		}

		return ['requested' => true, 'requestId' => $requestId, 'reason' => ''];
	}//end request()

	/**
	 * The signature level this instance asks for: `signing.level` when it holds SES, AdES or QES, else SES.
	 *
	 * Filinq refuses a level its signing provider does not offer, and the
	 * request then reads "not taken"; nothing signs at a lower level unasked.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-document-is-signed-through-filinq
	 */
	public function level(): string {
		$level = trim($this->appConfig->getValueString(Application::APP_ID, self::LEVEL_KEY, self::DEFAULT_LEVEL));
		if (in_array($level, self::LEVELS, true) === false) {
			return self::DEFAULT_LEVEL;
		}

		return $level;
	}//end level()

	/**
	 * The answer for a request filinq did not take.
	 *
	 * @param string $reason Why not.
	 *
	 * @return array{requested:bool,requestId:string,reason:string}
	 */
	private static function notRequested(string $reason): array {
		return ['requested' => false, 'requestId' => '', 'reason' => $reason];
	}//end notRequested()
}//end class
