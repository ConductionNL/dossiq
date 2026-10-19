<?php

/**
 * What shipped, what an administrator changed, and what is theirs.
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use Psr\Log\LoggerInterface;

/**
 * Reads and writes the provenance of everything dossiq seeded.
 *
 * 🔑 A SEED THAT FORGETS IT WROTE A ROW CANNOT ANSWER THE ONLY QUESTION AN
 * UPGRADE ASKS. A gemeente edits a shipped case type, takes a dossiq upgrade
 * six months later, and wants to know what moved. Before this, nothing recorded
 * that the row came from us, so the honest answer was a diff of the whole
 * register against a seed file, which is not an answer anybody reads.
 *
 * Three states, per object, and they are read not guessed:
 *
 *  - `shipped`: a ledger row exists and the object still hashes to it.
 *  - `changed`: a ledger row exists and the object no longer hashes to it.
 *  - `local`:   no ledger row. Somebody here authored it.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
class ShippedConfigurationService {

	/**
	 * The app config key naming the provenance ledger's schema.
	 */
	public const LEDGER = 'shipped_origin_schema';

	/**
	 * A shipped object nobody edited.
	 */
	public const STATE_SHIPPED = 'shipped';

	/**
	 * A shipped object somebody edited here.
	 */
	public const STATE_CHANGED = 'changed';

	/**
	 * An object nobody shipped.
	 */
	public const STATE_LOCAL = 'local';

	/**
	 * The target schema of a package's version record: one ledger row per
	 * package, whose target object is the package id. A package that writes
	 * no rows (settings only) still has a version installed here, and no
	 * overview reads it, because overviews filter by a real schema slug.
	 */
	public const PACKAGE_RECORD = 'package';

	/**
	 * A refresh wrote the newer shipped version.
	 */
	public const REFRESH_DONE = 'refreshed';

	/**
	 * A refresh found the row already as the set ships it.
	 */
	public const REFRESH_UNCHANGED = 'unchanged';

	/**
	 * A refresh left the row as it is here.
	 */
	public const REFRESH_KEPT = 'kept';

	/**
	 * Constructor.
	 *
	 * @param StarterStore       $store       The OpenRegister seam.
	 * @param ShippedSets        $sets        The sets dossiq ships, and their versions.
	 * @param ShippedFingerprint $fingerprint The hash that says whether an object moved.
	 * @param LoggerInterface    $logger      Logger.
	 */
	public function __construct(
		private readonly StarterStore $store,
		private readonly ShippedSets $sets,
		private readonly ShippedFingerprint $fingerprint,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record that dossiq seeded one object from one set.
	 *
	 * Idempotent: seeding the same object again rewrites its ledger row rather
	 * than adding a second one, so a repair step that runs twice does not make
	 * the screen list everything twice.
	 *
	 * @param string               $set          The set name.
	 * @param string               $targetSchema The seeded object's schema slug.
	 * @param string               $targetObject The seeded object's id.
	 * @param array<string, mixed> $object       The object as it shipped.
	 * @param string|null          $version      The set's version, when the caller
	 *                                           read it from the package itself;
	 *                                           null to look it up in ShippedSets.
	 * @param array<string, mixed>|null $shipped The row as the set ships it, hashed as
	 *                                           `shippedFingerprint`; null hashes `$object`.
	 * @param string               $objectsKey   The app config key naming the schema; when given,
	 *                                           the row is stamped as this instance stores it, read
	 *                                           back, because the store fills properties a set never
	 *                                           named. Empty stamps `$object` as given.
	 *
	 * @return boolean True when the stamp was written.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function stamp(
		string $set,
		string $targetSchema,
		string $targetObject,
		array $object,
		?string $version = null,
		?array $shipped = null,
		string $objectsKey = '',
	): bool {
		$stored = null;
		if ($objectsKey !== '') {
			$stored = $this->store->row(configKey: $objectsKey, id: $targetObject);
		}

		if ($stored !== null) {
			$shipped = ($shipped ?? $object);
			$object = (new ShippedRowShape())->asWritten(object: $stored, objectsKey: $objectsKey);
		}

		$version = ($version ?? $this->sets->versionOf(set: $set));
		if ($version === '' || $targetObject === '' || $targetSchema === '') {
			// ADR-102: a stamp that cannot name its set is worse than no stamp,
			// because the screen would report the object as shipped and be
			// unable to say from what.
			$this->logger->warning(
				'Dossiq starter: refusing to stamp an object with no declared set',
				['set' => $set, 'schema' => $targetSchema, 'object' => $targetObject]
			);
			return false;
		}

		$existing = $this->ledgerRow(targetSchema: $targetSchema, targetObject: $targetObject);
		$payload = [
			'set' => $set,
			'setVersion' => $version,
			'targetSchema' => $targetSchema,
			'targetObject' => $targetObject,
			'fingerprint' => $this->fingerprint->hashOf(object: $object),
			'shippedFingerprint' => $this->fingerprint->hashOf(object: ($shipped ?? $object)),
			'seededAt' => gmdate('c'),
		];

		// '' would mean "create", and the ledger would grow a second row for
		// the same object every time a repair step ran.
		$existingId = $this->store->idOf(row: $existing);
		$updates = null;
		if ($existingId !== '') {
			$updates = $existingId;
		}

		$saved = $this->store->save(
			configKey: self::LEDGER,
			payload: $payload,
			id: $updates,
		);

		return ($saved !== null);
	}//end stamp()

	/**
	 * Record that a package is installed here at a version, whether or not it
	 * wrote rows. Updated in place: one record per package.
	 *
	 * @param string $set     The package id.
	 * @param string $version The package version.
	 *
	 * @return boolean True when the record was written.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function recordPackage(string $set, string $version): bool {
		return $this->stamp(
			set: $set,
			targetSchema: self::PACKAGE_RECORD,
			targetObject: $set,
			object: ['package' => $set, 'version' => $version],
			version: $version,
		);
	}//end recordPackage()

	/**
	 * Whether the ledger can be written at all.
	 *
	 * Asked before an install writes a row: a row the ledger cannot record is
	 * a row an upgrade can never account for.
	 *
	 * @return boolean True when the ledger schema is configured and reachable.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function ledgerAvailable(): bool {
		return $this->store->available(configKey: self::LEDGER);
	}//end ledgerAvailable()

	/**
	 * The highest version of a set this instance has recorded any row from.
	 *
	 * @param string $set The set name.
	 *
	 * @return string The version, or '' when nothing from the set is recorded.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function installedVersion(string $set): string {
		$rows = $this->store->rows(configKey: self::LEDGER, filters: ['set' => $set]);
		$highest = '';
		foreach (($rows ?? []) as $row) {
			$version = (string)($row['setVersion'] ?? '');
			if (($row['set'] ?? '') === $set && ($highest === '' || version_compare($version, $highest, '>') === true)) {
				$highest = $version;
			}
		}

		return $highest;
	}//end installedVersion()

	/**
	 * What an administrator should be told about one object.
	 *
	 * @param string               $targetSchema The object's schema slug.
	 * @param string               $targetObject The object's id.
	 * @param array<string, mixed> $object       The object as it stands now.
	 * @param array<int, string>   $shippedKeys  The keys a package ships for it; when given, an object
	 *                                           whose whole row differs is still untouched while those
	 *                                           keys hash to what was recorded (a ledger stamped with the
	 *                                           shipped row rather than the stored one).
	 *
	 * @return array{state: string, set: string, setVersion: string} The answer.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function stateOf(string $targetSchema, string $targetObject, array $object, array $shippedKeys = []): array {
		$row = $this->ledgerRow(targetSchema: $targetSchema, targetObject: $targetObject);
		if ($row === null) {
			return ['state' => self::STATE_LOCAL, 'set' => '', 'setVersion' => ''];
		}

		$fingerprint = (string)($row['fingerprint'] ?? '');
		// A ledger row from before `shippedFingerprint` was recorded was
		// stamped with the shipped row itself.
		$shippedFingerprint = (string)($row['shippedFingerprint'] ?? $fingerprint);
		$untouched = $this->fingerprint->untouched(
			object: $object,
			fingerprint: $fingerprint,
			shippedFingerprint: $shippedFingerprint,
			shippedKeys: $shippedKeys,
		);

		return [
			'state' => $this->stateFor(untouched: $untouched),
			'set' => (string)($row['set'] ?? ''),
			'setVersion' => (string)($row['setVersion'] ?? ''),
		];
	}//end stateOf()

	/**
	 * Every seeded object of one schema, with its state and whether a newer
	 * version of its set is waiting.
	 *
	 * @param string $targetSchema The schema slug, for example caseType.
	 * @param string $objectsKey   The app config key naming that schema.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the
	 *                                               store is unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function overview(string $targetSchema, string $objectsKey): ?array {
		$ledger = $this->store->rows(configKey: self::LEDGER, filters: ['targetSchema' => $targetSchema]);
		if ($ledger === null) {
			return null;
		}

		$overview = [];
		foreach ($ledger as $row) {
			$targetObject = (string)($row['targetObject'] ?? '');
			$object = $this->store->row(configKey: $objectsKey, id: $targetObject);
			if ($object === null) {
				// The seeded row was deleted here. It is not "shipped and
				// untouched" and it is not "ours", so it is reported as gone
				// rather than quietly dropped off the screen.
				$overview[] = [
					'targetObject' => $targetObject,
					'title' => '',
					'state' => 'removed',
					'set' => (string)($row['set'] ?? ''),
					'setVersion' => (string)($row['setVersion'] ?? ''),
					'latestVersion' => $this->sets->versionOf(set: (string)($row['set'] ?? '')),
					'updateAvailable' => false,
				];
				continue;
			}

			$set = (string)($row['set'] ?? '');
			$latest = $this->sets->versionOf(set: $set);
			$seeded = (string)($row['setVersion'] ?? '');
			$untouched = $this->fingerprint->matches(
				object: $object,
				fingerprint: (string)($row['fingerprint'] ?? ''),
			);

			$overview[] = [
				'targetObject' => $targetObject,
				'title' => (string)($object['title'] ?? ($object['name'] ?? '')),
				'state' => $this->stateFor(untouched: $untouched),
				'set' => $set,
				'setVersion' => $seeded,
				'latestVersion' => $latest,
				'updateAvailable' => ($latest !== '' && $latest !== $seeded),
			];
		}//end foreach

		return $overview;
	}//end overview()

	/**
	 * Offer a newer shipped version of one object, and take it only when it is
	 * safe or accepted.
	 *
	 * 🔑 THE LOCAL CHANGE WINS BY DEFAULT. An upgrade that overwrites an edited
	 * case type destroys work an administrator did on purpose, and there is no
	 * undo for it. So a changed object is REFUSED and the newer version is
	 * reported as waiting; the administrator accepts it explicitly, having been
	 * told their change goes.
	 *
	 * @param string               $targetSchema The object's schema slug.
	 * @param string               $objectsKey   The app config key naming that schema.
	 * @param string               $targetObject The object's id.
	 * @param string               $set          The set the newer version comes from.
	 * @param array<string, mixed> $newShipped   The object as the newer set ships it.
	 * @param string|null          $version      The newer version, when the caller read it
	 *                                           from the package; null to look it up in ShippedSets.
	 *
	 * @return array{adopted: bool, reason: string} What happened, and why not when it did not.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function adopt(
		string $targetSchema,
		string $objectsKey,
		string $targetObject,
		string $set,
		array $newShipped,
		?string $version = null,
	): array {
		return $this->take(
			targetSchema: $targetSchema,
			objectsKey: $objectsKey,
			targetObject: $targetObject,
			set: $set,
			newShipped: $newShipped,
			overLocalChange: false,
			version: $version,
		);
	}//end adopt()

	/**
	 * Bring the rows a set already had here up to the version it now ships.
	 *
	 * Each row goes through {@see self::refresh()}; a kept row is logged by id.
	 * A row of a seed file bound for another app's register (`ownRegister`) is
	 * left as it is, because this ledger reads only dossiq's register.
	 *
	 * @param string                           $set     The set, a package id.
	 * @param string                           $version The set's new version.
	 * @param array<int, array<string, mixed>> $rows    The rows found already here: schemaKey, schema, id, row
	 *                                                  and ownRegister.
	 *
	 * @return array{refreshed: array<int, string>, kept: array<int, string>} The ids of each.
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function refreshRows(string $set, string $version, array $rows): array {
		$refresh = ['refreshed' => [], 'kept' => []];
		foreach ($rows as $row) {
			if (($row['ownRegister'] ?? false) === true) {
				continue;
			}

			$rowId = (string)$row['id'];
			$result = $this->refresh(
				targetSchema: (string)$row['schema'],
				objectsKey: (string)$row['schemaKey'],
				targetObject: $rowId,
				set: $set,
				newShipped: (array)$row['row'],
				version: $version,
			);

			if ($result['outcome'] === self::REFRESH_DONE) {
				$refresh['refreshed'][] = $rowId;
				continue;
			}

			if ($result['outcome'] === self::REFRESH_KEPT) {
				$refresh['kept'][] = $rowId;
				$this->logger->warning(
					'Dossiq: {set} {version} kept row {row} as this instance holds it ({reason})',
					['set' => $set, 'version' => $version, 'row' => $rowId, 'reason' => $result['reason']]
				);
			}
		}//end foreach

		return $refresh;
	}//end refreshRows()

	/**
	 * Bring one row a set wrote up to the version that set now ships.
	 *
	 * A row whose content already is what the set ships writes nothing. A row
	 * another set or nobody shipped is kept. Otherwise {@see self::adopt()}
	 * decides: an untouched row takes the new version and is stamped with it,
	 * a row changed here is kept.
	 *
	 * @param string               $targetSchema The row's schema, as the ledger records it.
	 * @param string               $objectsKey   The app config key naming that schema.
	 * @param string               $targetObject The row's id.
	 * @param string               $set          The set that ships it.
	 * @param array<string, mixed> $newShipped   The row as the set now ships it.
	 * @param string               $version      The set's new version.
	 *
	 * @return array{outcome: string, reason: string} `refreshed`, `unchanged` or `kept`, and why it was kept.
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	private function refresh(
		string $targetSchema,
		string $objectsKey,
		string $targetObject,
		string $set,
		array $newShipped,
		string $version,
	): array {
		$current = $this->store->row(configKey: $objectsKey, id: $targetObject);
		if ($current === null) {
			return ['outcome' => self::REFRESH_KEPT, 'reason' => 'not_found'];
		}

		if ($this->fingerprint->hashOf(object: $current) === $this->fingerprint->hashOf(object: $newShipped)) {
			return ['outcome' => self::REFRESH_UNCHANGED, 'reason' => ''];
		}

		$state = $this->stateOf(
			targetSchema: $targetSchema,
			targetObject: $targetObject,
			object: (new ShippedRowShape())->asWritten(object: $current, objectsKey: $objectsKey),
			shippedKeys: array_map('strval', array_keys($newShipped)),
		);
		if ($state['set'] !== $set) {
			return ['outcome' => self::REFRESH_KEPT, 'reason' => 'not_shipped_by_this_set'];
		}

		$adopted = $this->adopt(
			targetSchema: $targetSchema,
			objectsKey: $objectsKey,
			targetObject: $targetObject,
			set: $set,
			newShipped: $newShipped,
			version: $version,
		);
		if ($adopted['adopted'] === true) {
			return ['outcome' => self::REFRESH_DONE, 'reason' => ''];
		}

		return ['outcome' => self::REFRESH_KEPT, 'reason' => $adopted['reason']];
	}//end refresh()

	/**
	 * Take the newer shipped version over a change somebody made here.
	 *
	 * 🔑 A SECOND NAMED ACT RATHER THAN A FLAG ON THE FIRST. `adopt($x, true)`
	 * reads the same at the call site whichever way the boolean goes, and what
	 * it does differs by whether an administrator's work survives. The two
	 * gestures are genuinely different and the caller has to pick one by name.
	 *
	 * @param string               $targetSchema The object's schema slug.
	 * @param string               $objectsKey   The app config key naming that schema.
	 * @param string               $targetObject The object's id.
	 * @param string               $set          The set the newer version comes from.
	 * @param array<string, mixed> $newShipped   The object as the newer set ships it.
	 *
	 * @return array{adopted: bool, reason: string} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function adoptOverLocalChange(
		string $targetSchema,
		string $objectsKey,
		string $targetObject,
		string $set,
		array $newShipped,
	): array {
		return $this->take(
			targetSchema: $targetSchema,
			objectsKey: $objectsKey,
			targetObject: $targetObject,
			set: $set,
			newShipped: $newShipped,
			overLocalChange: true,
			version: null,
		);
	}//end adoptOverLocalChange()

	/**
	 * What both adoptions do, with the one difference between them.
	 *
	 * @param string               $targetSchema    The object's schema slug.
	 * @param string               $objectsKey      The app config key naming that schema.
	 * @param string               $targetObject    The object's id.
	 * @param string               $set             The set the newer version comes from.
	 * @param array<string, mixed> $newShipped      The object as the newer set ships it.
	 * @param boolean              $overLocalChange Whether a local change may go.
	 * @param string|null          $version         The version to stamp; null to look it up in ShippedSets.
	 *
	 * @return array{adopted: bool, reason: string} What happened.
	 */
	private function take(
		string $targetSchema,
		string $objectsKey,
		string $targetObject,
		string $set,
		array $newShipped,
		bool $overLocalChange,
		?string $version,
	): array {
		$current = $this->store->row(configKey: $objectsKey, id: $targetObject);
		if ($current === null) {
			return ['adopted' => false, 'reason' => 'not_found'];
		}

		$current = (new ShippedRowShape())->asWritten(object: $current, objectsKey: $objectsKey);
		$state = $this->stateOf(
			targetSchema: $targetSchema,
			targetObject: $targetObject,
			object: $current,
			shippedKeys: array_map('strval', array_keys($newShipped)),
		);

		if ($state['state'] === self::STATE_LOCAL) {
			return ['adopted' => false, 'reason' => 'not_shipped'];
		}

		if ($state['state'] === self::STATE_CHANGED && $overLocalChange === false) {
			return ['adopted' => false, 'reason' => 'changed_locally'];
		}

		// What the set ships wins; a key it does not ship keeps the value this
		// instance holds, so a field somebody filled in here survives the write.
		$payload = array_merge(
			array_diff_key($current, array_flip(ShippedFingerprint::IGNORED)),
			$newShipped
		);
		$payload['id'] = $targetObject;

		$saved = $this->store->save(configKey: $objectsKey, payload: $payload, id: $targetObject);
		if ($saved === null) {
			return ['adopted' => false, 'reason' => 'write_failed'];
		}

		$this->stamp(
			set: $set,
			targetSchema: $targetSchema,
			targetObject: $targetObject,
			object: (new ShippedRowShape())->asWritten(object: $saved, objectsKey: $objectsKey),
			version: $version,
			shipped: $newShipped,
		);

		return ['adopted' => true, 'reason' => ''];
	}//end take()

	/**
	 * Shipped and untouched, or shipped and changed here.
	 *
	 * @param boolean $untouched Whether the object still hashes to what shipped.
	 *
	 * @return string One of the two shipped states.
	 */
	private function stateFor(bool $untouched): string {
		if ($untouched === true) {
			return self::STATE_SHIPPED;
		}

		return self::STATE_CHANGED;
	}//end stateFor()

	/**
	 * The ledger row for one object, or null when nothing shipped it.
	 *
	 * @param string $targetSchema The object's schema slug.
	 * @param string $targetObject The object's id.
	 *
	 * @return array<string, mixed>|null The ledger row.
	 */
	private function ledgerRow(string $targetSchema, string $targetObject): ?array {
		if ($targetObject === '') {
			return null;
		}

		$rows = $this->store->rows(
			configKey: self::LEDGER,
			filters: [
				'targetSchema' => $targetSchema,
				'targetObject' => $targetObject,
				'_limit' => 1,
			],
		);

		if ($rows === null || $rows === []) {
			return null;
		}

		return $rows[0];
	}//end ledgerRow()
}//end class
