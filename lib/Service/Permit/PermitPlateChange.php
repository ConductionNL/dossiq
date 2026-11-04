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
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PermitPlateChange {
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
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function request(string $subjectRef, string $permitId, string $plate): array {
		$request = new PlateChangeRequest(subjectRef: $subjectRef, permitId: $permitId, plate: self::normalisePlate(plate: $plate));
		if ($request->isUsable() === false) {
			throw new PermitChangeRefused(PermitChangeRefused::INVALID, 'A permit and a licence plate of six letters and digits are needed.');
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			throw new PermitChangeRefused(PermitChangeRefused::UNAVAILABLE, 'OpenRegister or the dossiq register is missing.');
		}

		try {
			return $this->openFor(store: new PermitRegister(objectService: $objectService, register: $register), request: $request);
		} catch (PermitChangeRefused $refused) {
			throw $refused;
		} catch (Throwable $e) {
			throw new PermitChangeRefused(PermitChangeRefused::UNAVAILABLE, 'The register could not be read: ' . $e->getMessage());
		}
	}//end request()

	/**
	 * Check the permit and open the change case.
	 *
	 * @param PermitRegister     $store   The register.
	 * @param PlateChangeRequest $request The resident's request.
	 *
	 * @return array{caseId: string, identifier: string} The case opened.
	 *
	 * @throws PermitChangeRefused When the permit is not the resident's, not in force, or has no change type.
	 */
	private function openFor(PermitRegister $store, PlateChangeRequest $request): array {
		$permit = $store->read(schema: 'permit', id: $request->permitId);
		if ($request->isHeldBySubject(permit: $permit) === false) {
			// Someone else's permit answers exactly like a missing one.
			throw new PermitChangeRefused(PermitChangeRefused::NOT_FOUND, 'No such permit.');
		}

		if ((string)($permit['status'] ?? 'active') !== 'active') {
			throw new PermitChangeRefused(PermitChangeRefused::NOT_ACTIVE, 'This permit is not in force.');
		}

		$change = new PlateChange(request: $request, permit: $permit, changeType: $this->changeCaseType(store: $store, permit: $permit));

		return $this->openCase(store: $store, change: $change);
	}//end openFor()

	/**
	 * A plate as stored: upper case, no dashes or spaces.
	 *
	 * @param string $plate The plate as typed.
	 *
	 * @return string The plate.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public static function normalisePlate(string $plate): string {
		return strtoupper((string)preg_replace('/[\s-]+/', '', $plate));
	}//end normalisePlate()

	/**
	 * The case type a change of this permit opens.
	 *
	 * @param PermitRegister       $store  The register.
	 * @param array<string, mixed> $permit The permit.
	 *
	 * @return array<string, mixed> The change case type.
	 *
	 * @throws PermitChangeRefused UNAVAILABLE when the permit's case type names none.
	 */
	private function changeCaseType(PermitRegister $store, array $permit): array {
		$case = $store->read(schema: 'case', id: (string)($permit['case'] ?? ''));
		$type = $store->read(schema: 'caseType', id: (string)(($case ?? [])['caseType'] ?? ''));
		$changeId = (string)((($type ?? [])['issuesPermit'] ?? [])['changeCaseType'] ?? '');
		$changeType = $store->read(schema: 'caseType', id: $changeId);
		if ($changeType === null) {
			throw new PermitChangeRefused(PermitChangeRefused::UNAVAILABLE, 'No case type takes a change of this permit.');
		}

		return $changeType;
	}//end changeCaseType()

	/**
	 * Write the change case.
	 *
	 * @param PermitRegister $store  The register.
	 * @param PlateChange    $change The checked change.
	 *
	 * @return array{caseId: string, identifier: string} The case.
	 *
	 * @throws PermitChangeRefused UNAVAILABLE when the write fails.
	 */
	private function openCase(PermitRegister $store, PlateChange $change): array {
		$case = $change->draft();
		$properties = $this->answers(store: $store, change: $change);
		if ($properties !== []) {
			$case['properties'] = $properties;
		}

		$initial = $change->initialStatus();
		if ($initial !== '') {
			$case['status'] = $initial;
		}

		try {
			$saved = $store->save(schema: $this->settingsService->getConfigValue('case_schema', 'case'), object: $case);
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
	 * @param PermitRegister $store  The register.
	 * @param PlateChange    $change The checked change.
	 *
	 * @return array<int, array<string, string>> `{propertyDefinition, name, value}` per declared answer.
	 */
	private function answers(PermitRegister $store, PlateChange $change): array {
		$schema = $this->settingsService->getConfigValue('property_definition_schema');
		$caseTypeId = $change->changeTypeId();
		if ($schema === '' || $caseTypeId === '') {
			return [];
		}

		$answers = $change->answers();
		$entries = [];
		foreach ($store->search(schema: $schema, filters: ['caseType' => $caseTypeId]) as $row) {
			$name = trim((string)($row['name'] ?? ''));
			$id = trim((string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? '')));
			if ($id !== '' && isset($answers[$name]) === true) {
				$entries[] = ['propertyDefinition' => $id, 'name' => $name, 'value' => $answers[$name]];
			}
		}

		return $entries;
	}//end answers()
}//end class
