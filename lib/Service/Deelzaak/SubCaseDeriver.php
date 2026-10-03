<?php

/**
 * Dossiq sub-case derivation.
 *
 * Creating a deelzaak is not an ordinary case create that happens to set
 * `parentCase`. It is a derivation: the child starts from its parent, it takes
 * the access-bearing values the relation type declares it takes, and what it
 * took is recorded on the relation so the answer to "why is this case
 * confidential" is not a guess.
 *
 * OpenRegister owns all three of those (openregister#3764). The case schema
 * declares the inheritance on `case.parentCase` through
 * `x-openregister-relation.inherits`, `ObjectRelationService` applies it and
 * writes the provenance row. This resolves that service through the container
 * at call time, the same way every other optional OpenRegister dependency in
 * dossiq is resolved, and reports it rather than degrading quietly when it is
 * not there: a derive that silently becomes a plain create is an access change
 * nobody can see afterwards.
 *
 * Inheritance happens ONCE, here, at creation. A later change to the parent
 * does not reach the child, which is OpenRegister's rule and not ours.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Deelzaak
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
 * @spec openspec/specs/deelzaak-support/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Deelzaak;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Creates a sub-case as a derivation of its parent.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */
class SubCaseDeriver {
	/**
	 * The case property that holds the parent, and therefore the property whose
	 * relation declaration says what a child inherits.
	 *
	 * @var string
	 */
	public const PARENT_PROPERTY = 'parentCase';

	/**
	 * OpenRegister's relation service, resolved by name because OpenRegister is
	 * an optional runtime dependency.
	 *
	 * @var string
	 */
	private const RELATION_SERVICE = 'OCA\OpenRegister\Service\Relation\ObjectRelationService';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared OpenRegister/settings resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a sub-case from its parent, applying the declared inheritance.
	 *
	 * `inheritanceApplied` is false when OpenRegister's relation service could
	 * not be reached. The case is still created and still carries its parent,
	 * but nothing was inherited and no provenance row was written, and the
	 * caller is told so rather than being handed a result that looks complete.
	 *
	 * @param string $parentCaseUuid The parent case.
	 * @param array<string, mixed> $childData The child as the form filled it in.
	 *
	 * @return array{ok: bool, reason?: string, object?: array<string, mixed>, inherited?: array<string, mixed>, inheritanceApplied?: bool}
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function derive(string $parentCaseUuid, array $childData): array {
		if ($parentCaseUuid === '') {
			return ['ok' => false, 'reason' => 'parent_not_found'];
		}

		$objectService = $this->settingsService->getObjectService();
		$register      = $this->settingsService->getConfigValue('register');
		$schema        = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return ['ok' => false, 'reason' => 'not_configured'];
		}

		$parent = $this->findParent(
			objectService: $objectService,
			parentCaseUuid: $parentCaseUuid,
			register: $register,
			schema: $schema
		);
		if ($parent === null) {
			return ['ok' => false, 'reason' => 'parent_not_found'];
		}

		// The child names its parent before anything is inherited: the
		// declaration that says what to inherit hangs off this very property.
		$childData[self::PARENT_PROPERTY] = $parentCaseUuid;

		$relations   = $this->settingsService->getOpenRegisterClass(self::RELATION_SERVICE);
		$declaration = $this->declaration(relations: $relations, parent: $parent);
		$inherited   = [];

		if ($relations !== null) {
			$applied   = $relations->applyInheritance(
				parent: $parent,
				childData: $childData,
				inherits: ($declaration['inherits'] ?? [])
			);
			$childData = ($applied['data'] ?? $childData);
			$inherited = ($applied['inherited'] ?? []);
		}

		try {
			$created = $objectService->saveObject(
				object: $childData,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq: the sub-case could not be created',
				['parent' => $parentCaseUuid, 'error' => $e->getMessage()]
			);
			return ['ok' => false, 'reason' => 'create_failed'];
		}

		if ($relations !== null) {
			$this->record(
				relations: $relations,
				parent: $parent,
				created: $created,
				relationType: ($declaration['type'] ?? null),
				inherited: $inherited
			);
		}

		return [
			'ok' => true,
			'object' => $this->asArray(object: $created),
			'inherited' => $inherited,
			'inheritanceApplied' => ($relations !== null),
		];
	}//end derive()

	/**
	 * The parent case as OpenRegister's own entity, which the derive needs.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $parentCaseUuid The parent case.
	 * @param string $register The register.
	 * @param string $schema The case schema.
	 *
	 * @return object|null The parent entity.
	 */
	private function findParent(
		object $objectService,
		string $parentCaseUuid,
		string $register,
		string $schema,
	): ?object {
		try {
			$parent = $objectService->find($parentCaseUuid, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq: the parent case could not be read for a derive',
				['parent' => $parentCaseUuid, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($parent) === false) {
			return null;
		}

		return $parent;
	}//end findParent()

	/**
	 * What `case.parentCase` declares a child takes from its parent.
	 *
	 * Read off the PARENT's schema, because that is where the property naming
	 * the link lives. An empty answer is a real answer: a schema that declares
	 * no inheritance inherits nothing.
	 *
	 * @param object|null $relations OpenRegister's relation service.
	 * @param object $parent The parent entity.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private function declaration(?object $relations, object $parent): array {
		if ($relations === null) {
			$this->logger->warning(
				'Dossiq: OpenRegister\'s relation service is unavailable, so the sub-case '
				. 'inherits nothing from its parent and no provenance is recorded'
			);
			return [];
		}

		try {
			$schemaId = $parent->getSchema();
			$resolvedId  = null;
			if (is_numeric($schemaId) === true) {
				$resolvedId = (int)$schemaId;
			}

			$declaration = $relations->declarationFor(
				schemaId: $resolvedId,
				property: self::PARENT_PROPERTY
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq: the parentCase relation declaration could not be read',
				['error' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($declaration) === false) {
			return [];
		}

		return $declaration;
	}//end declaration()

	/**
	 * Write the provenance row, without losing the case when it fails.
	 *
	 * @param object $relations OpenRegister's relation service.
	 * @param object $parent The parent entity.
	 * @param mixed $created The created child.
	 * @param string|null $relationType The vocabulary key naming the link.
	 * @param array<string, mixed> $inherited What the child took.
	 *
	 * @return void
	 */
	private function record(
		object $relations,
		object $parent,
		mixed $created,
		?string $relationType,
		array $inherited,
	): void {
		if (is_object($created) === false) {
			return;
		}

		try {
			$relations->recordDerivation(
				parent: $parent,
				child: $created,
				relationType: $relationType,
				inherited: $inherited
			);
		} catch (\Throwable $e) {
			// The case exists and carries its parent. Losing the provenance row
			// is worth reporting and is not worth throwing away a created case
			// the handler is already looking at.
			$this->logger->warning(
				'Dossiq: the sub-case was created but its derivation was not recorded',
				['error' => $e->getMessage()]
			);
		}
	}//end record()

	/**
	 * Normalise an OpenRegister entity to a plain array.
	 *
	 * @param mixed $object The entity.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		return $object;
	}//end asArray()
}//end class
