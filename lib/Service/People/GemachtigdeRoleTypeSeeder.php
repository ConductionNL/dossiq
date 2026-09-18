<?php

/**
 * The one generic Gemachtigde role type, kept at exactly one row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;
use Throwable;

/**
 * Seeds the role type every case type offers: the authorised representative.
 *
 * Under Awb 2:1 anyone may let a representative act for them, in any case. So
 * the row this writes carries NO `caseType`: a role type scoped to a case type
 * is offered on that type alone, and a vergunning or a subsidie would then have
 * no way to record a gemachtigde at all.
 *
 * 🔴 IDENTITY IS `genericRole`, NOT THE NAME AND NOT THE SLUG. An administrator
 * may rename the row, and the shipped seed data already carries a row called
 * Gemachtigde at the slug `rol-gemachtigde`. Matching on the name would create
 * a second one the day somebody renames it to Gemachtigde/vertegenwoordiger;
 * matching on the slug alone would create a second one on an instance that
 * never received the seed file. Both end with two Gemachtigde entries in the
 * picker and nothing to tell them apart.
 *
 * 🔴 AN EXISTING ROW IS ADOPTED, NEVER DUPLICATED. `rol-gemachtigde` shipped
 * before `genericRole` held the value, so an upgraded instance holds a row
 * that IS the generic representative and does not say so. This stamps the key
 * onto that row instead of writing a second one beside it. The roles already
 * pointing at it keep pointing at it.
 *
 * A ROLE TYPE THAT NAMES A CASE TYPE IS NOT THIS ROW, even when it carries the
 * same `genericRole`. That is a type's own seat, offered on that type, and the
 * generic entry is what every OTHER type gets. The add-party list is what
 * removes the duplicate for the one type that declares its own (see
 * `roleTypeOptions.js`).
 *
 * SKIPS RATHER THAN THROWS. A repair step that throws aborts the upgrade, and
 * an OpenRegister that is absent, unconfigured or mid-install is not a defect
 * in this app. The seed re-runs on the next upgrade.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
 */
class GemachtigdeRoleTypeSeeder {

	use SearchesObjects;

	/**
	 * The generic role key this row carries, and the only thing that identifies it.
	 */
	public const GENERIC_ROLE = PartyVocabulary::ROLE_REPRESENTATIVE;

	/**
	 * The slug the shipped register seed writes the same row under.
	 */
	public const SEEDED_SLUG = 'rol-gemachtigde';

	/**
	 * How many role types one read takes. The same page size the vocabulary uses.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * The name the row is created with. An administrator may rename it.
	 */
	private const NAME = 'Gemachtigde';

	/**
	 * What the row says it is for.
	 */
	private const DESCRIPTION = 'Persoon of organisatie gemachtigd om namens een partij op te treden (Awb 2:1).';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Make sure exactly one generic Gemachtigde role type exists.
	 *
	 * @return array{available: bool, created: int, adopted: int, kept: int, refused: string|null}
	 *                                                                                            What the seed did.
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
	 */
	public function seed(): array {
		$nothing = [
			'available' => false,
			'created' => 0,
			'adopted' => 0,
			'kept' => 0,
			'refused' => null,
		];

		try {
			[$objectService, $register, $schema] = $this->target();
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			// \Throwable rather than \Exception: an OpenRegister that predates
			// the slug bridge answers a PHP Error, and an upgrade must not stop
			// because a role type could not be read.
			return $nothing;
		}

		$generic = $this->genericRows(rows: $rows);
		if ($generic !== []) {
			return array_merge($nothing, ['available' => true, 'kept' => count($generic)]);
		}

		$adoptable = $this->seededRow(rows: $rows);

		try {
			return $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->write(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					adoptable: $adoptable,
				),
			);
		} catch (Throwable $e) {
			return array_merge($nothing, ['available' => true, 'refused' => $e->getMessage()]);
		}
	}//end seed()

	/**
	 * Stamp the key onto the row that already is this role, or write the row.
	 *
	 * @param object                    $objectService OpenRegister's object service.
	 * @param string                    $register      The dossiq register.
	 * @param string                    $schema        The role type schema.
	 * @param array<string, mixed>|null $adoptable     The shipped row, when there is one.
	 *
	 * @return array{available: bool, created: int, adopted: int, kept: int, refused: string|null}
	 *                                                                                            What the write did.
	 */
	private function write(
		object $objectService,
		string $register,
		string $schema,
		?array $adoptable,
	): array {
		$done = [
			'available' => true,
			'created' => 0,
			'adopted' => 0,
			'kept' => 0,
			'refused' => null,
		];

		if ($adoptable !== null) {
			$row = $adoptable;
			unset($row['@self']);
			$row['genericRole'] = self::GENERIC_ROLE;
			$this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $row,
				uuid: $this->uuidOf(row: $adoptable),
			);

			return array_merge($done, ['adopted' => 1]);
		}

		$this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			object: [
				'name' => self::NAME,
				'description' => self::DESCRIPTION,
				'genericRole' => self::GENERIC_ROLE,
			],
		);

		return array_merge($done, ['created' => 1]);
	}//end write()

	/**
	 * The rows that already are the generic representative.
	 *
	 * A row naming a case type is that type's own seat and is not this one,
	 * whatever generic role it claims.
	 *
	 * @param array<int, array<string, mixed>> $rows The role type rows.
	 *
	 * @return array<int, array<string, mixed>> The generic rows.
	 */
	private function genericRows(array $rows): array {
		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => (string)($row['genericRole'] ?? '') === self::GENERIC_ROLE
					&& trim((string)($row['caseType'] ?? '')) === ''
			)
		);
	}//end genericRows()

	/**
	 * The shipped `rol-gemachtigde` row, when this instance holds one.
	 *
	 * @param array<int, array<string, mixed>> $rows The role type rows.
	 *
	 * @return array<string, mixed>|null The row, null when there is none to adopt.
	 */
	private function seededRow(array $rows): ?array {
		foreach ($rows as $row) {
			$self = (array)($row['@self'] ?? []);
			if ((string)($self['slug'] ?? '') !== self::SEEDED_SLUG) {
				continue;
			}

			if (trim((string)($row['caseType'] ?? '')) !== '') {
				continue;
			}

			return $row;
		}

		return null;
	}//end seededRow()

	/**
	 * The uuid of a row, whatever key it came back under.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null The uuid.
	 */
	private function uuidOf(array $row): ?string {
		$self = (array)($row['@self'] ?? []);
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end uuidOf()

	/**
	 * The object service, the register and the role type schema.
	 *
	 * @return array{0: object, 1: string, 2: string} The three.
	 *
	 * @throws RuntimeException When OpenRegister or the configuration is absent.
	 */
	private function target(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		$schema = $this->settingsService->getConfigValue('role_type_schema');
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema role_type_schema not configured');
		}

		return [$objectService, $register, $schema];
	}//end target()
}//end class
