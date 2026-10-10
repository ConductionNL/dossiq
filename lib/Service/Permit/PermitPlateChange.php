<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Permit;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * A resident asks for a new licence plate on a permit they hold, and a case opens for it.
 *
 * The portal never writes the permit (portal-permits-as-held-products D3):
 * enforcement reads it, so a handler stands between the resident and the
 * plate. The request opens a case of the type the permit's own case type
 * names in `issuesPermit.changeCaseType`, with the permit and the new plate
 * as its answers. The permit stays as it is until that case is decided.
 *
 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
 */
class PermitPlateChange {
	use SearchesObjects;

	/**
	 * A Dutch licence plate without dashes: six letters and digits.
	 */
	private const PLATE = '/^[A-Z0-9]{6}$/';

	/**
	 * @param SettingsService $settingsService Register, schemas and OpenRegister.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Open the change case.
	 *
	 * @param string $subjectRef The resident, from the verified assertion.
	 * @param string $permitId   The permit the resident named.
	 * @param string $plate      The new plate as typed.
	 *
	 * @return array{caseId: string, identifier: string} The case opened.
	 *
	 * @throws PermitChangeRefused When the request is unusable, the permit is not the resident's
	 *                             or not in force, or the register or case type is missing.
	 *
	 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
	 */
	public function request(string $subjectRef, string $permitId, string $plate): array {
		$plate = self::normalisePlate(plate: $plate);
		if (trim($subjectRef) === '' || trim($permitId) === '' || preg_match(self::PLATE, $plate) !== 1) {
			throw new PermitChangeRefused(PermitChangeRefused::INVALID, 'A permit and a licence plate of six letters and digits are needed.');
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			throw new PermitChangeRefused(PermitChangeRefused::UNAVAILABLE, 'OpenRegister or the dossiq register is missing.');
		}

		try {
			return $this->openFor(objectService: $objectService, register: $register, subjectRef: $subjectRef, permitId: $permitId, plate: $plate);
		} catch (PermitChangeRefused $refused) {
			throw $refused;
		} catch (Throwable $e) {
			throw new PermitChangeRefused(PermitChangeRefused::UNAVAILABLE, 'The register could not be read: ' . $e->getMessage());
		}
	}//end request()

	/**
	 * Check the permit and open the change case.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $subjectRef    The resident.
	 * @param string $permitId      The permit.
	 * @param string $plate         The new plate, normalised.
	 *
	 * @return array{caseId: string, identifier: string} The case opened.
	 *
	 * @throws PermitChangeRefused When the permit is not the resident's, not in force, or has no change type.
	 */
	private function openFor(object $objectService, string $register, string $subjectRef, string $permitId, string $plate): array {
		$permit = $this->read(objectService: $objectService, register: $register, schema: 'permit', id: $permitId);
		if ($permit === null || (string)($permit['portalSubject'] ?? '') !== $subjectRef) {
			// Someone else's permit answers exactly like a missing one.
			throw new PermitChangeRefused(PermitChangeRefused::NOT_FOUND, 'No such permit.');
		}

		if ((string)($permit['status'] ?? 'active') !== 'active') {
			throw new PermitChangeRefused(PermitChangeRefused::NOT_ACTIVE, 'This permit is not in force.');
		}

		$changeType = $this->changeCaseType(objectService: $objectService, register: $register, permit: $permit);

		return $this->openCase(
			objectService: $objectService,
			register: $register,
			changeType: $changeType,
			subjectRef: $subjectRef,
			permit: $permit,
			permitId: $permitId,
			plate: $plate
		);
	}//end openFor()

	/**
	 * A plate as stored: upper case, no dashes or spaces.
	 *
	 * @param string $plate The plate as typed.
	 *
	 * @return string The plate.
	 *
	 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
	 */
	public static function normalisePlate(string $plate): string {
		return strtoupper((string)preg_replace('/[\s-]+/', '', $plate));
	}//end normalisePlate()

