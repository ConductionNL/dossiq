<?php

/**
 * Dossiq Case Type Copy Service
 *
 * Deep-copies an existing case type (zaaktype) definition -- and every
 * owned sub-object (status types, result types, role types, property
 * definitions, document types, decision types) -- into a brand-new draft.
 * Also guards case-type deletion to draft-status definitions only.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 *
 * @spec openspec/changes/zaaktype-copy/tasks.md#T01
 * @spec openspec/changes/zaaktype-copy/tasks.md#T02
 * @spec openspec/changes/zaaktype-copy/tasks.md#T03
 * @spec openspec/changes/zaaktype-copy/tasks.md#T04
 * @spec openspec/changes/zaaktype-copy/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for duplicating case type definitions and guarding their
 * deletion to draft-status definitions.
 *
 * @spec openspec/changes/zaaktype-copy/tasks.md#T01
 */
class CaseTypeCopyService {

	/**
	 * Config keys (resolved via {@see SettingsService::getConfigValue()})
	 * for every schema owned by a case type, i.e. filtered by a `caseType`
	 * foreign key on the child record.
	 *
	 * @var array<int, string>
	 */
	private const CHILD_SCHEMA_CONFIG_KEYS = [
		'status_type_schema',
		'result_type_schema',
		'role_type_schema',
		'property_definition_schema',
		'document_type_schema',
		'decision_type_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared OR register/schema resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Deep-copy a case type into a new draft.
	 *
	 * Copies the case type itself (new id, title prefixed "Copy of ",
	 * forced back to draft, publication fields cleared, workflow-version
	 * pin and sibling case-type links dropped) plus every owned
	 * sub-object, re-pointed at the new case type's id.
	 *
	 * @param string $caseTypeId The source case type's OpenRegister id.
	 *
	 * @return array<string, mixed>|null The newly created case type, or
	 *                                   `null` when the source does not
	 *                                   resolve (or OpenRegister is
	 *                                   unavailable / misconfigured).
	 *
	 * @spec openspec/changes/zaaktype-copy/tasks.md#T01
	 * @spec openspec/changes/zaaktype-copy/tasks.md#T02
	 * @spec openspec/changes/zaaktype-copy/tasks.md#T03
	 * @spec openspec/changes/zaaktype-copy/tasks.md#T04
	 */
	public function copy(string $caseTypeId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
		if ($register === '' || $caseTypeSchema === '') {
			return null;
		}

		$source = $this->fetchObject(
			objectService: $objectService,
			register: $register,
			schema: $caseTypeSchema,
			id: $caseTypeId
		);
		if ($source === null) {
			return null;
		}

		$payload = $this->buildCopyPayload(source: $source);

		try {
			$created = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $caseTypeSchema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseTypeCopyService: failed to create case type copy',
				['caseTypeId' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$newCaseType = $this->toArray(value: $created);
		$newCaseTypeId = (string)($newCaseType['id'] ?? '');
		if ($newCaseTypeId === '') {
			return null;
		}

		foreach (self::CHILD_SCHEMA_CONFIG_KEYS as $configKey) {
			$this->copyChildren(
				objectService: $objectService,
				register: $register,
				configKey: $configKey,
				sourceCaseTypeId: $caseTypeId,
				newCaseTypeId: $newCaseTypeId
			);
		}

		$this->logger->info(
			'CaseTypeCopyService: copied case type',
			['source' => $caseTypeId, 'copy' => $newCaseTypeId]
		);

		return $newCaseType;
	}//end copy()

	/**
	 * Delete a case type, but only when it is a draft.
	 *
	 * @param string $caseTypeId The case type's OpenRegister id.
	 *
	 * @return array{ok: bool, reason?: string} `reason` is one of
	 *                                          `not_found`, `published`,
	 *                                          or `error` when `ok` is
	 *                                          `false`.
	 *
	 * @spec openspec/changes/zaaktype-copy/tasks.md#T05
	 */
	public function deleteDraft(string $caseTypeId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['ok' => false, 'reason' => 'not_found'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
		if ($register === '' || $caseTypeSchema === '') {
			return ['ok' => false, 'reason' => 'not_found'];
		}

		$source = $this->fetchObject(
			objectService: $objectService,
			register: $register,
			schema: $caseTypeSchema,
			id: $caseTypeId
		);
		if ($source === null) {
			return ['ok' => false, 'reason' => 'not_found'];
		}

		if (($source['isDraft'] ?? false) !== true) {
			return ['ok' => false, 'reason' => 'published'];
		}

		try {
			$deleted = $objectService->deleteObject(
				uuid: $caseTypeId,
				register: $register,
				schema: $caseTypeSchema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseTypeCopyService: failed to delete draft case type',
				['caseTypeId' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return ['ok' => false, 'reason' => 'error'];
		}

		if ($deleted !== true) {
			return ['ok' => false, 'reason' => 'error'];
		}

		return ['ok' => true];
	}//end deleteDraft()

	/**
	 * Build the payload for the new case type: strips identity fields and
	 * resets the fields a duplicate must not blindly inherit.
	 *
	 * @param array<string, mixed> $source The source case type.
	 *
	 * @return array<string, mixed> The payload to save as a new object.
	 */
	private function buildCopyPayload(array $source): array {
		$payload = $this->stripIdentity(data: $source);

		$sourceTitle = (string)($source['title'] ?? '');
		$payload['title'] = 'Copy of ' . $sourceTitle;
		$payload['isDraft'] = true;
		$payload['identifier'] = $this->generateIdentifier(sourceIdentifier: (string)($source['identifier'] ?? ''));
		$payload['publicationRequired'] = false;
		if (array_key_exists('publicationText', $payload) === true) {
			$payload['publicationText'] = '';
		}

		// Versions reset: a copy does not inherit the source's pinned
		// workflow definition version.
		$payload['workflowDefinition'] = null;

		// A duplicate is a new definition, not a sibling of the source's
		// related/sub case types.
		$payload['relatedCaseTypes'] = [];
		$payload['subCaseTypes'] = [];

		return $payload;
	}//end buildCopyPayload()

	/**
	 * Copy every child object of one owned sub-schema, re-pointed at the
	 * new case type's id.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $configKey The `SettingsService` config key for
	 *                          the child schema.
	 * @param string $sourceCaseTypeId The source case type's id.
	 * @param string $newCaseTypeId The new case type's id.
	 *
	 * @return void
	 */
	private function copyChildren(
		object $objectService,
		string $register,
		string $configKey,
		string $sourceCaseTypeId,
		string $newCaseTypeId,
	): void {
		$schema = $this->settingsService->getConfigValue($configKey);
		if ($schema === '') {
			return;
		}

		$children = $this->findChildren(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			caseTypeId: $sourceCaseTypeId
		);

		foreach ($children as $child) {
			$payload = $this->stripIdentity(data: $child);
			$payload['caseType'] = $newCaseTypeId;

			try {
				$objectService->saveObject(
					object: $payload,
					register: $register,
					schema: $schema,
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'CaseTypeCopyService: failed to copy child object',
					['schema' => $schema, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach
	}//end copyChildren()

	/**
	 * Find every object of a schema owned by (filtered on `caseType` ==)
	 * a given case type.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema id.
	 * @param string $caseTypeId The owning case type's id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findChildren(
		object $objectService,
		string $register,
		string $schema,
		string $caseTypeId,
	): array {
		try {
			$results = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'caseType' => $caseTypeId,
					],
					'limit' => 500,
				],
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseTypeCopyService: failed to list child objects',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($results) === true && isset($results['results']) === true) {
			$results = $results['results'];
		}

		if (is_array($results) === false) {
			return [];
		}

		return array_map(
			fn ($result): array => $this->toArray(value: $result),
			$results
		);
	}//end findChildren()

	/**
	 * Fetch a single object by id, tolerating a missing ObjectService
	 * result (RBAC / not-found) by returning `null`.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema id.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetchObject(
		object $objectService,
		string $register,
		string $schema,
		string $id,
	): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$obj = $objectService->find($id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'CaseTypeCopyService: object lookup failed',
				['id' => $id, 'schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($obj === null) {
			return null;
		}

		return $this->toArray(value: $obj);
	}//end fetchObject()

	/**
	 * Strip identity metadata (`id`, `@self`) from an object array so that
	 * saving it creates a NEW object instead of updating the source.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return array<string, mixed>
	 */
	private function stripIdentity(array $data): array {
		unset($data['id'], $data['@self']);
		return $data;
	}//end stripIdentity()

	/**
	 * Generate a fresh, human-traceable identifier for a copy.
	 *
	 * @param string $sourceIdentifier The source case type's identifier.
	 *
	 * @return string
	 */
	private function generateIdentifier(string $sourceIdentifier): string {
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		if ($sourceIdentifier === '') {
			return 'CT-' . $suffix;
		}

		return $sourceIdentifier . '-copy-' . $suffix;
	}//end generateIdentifier()

	/**
	 * Normalise an OpenRegister entity (or array) into a plain array.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($value) === true) {
			return (array)$value;
		}

		return [];
	}//end toArray()
}//end class
