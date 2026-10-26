<?php

/**
 * Dossiq decision letter.
 *
 * Finds the letter a decision goes out with and links it to the decision.
 *
 * THE LETTER IS FOUND BY TYPE. A case type declares, under
 * `documentDrafts.<purpose>`, the document type its letter for that purpose is
 * filed under; the letter is the case's newest document of that type. Both
 * roads that file a generated document resolve the type name to its catalogue
 * id, but documents filed before that carry the name, so either matches.
 *
 * THE LINK IS THE ZGW JOIN. Recording a decision writes one
 * `besluitinformatieobject` row (decision, informatieobject, registrationDate),
 * the record a ZGW client already reads. A document is linked only when it is
 * a document of the decision's own case.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decision-document/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Service\Zaakdossier\DocumentTypeResolver;

/**
 * The letter of a decision: found on the case by type, linked at recording.
 *
 * @spec openspec/specs/decision-document/spec.md
 */
class DecisionLetter {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Bridge to OpenRegister + config.
	 * @param CaseDocumentDrafts $drafts          The case type's draft declarations.
	 * @param TranslatedText     $text            Reads a declared language map in the reader's language.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDocumentDrafts $drafts,
		private readonly TranslatedText $text = new TranslatedText(),
	) {
	}//end __construct()

	/**
	 * The declared letter for a purpose and the case's newest document of its type.
	 *
	 * @param string $caseId  The case uuid.
	 * @param string $purpose The declared purpose, for example `decision`.
	 *
	 * @return array{
	 *     declaration: array{
	 *         title: string, documentType: string, templateSlug: string, format: string, signing: string, delivery: string
	 *     }|null,
	 *     document: array<string, mixed>|null,
	 *     empty: bool
	 * }
	 *         `declaration` is null when the case type declares no letter for the purpose; `empty` is true
	 *         while the case holds no such letter, so a facts card can show its empty text (`emptyWhen`).
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function forCase(string $caseId, string $purpose): array {
		$declared = $this->drafts->declarationForCase(caseId: $caseId, purpose: $purpose);
		$typeName = trim((string)($declared['documentType'] ?? ''));
		if ($declared === [] || $typeName === '') {
			return ['declaration' => null, 'document' => null, 'empty' => true];
		}

		$types = array_unique([$typeName, (new DocumentTypeResolver(settingsService: $this->settingsService))->resolve(name: $typeName)]);
		$newest = null;
		foreach ($this->caseDocuments(caseId: $caseId) as $document) {
			if (in_array((string)($document['informatieobjecttype'] ?? ''), $types, true) === false) {
				continue;
			}

			if ($newest === null || $this->createdOf(document: $document) > $this->createdOf(document: $newest)) {
				$newest = $document;
			}
		}

		$document = null;
		if ($newest !== null) {
			$document = [
				'id' => (string)($newest['id'] ?? ''),
				'title' => (string)($newest['title'] ?? ($newest['fileName'] ?? '')),
				'fileName' => (string)($newest['fileName'] ?? ''),
				'creatiedatum' => (string)($newest['creatiedatum'] ?? ''),
				'status' => (string)($newest['status'] ?? ''),
			];
		}

		return [
			'declaration' => [
				'title' => trim((string)($declared['title'] ?? '')),
				'documentType' => $typeName,
				'templateSlug' => trim((string)($declared['templateSlug'] ?? '')),
				'format' => trim((string)($declared['format'] ?? '')),
				'signing' => $this->text->forReader(value: ($declared['signing'] ?? '')),
				'delivery' => $this->text->forReader(value: ($declared['delivery'] ?? '')),
			],
			'document' => $document,
			'empty' => $document === null,
		];
	}//end forCase()

	/**
	 * Refuse a letter that is not a document of the case.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $documentId The informatieobject uuid; empty means no letter was chosen.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the document is not on the case.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function assertOnCase(string $caseId, string $documentId): void {
		if ($documentId === '') {
			return;
		}

		$joins = $this->search(
			schemaKey: 'dossier_zaakinformatieobject_schema',
			filters: ['case' => $caseId, 'informatieobject' => $documentId, '_limit' => 1],
		);
		if ($joins === []) {
			throw new InvalidArgumentException('The letter is not a document of this case.');
		}
	}//end assertOnCase()

	/**
	 * Link a recorded decision to its letter: the chosen one, else the one drafted with it.
	 *
	 * The chosen letter was checked with {@see assertOnCase()} before the
	 * decision was written; a drafted one was filed on the case by the draft.
	 *
	 * @param array<string, mixed> $result The assembled decision (`decisionId`, `draft`).
	 * @param string               $letter The chosen informatieobject uuid, or empty.
	 *
	 * @return string The linked letter, or empty when there is none.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function linkDecision(array $result, string $letter): string {
		if ($letter === '') {
			$letter = (string)($result['draft']['informatieobject'] ?? '');
		}

		$decisionId = (string)($result['decisionId'] ?? '');
		if ($letter !== '' && $decisionId !== '') {
			$this->link(decisionId: $decisionId, documentId: $letter);
		}

		return $letter;
	}//end linkDecision()

	/**
	 * Link a letter to a recorded decision.
	 *
	 * @param string $decisionId The decision uuid.
	 * @param string $documentId The informatieobject uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function link(string $decisionId, string $documentId): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('dossier_besluitinformatieobject_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		$this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			object: [
				'decision' => $decisionId,
				'informatieobject' => $documentId,
				'registrationDate' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			],
		);
	}//end link()

	/**
	 * The documents on a case, through its zaakinformatieobject joins.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function caseDocuments(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($objectService === null || $register === '' || $infoSchema === '') {
			return [];
		}

		$documents = [];
		foreach ($this->search(schemaKey: 'dossier_zaakinformatieobject_schema', filters: ['case' => $caseId, '_limit' => 500]) as $join) {
			$infoId = (string)($join['informatieobject'] ?? '');
			if ($infoId === '') {
				continue;
			}

			$document = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $infoSchema, id: $infoId);
			if ($document !== null) {
				$document['id'] = (string)($document['id'] ?? $infoId);
				$documents[] = $document;
			}
		}

		return $documents;
	}//end caseDocuments()

	/**
	 * Search one configured schema of the register.
	 *
	 * @param string               $schemaKey The app setting naming the schema.
	 * @param array<string, mixed> $filters   The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function search(string $schemaKey, array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
	}//end search()

	/**
	 * When a document was made: its creation date, else when it was stored.
	 *
	 * @param array<string, mixed> $document The informatieobject.
	 *
	 * @return string A sortable date.
	 */
	private function createdOf(array $document): string {
		$created = (string)($document['creatiedatum'] ?? '');
		$stored = (string)($document['@self']['created'] ?? '');
		return $created . '|' . $stored;
	}//end createdOf()
}//end class