	/**
	 * The case type a change of this permit opens.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $permit        The permit.
	 *
	 * @return array<string, mixed> The change case type.
	 *
	 * @throws PermitChangeRefused UNAVAILABLE when the permit's case type names none.
	 */
	private function changeCaseType(object $objectService, string $register, array $permit): array {
		$case = $this->read(objectService: $objectService, register: $register, schema: 'case', id: (string)($permit['case'] ?? ''));
		$type = $this->read(objectService: $objectService, register: $register, schema: 'caseType', id: (string)(($case ?? [])['caseType'] ?? ''));
		$changeId = (string)((($type ?? [])['issuesPermit'] ?? [])['changeCaseType'] ?? '');
		$changeType = $this->read(objectService: $objectService, register: $register, schema: 'caseType', id: $changeId);
		if ($changeType === null) {
			throw new PermitChangeRefused(PermitChangeRefused::UNAVAILABLE, 'No case type takes a change of this permit.');
		}

		return $changeType;
	}//end changeCaseType()

	/**
	 * Write the change case.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $changeType    The change case type.
	 * @param string               $subjectRef    The resident.
	 * @param array<string, mixed> $permit        The permit.
	 * @param string               $permitId      The permit's id.
	 * @param string               $plate         The new plate, normalised.
	 *
	 * @return array{caseId: string, identifier: string} The case.
	 *
	 * @throws PermitChangeRefused UNAVAILABLE when the write fails.
	 */
	private function openCase(
		object $objectService,
		string $register,
		array $changeType,
		string $subjectRef,
		array $permit,
		string $permitId,
		string $plate,
	): array {
		$changeTypeId = (string)($changeType['id'] ?? '');
		$case = [
			'title' => 'Kenteken wijzigen: ' . (string)($permit['title'] ?? ''),
			// The handler reads the request even when the case type declares
			// no property for it.
			'description' => 'Nieuw kenteken ' . $plate . ' voor vergunning ' . $permitId . '.',
			'caseType' => $changeTypeId,
			'startDate' => date('Y-m-d'),
			'portalSubject' => $subjectRef,
			'portalParty' => 'subject:' . $subjectRef,
			'intakeChannel' => 'website',
		];
		$properties = $this->answers(
			objectService: $objectService,
			register: $register,
			caseTypeId: $changeTypeId,
			answers: ['permit' => $permitId, 'nieuwKenteken' => $plate]
		);
		if ($properties !== []) {
			$case['properties'] = $properties;
		}

		$initial = trim((string)($changeType['initialStatus'] ?? ''));
		if ($initial !== '') {
			$case['status'] = $initial;
		}

		try {
			$saved = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->saveObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $this->settingsService->getConfigValue('case_schema', 'case'),
					object: $case
				)
			);
		} catch (Throwable $e) {
			$saved = null;
		}

		$caseId = (string)(($saved ?? [])['id'] ?? ((($saved ?? [])['@self'] ?? [])['id'] ?? ''));
		if ($caseId === '') {
			throw new PermitChangeRefused(PermitChangeRefused::UNAVAILABLE, 'The change case was not written.');
		}

		return ['caseId' => $caseId, 'identifier' => (string)(($saved ?? [])['identifier'] ?? '')];
	}//end openCase()

	/**
	 * The case's property entries for the answers the change case type declares.
	 *
	 * @param object                $objectService OpenRegister's object service.
	 * @param string                $register      The register.
	 * @param string                $caseTypeId    The change case type.
	 * @param array<string, string> $answers       The answer per property name.
	 *
	 * @return array<int, array<string, string>> `{propertyDefinition, name, value}` per declared answer.
	 */
	private function answers(object $objectService, string $register, string $caseTypeId, array $answers): array {
		$schema = $this->settingsService->getConfigValue('property_definition_schema');
		if ($schema === '' || $caseTypeId === '') {
			return [];
		}

		$rows = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseTypeId],
			)
		);

		$entries = [];
		foreach ((array)$rows as $row) {
			$name = trim((string)($row['name'] ?? ''));
			$id = trim((string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? '')));
			if ($id !== '' && isset($answers[$name]) === true) {
				$entries[] = ['propertyDefinition' => $id, 'name' => $name, 'value' => $answers[$name]];
			}
		}

		return $entries;
	}//end answers()

	/**
	 * Read one object as the system, or null.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $schema        The schema slug.
	 * @param string $id            The object id.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function read(object $objectService, string $register, string $schema, string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		// A missing object answers null (findObjectAsArray); any other failure
		// reaches request(), which refuses as unavailable.
		$found = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id)
		);

		if (is_array($found) === false) {
			return null;
		}

		return $found;
	}//end read()
}//end class
