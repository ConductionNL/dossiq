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
	 * Constructor.
	 *
	 * @param StarterStore    $store  The OpenRegister seam.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly StarterStore $store,
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
	 *
	 * @return boolean True when the stamp was written.
	 */
	public function stamp(string $set, string $targetSchema, string $targetObject, array $object): bool {
		$version = ShippedSets::versionOf(set: $set);
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
			'fingerprint' => ShippedFingerprint::of(object: $object),
			'seededAt' => gmdate('c'),
		];

		// '' would mean "create", and the ledger would grow a second row for
		// the same object every time a repair step ran.
		$existingId = $this->store->idOf(row: $existing);
		$saved = $this->store->save(
			configKey: self::LEDGER,
			payload: $payload,
			id: (($existingId === '') ? null : $existingId),
		);

		return ($saved !== null);
	}//end stamp()

	/**
	 * What an administrator should be told about one object.
	 *
	 * @param string               $targetSchema The object's schema slug.
	 * @param string               $targetObject The object's id.
	 * @param array<string, mixed> $object       The object as it stands now.
	 *
	 * @return array{state: string, set: string, setVersion: string} The answer.
	 */
	public function stateOf(string $targetSchema, string $targetObject, array $object): array {
		$row = $this->ledgerRow(targetSchema: $targetSchema, targetObject: $targetObject);
		if ($row === null) {
			return ['state' => self::STATE_LOCAL, 'set' => '', 'setVersion' => ''];
		}

		$untouched = ShippedFingerprint::matches(
			object: $object,
			fingerprint: (string)($row['fingerprint'] ?? ''),
		);

		return [
			'state' => ($untouched === true) ? self::STATE_SHIPPED : self::STATE_CHANGED,
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
					'latestVersion' => ShippedSets::versionOf(set: (string)($row['set'] ?? '')),
					'updateAvailable' => false,
				];
				continue;
			}

			$set = (string)($row['set'] ?? '');
			$latest = ShippedSets::versionOf(set: $set);
			$seeded = (string)($row['setVersion'] ?? '');
			$untouched = ShippedFingerprint::matches(
				object: $object,
				fingerprint: (string)($row['fingerprint'] ?? ''),
			);

			$overview[] = [
				'targetObject' => $targetObject,
				'title' => (string)($object['title'] ?? ($object['name'] ?? '')),
				'state' => ($untouched === true) ? self::STATE_SHIPPED : self::STATE_CHANGED,
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
	 * @param boolean              $accepted     Whether a local change may be overwritten.
	 *
	 * @return array{adopted: bool, reason: string} What happened, and why not when it did not.
	 */
	public function adopt(
		string $targetSchema,
		string $objectsKey,
		string $targetObject,
		string $set,
		array $newShipped,
		bool $accepted = false,
	): array {
		$current = $this->store->row(configKey: $objectsKey, id: $targetObject);
		if ($current === null) {
			return ['adopted' => false, 'reason' => 'not_found'];
		}

		$state = $this->stateOf(
			targetSchema: $targetSchema,
			targetObject: $targetObject,
			object: $current,
		);

		if ($state['state'] === self::STATE_LOCAL) {
			return ['adopted' => false, 'reason' => 'not_shipped'];
		}

		if ($state['state'] === self::STATE_CHANGED && $accepted === false) {
			return ['adopted' => false, 'reason' => 'changed_locally'];
		}

		$payload = $newShipped;
		$payload['id'] = $targetObject;

		$saved = $this->store->save(configKey: $objectsKey, payload: $payload, id: $targetObject);
		if ($saved === null) {
			return ['adopted' => false, 'reason' => 'write_failed'];
		}

		$this->stamp(
			set: $set,
			targetSchema: $targetSchema,
			targetObject: $targetObject,
			object: $saved,
		);

		return ['adopted' => true, 'reason' => ''];
	}//end adopt()

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
