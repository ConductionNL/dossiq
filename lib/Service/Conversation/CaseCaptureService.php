<?php

/**
 * Dossiq case capture service.
 *
 * A voice note or a screen capture attached to a case or to a task. A
 * toezichthouder's constatering ter plaatse is a photo and a voice note, and
 * both land where every other case document lands, under the case type's own
 * visibility declaration.
 *
 * dossiq provides no recorder. Where the platform offers one, its output is
 * attached here; where it does not, the affordance is absent rather than
 * replaced by a dossiq recorder. That is why this service accepts a file and
 * never produces one.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Conversation
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Conversation;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Files a capture as a case document.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseCaptureService {

	use SearchesObjects;

	/**
	 * Refusal reason: the file offered is not a capture.
	 *
	 * @var string
	 */
	public const REASON_NOT_A_CAPTURE = 'not_a_capture';

	/**
	 * Refusal reason: the capture carries no name or no content.
	 *
	 * @var string
	 */
	public const REASON_EMPTY = 'capture_empty';

	/**
	 * The media a capture can be: what a recorder or a camera produces.
	 *
	 * @var array<string>
	 */
	private const CAPTURE_PREFIXES = ['audio/', 'video/', 'image/'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema configuration.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Whether a media type is something a recorder or a camera produced.
	 *
	 * @param string $mimeType The media type offered.
	 *
	 * @return bool True when it is a capture.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function isCapture(string $mimeType): bool {
		foreach (self::CAPTURE_PREFIXES as $prefix) {
			if (str_starts_with(strtolower($mimeType), $prefix) === true) {
				return true;
			}
		}

		return false;
	}//end isCapture()

	/**
	 * Attach a capture to a case, optionally carried by a task.
	 *
	 * @param string      $caseId   Case UUID.
	 * @param string      $fileName The file the recorder produced.
	 * @param string      $mimeType Its media type.
	 * @param string      $content  Its bytes, base64 encoded.
	 * @param string|null $taskId   The task it was captured for, when there is one.
	 * @param string|null $author   The user that captured it.
	 *
	 * @return array{ok: bool, reason?: string, document?: array<string, mixed>}
	 *         The case document, or a refusal naming its reason.
	 *
	 * @throws RuntimeException When OpenRegister is not configured for documents.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function attachCapture(
		string $caseId,
		string $fileName,
		string $mimeType,
		string $content,
		?string $taskId = null,
		?string $author = null,
	): array {
		$capture = new CaptureFiling(caseId: $caseId, fileName: $fileName);
		$capture->mimeType = $mimeType;
		$capture->content = $content;
		$capture->taskId = $taskId;
		$capture->author = $author;

		$refusal = $this->refusalFor(capture: $capture);
		if ($refusal !== null) {
			return $refusal;
		}

		$capture->store = $this->documentStore();
		$capture->now = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

		$document = $this->storeDocument(capture: $capture);

		return ['ok' => true, 'document' => $this->fileOnCase(capture: $capture, document: $document)];
	}//end attachCapture()

	/**
	 * The refusal for an empty file or one a recorder did not produce; null when it is a capture.
	 *
	 * @param CaptureFiling $capture The capture.
	 *
	 * @return array{ok: false, reason: string}|null The refusal, or null.
	 */
	private function refusalFor(CaptureFiling $capture): ?array {
		if (trim($capture->fileName) === '' || $capture->content === '') {
			return ['ok' => false, 'reason' => self::REASON_EMPTY];
		}

		if ($this->isCapture(mimeType: $capture->mimeType) === false) {
			return ['ok' => false, 'reason' => self::REASON_NOT_A_CAPTURE];
		}

		return null;
	}//end refusalFor()

	/**
	 * Store the capture as a document.
	 *
	 * @param CaptureFiling $capture The capture, with its store and moment.
	 *
	 * @return array<string, mixed> The stored document.
	 *
	 * @throws RuntimeException When the document could not be stored.
	 */
	private function storeDocument(CaptureFiling $capture): array {
		$document = $this->saveObjectAsArray(
			objectService: $capture->store['objectService'],
			register: $capture->store['register'],
			schema: $capture->store['documentSchema'],
			object: [
				'title' => $capture->fileName,
				'fileName' => $capture->fileName,
				'format' => $capture->mimeType,
				'content' => $capture->content,
				'creationDate' => $capture->now,
				'author' => ($capture->author ?? ''),
				'description' => $this->captureDescription(mimeType: $capture->mimeType, taskId: $capture->taskId),
			],
		);

		if ($document === null) {
			throw new RuntimeException('The capture could not be stored');
		}

		return $document;
	}//end storeDocument()

	/**
	 * File the stored document on the case.
	 *
	 * @param CaptureFiling        $capture  The capture, with its store and moment.
	 * @param array<string, mixed> $document The stored document.
	 *
	 * @return array<string, mixed> The case document.
	 *
	 * @throws RuntimeException When the case document could not be stored.
	 */
	private function fileOnCase(CaptureFiling $capture, array $document): array {
		$caseDocument = $this->saveObjectAsArray(
			objectService: $capture->store['objectService'],
			register: $capture->store['register'],
			schema: $capture->store['caseDocumentSchema'],
			object: [
				'case' => $capture->caseId,
				'document' => (string)($document['id'] ?? ($document['uuid'] ?? '')),
				'title' => $capture->fileName,
				'description' => $this->captureDescription(mimeType: $capture->mimeType, taskId: $capture->taskId),
				'registrationDate' => $capture->now,
			],
		);

		if ($caseDocument === null) {
			throw new RuntimeException('The capture could not be filed on the case');
		}

		return $caseDocument;
	}//end fileOnCase()

	/**
	 * The OpenRegister seam and the two schemas a capture is filed through.
	 *
	 * Held apart from `attachCapture()` because it is the configuration half,
	 * and mixing it in put that method one branch over the complexity the
	 * house allows.
	 *
	 * @return array{objectService: object, register: int|string, documentSchema: int|string, caseDocumentSchema: int|string}
	 *         The store and the schemas.
	 *
	 * @throws RuntimeException When OpenRegister is absent or not configured for documents.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function documentStore(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$documentSchema = $this->settingsService->getConfigValue(key: 'document_schema');
		$caseDocumentSchema = $this->settingsService->getConfigValue(key: 'case_document_schema');

		if (empty($register) === true || empty($documentSchema) === true || empty($caseDocumentSchema) === true) {
			throw new RuntimeException('Document schema not configured');
		}

		return [
			'objectService' => $objectService,
			'register' => $register,
			'documentSchema' => $documentSchema,
			'caseDocumentSchema' => $caseDocumentSchema,
		];
	}//end documentStore()

	/**
	 * What the capture is, said once so the case list can read it.
	 *
	 * @param string      $mimeType Its media type.
	 * @param string|null $taskId   The task it was captured for.
	 *
	 * @return string The description written onto the document.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function captureDescription(string $mimeType, ?string $taskId): string {
		$kind = 'Opname';
		if (str_starts_with(strtolower($mimeType), 'audio/') === true) {
			$kind = 'Spraaknotitie';
		}

		if (str_starts_with(strtolower($mimeType), 'image/') === true) {
			$kind = 'Foto';
		}

		if ($taskId === null || $taskId === '') {
			return $kind;
		}

		return $kind . ' bij taak ' . $taskId;
	}//end captureDescription()

}//end class
