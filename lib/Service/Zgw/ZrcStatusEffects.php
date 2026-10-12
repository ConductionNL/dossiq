<?php

/**
 * ZRC status and result side effects
 *
 * What a new status or resultaat does to its zaak through the ZGW API:
 * an eindstatus closes the zaak (zrc-007a), derives its archiving parameters
 * (zrc-021) and settles indicatieGebruiksrecht on its documents (zrc-007b);
 * a later non-eindstatus reopens it (zrc-008); an eindstatus is refused while
 * a linked document still has no indicatieGebruiksrecht (zrc-007q); and a new
 * resultaat derives the archiving parameters on its own (zrc-021).
 *
 * Moved out of ZrcController (method-decomposition slice 6c). The eindstatus
 * test used to be written out twice, once per caller; it is one method here.
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

use OCA\Dossiq\Service\Archival\ArchivalNominationDeriver;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\ZgwService;
use OCA\Dossiq\Support\NormalisesObjectRows;

/**
 * The zaak-level effects of creating a status or a resultaat.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZrcStatusEffects {
	use NormalisesObjectRows;

	/**
	 * A UUID anywhere in a URL or bare value.
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

	/**
	 * Fields OpenRegister may hand back as integers that the case schema wants as strings.
	 *
	 * @var array<int, string>
	 */
	private const STRING_FIELDS = ['title', 'assignee', 'sourceOrganisation', 'identifier'];

	/**
	 * Constructor.
	 *
	 * @param ZgwService $zgwService The shared ZGW service (object store, mappings, logger).
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param ArchivalNominationDeriver $archivalDeriver The one zrc-021 derivation.
	 * @param ZrcUsageRights $usageRights Settles and checks indicatieGebruiksrecht on the zaak's documents.
	 * @param ZrcEindstatus $eindstatus Decides whether a status type is the eindstatus.
	 */
	public function __construct(
		private readonly ZgwService $zgwService,
		private readonly CaseDateNormaliser $dates,
		private readonly ArchivalNominationDeriver $archivalDeriver,
		private readonly ZrcUsageRights $usageRights,
		private readonly ZrcEindstatus $eindstatus,
	) {
	}//end __construct()

	/**
	 * Refuse an eindstatus while a linked document has no indicatieGebruiksrecht (zrc-007q).
	 *
	 * On the zaak's first close the indication is derived first (zrc-007b), so
	 * only documents it could not settle are refused. Any lookup failure lets
	 * the status through, as it always has.
	 *
	 * @param array $body The status body as received.
	 *
	 * @return array|null The 400 body, or null when the status may be created.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function unsetUsageRightsRefusal(array $body): ?array {
		try {
			$caseUuid = $this->uuidOf(value: (string)($body['case'] ?? ''));
			if ($caseUuid === null || $this->eindstatus->finalState(body: $body) !== true) {
				return null;
			}

			if ($this->caseIsClosed(caseUuid: $caseUuid) === false) {
				$this->usageRights->settleOnClose(zaakUuid: $caseUuid);
			}

			return $this->usageRights->firstUnsetRefusal(caseUuid: $caseUuid);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->debug(
				'zrc-007q: Could not check indicatieGebruiksrecht: ' . $e->getMessage()
			);
		}

		return null;
	}//end unsetUsageRightsRefusal()

	/**
	 * Whether a new status would reopen a closed zaak, which needs zaken.heropenen (zrc-008c).
	 *
	 * Only the status type's explicit flag counts here (not the highest
	 * volgnummer), as before. Fail-closed: when the answer cannot be read it
	 * is "yes", so the scope gate applies rather than being skipped.
	 *
	 * @param array $body The status body as received.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function isReopenAttempt(array $body): bool {
		try {
			$caseUuid = $this->uuidOf(value: (string)($body['case'] ?? ''));
			if ($caseUuid === null || (string)($body['statustype'] ?? '') === '' || $this->caseIsClosed(caseUuid: $caseUuid) === false) {
				return false;
			}

			return $this->eindstatus->explicitlyFinal(body: $body) === false;
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'zrc-008c: Unexpected error in checkReopenScope — denying request (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return true;
		}//end try
	}//end isReopenAttempt()

	/**
	 * Close or reopen the zaak a new status belongs to (zrc-007a, zrc-021, zrc-007b, zrc-008).
	 *
	 * @param array $body The status body as received.
	 * @param array $objectData The stored status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function applyStatusEffect(array $body, array $objectData): void {
		try {
			$final = $this->eindstatus->finalState(body: $body);
			if ($final === 'none') {
				return;
			}

			$caseUuid = $this->uuidOf(value: (string)($body['case'] ?? ''));
			$caseConfig = $this->zgwService->getZgwMappingService()->getMapping('case');
			if ($caseUuid === null || $caseConfig === null) {
				return;
			}

			$case = $this->find(uuid: $caseUuid, config: $caseConfig);
			if ($case === null) {
				return;
			}

			$caseData = $this->objectToArray(row: $case);
			// Strip metadata that confuses saveObject on re-save.
			unset($caseData['@self'], $caseData['organisation']);
			$caseData = $this->withStringFields(caseData: $caseData);

			if ($final === true) {
				$this->closeCase(caseUuid: $caseUuid, caseData: $caseData, caseConfig: $caseConfig, body: $body, objectData: $objectData);
			}

			// 🔑 Only a status type that says FALSE reopens. A flag stored as
			// 'false' or 0 is neither true nor false here and reopens nothing,
			// exactly as before this moved out of the controller.
			if ($final === false) {
				$this->reopenCase(caseUuid: $caseUuid, caseData: $caseData, caseConfig: $caseConfig);
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'handleEindstatusEffect failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end applyStatusEffect()

	/**
	 * Derive the zaak's archiving parameters when a resultaat is created (zrc-021).
	 *
	 * @param array $body The resultaat body as received.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function applyResultEffect(array $body): void {
		try {
			$caseUuid = $this->uuidOf(value: (string)($body['case'] ?? ''));
			$caseConfig = $this->zgwService->getZgwMappingService()->getMapping('case');
			if ($caseUuid === null || $caseConfig === null) {
				return;
			}

			$caseData = $this->objectToArray(row: $this->find(uuid: $caseUuid, config: $caseConfig));

			// Use the zaak endDate as einddatum (may be null if zaak isn't closed yet).
			$endDate = ($this->dates->toCalendarDateOrNull($caseData['endDate'] ?? null)
				?? $this->dates->todayAsCalendarDate());

			$caseData = $this->deriveArchiveActionDate(caseData: $caseData, dateStatusGezet: $endDate);
			$caseData = $this->withStringFields(caseData: $caseData);
			$this->saveCase(caseUuid: $caseUuid, caseData: $caseData, caseConfig: $caseConfig);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'zrc-021: handleResultaatCreated failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end applyResultEffect()

	/**
	 * Whether the zaak already has an einddatum.
	 *
	 * @param string $caseUuid The zaak.
	 *
	 * @return bool
	 */
	private function caseIsClosed(string $caseUuid): bool {
		$caseConfig = $this->zgwService->getZgwMappingService()->getMapping('case');
		if ($caseConfig === null) {
			return false;
		}

		$case = $this->find(uuid: $caseUuid, config: $caseConfig);
		if ($case === null) {
			return false;
		}

		$endDate = ($this->objectToArray(row: $case)['endDate'] ?? null);
		return ($endDate !== null && $endDate !== '');
	}//end caseIsClosed()

	/**
	 * Close the zaak on its eindstatus.
	 *
	 * @param string $caseUuid The zaak.
	 * @param array $caseData The zaak, ready to re-save.
	 * @param array $caseConfig The case mapping.
	 * @param array $body The status body.
	 * @param array $objectData The stored status.
	 *
	 * @return void
	 */
	private function closeCase(string $caseUuid, array $caseData, array $caseConfig, array $body, array $objectData): void {
		// Zrc-007a: the submitted datumStatusGezet, or today, through the one date write path.
		$submitted = ($body['datumStatusGezet'] ?? ($objectData['statusSetDate'] ?? null));
		$dateStatusGezet = $this->dates->todayAsCalendarDate();
		if ($submitted !== null && $submitted !== '') {
			$dateStatusGezet = $this->dates->toCalendarDate($submitted, 'datumStatusGezet');
		}

		$caseData['endDate'] = $dateStatusGezet;
		// Zrc-021: Derive archiefactiedatum from resultaat.resultaattype.brondatumArchiefprocedure.
		$caseData = $this->deriveArchiveActionDate(caseData: $caseData, dateStatusGezet: $dateStatusGezet);
		$this->saveCase(caseUuid: $caseUuid, caseData: $caseData, caseConfig: $caseConfig);

		// Zrc-007b: Set indicatieGebruiksrecht on all related informatieobjecten.
		$this->usageRights->settleOnClose(zaakUuid: $caseUuid);
	}//end closeCase()

	/**
	 * Reopen a closed zaak on a non-eindstatus (zrc-008).
	 *
	 * @param string $caseUuid The zaak.
	 * @param array $caseData The zaak, ready to re-save.
	 * @param array $caseConfig The case mapping.
	 *
	 * @return void
	 */
	private function reopenCase(string $caseUuid, array $caseData, array $caseConfig): void {
		$existingEndDate = $caseData['endDate'] ?? null;
		if ($existingEndDate === null || $existingEndDate === '') {
			return;
		}

		$caseData['endDate'] = null;
		$caseData['archiveActionDate'] = null;
		$caseData['archiveNomination'] = null;
		$this->saveCase(caseUuid: $caseUuid, caseData: $caseData, caseConfig: $caseConfig);

		$this->zgwService->getLogger()->info(
			'zrc-008: Heropened zaak ' . $caseUuid . ' — cleared endDate, archiveActionDate, archiveNomination'
		);
	}//end reopenCase()

	/**
	 * Derive archiefnominatie and archiefactiedatum for a closing zaak (zrc-021).
	 *
	 * 🔴 THE RULE ITSELF IS NOT HERE. {@see ArchivalNominationDeriver} is the one
	 * implementation both the ZGW API and the in-app closing path call.
	 *
	 * @param array $caseData The zaak data.
	 * @param string $dateStatusGezet The einddatum.
	 *
	 * @return array The zaak data with derived archiving parameters.
	 */
	private function deriveArchiveActionDate(array $caseData, string $dateStatusGezet): array {
		$zaakUuid = (string)($caseData['id'] ?? ($caseData['@self']['id'] ?? ''));
		$resultTypeId = $this->archivalDeriver->resultTypeForCase(caseId: $zaakUuid);
		if ($resultTypeId === null) {
			return $caseData;
		}

		return array_merge(
			$caseData,
			$this->archivalDeriver->derive(
				case: $caseData,
				resultTypeId: $resultTypeId,
				endDate: $dateStatusGezet,
			)
		);
	}//end deriveArchiveActionDate()

	/**
	 * Cast the string-typed case fields OpenRegister may return as integers, and default the title.
	 *
	 * @param array $caseData The zaak.
	 *
	 * @return array
	 */
	private function withStringFields(array $caseData): array {
		foreach (self::STRING_FIELDS as $field) {
			if (isset($caseData[$field]) === true && is_int($caseData[$field]) === true) {
				$caseData[$field] = (string)$caseData[$field];
			}
		}

		if (isset($caseData['title']) === false) {
			$caseData['title'] = '';
		}

		return $caseData;
	}//end withStringFields()

	/**
	 * Re-save the zaak under its uuid.
	 *
	 * @param string $caseUuid The zaak.
	 * @param array $caseData The zaak.
	 * @param array $caseConfig The case mapping.
	 *
	 * @return void
	 */
	private function saveCase(string $caseUuid, array $caseData, array $caseConfig): void {
		$caseData['id'] = $caseUuid;
		$this->zgwService->getObjectService()->saveObject(
			register: $caseConfig['sourceRegister'],
			schema: $caseConfig['sourceSchema'],
			object: $caseData,
			uuid: $caseUuid
		);
	}//end saveCase()

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
