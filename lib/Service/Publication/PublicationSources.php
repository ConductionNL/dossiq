<?php

/**
 * Dossiq publication sources.
 *
 * What a publication is made from: the case, its decision, and the documents
 * that may go out. The disclosure matrix lives here and only here: a
 * withheld document never goes out, a partly disclosed one only as its
 * finalized redaction, a disclosed one as itself.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Publication
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
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

use OCA\Dossiq\Kernel\Contracts\WooCaseDocumentsInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Loads the case, the decision and the disclosable documents of a publication.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
class PublicationSources {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService       $settingsService The case, decision, assessment and document schemas.
	 * @param WooCaseDocumentsInterface|null $caseDocuments   Loads a case document with its file content.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ?WooCaseDocumentsInterface $caseDocuments = null,
	) {
	}//end __construct()

	/**
	 * Load the case and decision objects for a publish/withdraw request.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The dossiq register slug.
	 * @param string $decisionSchema The dossiq decision schema slug.
	 * @param string $caseId The case UUID.
	 * @param string $decisionId The decision UUID.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} `[$case, $decision]`.
	 *
	 * @throws RuntimeException When either object cannot be loaded.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function caseAndDecision(object $objectService, string $register, string $decisionSchema, string $caseId, string $decisionId): array {
		$caseSchema = $this->settingsService->getConfigValue('case_schema');

		$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
		if ($case === null) {
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		return [$case, $this->decision(objectService: $objectService, register: $register, decisionSchema: $decisionSchema, decisionId: $decisionId)];
	}//end caseAndDecision()

	/**
	 * Load the decision of a publish or withdraw request.
	 *
	 * @param object $objectService  The OpenRegister ObjectService.
	 * @param string $register       The dossiq register slug.
	 * @param string $decisionSchema The dossiq decision schema slug.
	 * @param string $decisionId     The decision UUID.
	 *
	 * @return array<string, mixed> The decision.
	 *
	 * @throws RuntimeException When the decision cannot be loaded.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function decision(object $objectService, string $register, string $decisionSchema, string $decisionId): array {
		$decision = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $decisionSchema, id: $decisionId);
		if ($decision === null) {
			throw new RuntimeException('Decision not found: ' . $decisionId);
		}

		return $decision;
	}//end decision()

	/**
	 * Load and select the disclosable documents for a case's WOO assessments.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The dossiq register slug.
	 * @param string $caseId The case UUID.
	 *
	 * @return array{documents: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>, assessments: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function delivery(object $objectService, string $register, string $caseId): array {
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');
		$documentSchema = $this->settingsService->getConfigValue('document_schema');

		$assessments = [];
		if (empty($assessmentSchema) === false) {
			$assessments = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $assessmentSchema,
				filters: ['caseRef' => $caseId, '_limit' => 500],
			);
		}

		$documentLoader = function (string $documentRef) use ($objectService, $register, $documentSchema): ?array {
			// The informatieobject the case upload wrote, with its file read in.
			if ($this->caseDocuments !== null) {
				return $this->caseDocuments->load(documentId: $documentRef);
			}

			if (empty($documentSchema) === true || $documentRef === '') {
				return null;
			}

			return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $documentSchema, id: $documentRef);
		};

		return $this->collectDelivery(assessments: $assessments, documentLoader: $documentLoader) + ['assessments' => $assessments];
	}//end delivery()

	/**
	 * The disclosable documents and, beside each, what the delivered set records of it.
	 *
	 * The same matrix as selectDisclosableDocuments(), which reads its first
	 * half: `niet_openbaar` is never delivered, `deels_openbaar` only as its
	 * finalized redaction, `openbaar` as itself. The second half names the
	 * assessment, the file that went out and the original it came from
	 * (woo-delivered-set-is-a-record REQ-WDS-001); the original never goes
	 * into the publication.
	 *
	 * @param array<int, array<string, mixed>> $assessments    The case's document assessments.
	 * @param callable                         $documentLoader `fn(string $documentRef): ?array`.
	 *
	 * @return array{documents: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function collectDelivery(array $assessments, callable $documentLoader): array {
		$documents = [];
		$items = [];

		foreach ($assessments as $assessment) {
			$document = self::deliveredDocument(assessment: $assessment, documentLoader: $documentLoader);

			if ($document !== null) {
				$documents[] = $document;
				$items[] = self::itemOf(assessment: $assessment, document: $document);
			}
		}

		return ['documents' => $documents, 'items' => $items];
	}//end collectDelivery()

	/**
	 * The document that goes out for an assessment, or null when none may or the loader finds none.
	 *
	 * @param array<string, mixed> $assessment     The document assessment.
	 * @param callable             $documentLoader `fn(string $documentRef): ?array`.
	 *
	 * @return array<string, mixed>|null The delivered document.
	 */
	private static function deliveredDocument(array $assessment, callable $documentLoader): ?array {
		$deliveredRef = self::deliveredRefOf(assessment: $assessment);
		if ($deliveredRef === '') {
			return null;
		}

		return $documentLoader($deliveredRef);
	}//end deliveredDocument()

	/**
	 * The file that goes out for an assessment, or blank when none may.
	 *
	 * `openbaar` goes out as itself and `deels_openbaar` only as its finalized
	 * redaction, never as the original; anything else never goes out.
	 *
	 * @param array<string, mixed> $assessment The document assessment.
	 *
	 * @return string The delivered document's ref, or ''.
	 */
	private static function deliveredRefOf(array $assessment): string {
		$classification = (string)($assessment['classification'] ?? '');
		if ($classification === 'openbaar') {
			return (string)($assessment['documentRef'] ?? '');
		}

		if ($classification === 'deels_openbaar') {
			return (string)($assessment['redactedDocumentRef'] ?? '');
		}

		return '';
	}//end deliveredRefOf()

	/**
	 * What the delivered set records of one document that went out.
	 *
	 * @param array<string, mixed> $assessment The document assessment.
	 * @param array<string, mixed> $document   The delivered document.
	 *
	 * @return array<string, string> The manifest item.
	 */
	private static function itemOf(array $assessment, array $document): array {
		$self = ($assessment['@self'] ?? []);

		return [
			'assessment' => (string)($assessment['id'] ?? ($assessment['uuid'] ?? ($self['id'] ?? ''))),
			'classification' => (string)($assessment['classification'] ?? ''),
			'deliveredRef' => self::deliveredRefOf(assessment: $assessment),
			'originalRef' => (string)($assessment['documentRef'] ?? ''),
			'fileName' => (string)($document['fileName'] ?? ($document['title'] ?? '')),
			'content' => (string)($document['content'] ?? ''),
		];
	}//end itemOf()
}//end class
