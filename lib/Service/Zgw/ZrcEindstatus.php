<?php

/**
 * ZRC eindstatus decision
 *
 * Whether a status type is the eindstatus of its zaaktype: its own flag
 * (true, 'true', '1' or 1), or, without one, the highest volgnummer of its
 * zaaktype (ZGW). Moved out of ZrcController, where it was written out twice
 * (method-decomposition slice 6c).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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

use OCA\Dossiq\Service\ZgwService;
use OCA\Dossiq\Support\NormalisesObjectRows;

/**
 * Decides whether a new status's type is the eindstatus.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZrcEindstatus {
	use NormalisesObjectRows;

	/**
	 * A UUID anywhere in a URL or bare value.
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

	/**
	 * Constructor.
	 *
	 * @param ZgwService $zgwService The shared ZGW service (object store, mappings).
	 */
	public function __construct(
		private readonly ZgwService $zgwService,
	) {
	}//end __construct()

	/**
	 * Whether the body's status type is the eindstatus.
	 *
	 * `true` when the type says so (true, 'true', '1' or 1) or has the highest
	 * volgnummer of its zaaktype; `false` when the type's flag is literally
	 * false; `null` for any other stored flag; `'none'` when there is no status
	 * type to read.
	 *
	 * @param array $body The status body.
	 *
	 * @return bool|string|null
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function finalState(array $body): bool|string|null {
		$statustypeUuid = $this->uuidOf(value: (string)($body['statustype'] ?? ''));
		$stConfig = $this->zgwService->getZgwMappingService()->getMapping('statustype');
		if ($statustypeUuid === null || $stConfig === null) {
			return 'none';
		}

		$statustype = $this->find(uuid: $statustypeUuid, config: $stConfig);
		if ($statustype === null) {
			return 'none';
		}

		$stData = $this->objectToArray(row: $statustype);
		$flag = $this->finalFlagOf(stData: $stData);
		if ($this->saysTrue(flag: $flag) === true || $this->hasHighestSequenceNumber(stData: $stData, stConfig: $stConfig) === true) {
			return true;
		}

		if ($flag === false) {
			return false;
		}

		return null;
	}//end finalState()

	/**
	 * What the body's status type's own flag says, ignoring the volgnummer; null when there is no type to read.
	 *
	 * A type that cannot be found reads as not final.
	 *
	 * @param array $body The status body.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function explicitlyFinal(array $body): ?bool {
		$statustypeUuid = $this->uuidOf(value: (string)($body['statustype'] ?? ''));
		$stConfig = $this->zgwService->getZgwMappingService()->getMapping('statustype');
		if ($statustypeUuid === null || $stConfig === null) {
			return null;
		}

		return $this->saysTrue(flag: $this->finalFlagOf(stData: $this->objectToArray(row: $this->find(uuid: $statustypeUuid, config: $stConfig))));
	}//end explicitlyFinal()

	/**
	 * The status type's eindstatus flag as stored, false when absent.
	 *
	 * @param array $stData The status type.
	 *
	 * @return mixed
	 */
	private function finalFlagOf(array $stData): mixed {
		return $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));
	}//end finalFlagOf()

	/**
	 * Whether a stored flag means true (true, 'true', '1' or 1; OpenRegister may store either).
	 *
	 * @param mixed $flag The stored flag.
	 *
	 * @return bool
	 */
	private function saysTrue(mixed $flag): bool {
		return in_array($flag, [true, 1, '1', 'true'], true);
	}//end saysTrue()

	/**
	 * ZGW: without an explicit flag, the status type with the highest volgnummer of its zaaktype is the eindstatus.
	 *
	 * @param array $stData The status type.
	 * @param array $stConfig The statustype mapping.
	 *
	 * @return bool
	 */
	private function hasHighestSequenceNumber(array $stData, array $stConfig): bool {
		$caseTypeUuid = (string)($stData['caseType'] ?? '');
		$caseTypeUuid = ($this->uuidOf(value: $caseTypeUuid) ?? $caseTypeUuid);
		$thisOrder = (int)($stData['order'] ?? ($stData['sequenceNumber'] ?? 0));
		if ($caseTypeUuid === '' || $thisOrder <= 0) {
			return false;
		}

		$maxOrder = 0;
		foreach (($this->statusTypesOf(caseTypeUuid: $caseTypeUuid, stConfig: $stConfig)['results'] ?? []) as $st) {
			$stObj = $this->objectToArray(row: $st);
			$maxOrder = max($maxOrder, (int)($stObj['order'] ?? ($stObj['sequenceNumber'] ?? 0)));
		}

		return $thisOrder >= $maxOrder && $maxOrder > 0;
	}//end hasHighestSequenceNumber()

	/**
	 * All status types of a zaaktype, falling back to a direct query when the query builder throws.
	 *
	 * @param string $caseTypeUuid The zaaktype.
	 * @param array $stConfig The statustype mapping.
	 *
	 * @return array The paginated search answer.
	 */
	private function statusTypesOf(string $caseTypeUuid, array $stConfig): array {
		$objects = $this->zgwService->getObjectService();
		try {
			$query = $objects->buildSearchQuery(
				requestParams: ['caseType' => $caseTypeUuid, '_limit' => 100],
				register: $stConfig['sourceRegister'],
				schema: $stConfig['sourceSchema']
			);
			return $objects->searchObjectsPaginated(query: $query);
		} catch (\Throwable $e) {
			return $objects->searchObjectsPaginated(
				query: [
					'@self' => [
						'register' => (int)$stConfig['sourceRegister'],
						'schema' => (int)$stConfig['sourceSchema'],
					],
					'caseType' => $caseTypeUuid,
				]
			);
		}
	}//end statusTypesOf()

	/**
	 * Find one object of a mapped schema.
	 *
	 * @param string $uuid The object.
	 * @param array $config The mapping.
	 *
	 * @return mixed The row, or null.
	 */
	private function find(string $uuid, array $config): mixed {
		return $this->zgwService->getObjectService()->find(
			$uuid,
			register: $config['sourceRegister'],
			schema: $config['sourceSchema']
		);
	}//end find()

	/**
	 * The first UUID in a value, or null.
	 *
	 * @param string $value A URL or bare uuid.
	 *
	 * @return string|null
	 */
	private function uuidOf(string $value): ?string {
		if ($value === '' || preg_match(self::UUID_PATTERN, $value, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end uuidOf()
}//end class
