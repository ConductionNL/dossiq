<?php

/**
 * Dossiq Filinq Redaction Client.
 *
 * The one place this app hands a Woo document to filinq's anonymisation
 * pipeline, and the reason it exists is that the hand-off used to be a
 * comment. `WOORedactionService::queueViaDocuDesk()` recorded
 * `status: 'queued'`, logged a line saying the document had been queued, and
 * made no call at all: the comment beside it read "let Docudesk poll", and
 * filinq has no polling ingestion to do so. Every document assessed as deels
 * openbaar on an instance WITH filinq installed came back queued and stayed
 * exactly as it was. The manual fallback, on an instance WITHOUT filinq, was
 * the only branch that ever produced a redaction, because it asks a person to
 * do it.
 *
 * Filinq owns anonymisation for the fleet, so this class carries no detection
 * or redaction logic. It resolves filinq's `AnonymizationService` through
 * {@see FleetAppId} — both the app id and the PHP namespace moved when
 * docudesk became filinq, and either stale half resolves to null without
 * erroring — runs filinq's own entity extraction over the file, and then asks
 * filinq to anonymise it.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: invent a status. A document filinq
 * accepted reports what filinq returned; a document it refused reports the
 * refusal; a document whose bytes this app cannot locate falls to manual
 * redaction with the reason attached. None of those three is `queued`.
 *
 * `redacted` is read off the EFFECT, not off the run. Filinq can detect
 * entities and still name no output file, and a status computed from the
 * entity count alone would call that a redaction — the same shape as the
 * `queued` it replaced. So the outcome is `redacted` only when filinq handed
 * back an anonymised file id.
 *
 * Each outcome also carries the detection backend that was actually in force,
 * read from OpenRegister rather than from filinq's status. See
 * {@see detectionBackend()} for why that distinction matters.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Support\FleetAppId;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Sends a Woo document to filinq's anonymisation pipeline.
 *
 * @psalm-suppress UnusedClass Injected into WOORedactionService.
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */
class FilinqRedactionClient {

	/**
	 * Filinq's anonymisation service, below its app namespace root.
	 *
	 * @var string
	 */
	private const ANONYMIZATION_SERVICE = 'Service\AnonymizationService';

	/**
	 * OpenRegister's anonymisation backend state service, fully qualified.
	 *
	 * OpenRegister is not renamed and is a hard dependency of this app, so this
	 * one is pinned rather than resolved through {@see FleetAppId}. The
	 * `Anonymisation` segment is load-bearing: filinq's own client omits it and
	 * has been silently falling back to a hardcoded answer ever since.
	 *
	 * @var string
	 */
	private const OR_BACKEND_STATE_SERVICE
		= 'OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves filinq's service across the rename.
	 * @param ZgwDocumentService $documents Resolves a document UUID to its Nextcloud file id.
	 * @param IUserSession $userSession The acting user, recorded on filinq's override audit.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ZgwDocumentService $documents,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Redact one document through filinq.
	 *
	 * @param string $caseId The case UUID, for the log line only.
	 * @param array<string, mixed> $document The document record; needs `fileId`, or `id`/`uuid`
	 *                                       together with `fileName`.
	 *
	 * @return array<string, mixed> `{documentId, caseId, status, ...}` describing what filinq did.
	 *
	 * @throws RuntimeException When filinq is absent, the file cannot be located, or filinq refuses.
	 *
	 * @psalm-suppress MixedMethodCall filinq is an optional cross-app dependency.
	 * @psalm-suppress MixedArrayAccess filinq is an optional cross-app dependency.
	 * @psalm-suppress MixedAssignment filinq is an optional cross-app dependency.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver over the
	 *      app-id and namespace rename map.
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	public function redact(string $caseId, array $document): array {
		$service = FleetAppId::getService($this->container, 'filinq', self::ANONYMIZATION_SERVICE);
		if ($service === null) {
			throw new RuntimeException('filinq_unavailable: AnonymizationService did not resolve');
		}

		$fileId = $this->resolveFileId(document: $document);
		$userId = $this->actingUserId();

		try {
			$extraction = (array)$service->extractAndDetectEntities($fileId);
			$entities = (array)($extraction['entities'] ?? []);
			$result = (array)$service->anonymizeDocument(
				$fileId,
				$entities,
				'pdf-only',
				[],
				[],
				$userId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Filinq refused a Woo redaction',
				[
					'app' => Application::APP_ID,
					'caseId' => $caseId,
					'fileId' => $fileId,
					'exception' => $e->getMessage(),
				]
			);
			throw new RuntimeException('filinq_redaction_failed: ' . $e->getMessage(), 0, $e);
		}

		$anonymisedFileId = ($result['anonymizedFileId'] ?? ($result['fileId'] ?? null));
		$backend = $this->detectionBackend();

		$this->logger->info(
			'Filinq processed a Woo document',
			[
				'app' => Application::APP_ID,
				'caseId' => $caseId,
				'fileId' => $fileId,
				'entityCount' => count($entities),
				'detectionBackend' => $backend,
			]
		);

		$status = 'redacted';
		if ($entities === []) {
			$status = 'no_entities_detected';
		} elseif ($anonymisedFileId === null) {
			// Filinq detected entities and then named no output file. Whatever
			// happened, this document's bytes are still the ones a person
			// assessed as deels openbaar, so the outcome is not `redacted`.
			$status = 'no_output_produced';
		}

		return [
			// A run that detected nothing produced a file whose content is the
			// original's. Calling that `redacted` is the same failure as the
			// `queued` this class replaced, one step further down: a Woo
			// officer would be told a document had been cleaned when every
			// name and BSN in it survived. A human assessed this document as
			// deels openbaar, so it HAS something to remove; zero detections
			// means the detection backend answered nothing, not that the
			// document is clean. It is named as its own outcome and the caller
			// fails closed on it.
			'status' => $status,
			'sourceFileId' => $fileId,
			'entityCount' => count($entities),
			'anonymizedFileId' => $anonymisedFileId,
			'detectionBackend' => $backend,
			'warning' => ($result['warning'] ?? null),
		];
	}//end redact()

