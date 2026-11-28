<?php

/**
 * Dossiq Dossier Records
 *
 * The case dossier as it is stored in the register: the zaakinformatieobject
 * joins between a case and its documents, the informatieobject records, and
 * the default confidentiality of a document type.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\InformatieobjectStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use RuntimeException;

/**
 * The joins and informatieobject records of a case dossier.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class DossierRecords {
	use SearchesObjects;

	/**
	 * The register, read and refused the one shared way.
	 *
	 * @var DossierRegister
	 */
	private readonly DossierRegister $dossierRegister;

	/**
	 * Constructor.
	 *
	 * @param SettingsService             $settingsService Settings service (config + ObjectService).
	 * @param InformatieobjectAccessGuard $accessGuard     Whether a submitted classification may override the type default.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly InformatieobjectAccessGuard $accessGuard,
	) {
		$this->dossierRegister = new DossierRegister(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Resolve the ObjectService and register, throwing when unavailable.
	 *
	 * @return array{0: object, 1: string} The object service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the register is not configured.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function context(): array {
		return $this->dossierRegister->context();
	}//end context()

	/**
	 * Where the informatieobject records are stored.
	 *
	 * @return InformatieobjectStore The object service, the register and the informatieobject schema.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the register is not configured.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function informatieobjects(): InformatieobjectStore {
		[$objectService, $register] = $this->context();

		return new InformatieobjectStore(
			objectService: $objectService,
			register: $register,
			infoSchema: $this->settingsService->getConfigValue('dossier_informatieobject_schema'),
		);
	}//end informatieobjects()

	/**
	 * Link an existing informatieobject to a case without duplicating the join.
	 *
	 * @param string $caseId       The case (zaak) UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed> The join summary.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function link(string $caseId, string $infoObjectId): array {
		[$objectService, $register] = $this->context();
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $joinSchema,
			filters: ['case' => $caseId, 'informatieobject' => $infoObjectId, '_limit' => 1],
		);

		if (empty($existing) === false) {
			return [
				'id' => ($existing[0]['id'] ?? ''),
				'case' => $caseId,
				'duplicated' => false,
			];
		}

		return $this->createJoin(caseId: $caseId, infoObjectId: $infoObjectId);
	}//end link()

	/**
	 * Unlink an informatieobject from a case: only the join records are deleted.
	 *
	 * @param string $caseId       The case (zaak) UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return bool True when at least one join was removed.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function unlink(string $caseId, string $infoObjectId): bool {
		[$objectService, $register] = $this->context();
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

		$joins = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $joinSchema,
			filters: ['case' => $caseId, 'informatieobject' => $infoObjectId, '_limit' => 100],
		);

		$removed = false;
		foreach ($joins as $join) {
			$joinId = (string)($join['id'] ?? ($join['uuid'] ?? ''));
			if ($joinId === '') {
				continue;
			}

			$objectService->deleteObject(new ObjectDeletion(uuid: $joinId, target: new ObjectFindTarget(register: $register, schema: $joinSchema)));
			$removed = true;
		}

		return $removed;
	}//end unlink()

	/**
	 * The informatieobjecten joined to a case.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return list<array<string, mixed>> The documents.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function documentsOf(string $caseId): array {
		[$objectService, $register] = $this->context();
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		$store = new InformatieobjectStore(
			objectService: $objectService,
			register: $register,
			infoSchema: $this->settingsService->getConfigValue('dossier_informatieobject_schema'),
		);

		$joins = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $joinSchema,
			filters: ['case' => $caseId, '_limit' => 500],
		);

		return $this->joinedDocuments(store: $store, joins: $joins);
	}//end documentsOf()

	/**
	 * The informatieobjecten the join rows point at, skipping rows without one and documents that do not resolve.
	 *
	 * @param InformatieobjectStore $store Where the informatieobjecten are read from.
	 * @param array<int, array<string, mixed>> $joins The zaakinformatieobject join rows.
	 *
	 * @return array<int, array<string, mixed>> The documents.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	private function joinedDocuments(InformatieobjectStore $store, array $joins): array {
		$documents = [];
		foreach ($joins as $join) {
			$infoId = (string)($join['informatieobject'] ?? '');
			if ($infoId === '') {
				continue;
			}

			$doc = $this->findObjectAsArray(objectService: $store->objectService, register: $store->register, schema: $store->infoSchema, id: $infoId);

			if ($doc !== null) {
				$documents[] = $doc;
			}
		}

		return $documents;
	}//end joinedDocuments()

	/**
	 * Fetch a single informatieobject as an array.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed>|null The record, or null when it does not resolve.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function find(string $infoObjectId): ?array {
		[$objectService, $register] = $this->context();
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $infoSchema,
			id: $infoObjectId,
		);
	}//end find()

	/**
	 * Apply only the given fields to a stored informatieobject.
	 *
	 * @param string               $infoObjectId The informatieobject UUID.
	 * @param array<string, mixed> $changes      The fields to set.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function patch(string $infoObjectId, array $changes): void {
		[$objectService, $register] = $this->context();
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

		$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $infoSchema, id: $infoObjectId, changes: $changes);
	}//end patch()

	/**
	 * The default classification of an informatieobjecttype, or `intern`.
	 *
	 * @param string $type The informatieobjecttype UUID.
	 *
	 * @return string The classification.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function defaultClassification(string $type): string {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$typeSchema = $this->settingsService->getConfigValue('dossier_informatieobjecttype_schema');

		if ($objectService === null || $register === '' || $typeSchema === '') {
			return 'intern';
		}

		$record = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $typeSchema,
			id: $type,
		);

		$level = (string)($record['vertrouwelijkheidaanduiding'] ?? '');
		if (in_array($level, InformatieobjectAccessGuard::HIERARCHY, true) === true) {
			return $level;
		}

		return 'intern';
	}//end defaultClassification()

	/**
	 * The confidentiality an upload gets: the submitted one, or the type default.
	 *
	 * @param string $type The document type uuid.
	 * @param array<string, mixed> $metadata The submitted metadata.
	 *
	 * @return string The classification.
	 *
	 * @throws \InvalidArgumentException When the submitted level is less restrictive than the default.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function uploadClassification(string $type, array $metadata): string {
		$defaultClass = $this->defaultClassification(type: $type);
		$classification = (string)($metadata['vertrouwelijkheidaanduiding'] ?? '');
		if ($classification === '') {
			$classification = $defaultClass;
		} elseif ($this->accessGuard->isClassificationAllowed($defaultClass, $classification) === false) {
			// REQ-ZAK-003d: a user may only override to a MORE restrictive level.
			throw new InvalidArgumentException(
				'Classification may not be less restrictive than the document type default'
			);
		}

		return $classification;
	}//end uploadClassification()

	/**
	 * Create a zaakinformatieobject join object.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed> The join summary.
	 */
	private function createJoin(string $caseId, string $infoObjectId): array {
		[$objectService, $register] = $this->context();
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

		$join = [
			'case' => $caseId,
			'informatieobject' => $infoObjectId,
			'natureRelationshipDisplay' => 'Hoort at omgekeerd',
			'registrationDate' => date('Y-m-d\TH:i:s\Z'),
		];

		$saved = $objectService->saveObject(
			object: $join,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $joinSchema))
		);
		$joinId = $this->savedUuid(saved: $saved);

		return [
			'id' => $joinId,
			'case' => $caseId,
			'informatieobject' => $infoObjectId,
			'duplicated' => true,
		];
	}//end createJoin()

	/**
	 * Read the UUID out of whatever `ObjectService::saveObject()` returned.
	 *
	 * OpenRegister returns an ObjectEntity when the register is live and a
	 * plain array in the array-mode/test paths, so both shapes are handled.
	 * The UUID MUST come from the SAVED result — the input payload never
	 * carries an `id`, so reading it back from the payload always yielded ''.
	 *
	 * `is_callable()` rather than `method_exists()`: ObjectEntity declares
	 * `uuid` as a protected property and exposes `getUuid()` only through
	 * `OCP\AppFramework\Db\Entity::__call()`. `method_exists()` does not see
	 * magic methods and would report false for every live object, silently
	 * dropping this to the array branch and returning ''. `is_callable()`
	 * accounts for `__call()`, and `call_user_func()` keeps the invocation
	 * resolvable for static analysis.
	 *
	 * @param mixed $saved The saveObject() return value.
	 *
	 * @return string The saved object UUID, or '' when it cannot be resolved.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function savedUuid(mixed $saved): string {
		if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {
			return (string)call_user_func([$saved, 'getUuid']);
		}

		$row = (array)$saved;
		$self = (array)($row['@self'] ?? []);
		return (string)($row['id'] ?? ($self['id'] ?? ''));
	}//end savedUuid()
}//end class
