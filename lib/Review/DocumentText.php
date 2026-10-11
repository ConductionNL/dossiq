<?php

/**
 * Dossiq document text: the text OpenRegister extracted from a document's file.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a document's extracted text through OpenRegister's text extraction.
 *
 * Only text OpenRegister already extracted is read; nothing is extracted on
 * demand, so applying the triage rules never opens a file. A document
 * without a file, without extracted text, or on an OpenRegister without the
 * extraction service has no text, and a text condition never matches it.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
class DocumentText {

	/**
	 * OpenRegister's text extraction service.
	 */
	private const EXTRACTION = 'OCA\OpenRegister\Service\TextExtractionService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's extraction service by name.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The extracted text of the document's file, or null when it has none.
	 *
	 * @param array<string, mixed> $document The document record; its `fileId` names the file.
	 *
	 * @return string|null The text, null when there is no file, no extracted text or no extraction.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function of(array $document): ?string {
		$fileId = $document['fileId'] ?? null;
		if (is_numeric($fileId) === false || (int)$fileId <= 0) {
			return null;
		}

		$extraction = $this->extraction();
		if ($extraction === null) {
			return null;
		}

		try {
			$text = $extraction->getExtractedText((int)$fileId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the extracted text of a document could not be read, so no text condition matches it',
				['app' => Application::APP_ID, 'fileId' => (int)$fileId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_string($text) === false || trim($text) === '') {
			return null;
		}

		return $text;
	}//end of()

	/**
	 * OpenRegister's extraction service when it reads extracted text, else null.
	 *
	 * @return object|null
	 */
	private function extraction(): ?object {
		try {
			if ($this->container->has(self::EXTRACTION) === false) {
				return null;
			}

			$service = $this->container->get(self::EXTRACTION);
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: OpenRegister\'s text extraction is not available, so no text condition matches',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false || method_exists($service, 'getExtractedText') === false) {
			return null;
		}

		return $service;
	}//end extraction()
}//end class
