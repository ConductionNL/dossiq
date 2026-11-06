<?php

/**
 * Dossiq Filinq Redaction Client.
 *
 * The one place this app hands a document to filinq's anonymisation
 * pipeline. Filinq owns detection and redaction for the fleet, so this class
 * carries no detection or redaction logic of its own. It resolves filinq's
 * services through {@see FleetAppId}, because both the app id and the PHP
 * namespace moved when docudesk became filinq.
 *
 * The hand-off is three calls, in the order filinq's own gate requires:
 * detect, mark the run checked by a named person, then anonymise. Filinq
 * refuses to write a redacted copy for a detection run nobody checked
 * (`RedactionOutputGuard::assertMayWrite`), and the run is named by the exact
 * entity set, so the set that is marked is the set that is anonymised.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: invent a status. `redacted` is read off
 * the EFFECT: filinq handed back an anonymised file id for a run that removed
 * something. A refusal for want of a live detector is the outcome
 * `detection_unavailable` (dossiq#3191) and a refusal for want of a check is
 * `not_checked`; neither is an exception, because each has its own repair.
 *
 * Each outcome also carries the detection backend that was actually in force:
 * the one filinq's own detection report names when it carries one, else read
 * from OpenRegister. See {@see detectionBackend()}.
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
 * @spec openspec/specs/document-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Hands one document to filinq's anonymisation pipeline.
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
	 * Filinq's review gate, below its app namespace root.
	 *
	 * @var string
	 */
	private const OUTPUT_GUARD = 'Service\Redaction\RedactionOutputGuard';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves filinq's services across the rename.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run filinq's entity detection over one file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array<int, array<string, mixed>> The entities filinq detected, each `{type, value, ...}`.
	 *
	 * @throws RuntimeException When filinq is absent or the detection fails.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function detect(int $fileId): array {
		$service = $this->service(relative: self::ANONYMIZATION_SERVICE);

		try {
			$extraction = (array)$service->extractAndDetectEntities($fileId);
		} catch (Throwable $e) {
			throw new RuntimeException('filinq_detection_failed: ' . $e->getMessage(), 0, $e);
		}

		return array_values(array_filter((array)($extraction['entities'] ?? []), 'is_array'));
	}//end detect()

	/**
	 * Record in filinq that a named person checked this entity set for this file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 * @param array<int, array<string, mixed>> $entities The entity set the person checked.
	 * @param string $checkedBy The uid of the person who checked it.
	 *
	 * @return array<string, mixed> Filinq's mark.
	 *
	 * @throws RuntimeException When filinq is absent, nobody is named, or the mark is not written.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
	 */
	public function markChecked(int $fileId, array $entities, string $checkedBy): array {
		if (trim($checkedBy) === '') {
			throw new RuntimeException('checked_by_missing: a check names the person who made it');
		}

		$guard = $this->service(relative: self::OUTPUT_GUARD);

		try {
			return (array)$guard->markChecked($fileId, $entities, $checkedBy);
		} catch (Throwable $e) {
			throw new RuntimeException('filinq_mark_failed: ' . $e->getMessage(), 0, $e);
		}
	}//end markChecked()

	/**
	 * Ask filinq to write the redacted copy of one file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 * @param array<int, array<string, mixed>> $entities The checked entity set.
	 * @param string $userId The acting user, recorded on filinq's audit.
	 *
	 * @return array<string, mixed> `{status, sourceFileId, entityCount, anonymizedFileId, detectionBackend, ...}`.
	 *
	 * @throws RuntimeException When filinq is absent or fails for a reason that is not an outcome.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
	 */
	public function anonymize(int $fileId, array $entities, string $userId): array {
		$service = $this->service(relative: self::ANONYMIZATION_SERVICE);

		try {
			$result = (array)$service->anonymizeDocument(
				$fileId,
				$entities,
				'pdf-only',
				[],
				[],
				$userId
			);
		} catch (Throwable $e) {
			$refusal = ($this->detectorRefusal(error: $e) ?? $this->reviewRefusal(error: $e));
			if ($refusal !== null) {
				$this->logger->warning(
					'Filinq refused a redaction',
					['app' => Application::APP_ID, 'fileId' => $fileId, 'status' => $refusal['status']]
				);
				return $refusal + ['sourceFileId' => $fileId, 'entityCount' => count($entities)];
			}

			$this->logger->warning(
				'Filinq failed a redaction',
				['app' => Application::APP_ID, 'fileId' => $fileId, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('filinq_redaction_failed: ' . $e->getMessage(), 0, $e);
		}//end try

		$anonymisedFileId = ($result['anonymizedFileId'] ?? ($result['fileId'] ?? null));
		$detection = $this->detectionReport(result: $result);
		$backend = ($detection['backend'] ?? $this->detectionBackend());

		return [
			'status' => $this->statusOf(entities: $entities, anonymisedFileId: $anonymisedFileId, detection: $detection),
			'sourceFileId' => $fileId,
			'entityCount' => count($entities),
			'anonymizedFileId' => $anonymisedFileId,
			'detectionBackend' => $backend,
			'detectionOutcome' => ($detection['outcome'] ?? null),
			'warning' => ($result['warning'] ?? null),
		];
	}//end anonymize()

	/**
	 * One of filinq's services, or a refusal naming that filinq is absent.
	 *
	 * @param string $relative The class below filinq's namespace root.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When filinq is not installed.
	 */
	private function service(string $relative): object {
		$service = FleetAppId::getService($this->container, 'filinq', $relative);
		if ($service === null) {
			throw new RuntimeException('filinq_unavailable: ' . $relative . ' did not resolve');
		}

		return $service;
	}//end service()

	/**
	 * The outcome word for a run filinq completed.
	 *
	 * @param array<mixed>              $entities         What extraction detected.
	 * @param mixed                     $anonymisedFileId The file filinq named, or null.
	 * @param array<string, mixed>|null $detection        Filinq's own detection report, or null.
	 *
	 * @return string `redacted`, `no_entities_detected` or `no_output_produced`.
	 */
	private function statusOf(array $entities, mixed $anonymisedFileId, ?array $detection): string {
		// Filinq's own `nothing_found` counts as much as an empty entity list:
		// either way the file's content is the original's.
		if ($entities === [] || ($detection['outcome'] ?? null) === 'nothing_found') {
			return 'no_entities_detected';
		}

		if ($anonymisedFileId === null) {
			// Filinq detected entities and then named no output file. Whatever
			// happened, this document's bytes are still the ones a person
			// assessed as deels openbaar, so the outcome is not `redacted`.
			return 'no_output_produced';
		}

		return 'redacted';
	}//end statusOf()

	/**
	 * Filinq's report of the detector that looked, when its result carries one.
	 *
	 * Filinq (anonymisation-fails-closed-without-a-detector) adds `detection`
	 * with `backend`, `entitiesRedacted` and `outcome` to every completed run.
	 * An older filinq carries none, and the caller falls back to OpenRegister.
	 *
	 * @param array<string, mixed> $result Filinq's anonymisation result.
	 *
	 * @return array{backend?: string, outcome?: string}|null
	 */
	private function detectionReport(array $result): ?array {
		$detection = ($result['detection'] ?? null);
		if (is_array($detection) === false) {
			return null;
		}

		$report = [];
		foreach (['backend', 'outcome'] as $key) {
			if (is_string(($detection[$key] ?? null)) === true && $detection[$key] !== '') {
				$report[$key] = $detection[$key];
			}
		}

		return $report;
	}//end detectionReport()

	/**
	 * The outcome for filinq's detector refusal, or null for any other error.
	 *
	 * Recognised by name, not by `instanceof`: filinq is optional and its
	 * namespace moves with its app id, so this class never names it. The
	 * refusal carries `getReason()` (`detection_disabled`,
	 * `detection_backend_unavailable`, `detection_state_unknown`) and
	 * `getBackend()` ('' when unknown).
	 *
	 * @param Throwable $error What filinq threw.
	 *
	 * @return array<string, mixed>|null
	 */
	private function detectorRefusal(Throwable $error): ?array {
		$class = get_class($error);
		$short = substr($class, (int)strrpos($class, '\\') + 1);
		if ($short !== 'DetectionUnavailableException'
			|| method_exists($error, 'getReason') === false
			|| method_exists($error, 'getBackend') === false
		) {
			return null;
		}

		$backend = (string)$error->getBackend();
		if ($backend === '') {
			$backend = null;
		}

		return [
			'status' => 'detection_unavailable',
			'detectionUnavailable' => (string)$error->getReason(),
			'detectionBackend' => $backend,
			'anonymizedFileId' => null,
			'warning' => null,
		];
	}//end detectorRefusal()

	/**
	 * The outcome for filinq's review refusal, or null for any other error.
	 *
	 * Recognised by name for the same reason as {@see detectorRefusal()}. The
	 * refusal carries `getReason()` (`never_checked`, `checked_an_older_run`,
	 * `unattributed`) and filinq's own sentence as the message.
	 *
	 * @param Throwable $error What filinq threw.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reviewRefusal(Throwable $error): ?array {
		$class = get_class($error);
		$short = substr($class, (int)strrpos($class, '\\') + 1);
		if ($short !== 'RedactionNotReviewedException' || method_exists($error, 'getReason') === false) {
			return null;
		}

		return [
			'status' => 'not_checked',
			'notChecked' => (string)$error->getReason(),
			'message' => $error->getMessage(),
			'anonymizedFileId' => null,
			'warning' => null,
		];
	}//end reviewRefusal()

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
}//end class
