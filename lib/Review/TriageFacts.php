<?php

/**
 * Dossiq triage facts: what a triage rule reads about one document, without opening it.
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

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Woo\WooCaseDocuments;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gathers the facts TriageConditions matches for one document on one case.
 *
 * `custodian` and `sourceSystem` come from the document's provenance on the
 * case, `senderDomain` and the mail date from `provenance.mail`, the mime
 * type and creation date from its record. The document's date is the mail
 * date when the headers were read, else its creation date. The text is read
 * separately, and only when a rule asks for it.
 *
 * @phpstan-type FactRow array{
 *     custodian: string,
 *     sourceSystem: string,
 *     mimeType: string,
 *     date: string,
 *     senderDomain: string,
 *     text: ?string,
 *     record: array<string, mixed>
 * }
 * @psalm-type FactRow = array{
 *     custodian: string,
 *     sourceSystem: string,
 *     mimeType: string,
 *     date: string,
 *     senderDomain: string,
 *     text: ?string,
 *     record: array<string, mixed>
 * }
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
class TriageFacts {

	/**
	 * Constructor.
	 *
	 * @param WooCaseDocuments       $caseDocuments The document records.
	 * @param CaseDocumentProvenance $provenance    Custodian, source system and mail headers per document.
	 * @param DocumentText           $text          The extracted text per document.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly WooCaseDocuments $caseDocuments,
		private readonly CaseDocumentProvenance $provenance,
		private readonly DocumentText $text,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The facts of one document on one case, its text not read yet.
	 *
	 * @param string $caseId     The case UUID.
	 * @param string $documentId The document.
	 *
	 * @return FactRow
	 *
	 * @throws RefusedException When the provenance cannot be read.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function forDocument(string $caseId, string $documentId): array {
		$record = ($this->caseDocuments->meta(documentId: $documentId) ?? []);
		try {
			$provenance = $this->provenance->forDocument(caseId: $caseId, documentId: $documentId, record: $record);
			$mail = $this->provenance->mailOf(caseId: $caseId, documentId: $documentId, record: $record);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the provenance of a document could not be read, so the triage rules were not applied',
				['app' => Application::APP_ID, 'case' => $caseId, 'document' => $documentId, 'exception' => $e->getMessage()]
			);
			throw new RefusedException(
				rule: 'triage-rule-unavailable',
				sentence: 'The triage rules cannot be stored or applied, so nothing was marked.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		$date = $mail['date'];
		if ($date === '') {
			$date = (string)($record['creatiedatum'] ?? '');
		}

		return [
			'custodian' => $provenance['custodian'],
			'sourceSystem' => $provenance['sourceSystem'],
			'mimeType' => trim((string)($record['mimeType'] ?? ($record['format'] ?? ''))),
			'date' => $this->day(value: $date),
			'senderDomain' => $mail['senderDomain'],
			'text' => null,
			'record' => $record,
		];
	}//end forDocument()

	/**
	 * The facts with the document's extracted text read in; null when it has none.
	 *
	 * @param FactRow $facts The facts, as forDocument() answered them.
	 *
	 * @return FactRow
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function withText(array $facts): array {
		$facts['text'] = $this->text->extracted(document: $facts['record']);

		return $facts;
	}//end withText()

	/**
	 * A date or date-time as YYYY-MM-DD, '' when it is no date.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private function day(string $value): string {
		if (trim($value) === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (Throwable) {
			return '';
		}
	}//end day()
}//end class
