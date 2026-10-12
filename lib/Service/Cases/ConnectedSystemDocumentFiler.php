<?php

/**
 * Dossiq Connected System Document Filer.
 *
 * Files a document a connected system sent on a case that exists, through the
 * same screened upload path a handler's upload takes (decision 206). The
 * document type comes from the connection's mapping, never from the message.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-document-from-a-connected-system-takes-the-upload-path
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Files a connected system's document on a case.
 *
 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-document-from-a-connected-system-takes-the-upload-path
 */
class ConnectedSystemDocumentFiler {

	/**
	 * Constructor.
	 *
	 * @param DossierUploadHandler $uploads The screened document upload path.
	 * @param LoggerInterface      $logger  The logger.
	 */
	public function __construct(
		private readonly DossierUploadHandler $uploads,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * File the document, or say why not.
	 *
	 * @param array<string, mixed>  $operation     The generic add-document operation.
	 * @param array<string, string> $documentTypes The system's document type name to a dossiq document type id.
	 * @param array<string, mixed>  $case          The case the document belongs to.
	 * @param string                $system        The connection id, for the log.
	 *
	 * @return array{identifier?: string, refusal?: string, kind?: string} The outcome.
	 *
	 * @throws RuntimeException When no temporary file can be written.
	 *
	 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-document-from-a-connected-system-takes-the-upload-path
	 */
	public function file(array $operation, array $documentTypes, array $case, string $system): array {
		$content  = ($operation['content'] ?? '');
		$fileName = basename(self::text(value: ($operation['fileName'] ?? '')));
		if (is_string($content) === false || $content === '' || $fileName === '') {
			return self::refused(reason: 'Een document heeft een bestandsnaam en een inhoud nodig.');
		}

		$typeName = self::text(value: ($operation['documentTypeName'] ?? ''));
		$type     = self::text(value: ($documentTypes[$typeName] ?? ''));
		if ($typeName === '' || $type === '') {
			return self::refused(reason: 'Het documenttype is niet gekoppeld aan een documenttype in dossiq.');
		}

		$metadata = array_filter(
			[
				'title' => self::text(value: ($operation['title'] ?? $fileName)),
				'informatieobjecttype' => $type,
				'vertrouwelijkheidaanduiding' => self::text(value: ($operation['confidentiality'] ?? '')),
				'auteur' => self::text(value: ($operation['author'] ?? '')),
				'description' => self::text(value: ($operation['description'] ?? '')),
				'creatiedatum' => self::text(value: ($operation['creationDate'] ?? '')),
				'taal' => self::text(value: ($operation['language'] ?? '')),
				'direction' => 'incoming',
			],
			static fn (string $value): bool => $value !== ''
		);

		$result = $this->upload(
			caseId: self::text(value: ($case['@self']['id'] ?? ($case['id'] ?? ''))),
			fileName: $fileName,
			content: $content,
			format: self::text(value: ($operation['format'] ?? '')),
			metadata: $metadata
		);
		if (($result['success'] ?? false) !== true) {
			$this->logger->warning(
				'ConnectedSystemCaseWriter: a document from a connected system was not filed',
				['system' => $system, 'error' => (string)($result['error'] ?? '')]
			);
			return self::refused(reason: 'Het document is niet opgenomen in het dossier.');
		}

		return ['identifier' => self::text(value: ($result['informatieobject']['id'] ?? ''))];
	}//end file()

	/**
	 * Hand the bytes to the upload path as the file it expects.
	 *
	 * @param string               $caseId   The case uuid.
	 * @param string               $fileName The file name.
	 * @param string               $content  The bytes.
	 * @param string               $format   The media type, '' when the system named none.
	 * @param array<string, mixed> $metadata The document metadata.
	 *
	 * @return array<string, mixed> The upload path's result entry.
	 *
	 * @throws RuntimeException When no temporary file can be written.
	 */
	private function upload(string $caseId, string $fileName, string $content, string $format, array $metadata): array {
		$path = tempnam(sys_get_temp_dir(), 'dossiq-connected-');
		if ($path === false || file_put_contents($path, $content) === false) {
			throw new RuntimeException('No temporary file for the document.');
		}

		try {
			return $this->uploads->uploadOne(caseId: $caseId, file: ['name' => $fileName, 'tmp_name' => $path, 'type' => $format], metadata: $metadata);
		} finally {
			unlink($path);
		}
	}//end upload()

	/**
	 * A refusal outcome.
	 *
	 * @param string $reason The reason, safe to return to the system.
	 *
	 * @return array{refusal: string, kind: string} The outcome.
	 */
	private static function refused(string $reason): array {
		return ['refusal' => $reason, 'kind' => ConnectedSystemCaseWriter::REFUSED];
	}//end refused()

	/**
	 * A scalar as trimmed text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text, '' for anything that is not a scalar.
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
