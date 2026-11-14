<?php

/**
 * Dossiq MandaatVerifier.
 *
 * Mandaat-verificatie for the akkoord step of the beschikking lifecycle:
 * which mandaatregeling applies to a zaaktype, which niveau the approver
 * holds, and whether that niveau actually covers this decision — its
 * zaaktype, its beschikkingType, and its bedrag against the level's
 * `tot_bedrag` ceiling.
 *
 * Split out of BeschikkingService so that service keeps only the lifecycle
 * orchestration: the authority rules — the only thing standing between an
 * approver and a legally binding besluit — live here and nowhere else. A
 * level with no explicit ceiling is unlimited; a level whose zaaktypes or
 * beschikkingTypes list is non-empty must contain this decision's value.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Resolves and verifies the mandaat covering a beschikking approval.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class MandaatVerifier {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Verify whether a mandaat covers a decision. [T14 verifyMandaat]
	 *
	 * @param array<string, mixed> $regeling The mandaatRegeling object.
	 * @param string $level The proposed approver level.
	 * @param float $amount The decision bedrag.
	 * @param string $decisionType The decision type.
	 * @param string $caseType The case type.
	 *
	 * @return bool True when the level may sign this decision within its limit.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function verifyMandaat(
		array $regeling,
		string $level,
		float $amount,
		string $decisionType,
		string $caseType,
	): bool {
		foreach ((array)($regeling['mandateGroups'] ?? []) as $groep) {
			if ($this->groupMatches(groep: $groep, level: $level, decisionType: $decisionType, caseType: $caseType) === false) {
				continue;
			}

			if ($this->withinLimit(groep: $groep, amount: $amount) === true) {
				return true;
			}
		}

		return false;
	}//end verifyMandaat()

	/**
	 * Whether a mandate group is for this level, case type and decision type.
	 *
	 * @param mixed  $groep        The mandate group.
	 * @param string $level        The proposed approver level.
	 * @param string $decisionType The decision type.
	 * @param string $caseType     The case type.
	 *
	 * @return bool True when the group applies.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function groupMatches(mixed $groep, string $level, string $decisionType, string $caseType): bool {
		if ((string)($groep['level'] ?? '') !== $level) {
			return false;
		}

		if ($this->listAllows(list: (array)($groep['caseTypes'] ?? []), value: $caseType) === false) {
			return false;
		}

		return $this->listAllows(list: (array)($groep['decisionTypes'] ?? []), value: $decisionType);
	}//end groupMatches()

	/**
	 * Whether a group's list allows a value: an empty list allows every value.
	 *
	 * @param array<mixed> $list  The group's list.
	 * @param string       $value The value asked about.
	 *
	 * @return bool True when the list is empty or holds the value.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function listAllows(array $list, string $value): bool {
		return (empty($list) === true || in_array($value, $list, true) === true);
	}//end listAllows()

	/**
	 * Whether an amount stays within a group's limit; a group without a limit has none.
	 *
	 * @param mixed $groep  The mandate group.
	 * @param float $amount The decision bedrag.
	 *
	 * @return bool True when the amount is covered.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function withinLimit(mixed $groep, float $amount): bool {
		$limit = ($groep['to_amount'] ?? null);
		if ($limit === null) {
			return true;
		}

		return ($amount <= (float)$limit);
	}//end withinLimit()

	/**
	 * Resolve the mandaatRegeling applicable to a zaaktype.
	 *
	 * @param string $caseType The case type slug.
	 *
	 * @return array<string, mixed> The regeling, or an empty array when the case type has none.
	 *
	 * @throws RefusedException When the mandate register could not be read at all.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function resolveMandaatRegeling(string $caseType): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'mandaat_regeling_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		$regelingen = $this->allRegelingen(objectService: $objectService, register: $register, schema: $schema);

		return $this->regelingFor(regelingen: $regelingen, caseType: $caseType);
	}//end resolveMandaatRegeling()

	/**
	 * Every mandaatRegeling in the register.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The mandaatRegeling schema id.
	 *
	 * @return array<int, array<string, mixed>> The regelingen.
	 *
	 * @throws RefusedException When the mandate register could not be read at all.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	private function allRegelingen(object $objectService, string $register, string $schema): array {
		try {
			$regelingen = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: []
			);
		} catch (\Throwable $e) {
			$this->logger->error('BeschikkingService: resolveMandaatRegeling failed', ['exception' => $e->getMessage()]);
			// An empty regeling here reached the approver as "insufficient
			// mandate", which names the person. The register being unreadable
			// is not a fact about the person.
			throw new RefusedException(
				rule: 'mandaat-regeling-unreadable',
				sentence: 'The mandate scheme could not be read, so this approval cannot be checked right now.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}//end try

		return $regelingen;
	}//end allRegelingen()

	/**
	 * The first regeling with a mandate group for the case type; any regeling with a group when no case type is given.
	 *
	 * @param array<int, array<string, mixed>> $regelingen The regelingen.
	 * @param string                           $caseType   The case type slug.
	 *
	 * @return array<string, mixed> The regeling, or an empty array when none applies.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	private function regelingFor(array $regelingen, string $caseType): array {
		foreach ((array)$regelingen as $regeling) {
			$arr = $this->toArray(value: $regeling);
			foreach ((array)($arr['mandateGroups'] ?? []) as $groep) {
				$caseTypes = (array)($groep['caseTypes'] ?? []);
				if ($caseType === '' || in_array($caseType, $caseTypes, true) === true) {
					return $arr;
				}
			}
		}

		return [];
	}//end regelingFor()

	/**
	 * Resolve the highest niveau a user is authorised for, verifying the mandaat.
	 *
	 * The user-to-niveau mapping is supplied out-of-band (the gemeente maps
	 * approvers to groups). For the build we accept the niveau encoded in the
	 * approver UID prefix (e.g. `afdelingsmanager-wmo-15`) and verify it covers
	 * the beschikking. Returns null when no covering niveau is found.
	 *
	 * @param array<string, mixed> $regeling The mandaatRegeling.
	 * @param array<string, mixed> $decision The beschikking.
	 * @param string $approvedBy The approver UID.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function resolveNiveauForUser(array $regeling, array $decision, string $approvedBy): ?string {
		$amount = (float)($decision['feeAmount'] ?? 0);
		$decisionType = (string)($decision['decisionType'] ?? '');
		$caseType = (string)($decision['caseType'] ?? '');

		foreach ((array)($regeling['mandateGroups'] ?? []) as $groep) {
			$level = (string)($groep['level'] ?? '');
			if ($level === '' || str_starts_with($approvedBy, $level) === false) {
				continue;
			}

			$covered = $this->verifyMandaat(
				regeling: $regeling,
				level: $level,
				amount: $amount,
				decisionType: $decisionType,
				caseType: $caseType,
			);
			if ($covered === true) {
				return $level;
			}
		}

		return null;
	}//end resolveNiveauForUser()

	/**
	 * Normalise an ObjectService return value to an array.
	 *
	 * @param mixed $value The entity, array, or JsonSerializable.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function toArray(mixed $value): array {
		return ($this->objectToArrayOrNull(value: $value) ?? []);
	}//end toArray()
}//end class
