<?php

/**
 * Dossiq ZGW parent state resolver.
 *
 * Answers the two parent questions the ZGW write guards ask before a sub-resource is
 * written: is the zaak this sub-resource hangs under closed (zrc-007), and is the zaaktype
 * this type hangs under still a concept (ztc-009)? Each question comes in two forms, from a
 * stored sub-resource and from a request body. Split out of ZgwService, where the four
 * answers were four copies of one lookup (method-decomposition).
 *
 * The closed-zaak answer fails CLOSED: any error while looking the zaak up reads as "closed",
 * so a transient OpenRegister failure or a crafted UUID cannot bypass closed-zaak protection.
 * The concept answer fails open to "not applicable".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\ZgwMappingService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves whether a sub-resource's parent zaak is closed or its parent zaaktype is a concept.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwParentStateResolver {

	/**
	 * A UUID anywhere in a reference (bare, or the last segment of a URL).
	 */
	private const UUID_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

	/**
	 * The ZRC resources that hang under a zaak.
	 */
	private const CASE_SUB_RESOURCES = [
		'statussen',
		'resultaten',
		'rollen',
		'zaakeigenschappen',
		'zaakinformatieobjecten',
		'zaakobjecten',
		'klantcontacten',
	];

	/**
	 * The ZTC resources that hang under a zaaktype.
	 */
	private const CASE_TYPE_SUB_RESOURCES = [
		'statustypen',
		'resultaattypen',
		'roltypen',
		'eigenschappen',
		'zaaktype-informatieobjecttypen',
	];

	/**
	 * Constructor.
	 *
	 * @param ZgwMappingService $zgwMappingService Resolves the register and schema of a mapping
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly ZgwMappingService $zgwMappingService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the zaak a stored object belongs to is closed (has an einddatum).
	 *
	 * @param object|null $objectService The OpenRegister ObjectService, null when unavailable
	 * @param string $resource The ZGW resource name
	 * @param array $existingData The stored object
	 *
	 * @return bool|null True if closed, false if open, null if not applicable
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function resolveZaakClosed(?object $objectService, string $resource, array $existingData): ?bool {
		if ($resource === 'zaken') {
			return $this->hasEndDate(caseData: $existingData);
		}

		if (in_array($resource, self::CASE_SUB_RESOURCES, true) === false) {
			return null;
		}

		$zaakRef = $existingData['case'] ?? ($existingData['zaak'] ?? null);
		if ($zaakRef === null || $zaakRef === '') {
			return null;
		}

		return $this->closedState(
			objectService: $objectService,
			uuid: $this->uuidIn(reference: (string)$zaakRef) ?? $zaakRef,
			failMessage: 'Could not resolve zaak closed status — returning true (fail-closed)'
		);
	}//end resolveZaakClosed()

	/**
	 * Whether the zaak a request body names is closed, for sub-resource creation.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService, null when unavailable
	 * @param string $resource The ZGW resource name
	 * @param array $body The request body
	 *
	 * @return bool|null True if closed, false if open, null if not applicable
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function resolveZaakClosedFromBody(?object $objectService, string $resource, array $body): ?bool {
		if (in_array($resource, self::CASE_SUB_RESOURCES, true) === false) {
			return null;
		}

		$caseUrl = $body['case'] ?? null;
		if ($caseUrl === null || $caseUrl === '') {
			return null;
		}

		$zaakUuid = $this->uuidIn(reference: (string)$caseUrl);
		if ($zaakUuid === null) {
			return null;
		}

		return $this->closedState(
			objectService: $objectService,
			uuid: $zaakUuid,
			failMessage: 'Could not resolve zaak closed status from body — returning true (fail-closed)'
		);
	}//end resolveZaakClosedFromBody()

	/**
	 * Whether the zaaktype a stored type object belongs to is still a concept.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService, null when unavailable
	 * @param string $resource The ZGW resource name
	 * @param array $existingData The stored type object
	 *
	 * @return bool|null True if concept, false if published, null if not applicable
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function resolveParentZaaktypeDraft(?object $objectService, string $resource, array $existingData): ?bool {
		if (in_array($resource, self::CASE_TYPE_SUB_RESOURCES, true) === false) {
			return null;
		}

		$caseTypeRef = $existingData['caseType'] ?? null;
		if ($caseTypeRef === null || $caseTypeRef === '') {
			return null;
		}

		return $this->draftState(
			objectService: $objectService,
			uuid: $this->uuidIn(reference: (string)$caseTypeRef) ?? $caseTypeRef,
			failMessage: 'Could not resolve parent zaaktype draft status: '
		);
	}//end resolveParentZaaktypeDraft()

	/**
	 * Whether the zaaktype a request body names is still a concept, for type creation.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService, null when unavailable
	 * @param string $resource The ZGW resource name
	 * @param array $body The request body
	 *
	 * @return bool|null True if concept, false if published, null if not applicable
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function resolveParentZaaktypeDraftFromBody(?object $objectService, string $resource, array $body): ?bool {
		if (in_array($resource, self::CASE_TYPE_SUB_RESOURCES, true) === false) {
			return null;
		}

		$caseTypeRef = $body['caseType'] ?? null;
		if ($caseTypeRef === null || $caseTypeRef === '') {
			return null;
		}

		$zaaktypeUuid = $this->uuidIn(reference: (string)$caseTypeRef);
		if ($zaaktypeUuid === null) {
			return null;
		}

		return $this->draftState(
			objectService: $objectService,
			uuid: $zaaktypeUuid,
			failMessage: 'Could not resolve parent zaaktype draft from body: '
		);
	}//end resolveParentZaaktypeDraftFromBody()

	/**
	 * Look the zaak up and answer whether it is closed; any error reads as closed.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param mixed $uuid The zaak uuid (the raw reference when it held no uuid)
	 * @param string $failMessage What to log when the lookup fails
	 *
	 * @return bool|null True if closed or unknowable, false if open, null when not found
	 */
	private function closedState(?object $objectService, mixed $uuid, string $failMessage): ?bool {
		try {
			$caseData = $this->findMapped(objectService: $objectService, mappingKey: 'case', uuid: $uuid);
			if ($caseData === null) {
				return null;
			}

			return $this->hasEndDate(caseData: $caseData);
		} catch (\Throwable $e) {
			// Fail-CLOSED: callers check `$caseClosed === true && $hasGeforceerd === false`,
			// so true without the zaken.geforceerd-bijwerken scope answers 403.
			$this->logger->error($failMessage, ['exception' => $e->getMessage()]);
			return true;
		}
	}//end closedState()

	/**
	 * Look the zaaktype up and answer whether it is a concept; any error reads as not applicable.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param mixed $uuid The zaaktype uuid (the raw reference when it held no uuid)
	 * @param string $failMessage What to log, before the error, when the lookup fails
	 *
	 * @return bool|null True if concept, false if published, null when not found or unknowable
	 */
	private function draftState(?object $objectService, mixed $uuid, string $failMessage): ?bool {
		try {
			$ztData = $this->findMapped(objectService: $objectService, mappingKey: 'caseType', uuid: $uuid);
			if ($ztData === null) {
				return null;
			}

			$isDraft = $ztData['isDraft'] ?? ($ztData['concept'] ?? true);

			return in_array($isDraft, [false, 'false', '0', 0], true) === false;
		} catch (\Throwable $e) {
			$this->logger->warning($failMessage . $e->getMessage());
			return null;
		}
	}//end draftState()

	/**
	 * Find an object in the register and schema of a ZGW mapping.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param string $mappingKey The mapping (`case`, `caseType`)
	 * @param mixed $uuid The object uuid
	 *
	 * @return array|null The object, or null when the mapping or the object is missing
	 *
	 * @throws RuntimeException When the ObjectService is unavailable
	 */
	private function findMapped(?object $objectService, string $mappingKey, mixed $uuid): ?array {
		$config = $this->zgwMappingService->getMapping($mappingKey);
		if ($config === null) {
			return null;
		}

		if ($objectService === null) {
			throw new RuntimeException('OpenRegister ObjectService is not available');
		}

		$object = $objectService->find(
			$uuid,
			register: $config['sourceRegister'],
			schema: $config['sourceSchema']
		);
		if ($object === null) {
			return null;
		}

		if (is_array($object) === false) {
			return $object->jsonSerialize();
		}

		return $object;
	}//end findMapped()

	/**
	 * Whether a zaak carries an einddatum.
	 *
	 * @param array $caseData The zaak
	 *
	 * @return bool True when the zaak has ended
	 */
	private function hasEndDate(array $caseData): bool {
		$endDate = $caseData['endDate'] ?? null;

		return $endDate !== null && $endDate !== '';
	}//end hasEndDate()

	/**
	 * The first UUID in a reference.
	 *
	 * @param string $reference A bare uuid or a URL ending in one
	 *
	 * @return string|null The uuid, or null when the reference holds none
	 */
	private function uuidIn(string $reference): ?string {
		if (preg_match(self::UUID_PATTERN, $reference, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end uuidIn()
}//end class
