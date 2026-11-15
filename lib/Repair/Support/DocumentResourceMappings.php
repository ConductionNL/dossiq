<?php

/**
 * Dossiq DocumentResourceMappings.
 *
 * The default mappings of the document, decision, dispatch and notification resources.
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
 * The default mappings of the document, decision, dispatch and notification resources.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class DocumentResourceMappings {
	/**
	 * The default mapping for Besluit (decision).
	 *
	 * @var array<string, mixed>
	 */
	public const DECISION = [
		'zgwResource' => 'decision',
		'propertyMapping' => [
			'identificatie' => '{{ title }}',
			'notes' => '{{ explanation }}',
			'zaak' => '{{ _baseUrl | replace({"besluiten/v1/besluiten": "zaken/v1/zaken"}) }}/{{ case }}',
			'besluittype' => '{{ _baseUrl | replace({"besluiten/v1/besluiten": "catalogi/v1/besluittypen"}) }}/{{ decisionType }}',
			'verantwoordelijkeOrganisatie' => '{{ responsibleOrganisation }}',
			'bestuursorgaan' => '{{ governingBody }}',
			'date' => '{{ decisionDate }}',
			'effectiveDate' => '{{ effectiveDate }}',
			'vervaldatum' => '{{ expiryDate }}',
			'publicationDate' => '{{ publicationDate }}',
			'verzenddatum' => '{{ deliveryDate }}',
		],
		'reverseMapping' => [
			'title' => '{{ identificatie }}',
			'explanation' => '{{ toelichting }}',
			'case' => '{% if zaak is defined and zaak %}{{ zaak | zgw_extract_uuid }}{% endif %}',
			'decisionType' => '{{ besluittype | zgw_extract_uuid }}',
			'responsibleOrganisation' => '{{ verantwoordelijkeOrganisatie }}',
			'governingBody' => '{{ bestuursorgaan }}',
			'decisionDate' => '{{ datum }}',
			'effectiveDate' => '{{ ingangsdatum }}',
			'expiryDate' => '{{ vervaldatum }}',
			'publicationDate' => '{{ publicatiedatum }}',
			'deliveryDate' => '{{ verzenddatum }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_CASE,
	];

	/**
	 * The default mapping for EnkelvoudigInformatieObject (document).
	 *
	 * @var array<string, mixed>
	 */
	public const ENKELVOUDIG_INFORMATIE_OBJECT = [
		'zgwResource' => 'enkelvoudiginformatieobject',
		'propertyMapping' => [
			'identificatie' => '{{ identifier }}',
			'bronorganisatie' => '{{ sourceOrganisation }}',
			'creatiedatum' => '{{ creationDate }}',
			'titel' => '{{ title }}',
			'vertrouwelijkheidaanduiding' => '{{ confidentiality }}',
			'auteur' => '{{ author }}',
			'status' => '{{ status | default("") | zgw_enum("status", _valueMappings) }}',
			'format' => '{{ format }}',
			'taal' => '{{ language }}',
			'fileName' => '{{ fileName }}',
			'bestandsomvang' => '{{ fileSize }}',
			'inhoud' => '{{ _downloadUrl }}',
			'link' => '{{ link }}',
			'beschrijving' => '{{ description }}',
			'informatieobjecttype' => '{{ _baseUrl | replace({"documenten/v1/enkelvoudiginformatieobjecten": '
				. '"catalogi/v1/informatieobjecttypen"}) }}/{{ documentType }}',
			'locked' => '{{ locked }}',
			'registrationDate' => '{{ _created }}',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'indicatieGebruiksrecht' => '{{ usageRightsIndication is same as(true) ? "true" : (usageRightsIndication is same as(false) ? "false" : "") }}',
		],
		'reverseMapping' => [
			'identifier' => '{{ identificatie }}',
			'sourceOrganisation' => '{{ bronorganisatie }}',
			'creationDate' => '{{ creatiedatum }}',
			'title' => '{{ titel }}',
			'confidentiality' => '{{ vertrouwelijkheidaanduiding }}',
			'author' => '{{ auteur }}',
			'status' => '{{ status | default("") | zgw_enum_reverse("status", _valueMappings) }}',
			'format' => '{{ formaat }}',
			'language' => '{{ taal }}',
			'fileName' => '{{ bestandsnaam }}',
			'fileSize' => '{{ bestandsomvang }}',
			'link' => '{{ link }}',
			'description' => '{{ beschrijving }}',
			'documentType' => '{{ informatieobjecttype | zgw_extract_uuid }}',
			'usageRightsIndication' => '{{ indicatieGebruiksrecht }}',
		],
		'valueMapping' => [
			'confidentiality' => DefaultMappingTables::CONFIDENTIALITY_VALUES,
			// The KEYS are what the register stores and the values are what
			// ZGW sends. This table used to map Dutch to Dutch, while the
			// document schema declares in_bewerking, for_determination, final
			// and archived, so three of ZGW's four statuses could not be
			// stored at all and answered 400 'should be one of'.
			'status' => [
				'in_bewerking' => 'in_bewerking',
				'for_determination' => 'ter_vaststelling',
				'final' => 'definitief',
				'archived' => 'gearchiveerd',
			],
		],
		'reverseCast' => [
			'fileSize' => 'int',
		],
		'cast' => [
			'bestandsomvang' => 'int',
			'locked' => 'bool',
			'indicatieGebruiksrecht' => '?bool',
		],
		'queryParameterMapping' => [
			'informatieobjecttype' => [
				'field' => 'documentType',
				'extractUuid' => true,
			],
			'bronorganisatie' => [
				'field' => 'sourceOrganisation',
			],
		],
	];

	/**
	 * The default mapping for ObjectInformatieObject (documentLink).
	 *
	 * @var array<string, mixed>
	 */
	public const OBJECT_INFORMATIE_OBJECT = [
		'zgwResource' => 'objectinformatieobject',
		'propertyMapping' => [
			'informatieobject' => '{{ document }}',
			'object' => '{{ object }}',
			'objectType' => '{{ objectType | default("") | zgw_enum("objectType", _valueMappings) }}',
		],
		'reverseMapping' => [
			'document' => '{{ informatieobject }}',
			'object' => '{{ object }}',
			'objectType' => '{{ objectType | default("") | zgw_enum_reverse("objectType", _valueMappings) }}',
		],
		// This table existed and nothing used it, because neither template
		// called zgw_enum. It also mapped 'zaak' to 'zaak' and 'decision'
		// to 'decision', while the register stores 'case' and 'decision'
		// and ZGW spells them 'zaak' and 'besluit'. Neither of ZGW's two
		// values could be stored.
		'valueMapping' => [
			'objectType' => [
				'case' => 'zaak',
				'decision' => 'besluit',
			],
		],
		'queryParameterMapping' => [
			'informatieobject' => [
				'field' => 'document',
			],
			'object' => [
				'field' => 'object',
			],
		],
	];

	/**
	 * The default mapping for GebruiksRechten (usageRights).
	 *
	 * @var array<string, mixed>
	 */
	public const GEBRUIKSRECHTEN = [
		'zgwResource' => 'gebruiksrechten',
		'propertyMapping' => [
			'informatieobject' => '{{ document }}',
			'startdatum' => '{{ startDate }}',
			'endDate' => '{{ endDate }}',
			'omschrijvingVoorwaarden' => '{{ conditionsDescription }}',
		],
		'reverseMapping' => [
			'document' => '{{ informatieobject }}',
			'startDate' => '{{ startdatum }}',
			'endDate' => '{{ einddatum }}',
			'conditionsDescription' => '{{ omschrijvingVoorwaarden }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_DOCUMENT,
	];

	/**
	 * The default mapping for BesluitInformatieObject (decision document link).
	 *
	 * @var array<string, mixed>
	 */
	public const BESLUITINFORMATIEOBJECT = [
		'zgwResource' => 'besluitinformatieobject',
		'propertyMapping' => [
			'decision' => '{{ _baseUrl | replace({"besluiten/v1/besluitinformatieobjecten": "besluiten/v1/besluiten"}) }}/{{ decision }}',
			'informatieobject' => '{{ document }}',
		],
		'reverseMapping' => [
			'decision' => '{{ besluit | zgw_extract_uuid }}',
			'document' => '{{ informatieobject }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => [
			'decision' => [
				'field' => 'decision',
				'extractUuid' => true,
			],
			'informatieobject' => [
				'field' => 'document',
			],
		],
	];

	/**
	 * The default mapping for Verzending (dispatch).
	 *
	 * @var array<string, mixed>
	 */
	public const VERZENDING = [
		'zgwResource' => 'verzending',
		'propertyMapping' => [
			'informatieobject' => '{{ document }}',
			'betrokkene' => '{{ involvedParty }}',
			'aardRelatie' => '{{ relationshipType }}',
			'notes' => '{{ description }}',
			'receiptDate' => '{{ receiveDate }}',
			'verzenddatum' => '{{ sendDate }}',
			'contactPersoon' => '{{ contactPerson }}',
			'contactpersoonnaam' => '{{ contactPersonName }}',
		],
		'reverseMapping' => [
			'document' => '{{ informatieobject }}',
			'involvedParty' => '{{ betrokkene }}',
			'relationshipType' => '{{ aardRelatie }}',
			'description' => '{{ toelichting }}',
			'receiveDate' => '{{ ontvangstdatum }}',
			'sendDate' => '{{ verzenddatum }}',
			'contactPerson' => '{{ contactPersoon }}',
			'contactPersonName' => '{{ contactpersoonnaam }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => DefaultMappingTables::QUERY_BY_DOCUMENT,
	];

	/**
	 * Get the mapping metadata for Applicatie (Consumer entity).
	 *
	 * @var array<string, mixed>
	 */
	public const APPLICATIE = [
		'zgwResource' => 'applicatie',
		'zgwApiVersion' => '1',
		'enabled' => true,
		'fieldMapping' => [
			'clientIds[0]' => 'name',
			'label' => 'description',
			'uuid' => 'uuid',
			'heeftAlleAutorisaties' => 'authorizationConfiguration.superuser',
			'autorisaties' => 'authorizationConfiguration.scopes',
		],
	];

	/**
	 * The default mapping for Kanaal (notification channel).
	 *
	 * @var array<string, mixed>
	 */
	public const CHANNEL = [
		'zgwResource' => 'kanaal',
		'propertyMapping' => [
			// Inverted before this: the keys are ZGW field names, which are
			// `naam` and `documentatieLink`, and the templates read register
			// properties, which are `name` and `documentationLink`. Both
			// halves pointed the wrong way, so the NRC kanalen response
			// carried two fields ZGW does not define, both empty.
			'naam' => '{{ name }}',
			'documentatieLink' => '{{ documentationLink }}',
			'filters' => '{{ filters }}',
		],
		'cast' => [
			'filters' => 'jsonToArray',
		],
		'reverseMapping' => [
			'name' => '{{ naam }}',
			'documentationLink' => '{{ documentatieLink }}',
			'filters' => '{{ filters|json_encode|raw }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => [
			'name' => [
				'field' => 'name',
			],
		],
	];

	/**
	 * The default mapping for Abonnement (notification subscription).
	 *
	 * @var array<string, mixed>
	 */
	public const ABONNEMENT = [
		'zgwResource' => 'abonnement',
		'propertyMapping' => [
			'callbackUrl' => '{{ callbackUrl }}',
			'auth' => '{{ auth }}',
			'kanalen' => '{{ kanalen }}',
		],
		'cast' => [
			'kanalen' => 'jsonToArray',
		],
		'reverseMapping' => [
			'callbackUrl' => '{{ callbackUrl }}',
			'auth' => '{{ auth }}',
			'kanalen' => '{{ kanalen|json_encode|raw }}',
		],
		'valueMapping' => [],
		'queryParameterMapping' => [],
	];
}//end class
