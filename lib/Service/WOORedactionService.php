<?php

/**
 * Dossiq WOO Redaction Service
 *
 * Service for optional Docudesk-driven redaction of WOO documents assessed
 * as 'deels openbaar'. Performs feature detection to decide between the
 * Docudesk pipeline and the manual upload-redacted-version fallback.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for WOO document redaction with Docudesk feature detection.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-8
 */
class WOORedactionService {

	/**
	 * The canonical fleet name of the document app.
	 *
	 * Resolved through {@see FleetAppId} rather than pinned: filinq renamed
	 * from `docudesk` in August 2026, and this probe — which gates the whole Woo
	 * redaction pipeline — answered false on every instance running the renamed
	 * app, so redaction silently degraded to "manual redaction required".
	 */
	private const DOCUMENT_APP = 'filinq';

	/**
	 * Why a document filinq ran on still needs a person, per reported outcome.
	 *
	 * Keyed by the status {@see FilinqRedactionClient::redact()} reports. The
	 * two are different repairs: `no_entities_detected` is a detection backend
	 * that answered nothing on a document a person judged to hold something
	 * worth withholding; `no_output_produced` is filinq finding entities and
	 * naming no redacted file. Collapsing them into one sentence would send a
	 * Woo officer to look at the wrong half.
	 *
	 * @var array<string, string>
	 */
	private const MANUAL_REASONS = [
		'no_entities_detected' => 'filinq_detected_no_entities',
		'no_output_produced' => 'filinq_produced_no_redacted_file',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Nextcloud app manager for feature detection
	 * @param FilinqRedactionClient $filinq The hand-off to filinq's anonymisation pipeline
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly FilinqRedactionClient $filinq,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether Docudesk is installed and enabled.
	 *
	 * @return bool True if Docudesk is available
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-8
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver
	 * over the app-id RENAME MAP: it answers what an app is called on THIS
	 * instance, where the same app may still carry its old id. Injecting it
	 * would add a constructor dependency to say the same thing, and the
	 * lookup is duck-typed by design — an id nothing answers to must return
	 * null rather than fail, which is what makes a cross-app call optional.
	 */
	public function isDocuDeskInstalled(): bool {
		return FleetAppId::isEnabledForUser($this->appManager, self::DOCUMENT_APP);
	}//end isDocuDeskInstalled()

	/**
	 * Queue documents for redaction.
	 *
	 * If Docudesk is installed, sends the documents to its anonymization pipeline.
	 * Otherwise returns metadata indicating manual redaction is required.
	 *
	 * @param string $caseId The case UUID
	 * @param array<int, array<string, mixed>> $documents Documents assessed as 'deels_openbaar'
	 *
	 * @return array<string, mixed> Redaction result with mode and per-document status
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-8
	 */
	public function queueForRedaction(string $caseId, array $documents): array {
		if (empty($documents) === true) {
			return ['mode' => 'none', 'redacted' => [], 'queued' => [], 'manual' => []];
		}

		if ($this->isDocuDeskInstalled() === true) {
			return $this->redactViaFilinq(caseId: $caseId, documents: $documents);
		}

		return $this->manualRedactionFallback(caseId: $caseId, documents: $documents);
	}//end queueForRedaction()

	/**
	 * Redact documents through filinq's anonymisation pipeline.
	 *
	 * 🔴 THIS METHOD USED TO MAKE NO CALL. It looped the documents, recorded
	 * `status: 'queued'`, logged that each one had been queued via Docudesk,
	 * and returned. The comment where the call belonged read "Actual API call
	 * deferred to DocuDeskService ... For now we record the intent and let
	 * Docudesk poll", and filinq ships no ingestion that polls for such
	 * intents, so nothing on either side of the seam ever moved. An instance
	 * WITH filinq installed took this branch, reported every document queued,
	 * and redacted none of them; the manual branch below, taken only when
	 * filinq is absent, was the app's sole working redaction path.
	 *
	 * Each document is now handed to filinq one at a time and reports what
	 * filinq did with it. A document filinq cannot be given — no file id and
	 * no id/fileName pair to find one — falls to manual redaction carrying the
	 * reason, and a document filinq refuses reports the refusal. A run that
	 * detected no personal data at all also lands on the manual list: filinq
	 * produced a file, but its content is the original's, and a human already
	 * judged this document to hold something worth withholding. There is no
	 * longer any outcome called `queued`, because nothing queues.
	 *
	 * @param string $caseId The case UUID
	 * @param array<int, array<string, mixed>> $documents Documents to redact
	 *
	 * @return array<string, mixed> Per-document redaction outcomes
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	private function redactViaFilinq(string $caseId, array $documents): array {
		$redacted = [];
		$manual = [];

		foreach ($documents as $document) {
			$docId = $document['id'] ?? $document['uuid'] ?? null;
			if ($docId === null) {
				continue;
			}

			try {
				$outcome = $this->filinq->redact(caseId: $caseId, document: $document);
			} catch (Throwable $e) {
				// Filinq could not take this document. The reader needs to know
				// which one and why, and the document still needs redacting, so
				// it joins the manual list rather than reporting a success.
				$this->logger->warning(
					'WOO redaction fell back to manual for document ' . $docId . ' in case ' . $caseId,
					['app' => Application::APP_ID, 'error' => $e->getMessage()],
				);

				$manual[] = [
					'documentId' => $docId,
					'caseId' => $caseId,
					'status' => 'awaiting_manual_redaction',
					'reason' => $e->getMessage(),
					'instruction' => 'Upload a redacted version to replace this document.',
				];
				continue;
			}//end try

			$outcome['documentId'] = $docId;
			$outcome['caseId'] = $caseId;
			$outcome['mode'] = 'filinq';

			$reported = (string)($outcome['status'] ?? '');
			if ($reported !== 'redacted') {
				// Filinq ran and removed nothing, or removed something and
				// produced no file to show for it. Either way the document
				// still needs a person, so it belongs on the manual list
				// carrying what filinq reported, not on the redacted one
				// carrying an outcome word that would close the task. The
				// reason is the outcome filinq's run actually had, because
				// "no entities" and "no output" call for different repairs:
				// one is a detection backend that answered nothing, the other
				// is a redaction that produced no document.
				$outcome['status'] = 'awaiting_manual_redaction';
				$outcome['reason'] = (self::MANUAL_REASONS[$reported] ?? 'filinq_returned_' . $reported);
				$outcome['instruction'] = 'Upload a redacted version to replace this document.';
				$manual[] = $outcome;
				continue;
			}

			$redacted[] = $outcome;
		}//end foreach

		return [
			'mode' => 'filinq',
			'redacted' => $redacted,
			'queued' => [],
			'manual' => $manual,
		];
	}//end redactViaFilinq()

	/**
	 * Return manual redaction instructions when Docudesk is not installed.
	 *
	 * @param string $caseId The case UUID
	 * @param array<int, array<string, mixed>> $documents Documents needing manual redaction
	 *
	 * @return array<string, mixed> Manual redaction metadata
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-8
	 */
	private function manualRedactionFallback(string $caseId, array $documents): array {
		$manual = [];

		foreach ($documents as $document) {
			$docId = $document['id'] ?? $document['uuid'] ?? null;
			if ($docId === null) {
				continue;
			}

			$manual[] = [
				'documentId' => $docId,
				'caseId' => $caseId,
				'status' => 'awaiting_manual_redaction',
				'instruction' => 'Upload a redacted version to replace this document.',
			];
		}

		$this->logger->info(
			'WOO redaction fallback (manual) for ' . count($manual) . ' documents in case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return [
			'mode' => 'manual',
			'redacted' => [],
			'queued' => [],
			'manual' => $manual,
		];
	}//end manualRedactionFallback()
}//end class
