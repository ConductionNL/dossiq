<?php

/**
 * What an inspector captured in the field, reaching the record.
 *
 * `EvidenceMetadataService` has validated and enriched field-evidence metadata
 * since mobiel-inspectie-offline shipped, and `TranscriptionService` has queued
 * voice memos for transcription just as long. NOTHING CALLED EITHER. There was
 * no endpoint a device could post a capture to, so a photo or a voice memo
 * taken at a site reached nobody, and the `fieldEvidence` admin page a sibling
 * change shipped reads a schema no writer has ever touched.
 *
 * 🔴 THE GUARD IS ON THE CASE THE INSPECTION BELONGS TO. A `fieldInspection`
 * names its `caseRef`, so the ordinary per-case rule applies here exactly as it
 * does on the checklist beside it. `#[NoAdminRequired]` with no per-object
 * guard is an IDOR, and this one would let anybody file evidence against
 * somebody else's inspection, which is the worst possible place for it: the
 * record is chain-of-evidence for an enforcement decision.
 *
 * 🔴 THE FALLBACK POSITION IS THE INSPECTION'S OWN, NOT THE CASE ADDRESS.
 * `EvidenceMetadataService::classifyGps()` names its fallback `caseAddress`,
 * and no case record carries coordinates: the address seam is exactly what
 * leaves `CaseAreaResolver` dark. A `fieldInspection` DOES carry `gpsLocation`,
 * recorded when the inspector arrived, and it is both nearer to the truth and
 * a record that exists. When the inspection has none either, the capture is
 * stored with no location at all rather than one somebody invented.
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
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\EvidenceMetadataService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TranscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Take one capture from a device and put it on the inspection.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
 */
class FieldEvidenceController extends Controller {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param string                  $appName       The app name.
	 * @param IRequest                $request       The request.
	 * @param EvidenceMetadataService $metadata      Validates the capture and builds the record.
	 * @param TranscriptionService    $transcription Queues a voice memo to be transcribed.
	 * @param SettingsService         $settings      Register and schema resolution.
	 * @param CaseAccessGuard         $accessGuard   Per-case authorization, failing closed.
	 * @param IUserSession            $userSession   The session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EvidenceMetadataService $metadata,
		private readonly TranscriptionService $transcription,
		private readonly SettingsService $settings,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * File one piece of evidence against an inspection.
	 *
	 * @param string $inspectionRef UUID of the fieldInspection.
	 *
	 * @return JSONResponse The stored record, or the refusal.
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
	 */
	#[NoAdminRequired]
	public function capture(string $inspectionRef): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		$evidenceSchema = $this->settings->getConfigValue('field_evidence_schema');
		$inspectionSchema = $this->settings->getConfigValue('field_inspection_schema');
		if ($objectService === null || $register === '' || $evidenceSchema === '' || $inspectionSchema === '') {
			// 503 and not 500: the instance has not finished importing the
			// offline register. That is an operator's job and a temporary
			// state, and telling a device it made a bad request would have it
			// discard a capture it cannot take again.
			return new JSONResponse(
				['error' => 'The field inspection register is not configured on this instance.'],
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}

		$inspection = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $inspectionSchema,
			id: $inspectionRef,
		);
		$caseId = trim((string)($inspection['caseRef'] ?? ''));

		// An inspection nobody can find and one on somebody else's case get the
		// SAME answer. Distinguishing them tells an outsider which inspection
		// ids exist.
		if ($inspection === null
			|| $caseId === ''
			|| $this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false
		) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->metadata->buildEvidencePayload(
				inspectionRef: $inspectionRef,
				type: $this->capturedType(),
				extra: $this->capturedExtra(),
				caseAddress: $this->fallbackPositionOf(inspection: $inspection),
				gpsReading: $this->capturedReading(),
			);
		} catch (InvalidArgumentException $e) {
			// The service's own sentence. A photo over the compression target
			// and a memo over five minutes are different things to fix, and one
			// message over both helps neither.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$stored = $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $evidenceSchema,
			object: $payload,
		);
		if ($stored === null) {
			return new JSONResponse(['error' => 'The evidence could not be stored.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->queueTranscriptionIfSpoken(stored: $stored));
	}//end capture()

	/**
	 * Queue a voice memo to be transcribed, and leave anything else alone.
	 *
	 * The duration travels on the stored record because `TranscriptionService`
	 * reads it there to refuse a memo over the limit, and it is declared on the
	 * schema for the same reason: an undeclared key is dropped in silence, and
	 * a limit read off a key that is never stored is a limit nothing enforces.
	 *
	 * @param array<string, mixed> $stored The stored evidence record.
	 *
	 * @return array<string, mixed> The record as the device should see it.
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
	 */
	private function queueTranscriptionIfSpoken(array $stored): array {
		if (($stored['type'] ?? '') !== 'voice_memo') {
			return $stored;
		}

		try {
			return $this->transcription->queue(evidence: $stored);
		} catch (InvalidArgumentException $e) {
			// THE CAPTURE IS ALREADY STORED, AND IT STAYS STORED. A memo too
			// long to transcribe automatically is still evidence somebody
			// recorded at a site, and losing it because a machine cannot read
			// it aloud is the wrong trade. It is answered with the reason, and
			// somebody types the transcription.
			$stored['transcriptionStatus'] = TranscriptionService::STATUS_FALLBACK;
			$stored['transcriptionNote'] = $e->getMessage();

			return $stored;
		}
	}//end queueTranscriptionIfSpoken()

	/**
	 * What kind of evidence this is.
	 *
	 * Not validated against the enum here: the schema declares it and
	 * OpenRegister refuses an undeclared value, so a second copy of the list
	 * would be one more place for the two to drift apart.
	 *
	 * @return string The declared type.
	 */
	private function capturedType(): string {
		return trim((string)$this->request->getParam('type', ''));
	}//end capturedType()

	/**
	 * Everything the device sent beside the type and the reading.
	 *
	 * @return array<string, mixed> The extra fields.
	 */
	private function capturedExtra(): array {
		$extra = [];
		foreach (['localBlobRef', 'capturedAt', 'sensitivityLevel', 'byteSize', 'durationSeconds'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$extra[$key] = $value;
			}
		}

		$tags = $this->request->getParam('tags');
		if (is_array($tags) === true) {
			$extra['tags'] = $tags;
		}

		return $extra;
	}//end capturedExtra()

	/**
	 * The sensor reading the device took, or null when it had no fix.
	 *
	 * @return array<string, mixed>|null The reading.
	 */
	private function capturedReading(): ?array {
		$reading = $this->request->getParam('gpsLocation');
		if (is_array($reading) === false) {
			return null;
		}

		return $reading;
	}//end capturedReading()

	/**
	 * Where to say the capture happened when the device had no fix.
	 *
	 * @param array<string, mixed> $inspection The inspection record.
	 *
	 * @return array<string, mixed>|null The inspection's own position, or null.
	 */
	private function fallbackPositionOf(array $inspection): ?array {
		$position = ($inspection['gpsLocation'] ?? null);
		if (is_array($position) === false || isset($position['lat']) === false) {
			return null;
		}

		return $position;
	}//end fallbackPositionOf()
}//end class
