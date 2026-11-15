<?php

/**
 * DRC rules for linking a document to a case or a besluit
 *
 * An objectinformatieobject names a valid object of the stated type (drc-002),
 * is not there yet (drc-003) and has its counterpart in the Zaken or Besluiten
 * API (drc-004). The document URL itself is checked by the rules service first.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;

/**
 * Create rules of an objectinformatieobject.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class DrcObjectLinkRules {
	/**
	 * The path an object URL of each type contains, and the refusal when it does not (drc-002c/d).
	 *
	 * @var array<string, array{path: string, detail: string, reason: string}>
	 */
	private const OBJECT_PATHS = [
		'case' => [
			'path' => '/zaken/',
			'detail' => 'De object URL wijst niet naar een zaak.',
			'reason' => 'De object URL wijst niet naar een zaak resource.',
		],
		'decision' => [
			'path' => '/besluiten/',
			'detail' => 'De object URL wijst niet naar een besluit.',
			'reason' => 'De object URL wijst niet naar een besluit resource.',
		],
	];

	/**
	 * The relation schema setting and its search field per object type (drc-004).
	 *
	 * @var array<string, array{schemaKey: string, searchField: string}>
	 */
	private const RELATIONS = [
		'case' => ['schemaKey' => 'case_document_schema', 'searchField' => 'case'],
		'decision' => ['schemaKey' => 'decision_document_schema', 'searchField' => 'decision'],
	];

	/**
	 * Constructor.
	 *
	 * @param ZgwRuleLookups $lookups Store lookups in the request context.
	 * @param ZgwRuleResults $results Builds the answers of a rule.
	 * @param SettingsService $settingsService The settings service.
	 * @param FieldValidator $fieldValidator The field validator.
	 * @param DrcDocumentRelations $relations The stored relations of a document.
	 */
	public function __construct(
		private readonly ZgwRuleLookups $lookups,
		private readonly ZgwRuleResults $results,
		private readonly SettingsService $settingsService,
		private readonly FieldValidator $fieldValidator,
		private readonly DrcDocumentRelations $relations,
	) {
	}//end __construct()

	/**
	 * Rules for creating an ObjectInformatieObject.
	 *
	 * Implements drc-002.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function rulesObjectinformatieobjectenCreate(array $body): array {
		$ioUrl      = $body['informatieobject'] ?? '';
		$objectUrl  = $body['object'] ?? '';
		$objectType = $body['objectType'] ?? '';

		$error = null;
		if ($objectUrl !== '') {
			$error = $this->validateObjectUrl(objectUrl: $objectUrl, objectType: $objectType);
		}

		$error ??= $this->checkOioRelations(ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $objectType);
		if ($error !== null) {
			return $error;
		}

		return $this->results->isValid(body: $body);
	}//end rulesObjectinformatieobjectenCreate()

	/**
	 * Drc-003 then drc-004: the relation is not a duplicate, and its ZIO/BIO exists.
	 *
	 * Uniqueness runs first so a duplicate error takes priority over the cross-register check.
	 *
	 * @param mixed $ioUrl The informatieobject URL
	 * @param mixed $objectUrl The object URL
	 * @param mixed $objectType The objectType
	 *
	 * @return array|null The refusal, or null when acceptable or when there is nothing to look up
	 */
	private function checkOioRelations(mixed $ioUrl, mixed $objectUrl, mixed $objectType): ?array {
		if ($ioUrl === '' || $objectUrl === '' || $this->lookups->objectService() === null) {
			return null;
		}

		$error = $this->relations->checkOioUniqueness(ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $objectType);
		if ($error !== null || $objectType === '') {
			return $error;
		}

		return $this->validateOioCrossRegister(ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $objectType);
	}//end checkOioRelations()

	/**
	 * Validate OIO object URL (drc-002a/b/c/d).
	 *
	 * @param string $objectUrl The object URL
	 * @param string $objectType The object type (zaak or besluit)
	 *
	 * @return array|null Validation error, or null if valid
	 */
	private function validateObjectUrl(string $objectUrl, string $objectType): ?array {
		// Drc-002a/b: Check URL validity.
		if ($this->fieldValidator->isValidUrl(url: $objectUrl) === false) {
			return $this->badObjectUrl(detail: 'De object URL is ongeldig.');
		}

		// Drc-002a/b: Check URL contains a UUID.
		if ($this->fieldValidator->extractUuid(url: $objectUrl) === null) {
			return $this->badObjectUrl(detail: 'De object URL bevat geen geldig UUID.');
		}

		// Drc-002c/d: Validate objectType matches the URL path.
		$expected = (self::OBJECT_PATHS[$objectType] ?? null);
		if ($expected !== null && strpos($objectUrl, $expected['path']) === false) {
			return $this->wrongObjectResource(detail: $expected['detail'], reason: $expected['reason']);
		}

		return null;
	}//end validateObjectUrl()

	/**
	 * The refusal for an object URL that is no URL, or names no uuid.
	 *
	 * @param string $detail The sentence, also the field's reason.
	 *
	 * @return array The refusal.
	 */
	private function badObjectUrl(string $detail): array {
		return $this->results->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->results->fieldError(fieldName: 'object', code: 'bad-url', reason: $detail),
			]
		);
	}//end badObjectUrl()

	/**
	 * The refusal for an object URL that points at another kind of resource.
	 *
	 * @param string $detail The sentence.
	 * @param string $reason The field's reason.
	 *
	 * @return array The refusal.
	 */
	private function wrongObjectResource(string $detail, string $reason): array {
		return $this->results->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->results->fieldError(fieldName: 'object', code: 'invalid-resource', reason: $reason),
			]
		);
	}//end wrongObjectResource()

	/**
	 * Validate OIO cross-register: ZIO/BIO must exist (drc-004 VNG).
	 *
	 * @param string $ioUrl The informatieobject URL
	 * @param string $objectUrl The object URL (zaak or besluit)
	 * @param string $objectType The object type (zaak or besluit)
	 *
	 * @return array|null The refusal when the relation does not exist or could not be looked up, null if valid
	 */
	private function validateOioCrossRegister(string $ioUrl, string $objectUrl, string $objectType): ?array {
		$ioUuid = $this->fieldValidator->extractUuid(url: $ioUrl) ?? '';
		$objectUuid = $this->fieldValidator->extractUuid(url: $objectUrl) ?? '';

		if ($ioUuid === '' || $objectUuid === '') {
			return null;
		}

		$link = new DocumentLink(objectType: $objectType, objectUuid: $objectUuid, documentUuid: $ioUuid);
		$search = $this->relationSearch(link: $link);
		if ($search === null) {
			return null;
		}

		return $this->requireRelation(link: $link, search: $search);
	}//end validateOioCrossRegister()

	/**
	 * Where the counterpart of a link is looked up, or null when it cannot
	 * be: no register, an object type without a relation schema, or that
	 * schema not configured.
	 *
	 * @param DocumentLink $link The link.
	 *
	 * @return RelationSearch|null The search.
	 */
	private function relationSearch(DocumentLink $link): ?RelationSearch {
		$register = $this->lookups->register();
		$relation = (self::RELATIONS[$link->objectType] ?? null);
		if ($register === '' || $relation === null) {
			return null;
		}

		$schema = $this->settingsService->getConfigValue(key: $relation['schemaKey']);
		if ($schema === '') {
			return null;
		}

		return new RelationSearch(
			register: $register,
			schema: $schema,
			requestParams: [
				$relation['searchField'] => $link->objectUuid,
				'document' => $link->documentUuid,
				'_limit' => 1,
			]
		);
	}//end relationSearch()

	/**
	 * Refuse a link whose counterpart does not exist, or could not be looked up.
	 *
	 * @param DocumentLink   $link   The link.
	 * @param RelationSearch $search Where its counterpart is looked up.
	 *
	 * @return array|null The refusal, or null when the counterpart exists.
	 */
	private function requireRelation(DocumentLink $link, RelationSearch $search): ?array {
		try {
			$total = $this->relationTotal(search: $search);
		} catch (\Throwable $e) {
			// The lookup did not run, so nobody knows whether the counterpart exists. Refuse.
			return $this->results->notVerified(rule: 'drc-004', object: $link->label(), cause: $e->getMessage());
		}

		if ($total === 0) {
			return $this->missingRelation(link: $link);
		}

		return null;
	}//end requireRelation()

	/**
	 * How many counterparts the search finds.
	 *
	 * @param RelationSearch $search The search.
	 *
	 * @return mixed The total the store answered, or the number of rows.
	 */
	private function relationTotal(RelationSearch $search): mixed {
		$query = $this->lookups->objectService()->buildSearchQuery(
			requestParams: $search->requestParams,
			register: $search->register,
			schema: $search->schema
		);
		$result = $this->lookups->objectService()->searchObjectsPaginated(query: $query);

		return $result['total'] ?? count($result['results'] ?? []);
	}//end relationTotal()

	/**
	 * The refusal for a link without its ZaakInformatieObject or BesluitInformatieObject.
	 *
	 * @param DocumentLink $link The link.
	 *
	 * @return array The refusal.
	 */
	private function missingRelation(DocumentLink $link): array {
		$detail = 'Er bestaat geen BesluitInformatieObject in de Besluiten API voor deze combinatie.';
		if ($link->objectType === 'case') {
			$detail = 'Er bestaat geen ZaakInformatieObject in de Zaken API voor deze combinatie.';
		}

		return $this->results->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->results->fieldError(
					fieldName: 'nonFieldErrors',
					code: 'inconsistent-relation',
					reason: $detail
				),
			]
		);
	}//end missingRelation()
}//end class
