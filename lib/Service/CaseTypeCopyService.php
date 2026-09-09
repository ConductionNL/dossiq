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

use OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload;
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
	 * @param SettingsService        $settingsService Shared OR register/schema resolver.
	 * @param CaseTypeStore          $store           The app's one row and reference normaliser.
	 * @param DerivedCaseTypePayload $payloads        What a duplicate and a version look like.
	 * @param LoggerInterface        $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $store,
		private readonly DerivedCaseTypePayload $payloads,
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
		return $this->derive(caseTypeId: $caseTypeId, asVersion: false);
	}//end copy()

	/**
	 * Start a new version of a published case type.
	 *
	 * A version is NOT a duplicate, and the difference is the whole point.
	 * A duplicate is a second case type: new title, new identifier, no relation
	 * to the first. A version is the SAME case type later on, so it keeps the
	 * title and the identifier (ZGW's `identificatie` is what makes two rows
	 * versions of one zaaktype) and gains a link back to the version it came
	 * from.
	 *
	 * 🔴 THE POINT OF MINTING AN OBJECT RATHER THAN EDITING ONE IS THE CASES
	 * THAT ARE ALREADY RUNNING. Every case carries `caseType` as the id of one
	 * specific row, and its `status` is a statusType owned by that row. Editing
	 * a published case type in place reaches every case of that type
	 * immediately, which is why the page's own banner ("changes will only apply
	 * to new cases") was not true. Copying instead leaves the running cases on
	 * the objects they started under, and pins them there with no extra field
	 * on the case: the reference they already hold IS the pin. Nothing migrates
	 * a running case forward, deliberately. Its current status is a row the new
	 * version does not contain, and its deadline was computed from the old
	 * version's `processingDeadline`.
	 *
	 * The new version starts as a draft. It becomes the version new cases get
	 * when it is published, which is when {@see CaseTypePublishService} writes
	 * `supersededBy` onto the version it replaces.
	 *
	 * @param string $caseTypeId The case type to make the next version of.
	 *
	 * @return array<string, mixed>|null The new draft version, or `null` when
	 *                                   the source does not resolve.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function newVersion(string $caseTypeId): ?array {
		return $this->derive(caseTypeId: $caseTypeId, asVersion: true);
	}//end newVersion()

	/**
	 * Make a new case type out of an existing one.
	 *
	 * The two gestures share every mechanic and differ only in what the
	 * payload says: a duplicate renames and re-identifies and starts its own
	 * chain, a version keeps both and links back. Sharing the mechanics is the
	 * point rather than a tidiness: the initial-status repointing below was
	 * missing from the copy path for as long as it had its own copy of this,
	 * and two paths would have meant fixing it twice.
	 *
	 * @param string  $caseTypeId The source case type's id.
	 * @param boolean $asVersion  True for the next version, false for a duplicate.
	 *
	 * @return array<string, mixed>|null The new case type, or `null` when the
	 *                                   source does not resolve.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	private function derive(string $caseTypeId, bool $asVersion): ?array {
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

		$payload = $this->payloads->duplicate(source: $source);
		if ($asVersion === true) {
			$payload = $this->payloads->nextVersion(source: $source, sourceId: $caseTypeId);
		}

		$created = $this->saveNew(
			objectService: $objectService,
			register: $register,
			schema: $caseTypeSchema,
			payload: $payload,
			caseTypeId: $caseTypeId
		);
		if ($created === null) {
			return null;
		}

		$newCaseTypeId = (string)($created['id'] ?? '');
		if ($newCaseTypeId === '') {
			return null;
		}

		$statusMap = $this->copyEveryChild(
			objectService: $objectService,
			register: $register,
			sourceCaseTypeId: $caseTypeId,
			newCaseTypeId: $newCaseTypeId
		);

		$created = $this->repointInitialStatus(
			objectService: $objectService,
			register: $register,
			schema: $caseTypeSchema,
			caseType: $created,
			statusMap: $statusMap
		);

		$this->logger->info(
			'CaseTypeCopyService: derived a new case type',
			[
				'source' => $caseTypeId,
				'created' => $newCaseTypeId,
				'asVersion' => $asVersion,
				'version' => (int)($created['version'] ?? 0),
			]
		);

		return $created;
	}//end derive()

	/**
	 * Save a payload as a new object, answering null when the write fails.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The register slug.
	 * @param string               $schema        The case type schema id.
	 * @param array<string, mixed> $payload       The object to create.
	 * @param string               $caseTypeId    The source id, for the log line.
	 *
	 * @return array<string, mixed>|null The created object.
	 */
	private function saveNew(
		object $objectService,
		string $register,
		string $schema,
		array $payload,
		string $caseTypeId,
	): ?array {
		try {
			$created = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseTypeCopyService: failed to create the new case type',
				['caseTypeId' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->store->asRow(value: $created);
	}//end saveNew()

	/**
	 * Copy every owned sub-schema, and answer the status id map.
	 *
	 * @param object $objectService    The OpenRegister ObjectService.
	 * @param string $register         The register slug.
	 * @param string $sourceCaseTypeId The source case type's id.
	 * @param string $newCaseTypeId    The new case type's id.
	 *
	 * @return array<string, string> Old status id to new status id.
	 */
	private function copyEveryChild(
		object $objectService,
		string $register,
		string $sourceCaseTypeId,
		string $newCaseTypeId,
	): array {
		$statusMap = [];
		foreach (self::CHILD_SCHEMA_CONFIG_KEYS as $configKey) {
			$copied = $this->copyChildren(
				objectService: $objectService,
				register: $register,
				configKey: $configKey,
				sourceCaseTypeId: $sourceCaseTypeId,
				newCaseTypeId: $newCaseTypeId
			);

			if ($configKey === 'status_type_schema') {
				$statusMap = $copied;
			}
		}

		return $statusMap;
	}//end copyEveryChild()

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
	 * Point the new case type's initial status at its OWN copy of that status.
	 *
	 * Without this the copy files new cases into the SOURCE's status row: the
	 * children are copied but `initialStatus` still holds the old id, and the
	 * two are never reconciled. Publish validation catches it (the initial
	 * status is not one of the type's own statuses) so it never reached a
	 * running case, but it made every duplicate and every new version ask the
	 * author to re-pick a status they had already picked.
	 *
	 * @param object                $objectService The OpenRegister ObjectService.
	 * @param string                $register      The register slug.
	 * @param string                $schema        The case type schema id.
	 * @param array<string, mixed>  $caseType      The freshly created case type.
	 * @param array<string, string> $statusMap     Old status id to new status id.
	 *
	 * @return array<string, mixed> The case type, repointed when it needed it.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	private function repointInitialStatus(
		object $objectService,
		string $register,
		string $schema,
		array $caseType,
		array $statusMap,
	): array {
		$initial = $this->store->referenceId(value: ($caseType['initialStatus'] ?? ''));
		if ($initial === '' || isset($statusMap[$initial]) === false) {
			return $caseType;
		}

		$caseType['initialStatus'] = $statusMap[$initial];

		try {
			$saved = $objectService->saveObject(
				object: $caseType,
				register: $register,
				schema: $schema,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseTypeCopyService: could not repoint the initial status',
				['caseType' => ($caseType['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return $caseType;
		}

		return $this->store->asRow(value: $saved);
	}//end repointInitialStatus()


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
	 * @return array<string, string> Old child id to new child id, for the
	 *                               children that copied. Callers use it to
	 *                               repoint references the parent holds.
	 */
	private function copyChildren(
		object $objectService,
		string $register,
		string $configKey,
		string $sourceCaseTypeId,
		string $newCaseTypeId,
	): array {
		$schema = $this->settingsService->getConfigValue($configKey);
		if ($schema === '') {
			return [];
		}

		$children = $this->findChildren(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			caseTypeId: $sourceCaseTypeId
		);

		$map = [];
		foreach ($children as $child) {
			$oldId = $this->store->referenceId(value: ($child['id'] ?? ''));
			$payload = $this->stripIdentity(data: $child);
			$payload['caseType'] = $newCaseTypeId;

			try {
				$created = $objectService->saveObject(
					object: $payload,
					register: $register,
					schema: $schema,
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'CaseTypeCopyService: failed to copy child object',
					['schema' => $schema, 'exception' => $e->getMessage()]
				);
				continue;
			}

			$newId = $this->store->referenceId(value: ($this->store->asRow(value: $created)['id'] ?? ''));
			if ($oldId !== '' && $newId !== '') {
				$map[$oldId] = $newId;
			}
		}//end foreach

		return $map;
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
			fn ($result): array => $this->store->asRow(value: $result),
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

		return $this->store->asRow(value: $obj);
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


}//end class
