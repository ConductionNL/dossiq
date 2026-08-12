<?php

/**
 * Procest case-relation OpenRegister store.
 *
 * The only place case objects are read and written on behalf of the peer
 * relation surface. Reads resolve through the session's ObjectService, so
 * OpenRegister's per-object RBAC applies and a case the actor may not read
 * comes back as null — that null IS the access guard the callers fail closed
 * on, not an incidental "not found".
 *
 * Split out of CaseRelationService so that service keeps only the relation
 * policy while the OpenRegister register/schema resolution, the ObjectEntity
 * normalisation and the failure logging live here.
 *
 * A lookup or save that throws is logged and degraded to null / no-op rather
 * than propagating: a relation write must never take down the case operation
 * that triggered it.
 *
 * @category Service
 * @package  OCA\Procest\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Relation;

use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes case objects for the peer-relation surface.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseRelationStore {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared OR/settings resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch a single case object by UUID through the session's ObjectService.
	 *
	 * Resolving via OpenRegister applies its per-object RBAC for the current
	 * user, so an unreadable case resolves to null — this is the access guard.
	 *
	 * @param string $caseUuid Case UUID.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function fetchCase(string $caseUuid): ?array {
		if ($caseUuid === '') {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$obj = $objectService->find($caseUuid, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'CaseRelationService: case lookup failed',
				['uuid' => $caseUuid, 'error' => $e->getMessage()]
			);
			return null;
		}

		return $this->normalizeCaseObject(object: $obj);
	}//end fetchCase()

	/**
	 * Persist a relation list back onto a case, JSON-encoding the field
	 * (the `relatedCases` field is a JSON-encoded string).
	 *
	 * @param array<string, mixed> $case Case object to update.
	 * @param array<int, array<string, mixed>> $relations Relation entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function persistRelations(array $case, array $relations): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			return;
		}

		$payload = $case;
		$payload['relatedCases'] = json_encode(array_values($relations));

		try {
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseRelationService: failed to persist relatedCases',
				['error' => $e->getMessage()]
			);
		}
	}//end persistRelations()

	/**
	 * Normalise an OpenRegister lookup result to a plain case array.
	 *
	 * @param mixed $object The value returned by the ObjectService.
	 *
	 * @return array<string, mixed>|null The case as an array, or null when unusable.
	 */
	private function normalizeCaseObject(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === true) {
			return $object;
		}

		return null;
	}//end normalizeCaseObject()
}//end class
