<?php

/**
 * Dossiq case document drafts
 *
 * Drafts a document from the template a case type names for a purpose
 * (case-document-drafts, REQ-CDD-001 to REQ-CDD-003). The case type declares,
 * under `documentDrafts.<purpose>`, the filinq template by slug and namespace,
 * the format, the document type it is filed under, and the data it renders
 * (CaseDraftData). This class asks filinq through its published
 * DocumentGenerationRequestedEvent, files the document filinq stored on the
 * case, and answers what happened. No filinq is a named answer
 * (`filinq-missing`), never a success; filinq's own refusal is the reason.
 *
 * Which procedure drafts what is configuration on its case type, never code here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Drafts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-a-case-type-names-the-template-it-drafts-a-document-from-req-cdd-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Drafts;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Zaakdossier\DocumentTypeResolver;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;
use OCA\Filinq\Event\DocumentGenerationRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Throwable;

/**
 * Drafts a case type's declared documents through filinq.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-a-case-type-names-the-template-it-drafts-a-document-from-req-cdd-001
 */
class CaseDocumentDrafts {

	use SearchesObjects;

	/**
	 * Filinq's request event, named by string: filinq is optional at runtime.
	 */
	public const REQUEST_EVENT = 'OCA\Filinq\Event\DocumentGenerationRequestedEvent';

