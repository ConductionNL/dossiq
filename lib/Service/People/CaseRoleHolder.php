<?php

/**
 * Who holds a generic role on a case, and binding a person to one.
 *
 * A generic role is the ZGW `omschrijvingGeneriek` a role type carries
 * (`genericRole`: handler, advisor, coordinator, ...), so a rule can say "the
 * handler" without knowing how an instance names its role types. The handler
 * seat is also the case's `assignee`, which My Work and every lens read, so the
 * handler is either the assignee or a role binding with a handler role type.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
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
 * @spec openspec/specs/case-access-purpose/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and adds generic-role bindings of a person on a case.
 *
 * @spec openspec/specs/case-access-purpose/spec.md
 */
class CaseRoleHolder {

	use SearchesObjects;

	/**
	 * The generic role whose seat is also the case's assignee.
	 */
	public const GENERIC_HANDLER = 'handler';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService The app's OpenRegister configuration.
	 * @param LoggerInterface  $logger          Says why a role could not be read or added.
	 * @param IUserManager|null $userManager    Names a person bound without a display name.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?IUserManager $userManager=null,
	) {
	}//end __construct()

	/**
	 * Whether a person holds one of the generic roles on a case.
	 *
	 * @param array<string, mixed> $case         The stored case.
	 * @param string               $uid          The person's Nextcloud user id.
	 * @param array<int, string>   $genericRoles The generic roles that count.
	 *
	 * @return bool True when the person holds one.
	 *
	 * @throws RuntimeException When the role bindings cannot be read.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-the-cases-own-handler-opens-it-without-the-dialog
	 */
	public function holdsAny(array $case, string $uid, array $genericRoles): bool {
		$uid = trim($uid);
		if ($uid === '' || $genericRoles === []) {
			return false;
		}

		if (in_array(self::GENERIC_HANDLER, $genericRoles, true) === true && trim((string)($case['assignee'] ?? '')) === $uid) {
			return true;
		}

		$roleTypes = $this->roleTypeIds(genericRoles: $genericRoles, caseTypeId: '');
		if ($roleTypes === []) {
			return false;
		}

		foreach ($this->rows(schemaKey: 'role_schema', filters: ['case' => $this->idOf(row: $case), '_limit' => CaseSeats::PAGE_SIZE]) as $row) {
			$isRole = in_array(trim((string)($row['roleType'] ?? '')), $roleTypes, true);
			if ($isRole === true && $this->uidOf(participant: (string)($row['participant'] ?? '')) === $uid) {
				return true;
			}
		}

		return false;
	}//end holdsAny()

	/**
	 * Bind a person to a generic role on a case, unless they hold it already.
	 *
	 * The role type is the case type's own one with that generic role, else one
	 * every case type shares (no `caseType`).
	 *
	 * @param array<string, mixed> $case        The stored case.
	 * @param string               $uid         The person's Nextcloud user id.
	 * @param string               $genericRole The generic role.
	 * @param string               $displayName What the People tab calls them; '' reads their Nextcloud name.
	 *
	 * @return bool True when a binding was added.
	 *
	 * @throws RuntimeException When the role types or bindings cannot be read.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler
	 */
	public function bind(array $case, string $uid, string $genericRole, string $displayName): bool {
		$caseId = $this->idOf(row: $case);
		if ($caseId === '' || trim($uid) === '' || $this->holdsAny(case: $case, uid: $uid, genericRoles: [$genericRole]) === true) {
			return false;
		}

		$roleTypes = $this->roleTypeIds(genericRoles: [$genericRole], caseTypeId: trim((string)($case['caseType'] ?? '')));
		if ($roleTypes === []) {
			$this->logger->warning(
				'Dossiq: no role type carries the generic role, so the reader is not bound to the case',
				['caseId' => $caseId, 'genericRole' => $genericRole],
			);
			return false;
		}

		try {
			$saved = $this->saveObjectAsArray(
				objectService: $this->objectService(),
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue('role_schema'),
				object: [
					'name' => $this->nameOf(uid: trim($uid), displayName: $displayName),
					'roleType' => $roleTypes[0],
					'case' => $caseId,
					'participant' => CaseSeats::USER_PREFIX . trim($uid),
				],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the reader could not be bound to the case',
				['caseId' => $caseId, 'genericRole' => $genericRole, 'exception' => $e->getMessage()],
			);
			return false;
		}

		return $saved !== null;
	}//end bind()

	/**
	 * What to call a person: the given name, else their Nextcloud display name, else the uid.
	 *
	 * @param string $uid         The person's user id.
	 * @param string $displayName The given name.
	 *
	 * @return string The name.
	 */
	private function nameOf(string $uid, string $displayName): string {
		if (trim($displayName) !== '') {
			return trim($displayName);
		}

		$name = trim((string) ($this->userManager?->getDisplayName($uid) ?? ''));
		if ($name === '') {
			return $uid;
		}

		return $name;
	}//end nameOf()

	/**
	 * The role types with one of the generic roles, the case type's own first.
	 *
	 * @param array<int, string> $genericRoles The generic roles.
	 * @param string             $caseTypeId   The case type whose own role types lead, or '' for any.
	 *
	 * @return array<int, string> Their uuids.
	 */
	private function roleTypeIds(array $genericRoles, string $caseTypeId): array {
		$found = ['own' => [], 'shared' => [], 'other' => []];
		foreach ($this->rows(schemaKey: 'role_type_schema', filters: ['_limit' => CaseSeats::PAGE_SIZE]) as $row) {
			$id = $this->idOf(row: $row);
			if ($id === '' || in_array(trim((string)($row['genericRole'] ?? '')), $genericRoles, true) === false) {
				continue;
			}

			$found[$this->bucketOf(ownerType: trim((string)($row['caseType'] ?? '')), caseTypeId: $caseTypeId)][] = $id;
		}

		if ($caseTypeId === '') {
			return array_merge($found['own'], $found['shared'], $found['other']);
		}

		return array_merge($found['own'], $found['shared']);
	}//end roleTypeIds()

	/**
	 * Where a role type stands for a case type: its own, shared by every case type, or another's.
	 *
	 * @param string $ownerType  The role type's case type, '' when it has none.
	 * @param string $caseTypeId The case type asked for, '' for any.
	 *
	 * @return string own, shared or other.
	 */
	private function bucketOf(string $ownerType, string $caseTypeId): string {
		if ($ownerType === '') {
			return 'shared';
		}

		if ($caseTypeId !== '' && $ownerType === $caseTypeId) {
			return 'own';
		}

		return 'other';
	}//end bucketOf()

	/**
	 * Rows of one configured schema; a schema that is not configured has none.
	 *
	 * @param string               $schemaKey The configuration key naming the schema.
	 * @param array<string, mixed> $filters   The search filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RuntimeException When OpenRegister is absent; a failed search throws as OpenRegister does.
	 */
	private function rows(string $schemaKey, array $filters): array {
		$register = $this->settingsService->getConfigValue('register');
		$schema   = $this->settingsService->getConfigValue($schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		return $this->searchObjectsAsArrays(objectService: $this->objectService(), register: $register, schema: $schema, filters: $filters);
	}//end rows()

	/**
	 * OpenRegister's object service.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister is absent.
	 */
	private function objectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end objectService()

	/**
	 * The uuid of a row, wherever the reader put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);

		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()

	/**
	 * The bare uid behind a participant reference.
	 *
	 * @param string $participant A uid or a `user:<uid>` reference.
	 *
	 * @return string The uid.
	 */
	private function uidOf(string $participant): string {
		$participant = trim($participant);
		if (str_starts_with($participant, CaseSeats::USER_PREFIX) === true) {
			return substr($participant, strlen(CaseSeats::USER_PREFIX));
		}

		return $participant;
	}//end uidOf()
}//end class
