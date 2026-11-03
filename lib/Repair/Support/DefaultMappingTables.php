<?php

/**
 * Dossiq DefaultMappingTables.
 *
 * Every default mapping by resource key, with the setting that names its source schema, and the values the mappings share.
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
 * Every default mapping by resource key, with the setting that names its source schema, and the values the mappings share.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class DefaultMappingTables {
	/**
	 * A list query on `zaak` filters on the case, by its uuid.
	 */
	public const QUERY_BY_CASE = ['zaak' => ['field' => 'case', 'extractUuid' => true]];

	/**
	 * A list query on `caseType` filters on the case type, by its uuid.
	 */
	public const QUERY_BY_CASE_TYPE = ['caseType' => ['field' => 'caseType', 'extractUuid' => true]];

	/**
	 * A list query on `informatieobject` filters on the document.
	 */
	public const QUERY_BY_DOCUMENT = ['informatieobject' => ['field' => 'document']];

	/**
	 * `vertrouwelijkheidaanduiding` is the same word on both sides, so each value maps to itself.
	 */
	public const CONFIDENTIALITY_VALUES = [
		'openbaar' => 'openbaar',
		'beperkt_openbaar' => 'beperkt_openbaar',
		'intern' => 'intern',
		'zaakvertrouwelijk' => 'zaakvertrouwelijk',
		'vertrouwelijk' => 'vertrouwelijk',
		'confidentieel' => 'confidentieel',
		'geheim' => 'geheim',
		'zeer_geheim' => 'zeer_geheim',
	];

	/**
	 * Each resource key's default mapping, and the setting that names its source schema (null: no source).
	 *
	 * @var array<string, array{schemaSetting: string|null, mapping: array<string, mixed>}>
	 */
	public const BY_RESOURCE = [
		'catalogus' => ['schemaSetting' => 'catalogus_schema', 'mapping' => CatalogueResourceMappings::CATALOGUS],
		'zaak' => ['schemaSetting' => 'case_schema', 'mapping' => CaseResourceMappings::CASE],
		'caseType' => ['schemaSetting' => 'case_type_schema', 'mapping' => CatalogueResourceMappings::CASE_TYPE],
		'status' => ['schemaSetting' => 'status_record_schema', 'mapping' => CaseResourceMappings::STATUS],
		'statustype' => ['schemaSetting' => 'status_type_schema', 'mapping' => CatalogueResourceMappings::STATUS_TYPE],
		'result' => ['schemaSetting' => 'result_schema', 'mapping' => CaseResourceMappings::RESULT],
		'resultaattype' => ['schemaSetting' => 'result_type_schema', 'mapping' => CatalogueResourceMappings::RESULT_TYPE],
		'role' => ['schemaSetting' => 'role_schema', 'mapping' => CaseResourceMappings::ROLE],
		'roltype' => ['schemaSetting' => 'role_type_schema', 'mapping' => CatalogueResourceMappings::ROLE_TYPE],
		'eigenschap' => ['schemaSetting' => 'property_definition_schema', 'mapping' => CatalogueResourceMappings::ATTRIBUTE],
		'decision' => ['schemaSetting' => 'decision_schema', 'mapping' => DocumentResourceMappings::DECISION],
		'besluittype' => ['schemaSetting' => 'decision_type_schema', 'mapping' => CatalogueResourceMappings::DECISION_TYPE],
		'informatieobjecttype' => ['schemaSetting' => 'document_type_schema', 'mapping' => CatalogueResourceMappings::INFORMATIE_OBJECT_TYPE],
		'zaaktypeinformatieobjecttype' => [
			'schemaSetting' => 'zaaktype_informatieobjecttype_schema',
			'mapping' => CatalogueResourceMappings::CASE_TYPE_INFORMATIEOBJECTTYPE,
		],
		'enkelvoudiginformatieobject' => ['schemaSetting' => 'document_schema', 'mapping' => DocumentResourceMappings::ENKELVOUDIG_INFORMATIE_OBJECT],
		'objectinformatieobject' => ['schemaSetting' => 'document_link_schema', 'mapping' => DocumentResourceMappings::OBJECT_INFORMATIE_OBJECT],
		'gebruiksrechten' => ['schemaSetting' => 'usage_rights_schema', 'mapping' => DocumentResourceMappings::GEBRUIKSRECHTEN],
		'zaakeigenschap' => ['schemaSetting' => 'case_property_schema', 'mapping' => CaseResourceMappings::ZAAKEIGENSCHAP],
		'zaakinformatieobject' => ['schemaSetting' => 'case_document_schema', 'mapping' => CaseResourceMappings::ZAAKINFORMATIEOBJECT],
		'zaakobject' => ['schemaSetting' => 'case_object_schema', 'mapping' => CaseResourceMappings::ZAAKOBJECT],
		'klantcontact' => ['schemaSetting' => 'customer_contact_schema', 'mapping' => CaseResourceMappings::KLANTCONTACT],
		'besluitinformatieobject' => ['schemaSetting' => 'decision_document_schema', 'mapping' => DocumentResourceMappings::BESLUITINFORMATIEOBJECT],
		'verzending' => ['schemaSetting' => 'dispatch_schema', 'mapping' => DocumentResourceMappings::VERZENDING],
		'applicatie' => ['schemaSetting' => null, 'mapping' => DocumentResourceMappings::APPLICATIE],
		'kanaal' => ['schemaSetting' => 'kanaal_schema', 'mapping' => DocumentResourceMappings::CHANNEL],
		'abonnement' => ['schemaSetting' => 'abonnement_schema', 'mapping' => DocumentResourceMappings::ABONNEMENT],
	];
}//end class
