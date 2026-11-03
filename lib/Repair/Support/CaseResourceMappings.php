<?php

/**
 * Dossiq CaseResourceMappings.
 *
 * The default mappings of the case resources: a case and what it records.
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
 * The default mappings of the case resources: a case and what it records.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class CaseResourceMappings {
	/**
	 * The default mapping for Zaak (case).
	 *
	 * @var array<string, mixed>
	 */
	public const CASE = [
		'zgwResource' => 'zaak',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'identificatie' => '{{ identifier }}',
			'bronorganisatie' => '{{ sourceOrganisation }}',
			'omschrijving' => '{{ title }}',
			'notes' => '{{ description }}',
			'caseType' => '{{ _baseUrl | replace({"zaken/v1/zaken": "catalogi/v1/zaaktypen"}) }}/{{ caseType }}',
			'registrationDate' => '{{ _created }}',
			'startdatum' => '{{ startDate }}',
			'endDate' => '{{ endDate }}',
			'einddatumGepland' => '{{ plannedEndDate }}',
			'uiterlijkeEinddatumAfdoening' => '{{ deadline }}',
			'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
			'verantwoordelijkeOrganisatie' => '{{ assignee }}',
			'archiefnominatie' => '{{ archiveNomination }}',
			'archiefactiedatum' => '{{ archiveActionDate }}',
			'archiefstatus' => '{{ archiveStatus | default("") | zgw_enum("archiveStatus", _valueMappings) }}',
			'betalingsindicatie' => '{{ paymentIndication | default("") | zgw_enum("paymentIndication", _valueMappings) }}',
			'laatsteBetaaldatum' => '{{ lastPaymentDate }}',
			'hoofdzaak' => '{% if parentCase %}{{ _baseUrl }}/{{ parentCase }}{% endif %}',
		],
		'reverseMapping' => [
			'title' => '{{ omschrijving }}',
			'description' => '{{ toelichting }}',
			'identifier' => '{{ identificatie }}',
			'sourceOrganisation' => '{{ bronorganisatie }}',
			'caseType' => '{{ zaaktype | zgw_extract_uuid }}',
			'startDate' => '{{ startdatum }}',
			'endDate' => '{{ einddatum }}',
			'plannedEndDate' => '{{ einddatumGepland }}',
			'deadline' => '{{ uiterlijkeEinddatumAfdoening }}',
			'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
			'assignee' => '{{ verantwoordelijkeOrganisatie }}',
			'archiveNomination' => '{{ archiefnominatie }}',
			'archiveActionDate' => '{{ archiefactiedatum }}',
			'archiveStatus' => '{{ archiefstatus | default("") | zgw_enum_reverse("archiveStatus", _valueMappings) }}',
			'paymentIndication' => '{{ betalingsindicatie | default("") | zgw_enum_reverse("paymentIndication", _valueMappings) }}',
			'lastPaymentDate' => '{{ laatsteBetaaldatum }}',
			'parentCase' => '{{ hoofdzaak | zgw_extract_uuid }}',
		],
		// Two of these three enums were HALF translated, which is worse than
		// either language on its own: only the values that happened to stay
		// Dutch were reachable, and ZGW's own words for the rest answered
		// 400. `vertrouwelijkheidaanduiding` really is identical on both
		// sides, so it maps to itself and says so.
		'valueMapping' => [
			'confidentiality' => DefaultMappingTables::CONFIDENTIALITY_VALUES,
			'archiveStatus' => [
				'nog_te_archiveren' => 'nog_te_archiveren',
				'archived' => 'gearchiveerd',
				'archived_retention_period_unknown' => 'gearchiveerd_procestermijn_onbekend',
				'overgedragen' => 'overgedragen',
			],
			'paymentIndication' => [
				'nvt' => 'nvt',
				'not_yet' => 'nog_niet',
				'gedeeltelijk' => 'gedeeltelijk',
				'geheel' => 'geheel',
			],
		],
		'nullableFields' => [
			'endDate',
			'einddatumGepland',
			'uiterlijkeEinddatumAfdoening',
			'archiefnominatie',
			'archiefactiedatum',
			'archiefstatus',
			'betalingsindicatie',
			'laatsteBetaaldatum',
			'hoofdzaak',
		],
		'queryParameterMapping' => [
			'caseType' => [
				'field' => 'caseType',
				'extractUuid' => true,
			],
			'identificatie' => [
				'field' => 'identifier',
			],
			'bronorganisatie' => [
				'field' => 'sourceOrganisation',
			],
			'startdatum' => [
				'field' => 'startDate',
			],
			'startdatum__gte' => [
				'field' => 'startDate',
				'operator' => 'gte',
			],
			'startdatum__lte' => [
				'field' => 'startDate',
				'operator' => 'lte',
			],
			'endDate' => [
				'field' => 'endDate',
			],
			'einddatum__isnull' => [
				'field' => 'endDate',
				'operator' => 'isnull',
			],
			'archiefnominatie' => [
				'field' => 'archiveNomination',
			],
			'archiefactiedatum__lt' => [
				'field' => 'archiveActionDate',
				'operator' => 'lt',
			],
			'archiefstatus' => [
				'field' => 'archiveStatus',
			],
		],
	];

	/**
	 * The default mapping for Status.
	 *
	 * @var array<string, mixed>
	 */
	public const STATUS = [
		'zgwResource' => 'status',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'zaak' => '{{ _baseUrl | replace({"zaken/v1/statussen": "zaken/v1/zaken"}) }}/{{ case }}',
			'statustype' => '{{ _baseUrl | replace({"zaken/v1/statussen": "catalogi/v1/statustypen"}) }}/{{ statusType }}',
			'datumStatusGezet' => '{{ _created }}',
			'statustoelichting' => '{{ description }}',
		],
		'reverseMapping' => [
			'case' => '{{ zaak | zgw_extract_uuid }}',
			'statusType' => '{{ statustype | zgw_extract_uuid }}',
			'description' => '{{ statustoelichting }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE,
	];

	/**
	 * The default mapping for Resultaat (result).
	 *
	 * @var array<string, mixed>
	 */
	public const RESULT = [
		'zgwResource' => 'result',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'zaak' => '{{ _baseUrl | replace({"zaken/v1/resultaten": "zaken/v1/zaken"}) }}/{{ case }}',
			'resultaattype' => '{{ _baseUrl | replace({"zaken/v1/resultaten": "catalogi/v1/resultaattypen"}) }}/{{ resultType }}',
			'notes' => '{{ description }}',
		],
		'reverseMapping' => [
			'name' => '{{ toelichting }}',
			'case' => '{{ zaak | zgw_extract_uuid }}',
			'resultType' => '{{ resultaattype | zgw_extract_uuid }}',
			'description' => '{{ toelichting }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE,
	];

	/**
	 * The default mapping for Rol (role).
	 *
	 * @var array<string, mixed>
	 */
	public const ROLE = [
		'zgwResource' => 'role',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'zaak' => '{{ _baseUrl | replace({"zaken/v1/rollen": "zaken/v1/zaken"}) }}/{{ case }}',
			'roltype' => '{{ _baseUrl | replace({"zaken/v1/rollen": "catalogi/v1/roltypen"}) }}/{{ roleType }}',
			'omschrijving' => '{{ name }}',
			'omschrijvingGeneriek' => '{{ description }}',
			'betrokkeneIdentificatie' => '{{ participant }}',
		],
		'reverseMapping' => [
			'name' => '{{ omschrijving }}',
			'description' => '{{ omschrijvingGeneriek }}',
			'case' => '{{ zaak | zgw_extract_uuid }}',
			'roleType' => '{{ roltype | zgw_extract_uuid }}',
			'participant' => '{{ betrokkeneIdentificatie }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE,
	];

	/**
	 * The default mapping for ZaakEigenschap (case property).
	 *
	 * @var array<string, mixed>
	 */
	public const ZAAKEIGENSCHAP = [
		'zgwResource' => 'zaakeigenschap',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'zaak' => '{{ _baseUrl | replace({"zaken/v1/zaakeigenschappen": "zaken/v1/zaken"}) }}/{{ case }}',
			'eigenschap' => '{{ _baseUrl | replace({"zaken/v1/zaakeigenschappen": "catalogi/v1/eigenschappen"}) }}/{{ propertyDefinition }}',
			'value' => '{{ value }}',
		],
		'reverseMapping' => [
			'case' => '{{ zaak | zgw_extract_uuid }}',
			'propertyDefinition' => '{{ eigenschap | zgw_extract_uuid }}',
			'value' => '{{ waarde }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE,
	];

	/**
	 * The default mapping for ZaakInformatieObject (case document link).
	 *
	 * @var array<string, mixed>
	 */
	public const ZAAKINFORMATIEOBJECT = [
		'zgwResource' => 'zaakinformatieobject',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'zaak' => '{{ _baseUrl | replace({"zaken/v1/zaakinformatieobjecten": "zaken/v1/zaken"}) }}/{{ case }}',
			'informatieobject' => '{{ document }}',
			'titel' => '{{ title }}',
			'beschrijving' => '{{ description }}',
			'registrationDate' => '{{ registrationDate }}',
		],
		'reverseMapping' => [
			'case' => '{{ zaak | zgw_extract_uuid }}',
			'document' => '{{ informatieobject }}',
			'title' => '{{ titel }}',
			'description' => '{{ beschrijving }}',
			'registrationDate' => '{{ registratiedatum }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => [
			'zaak' => [
				'field' => 'case',
				'extractUuid' => true,
			],
			'informatieobject' => [
				'field' => 'document',
			],
		],
	];

	/**
	 * The default mapping for ZaakObject (case object).
	 *
	 * @var array<string, mixed>
	 */
	public const ZAAKOBJECT = [
		'zgwResource' => 'zaakobject',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'zaak' => '{{ _baseUrl | replace({"zaken/v1/zaakobjecten": "zaken/v1/zaken"}) }}/{{ case }}',
			'object' => '{{ objectUrl }}',
			'objectType' => '{{ objectType }}',
			'objectIdentificatie' => '{{ objectIdentification }}',
			'relatieomschrijving' => '{{ description }}',
		],
		'cast' => [
			'objectIdentificatie' => 'jsonToArray',
		],
		'reverseMapping' => [
			'case' => '{{ zaak | zgw_extract_uuid }}',
			'objectUrl' => '{{ object }}',
			'objectType' => '{{ objectType }}',
			'objectIdentification' => '{{ objectIdentificatie | json_encode | raw }}',
			'description' => '{{ relatieomschrijving }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE,
	];

	/**
	 * The default mapping for KlantContact (customer contact).
	 *
	 * @var array<string, mixed>
	 */
	public const KLANTCONTACT = [
		'zgwResource' => 'klantcontact',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'propertyMapping' => [
			'url' => '{{ _baseUrl }}/{{ _uuid }}',
			'uuid' => '{{ _uuid }}',
			'zaak' => '{{ _baseUrl | replace({"zaken/v1/klantcontacten": "zaken/v1/zaken"}) }}/{{ case }}',
			'datumtijd' => '{{ contactDateTime }}',
			'kanaal' => '{{ channel }}',
			'subject' => '{{ subject }}',
			'initiator' => '{{ initiator }}',
		],
		'reverseMapping' => [
			'case' => '{{ zaak | zgw_extract_uuid }}',
			'contactDateTime' => '{{ datumtijd }}',
			'channel' => '{{ kanaal }}',
			'subject' => '{{ onderwerp }}',
			'initiator' => '{{ initiator }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE,
	];
}//end class