	/**
	 * The template namespace when the declaration names none.
	 */
	public const DEFAULT_NAMESPACE = 'dossiq';

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService The object service, register and schema keys.
	 * @param CaseTypeStore          $caseTypes       The case type reference on a case.
	 * @param CaseTypeResolver       $resolver        The effective case type.
	 * @param CaseDraftData          $draftData       Builds the declared data.
	 * @param IEventDispatcher       $events          Asks filinq.
	 * @param GeneratedDocumentFiler $filer           Files what filinq stored on the case.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $caseTypes,
		private readonly CaseTypeResolver $resolver,
		private readonly CaseDraftData $draftData,
		private readonly IEventDispatcher $events,
		private readonly GeneratedDocumentFiler $filer,
	) {
	}//end __construct()

	/**
	 * Draft the case type's document for a purpose and file it on the case.
	 *
	 * @param string $caseId  The case uuid.
	 * @param string $purpose The declared purpose, for example `decision`.
	 *
	 * @return array{status: string, reason: string, informatieobject: string, fileId: int}
	 *         `status` is `drafted`, `not-declared`, `filinq-missing`, `refused` (the data cannot be
	 *         built; `reason` says why) or `failed` (filinq refused or nothing was filed).
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-is-asked-of-filinq-and-filed-on-the-case-req-cdd-003
	 */
	public function draft(string $caseId, string $purpose): array {
		$case = $this->caseRow(caseId: $caseId);
		$declaration = $this->declarationFor(case: $case, purpose: $purpose);
		if ($declaration === []) {
			return $this->answer(status: 'not-declared', reason: $purpose);
		}

		if (class_exists(self::REQUEST_EVENT) === false) {
			return $this->answer(status: 'filinq-missing', reason: 'filinq-not-installed');
		}

		try {
			$built = $this->draftData->build(
				declaration: $declaration,
				case: $case,
				related: fn (string $schemaKey, string $caseField): array => $this->related(schemaKey: $schemaKey, caseField: $caseField, caseId: $caseId)
			);
		} catch (InvalidArgumentException $e) {
			return $this->answer(status: 'refused', reason: $e->getMessage());
		}

		$event = $this->requestEvent(caseId: $caseId, case: $case, declaration: $declaration, built: $built);
		$this->events->dispatchTyped($event);

		return $this->outcome(caseId: $caseId, event: $event, declaration: $declaration);
	}//end draft()

	/**
	 * The declaration for one purpose on the case's case type, or [] when it declares none.
	 *
	 * @param array<string, mixed> $case    The case row.
	 * @param string               $purpose The purpose.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-a-case-type-names-the-template-it-drafts-a-document-from-req-cdd-001
	 */
	public function declarationFor(array $case, string $purpose): array {
		$reference = $this->caseTypes->referenceId(value: ($case['caseType'] ?? ''));
		if ($reference === '' || $purpose === '') {
			return [];
		}

		$declared = ((array)($this->resolver->effectiveCaseType(caseTypeId: $reference)['documentDrafts'] ?? []))[$purpose] ?? null;
		if (is_array($declared) === false || trim((string)($declared['templateSlug'] ?? '')) === '') {
			return [];
		}

		return $declared;
	}//end declarationFor()

	/**
	 * Every document type a case's case type drafts itself, under any purpose.
	 *
	 * Each type is named as declared and as its catalogue id, because a
	 * drafted document is filed under the id and older ones carry the name.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return list<string> The type names and ids, or [] when the case type drafts nothing.
	 */
	public function documentTypesForCase(string $caseId): array {
		$case = $this->caseRow(caseId: $caseId);
		$reference = $this->caseTypes->referenceId(value: ($case['caseType'] ?? ''));
		if ($reference === '') {
			return [];
		}

		$resolver = new DocumentTypeResolver(settingsService: $this->settingsService);
		$types = [];
		foreach ((array)($this->resolver->effectiveCaseType(caseTypeId: $reference)['documentDrafts'] ?? []) as $declared) {
			if (is_array($declared) === false) {
				continue;
			}

			$name = trim((string)($declared['documentType'] ?? ''));
			if ($name !== '') {
				$types[] = $name;
				$types[] = $resolver->resolve(name: $name);
			}
		}

		return array_values(array_unique(array_filter($types)));
	}//end documentTypesForCase()

	/**
	 * The declaration for one purpose on a case's case type, read by case id.
	 *
	 * @param string $caseId  The case uuid.
	 * @param string $purpose The purpose.
	 *
	 * @return array<string, mixed> The declaration, or [] when the case type declares none.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-a-case-type-names-the-template-it-drafts-a-document-from-req-cdd-001
	 */
	public function declarationForCase(string $caseId, string $purpose): array {
		return $this->declarationFor(case: $this->caseRow(caseId: $caseId), purpose: $purpose);
	}//end declarationForCase()

	/**
	 * The request filinq's published contract takes.
	 *
	 * @param string               $caseId      The case uuid.
	 * @param array<string, mixed> $case        The case row.
	 * @param array<string, mixed> $declaration The draft declaration.
	 * @param array<string, mixed> $built       The declared data.
	 *
	 * @return DocumentGenerationRequestedEvent The request event.
	 */
	private function requestEvent(string $caseId, array $case, array $declaration, array $built): DocumentGenerationRequestedEvent {
		$dataKey = trim((string)($declaration['dataKey'] ?? ''));
		$data = ['case' => $case];
		if ($dataKey === '') {
			$data = array_merge($data, $built);
		}

		if ($dataKey !== '') {
			$data[$dataKey] = $built;
		}

		$title = trim((string)($declaration['title'] ?? ''));
		if ($title === '') {
			$title = (string)$declaration['templateSlug'];
		}

		return new DocumentGenerationRequestedEvent(
			request: [
				'templateSlug' => (string)$declaration['templateSlug'],
				'templateNamespace' => trim((string)($declaration['templateNamespace'] ?? self::DEFAULT_NAMESPACE)),
				'format' => (string)($declaration['format'] ?? 'pdf'),
				'filename' => $title,
				'storeFile' => true,
				'data' => $data,
				'object' => [
					'register' => $this->settingsService->getConfigValue('register'),
					'schema' => $this->settingsService->getConfigValue('case_schema'),
					'id' => $caseId,
				],
				'metadata' => $this->metadata(declaration: $declaration),
			],
			requestingApp: Application::APP_ID
		);
	}//end requestEvent()

	/**
	 * What filinq answered, with the document filed on the case.
	 *
	 * @param string               $caseId      The case uuid.
	 * @param DocumentGenerationRequestedEvent $event The dispatched event.
	 * @param array<string, mixed> $declaration The draft declaration.
	 *
	 * @return array{status: string, reason: string, informatieobject: string, fileId: int}
	 */
	private function outcome(string $caseId, DocumentGenerationRequestedEvent $event, array $declaration): array {
		$error = (string)($event->getError() ?? '');
		if ($error !== '') {
			return $this->answer(status: 'failed', reason: $error);
		}

		$result = $event->getResult();
		if (is_array($result) === false) {
			return $this->answer(status: 'filinq-missing', reason: 'nobody-answered');
		}

		$fileId = (int)($result['fileId'] ?? 0);
		if ($fileId <= 0) {
			return $this->answer(status: 'failed', reason: 'filinq-stored-no-file');
		}

		try {
			$created = $this->filer->file(
				caseId: $caseId,
				fileId: $fileId,
				title: (string)($result['name'] ?? $declaration['templateSlug']),
				mime: (string)($result['mime'] ?? 'application/pdf'),
				metadata: $this->metadata(declaration: $declaration)
			);
		} catch (Throwable $e) {
			return $this->answer(status: 'failed', reason: 'not-filed: ' . $e->getMessage(), fileId: $fileId);
		}

		return $this->answer(status: 'drafted', reason: '', informatieobject: (string)($created['id'] ?? ''), fileId: $fileId);
	}//end outcome()

	/**
	 * The metadata the request carries and the filed document keeps.
	 *
	 * @param array<string, mixed> $declaration The draft declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function metadata(array $declaration): array {
		return [
			GeneratedDocumentFiler::TYPE => (string)($declaration['documentType'] ?? ''),
			'direction' => 'outgoing',
			'status' => 'draft',
			GeneratedDocumentFiler::FILED_BY_CALLER => true,
		];
	}//end metadata()

	/**
	 * The case's related records of one schema, read regardless of the caller.
	 *
	 * @param string $schemaKey The app setting naming the schema.
	 * @param string $caseField The field holding the case.
	 * @param string $caseId    The case uuid.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function related(string $schemaKey, string $caseField, string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [$caseField => $caseId, '_limit' => 1000]
		);

		return array_values(
			array_filter($rows, fn (array $row): bool => $this->caseTypes->referenceId(value: ($row[$caseField] ?? '')) === $caseId)
		);
	}//end related()

	/**
	 * The case row, or [].
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function caseRow(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return [];
		}

		return ($this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId) ?? []);
	}//end caseRow()

	/**
	 * One answer.
	 *
	 * @param string $status           The status.
	 * @param string $reason           Why, when not drafted.
	 * @param string $informatieobject The filed document.
	 * @param int    $fileId           The file filinq stored.
	 *
	 * @return array{status: string, reason: string, informatieobject: string, fileId: int}
	 */
	private function answer(string $status, string $reason = '', string $informatieobject = '', int $fileId = 0): array {
		return ['status' => $status, 'reason' => $reason, 'informatieobject' => $informatieobject, 'fileId' => $fileId];
	}//end answer()
}//end class
