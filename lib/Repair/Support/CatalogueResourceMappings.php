<?php

/**
 * Dossiq CatalogueResourceMappings.
 *
 * The default mappings of the catalogue resources: catalogues, case types and the types they declare.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

/**
 * The default mappings of the catalogue resources: catalogues, case types and the types they declare.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class CatalogueResourceMappings {
	/**
	 * The default mapping for Catalogus.
	 *
	 * @var array<string, mixed>
	 */
	public const CATALOGUS = [
		'zgwResource' => 'catalogus',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'domein' => '{{ domein }}',
			'rsin' => '{{ rsin }}',
			// Inverted before this, the same way the kanaal mapping was: the
			// keys are the ZGW field names and the templates read register
			// properties. The ZTC catalogussen response carried three fields
			// ZGW does not define, all empty, and dropped the three it does.
			'contactpersoonBeheerNaam' => '{{ contactPersonManagementName }}',
			'contactpersoonBeheerTelefoonnummer' => '{{ contactPersonManagementPhoneNumber }}',
			'contactpersoonBeheerEmailadres' => '{{ contactPersonManagementEmailAddress }}',
			'zaaktypen' => '[]',
			'besluittypen' => '[]',
			'informatieobjecttypen' => '[]',
		],
		'cast' => [
			'zaaktypen' => 'jsonToArray',
			'besluittypen' => 'jsonToArray',
			'informatieobjecttypen' => 'jsonToArray',
		],
		'reverseMapping' => [
			'domein' => '{{ domein }}',
			'rsin' => '{{ rsin }}',
			'contactPersonManagementName' => '{{ contactpersoonBeheerNaam }}',
			'contactPersonManagementPhoneNumber' => '{{ contactpersoonBeheerTelefoonnummer }}',
			'contactPersonManagementEmailAddress' => '{{ contactpersoonBeheerEmailadres }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => [
			'domein' => [
				'field' => 'domein',
			],
			'rsin' => [
				'field' => 'rsin',
			],
		],
	];

	/**
	 * The default mapping for ZaakType (caseType).
	 *
	 * @var array<string, mixed>
	 */
	public const CASE_TYPE = [
		'zgwResource' => 'caseType',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'identificatie' => '{{ identifier }}',
			'omschrijving' => '{{ title }}',
			'omschrijvingGeneriek' => '{{ description }}',
			'catalogus' => '{{ _baseUrl | replace({"catalogi/v1/zaaktypen": "catalogi/v1/catalogussen"}) }}/{{ catalogus }}',
			'doel' => '{{ purpose }}',
			'aanleiding' => '{{ trigger }}',
			'subject' => '{{ subject }}',
			'doorlooptijd' => '{{ processingDeadline }}',
			'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
			'concept' => '{{ isDraft }}',
			'startValidity' => '{{ validFrom }}',
			'endValidity' => '{{ validUntil }}',
			'handelingInitiator' => '{{ origin }}',
			'indicatieInternOfExtern' => '{{ internalOrExternal }}',
			'handelingBehandelaar' => '{{ handlerAction }}',
			'opschortingEnAanhoudingMogelijk' => '{{ suspensionAllowed }}',
			'extensionPossible' => '{{ extensionAllowed }}',
			'verlengingstermijn' => '{{ extensionPeriod }}',
			'publicatieIndicatie' => '{{ publicationRequired }}',
			// Encoded here and decoded by the `cast` below, because Twig renders
			// a bare array as the literal string "Array". Unlike referentieproces
			// and gerelateerdeZaaktypen beside it, this one is backed by an ARRAY
			// property, so the JSON text is a transport step and never the stored
			// value. See the reverseCast for the inbound half.
			'productenOfDiensten' => '{{ productsOrServices | json_encode }}',
			'selectielijstDossiqype' => '{{ selectionListProcessType }}',
			'referentieproces' => '{{ referenceProcess | json_encode }}',
			'responsible' => '{{ responsible }}',
			'gerelateerdeZaaktypen' => '{{ relatedCaseTypes | json_encode }}',
			'besluittypen' => 'decisionTypes',
			'informatieobjecttypen' => '[]',
		],
		'reverseMapping' => [
			'title' => '{{ omschrijving }}',
			'description' => '{{ omschrijvingGeneriek }}',
			'identifier' => '{{ identificatie }}',
			'catalogus' => '{{ catalogus | zgw_extract_uuid }}',
			'purpose' => '{{ doel }}',
			'trigger' => '{{ aanleiding }}',
			'subject' => '{{ onderwerp }}',
			'processingDeadline' => '{{ doorlooptijd }}',
			'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
			'isDraft' => '{{ concept }}',
			'validFrom' => '{{ beginGeldigheid }}',
			'validUntil' => '{{ eindeGeldigheid }}',
			'origin' => '{{ handelingInitiator }}',
			'internalOrExternal' => '{{ indicatieInternOfExtern }}',
			'handlerAction' => '{{ handelingBehandelaar }}',
			'suspensionAllowed' => '{{ opschortingEnAanhoudingMogelijk }}',
			'extensionAllowed' => '{{ verlengingMogelijk }}',
			'extensionPeriod' => '{{ verlengingstermijn }}',
			'publicationRequired' => '{{ publicatieIndicatie }}',
			'selectionListProcessType' => '{{ selectielijstDossiqype }}',
			'responsible' => '{{ verantwoordelijke }}',
			'productsOrServices' => '{{ productenOfDiensten | json_encode }}',
			'referenceProcess' => '{{ referentieproces | json_encode }}',
			'relatedCaseTypes' => '{{ gerelateerdeZaaktypen | json_encode }}',
			'versionDate' => '{{ versiedatum }}',
		],
		'reverseCast' => [
			'isDraft' => 'bool',
			'suspensionAllowed' => 'bool',
			'extensionAllowed' => 'bool',
			'publicationRequired' => 'bool',
			// The template above json_encodes it, because Twig cannot emit an
			// array; this turns it back into one before the write. Without it
			// OpenRegister refuses with "should be type 'array or null' but is
			// 'string'" and the whole ZTC setUp falls over behind that one 400.
			'productsOrServices' => 'jsonToArray',
		],
		'cast' => [
			'concept' => 'bool',
			'opschortingEnAanhoudingMogelijk' => 'bool',
			'extensionPossible' => 'bool',
			'publicatieIndicatie' => 'bool',
			'productenOfDiensten' => 'jsonToArray',
			'gerelateerdeZaaktypen' => 'jsonToArray',
			'informatieobjecttypen' => 'jsonToArray',
			'referentieproces' => 'jsonToArray',
		],
		'valueMapping' => [],
		'queryParameterMapping' => [
			'identificatie' => [
				'field' => 'identifier',
			],
			'catalogus' => [
				'field' => 'catalogus',
				'extractUuid' => true,
			],
		],
	];

	/**
	 * The default mapping for StatusType (statusType).
	 *
	 * @var array<string, mixed>
	 */
	public const STATUS_TYPE = [
		'zgwResource' => 'statustype',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'omschrijving' => '{{ name }}',
			'omschrijvingGeneriek' => '{{ description }}',
			'caseType' => '{{ _baseUrl | replace({"catalogi/v1/statustypen": "catalogi/v1/zaaktypen"}) }}/{{ caseType }}',
			'sequenceNumber' => '{{ order }}',
			'isEindstatus' => '{{ isFinal }}',
			// What the applicant reads, which ZTC has carried since 1.0 and
			// this mapping did not. `default(name)` is the same fallback
			// StatusPublicLabels applies in PHP and in the browser: a status
			// that declares no public label publishes its name, so a
			// consumer that has been reading `statustekst` sees the name
			// appear rather than a field that used to be absent.
			'statustekst' => '{{ publicLabel | default(name) }}',
		],
		'reverseMapping' => [
			'name' => '{{ omschrijving }}',
			'description' => '{{ omschrijvingGeneriek }}',
			'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
			'order' => '{{ volgnummer }}',
			'isFinal' => '{{ isEindstatus }}',
			// Inbound the fallback must NOT run: a foreign catalogue whose
			// statustekst equals its omschrijving would otherwise be stored
			// as a public label somebody chose, and the two would then be
			// impossible to tell apart when it is edited here.
			'publicLabel' => '{{ statustekst }}',
		],
		'reverseCast' => [
			'order' => 'int',
			'isFinal' => 'bool',
		],
		'cast' => [
			'sequenceNumber' => 'int',
			'isEindstatus' => 'bool',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE_TYPE,
	];

	/**
	 * The default mapping for ResultaatType (resultType).
	 *
	 * @var array<string, mixed>
	 */
	public const RESULT_TYPE = [
		'zgwResource' => 'resultaattype',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'omschrijving' => '{{ name }}',
			'omschrijvingGeneriek' => '{{ genericDescription }}',
			'notes' => '{{ description }}',
			'caseType' => '{{ _baseUrl | replace({"catalogi/v1/resultaattypen": "catalogi/v1/zaaktypen"}) }}/{{ caseType }}',
			'archiefnominatie' => '{{ archivalAction }}',
			'archiefactietermijn' => '{{ archivalPeriod }}',
			'brondatumArchiefprocedure' => '{{ sourceDateArchiveProcedure | json_encode }}',
			'selectielijstklasse' => '{{ selectionListClass }}',
		],
		'reverseMapping' => [
			'name' => '{{ omschrijving }}',
			'genericDescription' => '{{ omschrijvingGeneriek }}',
			'description' => '{{ toelichting }}',
			'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
			'archivalAction' => '{{ archiefnominatie }}',
			'archivalPeriod' => '{{ archiefactietermijn }}',
			'sourceDateArchiveProcedure' => '{{ brondatumArchiefprocedure | json_encode }}',
			'selectionListClass' => '{{ selectielijstklasse }}',
		],
		'valueMapping' => [
			'archivalAction' => [
				'bewaren' => 'bewaren',
				'vernietigen' => 'vernietigen',
				'blijvend_bewaren' => 'blijvend_bewaren',
			],
		],
		'cast' => [
			'sourceDateArchiveProcedure' => 'jsonToArray',
		],
		'reverseCast' => [],
		'nullableFields' => [
			'archiefactietermijn',
			'omschrijvingGeneriek',
			'brondatumArchiefprocedure',
			'selectielijstklasse',
		],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE_TYPE,
	];

	/**
	 * The default mapping for RolType (roleType).
	 *
	 * @var array<string, mixed>
	 */
	public const ROLE_TYPE = [
		'zgwResource' => 'roltype',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'omschrijving' => '{{ name }}',
			'omschrijvingGeneriek' => '{{ description }}',
			'caseType' => '{{ _baseUrl | replace({"catalogi/v1/roltypen": "catalogi/v1/zaaktypen"}) }}/{{ caseType }}',
		],
		'reverseMapping' => [
			'name' => '{{ omschrijving }}',
			'description' => '{{ omschrijvingGeneriek }}',
			'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE_TYPE,
	];

	/**
	 * The default mapping for Eigenschap (propertyDefinition).
	 *
	 * @var array<string, mixed>
	 */
	public const ATTRIBUTE = [
		'zgwResource' => 'eigenschap',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'name' => '{{ name }}',
			'definitie' => '{{ definition }}',
			'notes' => '{{ description }}',
			'caseType' => '{{ _baseUrl | replace({"catalogi/v1/eigenschappen": "catalogi/v1/zaaktypen"}) }}/{{ caseType }}',
			'specificatie' => '{{ {formaat: propertyType ?: "tekst"} | json_encode }}',
		],
		'reverseMapping' => [
			'name' => '{{ naam }}',
			'definition' => '{{ definitie }}',
			'description' => '{{ toelichting }}',
			'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE_TYPE,
	];

	/**
	 * The default mapping for BesluitType (decisionType).
	 *
	 * @var array<string, mixed>
	 */
	public const DECISION_TYPE = [
		'zgwResource' => 'besluittype',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'omschrijving' => '{{ name }}',
			'notes' => '{{ description }}',
			'catalogus' => '{{ _baseUrl | replace({"catalogi/v1/besluittypen": "catalogi/v1/catalogussen"}) }}/{{ catalogus }}',
			'zaaktypen' => 'caseTypes',
			'concept' => '{{ isDraft }}',
			'publicatieIndicatie' => '{{ publicationRequired }}',
			'informatieobjecttypen' => 'documentTypes',
			'startValidity' => '{{ validFrom }}',
			'endValidity' => '{{ validUntil }}',
		],
		'reverseMapping' => [
			'name' => '{{ omschrijving }}',
			'description' => '{{ toelichting }}',
			'catalogus' => '{{ catalogus | zgw_extract_uuid }}',
			'isDraft' => '{{ concept }}',
			'publicationRequired' => '{{ publicatieIndicatie }}',
			'caseTypes' => 'zaaktypen',
			'documentTypes' => 'informatieobjecttypen',
			'validFrom' => '{{ beginGeldigheid }}',
			'validUntil' => '{{ eindeGeldigheid }}',
		],
		'reverseCast' => [
			'isDraft' => 'bool',
			'publicationRequired' => 'bool',
		],
		'cast' => [
			'concept' => 'bool',
			'publicatieIndicatie' => 'bool',
		],
		'valueMapping' => [],
		'queryParameterMapping' => [
			'zaaktypen' => [
				'field' => 'caseType',
				'extractUuid' => true,
			],
			'catalogus' => [
				'field' => 'catalogus',
				'extractUuid' => true,
			],
			'omschrijving' => [
				'field' => 'name',
			],
		],
	];

	/**
	 * The default mapping for InformatieObjectType (documentType).
	 *
	 * @var array<string, mixed>
	 */
	public const INFORMATIE_OBJECT_TYPE = [
		'zgwResource' => 'informatieobjecttype',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'omschrijving' => '{{ name }}',
			'notes' => '{{ description }}',
			'catalogus' => '{{ _baseUrl | replace({"catalogi/v1/informatieobjecttypen": "catalogi/v1/catalogussen"}) }}/{{ catalogus }}',
			'concept' => '{{ isDraft }}',
			'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
			'informatieobjectcategorie' => '{{ category }}',
			'startValidity' => '{{ validFrom }}',
			'endValidity' => '{{ validUntil }}',
			'verplicht' => '{{ isRequired }}',
		],
		'reverseMapping' => [
			'name' => '{{ omschrijving }}',
			'description' => '{{ toelichting }}',
			'catalogus' => '{{ catalogus | zgw_extract_uuid }}',
			'isDraft' => '{{ concept }}',
			'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
			'category' => '{{ informatieobjectcategorie }}',
			'validFrom' => '{{ beginGeldigheid }}',
			'validUntil' => '{{ eindeGeldigheid }}',
			'isRequired' => '{{ verplicht }}',
		],
		'reverseCast' => [
			'isDraft' => 'bool',
			'isRequired' => 'bool',
		],
		'cast' => [
			'concept' => 'bool',
			'verplicht' => 'bool',
		],
		'valueMapping' => [],
		'queryParameterMapping' => [
			'catalogus' => [
				'field' => 'catalogus',
				'extractUuid' => true,
			],
		],
	];

	/**
	 * The default mapping for ZaaktypeInformatieobjecttype.
	 *
	 * @var array<string, mixed>
	 */
	public const CASE_TYPE_INFORMATIEOBJECTTYPE = [
		'zgwResource' => 'zaaktypeinformatieobjecttype',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'caseType' => '{{ _baseUrl | replace({"catalogi/v1/zaaktype-informatieobjecttypen": "catalogi/v1/zaaktypen"}) }}/{{ caseType }}',
			'informatieobjecttype' => '{{ _baseUrl | replace({"catalogi/v1/zaaktype-informatieobjecttypen": '
				. '"catalogi/v1/informatieobjecttypen"}) }}/{{ informatieobjecttype }}',
			// Same inversion as `richting` below: the ZGW field is
			// `volgnummer`, and the value comes from `sequenceNumber`.
			'volgnummer' => '{{ sequenceNumber }}',
			// The ZGW field is `richting`, not `direction`. This key used to
			// say `direction` and read `{{ richting }}`, so the response
			// carried a field ZGW does not define, holding a value the
			// register does not store. Both halves were inverted.
			'richting' => '{{ direction | default("") | zgw_enum("direction", _valueMappings) }}',
			'statustype' => '{{ statustype }}',
		],
		'reverseMapping' => [
			'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
			'informatieobjecttype' => '{{ informatieobjecttype | zgw_extract_uuid }}',
			'sequenceNumber' => '{{ volgnummer }}',
			'direction' => '{{ richting | default("") | zgw_enum_reverse("direction", _valueMappings) }}',
			'statustype' => '{{ statustype }}',
		],
		'reverseCast' => [
			'sequenceNumber' => 'int',
		],
		'cast' => [
			'volgnummer' => 'int',
		],
		// ZGW spells this enum in Dutch and the schema declares it in
		// English, and both templates used to pass the value straight
		// through. POST /catalogi/v1/zaaktype-informatieobjecttypen with
		// ZGW's own `richting: inkomend` answered 400 "should be one of:
		// 'inbound', 'internal', 'outbound'", so the endpoint could not
		// accept a conformant body at all.
		'valueMapping' => [
			'direction' => [
				'inbound' => 'inkomend',
				'internal' => 'intern',
				'outbound' => 'uitgaand',
			],
		],
		'queryParameterMapping' => [
			'caseType' => [
				'field' => 'caseType',
				'extractUuid' => true,
			],
			'informatieobjecttype' => [
				'field' => 'informatieobjecttype',
				'extractUuid' => true,
			],
		],
	];
}//end class
