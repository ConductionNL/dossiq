<?php

/**
 * Dossiq role link service.
 *
 * Links a case to an earlier case, or to an object that belongs to a case, with
 * a role its case type declares in `relations[]`. Two writes follow from one
 * link: the typed case relation, written by `CaseRelationService` so the link
 * reads the same as one a handler made by hand, and the record the role opens
 * (an objection, a review request, an appeal), holding the case and the target
 * the server resolved and the acting user from the session.
 *
 * The role is configuration. Which schema a target lives in, which relation
 * type the link writes and which record it opens are read from the case type,
 * so a procedure that needs a linked case declares a role instead of shipping
 * a class.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;
use RuntimeException;

/**
 * Links a case with a declared role and opens the role's record.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-linking-a-case-with-a-role-writes-the-relation-and-the-record-req-lcr-2
 */
class RoleLinkService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Register, schema keys and the object service.
	 * @param CaseRelationService $relations       Writes the typed case relation.
	 * @param IUserSession        $userSession     The acting user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseRelationService $relations,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The roles a case's case type declares, and which required ones are missing.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{roles: array<int, array<string, mixed>>, missing: array<int, string>} The roles.
	 *
	 * @throws RuntimeException When the case or its case type cannot be read.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-the-case-says-which-required-roles-are-missing-req-lcr-3
	 */
	public function rolesFor(string $caseId): array {
		$objectService = $this->objectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$roles         = $this->definitionsFor(objectService: $objectService, register: $register, caseId: $caseId);

		$answer  = [];
		$missing = [];
		foreach ($roles as $definition) {
			$linked = $this->isLinked(
				objectService: $objectService,
				register: $register,
				caseId: $caseId,
				definition: $definition
			);
			if ($definition->requiredAtIntake === true && $linked === false) {
				$missing[] = $definition->role;
			}

			$answer[] = array_merge($definition->toArray(), ['linked' => $linked]);
		}

		return ['roles' => $answer, 'missing' => $missing];
	}//end rolesFor()

	/**
	 * Link a case with a role to the target the caller names.
	 *
	 * @param string               $caseId   The case being linked.
	 * @param string               $role     The role, as the case type declares it.
	 * @param string               $targetId The case or object the link points at.
	 * @param array<string, mixed> $payload  Extra fields for the record the role opens.
	 *
	 * @return array{linkedCase: string, record: array<string, mixed>|null} What was written.
	 *
	 * @throws InvalidArgumentException When the role is not declared or the target is unusable.
	 * @throws RuntimeException When the register cannot be reached or a write is refused.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-linking-a-case-with-a-role-writes-the-relation-and-the-record-req-lcr-2
	 */
	public function link(string $caseId, string $role, string $targetId, array $payload = []): array {
		$caseId   = trim($caseId);
		$targetId = trim($targetId);
		if ($caseId === '' || $targetId === '') {
			throw new InvalidArgumentException('Name the case and what it is linked to.');
		}

		$objectService = $this->objectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$roles         = $this->definitionsFor(objectService: $objectService, register: $register, caseId: $caseId);
		if (isset($roles[$role]) === false) {
			throw new InvalidArgumentException("This case type declares no relation role '$role'.");
		}

		$definition = $roles[$role];
		$linkedCase = $this->resolveLinkedCase(
			objectService: $objectService,
			register: $register,
			definition: $definition,
			targetId: $targetId
		);

		if ($linkedCase !== '' && $linkedCase !== $caseId) {
			$result = $this->relations->addRelation(
				caseId: $caseId,
				targetId: $linkedCase,
				natureRelationship: $definition->natureRelationship
			);
			$reason = (string)($result['reason'] ?? '');
			if (($result['ok'] ?? false) !== true && $reason !== 'duplicate') {
				throw new RuntimeException("The cases could not be linked: $reason.");
			}
		}

		return [
			'linkedCase' => $linkedCase,
			'record'     => $this->openRecord(
				objectService: $objectService,
				register: $register,
				definition: $definition,
				caseId: $caseId,
				targetId: $targetId,
				payload: $payload
			),
		];
	}//end link()

	/**
	 * The roles declared by the case type of a case.
	 *
	 * @param object $objectService The object service.
	 * @param string $register      The register.
	 * @param string $caseId        The case uuid.
	 *
	 * @return array<string, RoleLinkDefinition> The roles, keyed by role.
	 *
	 * @throws RuntimeException When the case or its case type cannot be read.
	 */
	private function definitionsFor(object $objectService, string $register, string $caseId): array {
		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schemaKey(key: 'case_schema'),
			id: $caseId
		);
		if ($case === null) {
			throw new RuntimeException('Case not found');
		}

		$caseTypeId = $this->uuidOf(value: ($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return [];
		}

		$caseType = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schemaKey(key: 'case_type_schema'),
			id: $caseTypeId
		);
		if ($caseType === null) {
			throw new RuntimeException('Case type not found');
		}

		return (new RoleLinkDefinitionReader())->allOf(caseType: $caseType);
	}//end definitionsFor()

	/**
	 * The case the link points at: the target itself, or the case its object belongs to.
	 *
	 * @param object             $objectService The object service.
	 * @param string             $register      The register.
	 * @param RoleLinkDefinition $definition    The role.
	 * @param string             $targetId      The target uuid.
	 *
	 * @return string The case uuid, or '' when the object belongs to no case.
	 *
	 * @throws InvalidArgumentException When the target cannot be read.
	 */
	private function resolveLinkedCase(
		object $objectService,
		string $register,
		RoleLinkDefinition $definition,
		string $targetId,
	): string {
		$schemaKey = 'case_schema';
		if ($definition->target === RoleLinkDefinition::TARGET_OBJECT) {
			$schemaKey = $definition->targetSchema;
		}

		$target = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schemaKey(key: $schemaKey),
			id: $targetId
		);
		if ($target === null) {
			throw new InvalidArgumentException("The {$definition->label} this links to was not found.");
		}

		if ($definition->target === RoleLinkDefinition::TARGET_CASE) {
			return $targetId;
		}

		return $this->uuidOf(value: ($target[$definition->targetCaseField] ?? ''));
	}//end resolveLinkedCase()

	/**
	 * Write the record the role opens, when it opens one.
	 *
	 * Caller fields first, then the case, the target and the actor the server
	 * resolved, so a caller can never point the record elsewhere nor name who
	 * registered it. Linking twice answers the record already there.
	 *
	 * @param object               $objectService The object service.
	 * @param string               $register      The register.
	 * @param RoleLinkDefinition   $definition    The role.
	 * @param string               $caseId        The linked case.
	 * @param string               $targetId      The target.
	 * @param array<string, mixed> $payload       Caller fields.
	 *
	 * @return array<string, mixed>|null The record, or null when the role opens none.
	 *
	 * @throws RuntimeException When the record schema is not configured.
	 */
	private function openRecord(
		object $objectService,
		string $register,
		RoleLinkDefinition $definition,
		string $caseId,
		string $targetId,
		array $payload,
	): ?array {
		if ($definition->opensRecord() === false) {
			return null;
		}

		$schema   = $this->schemaKey(key: $definition->recordSchema);
		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				$definition->recordCaseField   => $caseId,
				$definition->recordTargetField => $targetId,
			]
		);
		if ($existing !== []) {
			return $existing[0];
		}

		$record = $payload;
		$record[$definition->recordCaseField]   = $caseId;
		$record[$definition->recordTargetField] = $targetId;
		if ($definition->recordActorField !== null) {
			$record[$definition->recordActorField] = $this->actor();
		}

		return ($this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			object: $record
		) ?? $record);
	}//end openRecord()

	/**
	 * Whether a case already holds a link for a role.
	 *
	 * @param object             $objectService The object service.
	 * @param string             $register      The register.
	 * @param string             $caseId        The case.
	 * @param RoleLinkDefinition $definition    The role.
	 *
	 * @return bool True when a record (or, for a role without one, a relation of its type) exists.
	 */
	private function isLinked(
		object $objectService,
		string $register,
		string $caseId,
		RoleLinkDefinition $definition,
	): bool {
		if ($definition->opensRecord() === true) {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schemaKey(key: $definition->recordSchema),
				filters: [$definition->recordCaseField => $caseId]
			) !== [];
		}

		foreach ($this->relations->listRelations(caseId: $caseId) as $relation) {
			if (($relation['aardRelatie'] ?? '') === $definition->natureRelationship) {
				return true;
			}
		}

		return false;
	}//end isLinked()

	/**
	 * The object service, or a refusal when OpenRegister is absent.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end objectService()

	/**
	 * The configured schema behind a config key, or a refusal naming the key.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is not configured.
	 */
	private function schemaKey(string $key): string {
		$schema = $this->settingsService->getConfigValue(key: $key);
		if ($schema === '') {
			throw new RuntimeException("The schema '$key' is not configured.");
		}

		return $schema;
	}//end schemaKey()

	/**
	 * The acting user id from the session.
	 *
	 * @return string The user id, or 'system' outside a user session.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end actor()

	/**
	 * A uuid from a reference value: a string, or an expanded object.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The uuid, or ''.
	 */
	private function uuidOf(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			foreach (['id', 'uuid'] as $key) {
				if (is_string($value[$key] ?? null) === true) {
					return trim($value[$key]);
				}
			}
		}

		return '';
	}//end uuidOf()
}//end class
