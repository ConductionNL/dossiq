<?php

/**
 * Takes the version a set now ships over one row, and stamps the ledger.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

/**
 * Adopts and refreshes shipped rows for ShippedConfigurationService.
 *
 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
 */
class ShippedRowAdopter {
	/**
	 * Constructor.
	 *
	 * @param ShippedConfigurationService $ledger      Reads a row's state and stamps the ledger.
	 * @param ShippedFingerprint          $fingerprint The hash that says whether a row is what shipped.
	 */
	public function __construct(
		private readonly ShippedConfigurationService $ledger,
		private readonly ShippedFingerprint $fingerprint,
	) {
	}//end __construct()

	/**
	 * Bring one row a set wrote up to the version that set now ships.
	 *
	 * A row whose content already is what the set ships writes nothing. A row
	 * another set or nobody shipped is kept. Otherwise an untouched row takes
	 * the new version and is stamped with it; a row changed here is kept,
	 * unless `$overLocalChange` asks for {@see ShippedConfigurationService::adoptOverLocalChange()}.
	 *
	 * @param array<string, mixed> $row The row: `targetSchema` (its schema, as the ledger records it), `objectsKey`
	 *                                  (the app config key naming that schema), `targetObject` (its id), `set`
	 *                                  (the set that ships it) and `newShipped` (the row as the set now ships it).
	 * @param array<string, mixed> $how `overLocalChange` (whether a row changed here takes the set's keys too),
	 *                                  `version` (the set's new version) and `rows` (the StarterStore the row is
	 *                                  read from and written to).
	 *
	 * @return array{outcome: string, reason: string} `refreshed`, `unchanged` or `kept`, and why it was kept.
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function refresh(array $row, array $how): array {
		$current = $how['rows']->row(configKey: $row['objectsKey'], id: $row['targetObject']);
		if ($current === null) {
			return ['outcome' => ShippedConfigurationService::REFRESH_KEPT, 'reason' => 'not_found'];
		}

		if ($this->fingerprint->hashOf(object: $current) === $this->fingerprint->hashOf(object: $row['newShipped'])) {
			return ['outcome' => ShippedConfigurationService::REFRESH_UNCHANGED, 'reason' => ''];
		}

		$state = $this->ledger->stateOf(
			targetSchema: $row['targetSchema'],
			targetObject: $row['targetObject'],
			object: (new ShippedRowShape())->asWritten(object: $current, objectsKey: $row['objectsKey']),
			shippedKeys: array_map('strval', array_keys($row['newShipped'])),
		);
		// A row the ledger does not know ('' as its set), under the id this set ships it by, was written before the
		// ledger existed: an install over local changes takes it over, a plain install keeps it.
		$adoptable = [$row['set'], ...array_fill(0, (int) $how['overLocalChange'], '')];
		if (in_array($state['set'], $adoptable, true) === false) {
			return ['outcome' => ShippedConfigurationService::REFRESH_KEPT, 'reason' => 'not_shipped_by_this_set'];
		}

		$adopted = $this->take(row: $row, how: $how);
		if ($adopted['adopted'] === true) {
			return ['outcome' => ShippedConfigurationService::REFRESH_DONE, 'reason' => ''];
		}

		return ['outcome' => ShippedConfigurationService::REFRESH_KEPT, 'reason' => $adopted['reason']];
	}//end refresh()

	/**
	 * What both adoptions do, with the one difference between them.
	 *
	 * @param array<string, mixed> $row The row, as {@see self::refresh()} takes it.
	 * @param array<string, mixed> $how `overLocalChange` (whether a local change may go), `version` (the version
	 *                                  to stamp; null to look it up in ShippedSets) and `rows` (the StarterStore the
	 *                                  row is read from and written to).
	 *
	 * @return array{adopted: bool, reason: string} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function take(array $row, array $how): array {
		$current = $how['rows']->row(configKey: $row['objectsKey'], id: $row['targetObject']);
		if ($current === null) {
			return ['adopted' => false, 'reason' => 'not_found'];
		}

		$current = (new ShippedRowShape())->asWritten(object: $current, objectsKey: $row['objectsKey']);
		$state = $this->ledger->stateOf(
			targetSchema: $row['targetSchema'],
			targetObject: $row['targetObject'],
			object: $current,
			shippedKeys: array_map('strval', array_keys($row['newShipped'])),
		);
		$refusal = $this->refusalFor(state: $state['state'], overLocalChange: $how['overLocalChange']);
		if ($refusal !== '') {
			return ['adopted' => false, 'reason' => $refusal];
		}

		if ($this->writeAndStamp(row: $row + ['current' => $current], how: $how) === false) {
			return ['adopted' => false, 'reason' => 'write_failed'];
		}

		return ['adopted' => true, 'reason' => ''];
	}//end take()

	/**
	 * Write the shipped keys over the row and stamp the ledger with what was written.
	 *
	 * @param array<string, mixed> $row The row, as {@see self::refresh()} takes it, with `current`: the row as written now.
	 * @param array<string, mixed> $how As {@see self::take()} takes it.
	 *
	 * @return bool False when the write failed.
	 */
	private function writeAndStamp(array $row, array $how): bool {
		// What the set ships wins; a key it does not ship keeps the value this
		// instance holds, so a field somebody filled in here survives the write.
		$payload = array_merge(array_diff_key($row['current'], array_flip(ShippedFingerprint::IGNORED)), $row['newShipped']);
		$payload['id'] = $row['targetObject'];

		$saved = $how['rows']->save(configKey: $row['objectsKey'], payload: $payload, id: $row['targetObject']);
		if ($saved === null) {
			return false;
		}

		$this->ledger->stamp(
			set: $row['set'],
			targetSchema: $row['targetSchema'],
			targetObject: $row['targetObject'],
			object: (new ShippedRowShape())->asWritten(object: $saved, objectsKey: $row['objectsKey']),
			version: $how['version'],
			shipped: $row['newShipped'],
		);

		return true;
	}//end writeAndStamp()

	/**
	 * Why a row in this state is not adopted, or '' when it is.
	 *
	 * @param string $state           The row's state.
	 * @param bool   $overLocalChange Whether a local change may go.
	 *
	 * @return string `not_shipped`, `changed_locally` or ''.
	 */
	private function refusalFor(string $state, bool $overLocalChange): string {
		if ($overLocalChange === true) {
			return '';
		}

		return ([ShippedConfigurationService::STATE_LOCAL => 'not_shipped', ShippedConfigurationService::STATE_CHANGED => 'changed_locally'][$state] ?? '');
	}//end refusalFor()
}//end class
