<?php

/**
 * Finds a person by BSN, exactly, for a caller allowed to look citizens up.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\Kcc\GuardedCitizenLookup;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\TrustedRevealRead;
use OCP\IUser;
use Throwable;

/**
 * The "zoek op BSN" of the party picker (coordinator decision 24 Oct). A BSN
 * is shown only on request in OpenRegister and takes no part in free text, so
 * finding a person by it is its own lookup:
 *
 * - the guard runs first (`GuardedCitizenLookup`, the KCC rule); a refusal is
 *   recorded and nothing is read;
 * - only a well-formed BSN (nine digits, 11-proef) is looked up;
 * - the person is read under the caller's rights, the filter run trusted
 *   because OpenRegister refuses filtering on an explicit-reveal value;
 * - every answered lookup is recorded, found or not;
 * - the answer names the person, never their number.
 *
 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
 */
class PersonNumberLookup {

	use SearchesObjects;

	/**
	 * The fields of a person the picker shows.
	 */
	private const SHOWN = ['id', 'displayName', 'name', 'birth', 'residence', 'indicatieGeheim', '@self'];

	/**
	 * Constructor.
	 *
	 * @param GuardedCitizenLookup $lookups         Who may look a citizen up, and the record.
	 * @param SettingsService      $settingsService The register and the object service.
	 * @param TrustedRevealRead    $trusted         Runs the filter on the BSN.
	 */
	public function __construct(
		private readonly GuardedCitizenLookup $lookups,
		private readonly SettingsService $settingsService,
		private readonly TrustedRevealRead $trusted,
	) {
	}//end __construct()

	/**
	 * The people with this BSN, or null when the caller may not look citizens up.
	 *
	 * @param IUser  $user The caller.
	 * @param string $bsn  The BSN typed.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @throws Throwable When the register cannot be read.
	 *
	 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
	 */
	public function find(IUser $user, string $bsn): ?array {
		$bsn = trim($bsn);
		if ($this->lookups->isAllowed(user: $user) === false) {
			$this->lookups->recordRefusal(uid: $user->getUID(), burgerId: $bsn);
			return null;
		}

		if (self::isBsn(value: $bsn) === false) {
			return [];
		}

		$people = $this->people(bsn: $bsn);
		$this->lookups->recordAnswer(user: $user, burgerId: $bsn);

		return array_map(static fn (array $person): array => array_intersect_key($person, array_flip(self::SHOWN)), $people);
	}//end find()

	/**
	 * Whether a value is a BSN: nine digits that pass the 11-proef.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	public static function isBsn(string $value): bool {
		if (preg_match('/^[0-9]{9}$/', $value) !== 1) {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += (9 - $i) * (int)$value[$i];
		}

		$sum -= (int)$value[8];

		return $sum % 11 === 0;
	}//end isBsn()

	/**
	 * The people with this BSN the caller may read.
	 *
	 * @param string $bsn The BSN.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws Throwable When the register cannot be read; the caller answers that, not "nobody".
	 */
	private function people(string $bsn): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			return [];
		}

		return (array)$this->trusted->run(
			fn (): array => $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: 'brpPerson',
				filters: ['citizenServiceNumber' => $bsn, '_limit' => 5]
			)
		);
	}//end people()
}//end class