	/**
	 * The detection backend that was actually in force for this run.
	 *
	 * Read from OpenRegister, which owns detection for the fleet, and NOT from
	 * filinq's own `AnonymiserBackendStateClient`. That client asks the
	 * container for `OCA\OpenRegister\Service\AnonymisationBackendService`,
	 * which is not a class: the service lives one namespace segment deeper, at
	 * `OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService`. The
	 * lookup therefore throws on every instance, the catch returns a hardcoded
	 * `method => regex`, and filinq's admin warning is on everywhere for a
	 * reason that has nothing to do with the configured backend. Nothing on
	 * filinq's run path consults that value at all, so a Woo redaction has
	 * never said which detector produced its entities.
	 *
	 * This is a report, never a gate. A backend that cannot be resolved is
	 * recorded as unknown rather than assumed, because the whole point is that
	 * an assumed value is what went wrong upstream.
	 *
	 * @return string|null The effective method, or null when OpenRegister
	 *                     cannot be asked.
	 *
	 * @psalm-suppress MixedMethodCall OpenRegister is resolved by class name.
	 * @psalm-suppress MixedAssignment OpenRegister is resolved by class name.
	 * @psalm-suppress MixedPropertyFetch OpenRegister is resolved by class name.
	 */
	private function detectionBackend(): ?string {
		try {
			$service = $this->container->get(self::OR_BACKEND_STATE_SERVICE);
			$state = $service->getState();
		} catch (Throwable $e) {
			$this->logger->debug(
				'The anonymisation backend state could not be read from OpenRegister',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$method = null;
		if (is_object($state) === true) {
			$method = ($state->effectiveMethod ?? null);
		}

		if (is_array($state) === true) {
			$method = ($state['effectiveMethod'] ?? null);
		}

		if (is_string($method) === false || $method === '') {
			return null;
		}

		return $method;
	}//end detectionBackend()

	/**
	 * The Nextcloud file id filinq must anonymise.
	 *
	 * @param array<string, mixed> $document The document record.
	 *
	 * @return int The file id.
	 *
	 * @throws RuntimeException When the record carries nothing that resolves to a file.
	 */
	private function resolveFileId(array $document): int {
		$explicit = ($document['fileId'] ?? null);
		if (is_int($explicit) === true || (is_string($explicit) === true && ctype_digit($explicit) === true)) {
			return (int)$explicit;
		}

		$uuid = (string)($document['id'] ?? ($document['uuid'] ?? ''));
		$fileName = (string)($document['fileName'] ?? '');
		if ($uuid === '' || $fileName === '') {
			throw new RuntimeException(
				'file_unresolved: the document carries no fileId, and no id/fileName pair to find one'
			);
		}

		try {
			return $this->documents->getFileId(uuid: $uuid, fileName: $fileName);
		} catch (Throwable $e) {
			throw new RuntimeException('file_unresolved: ' . $e->getMessage(), 0, $e);
		}
	}//end resolveFileId()

	/**
	 * The acting user's uid, for filinq's override audit trail.
	 *
	 * @return string The uid, or an empty string when nothing is logged in.
	 */
	private function actingUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end actingUserId()
}//end class
