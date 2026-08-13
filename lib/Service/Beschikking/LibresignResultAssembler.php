<?php

/**
 * Procest LibreSign result assembler.
 *
 * Turns LibreSign's raw API responses into the two contracts procest actually
 * publishes: the `sign()` result (which requires materialising the signed PDF
 * and persisting it through the EXISTING ZgwDocumentService binary storage
 * path) and the validatierapport. Split out of LibresignSigningAdapter so that
 * adapter keeps the LibreSign *conversation* — availability, signer identity,
 * request, poll loop — while reading LibreSign's status vocabulary and shaping
 * what procest hands back, plus the file plumbing behind it, live here.
 *
 * Status interpretation lives with result assembly on purpose: the internal
 * status value is itself part of the validatierapport, so a single owner keeps
 * the vocabulary the adapter branches on and the vocabulary the report
 * publishes from drifting apart.
 *
 * No new storage mechanism is introduced: the signed bytes are read from
 * Nextcloud by file id and written back through ZgwDocumentService, exactly as
 * before the split.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

use DateTimeImmutable;
use OCA\Procest\Service\ZgwDocumentService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use RuntimeException;
use Throwable;

/**
 * Builds procest's signed-result and validatierapport contracts.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */
class LibresignResultAssembler {
	/**
	 * The validatierapport `soort` discriminator.
	 *
	 * @var string
	 */
	private const REPORT_SOORT = 'libresign-handtekening-rapport';

	/**
	 * The norm the validatierapport claims conformance to.
	 *
	 * @var string
	 */
	private const REPORT_NORM = 'eIDAS / ETSI EN 319 102-1 (LibreSign)';

	/**
	 * Internal status: the request is still awaiting signature.
	 *
	 * @var string
	 */
	public const PENDING = LibresignStatusMapper::PENDING;

	/**
	 * Internal status: the document is signed.
	 *
	 * @var string
	 */
	public const SIGNED = LibresignStatusMapper::SIGNED;

	/**
	 * Internal status: the signer declined.
	 *
	 * @var string
	 */
	public const DECLINED = LibresignStatusMapper::DECLINED;

	/**
	 * Internal status: LibreSign reported a value outside the known vocabulary.
	 *
	 * @var string
	 */
	public const UNKNOWN = LibresignStatusMapper::UNKNOWN;

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Reads the LibreSign-produced signed file by id.
	 * @param ZgwDocumentService $documentService The EXISTING binary document storage service.
	 * @param LibresignStatusMapper $statusMapper Maps LibreSign status values onto the internal
	 *                                            vocabulary (stateless; defaults to a fresh instance).
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ZgwDocumentService $documentService,
		private readonly LibresignStatusMapper $statusMapper = new LibresignStatusMapper(),
	) {
	}//end __construct()

	/**
	 * Read the internal status value out of a raw LibreSign status payload.
	 *
	 * @param array<string, mixed> $status The raw LibreSign status payload.
	 *
	 * @return string One of self::PENDING / SIGNED / DECLINED / UNKNOWN.
	 *
	 * @spec openspec/specs/libresign-besluit-signing/spec.md
	 */
	public function mapStatus(array $status): string {
		$raw = (string)($status['statusText'] ?? ($status['status'] ?? ''));

		return $this->statusMapper->map($raw);
	}//end mapStatus()

	/**
	 * Download the LibreSign-produced signed PDF, persist it via the existing
	 * document storage service, and return the full sign() contract.
	 *
	 * @param string $fileId The original PDF file id.
	 * @param string $signatory The signer UID (owns the signed file in NC storage).
	 * @param string $uuid The LibreSign request uuid.
	 * @param array<string, mixed> $status The last polled LibreSign status payload.
	 *
	 * @return array<string, string>
	 *
	 * @throws RuntimeException 'libresign_signed_file_missing' when the signed file cannot be read.
	 *
	 * @spec openspec/specs/libresign-besluit-signing/spec.md
	 */
	public function assembleSignedResult(
		string $fileId,
		string $signatory,
		string $uuid,
		array $status,
	): array {
		$signedFileId = (int)($status['file']['signedFileId'] ?? 0);
		if ($signedFileId <= 0) {
			throw new RuntimeException('libresign_signed_file_missing');
		}

		$content = $this->readSignedFileContent(uid: $signatory, fileId: $signedFileId);
		$fileName = 'beschikking-' . $fileId . '-signed.pdf';

		// Persist through the EXISTING zaakdossier binary storage path — no new storage
		// mechanism. storeRaw() returns the byte count; getFileId() resolves the id it just
		// wrote, both against the same service.
		$this->documentService->storeRaw(uuid: $fileId, fileName: $fileName, content: $content);
		$newFileId = $this->documentService->getFileId(uuid: $fileId, fileName: $fileName);

		return [
			'signedBestandId' => (string)$newFileId,
			'validatieRapportId' => $uuid,
			'certificaatSerienummer' => (string)($status['certificateSerialNumber'] ?? ('libresign-' . $uuid)),
			'tspProviderEidasId' => 'LibreSign',
			'ondertekeningTijdstip' => (new DateTimeImmutable())->format('c'),
		];
	}//end assembleSignedResult()

	/**
	 * Build the validatierapport for a resolved LibreSign status.
	 *
	 * @param string $validationRapportId The LibreSign request uuid.
	 * @param array<string, mixed> $status The raw LibreSign status payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/libresign-besluit-signing/spec.md
	 */
	public function assembleValidationReport(
		string $validationRapportId,
		array $status,
	): array {
		$mappedStatus = $this->mapStatus(status: $status);

		return [
			'validatieRapportId' => $validationRapportId,
			'soort' => self::REPORT_SOORT,
			'norm' => self::REPORT_NORM,
			'geldig' => ($mappedStatus === self::SIGNED),
			'status' => $mappedStatus,
			'signers' => (array)($status['signers'] ?? []),
			'gegenereerdOp' => (new DateTimeImmutable())->format('c'),
		];
	}//end assembleValidationReport()

	/**
	 * Build the degraded, structured-but-invalid validatierapport used when the
	 * LibreSign transport fails.
	 *
	 * Deliberately answers rather than throws, matching MockSigningAdapter's
	 * always-answers shape: a caller must never read a transport failure as a
	 * valid signature.
	 *
	 * @param string $validationRapportId The LibreSign request uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/libresign-besluit-signing/spec.md
	 */
	public function assembleFailedValidationReport(string $validationRapportId): array {
		return [
			'validatieRapportId' => $validationRapportId,
			'soort' => self::REPORT_SOORT,
			'norm' => self::REPORT_NORM,
			'geldig' => false,
			'foutmelding' => 'libresign_api_error',
			'gegenereerdOp' => (new DateTimeImmutable())->format('c'),
		];
	}//end assembleFailedValidationReport()

	/**
	 * Read the LibreSign-produced signed file's bytes by Nextcloud file id.
	 *
	 * @param string $uid The Nextcloud user whose folder holds the signed file.
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return string
	 *
	 * @throws RuntimeException 'libresign_signed_file_missing'.
	 */
	private function readSignedFileContent(string $uid, int $fileId): string {
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$nodes = $userFolder->getById($fileId);
		} catch (Throwable $e) {
			throw new RuntimeException('libresign_signed_file_missing', 0, $e);
		}

		if (count($nodes) === 0) {
			throw new RuntimeException('libresign_signed_file_missing');
		}

		$node = $nodes[0];
		if (($node instanceof File) === false) {
			throw new RuntimeException('libresign_signed_file_missing');
		}

		return $node->getContent();
	}//end readSignedFileContent()
}//end class
