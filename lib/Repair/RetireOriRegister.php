<?php

/**
 * Retires the ORI (Open Raadsinformatie) register — but only once it is safe.
 *
 * WHY dossiq STOPS OWNING ORI. Raadsinformatie is decidiq's domain: meetings,
 * agenda items, votes, council members and political groups are its core Popolo
 * model, and dossiq's `ori` register is a parallel, Dutch-named duplicate of it
 * (ADR-019/ADR-022). This app therefore no longer declares that register:
 * `lib/Settings/ori_register.json` and `RegisterOriRegister` are gone, and a
 * fresh install never provisions one.
 *
 * WHY THIS STEP DOES NOT SIMPLY DELETE IT. Dropping the declaration stops the
 * register being re-created; it does nothing about the register already on an
 * existing install, and that register holds real objects — 115 of them on the
 * reference instance, across vergadering, agendapunt, raadsdocument, stemming,
 * raadslid and fractie. Deleting those without a migration destroys the record.
 *
 * The paired decidiq change (`ori-adoption`) ships the importer that moves them.
 * Until an instance has actually run it, this step WARNS AND KEEPS EVERYTHING.
 * Removal never outruns migration; that ordering is the whole point.
 *
 * Four branches, and each says which case it is rather than passing silently:
 *
 *   1. OpenRegister absent            -> no-op, info line.
 *   2. `ori` register absent          -> no-op (already retired, or never
 *                                        provisioned on a fresh install).
 *   3. register present WITH objects  -> WARN and keep. Names the command to
 *                                        run. The upgrade itself still succeeds.
 *   4. register present, no objects   -> retire it.
 *
 * Branch 3 is the one worth testing hardest: a guard that cannot say NO is not
 * a guard. There is a test that seeds a single unmigrated object and asserts the
 * register survives.
 *
 * Idempotent: safe to run on every upgrade until it can finally retire.
 *
 * @category  Repair
 * @package   OCA\Dossiq\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/ori-removal/specs/ori-removal/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Removes the ORI register once its objects are gone, and not before.
 *
 * @spec openspec/changes/ori-removal/specs/ori-removal/spec.md
 */
class RetireOriRegister implements IRepairStep {

	/**
	 * Slug of the register being retired.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'ori';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/ori-removal/specs/ori-removal/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ori-removal/specs/ori-removal/spec.md
	 */
	public function getName(): string {
		return 'Retire the ORI register once its objects have moved to decidiq';
	}//end getName()

	/**
	 * Retire the register, or say why it cannot be retired yet.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-removal/specs/ori-removal/spec.md
	 */
	public function run(IOutput $output): void {
		$registerId = $this->findRegisterId();
		if ($registerId === null) {
			$output->info(
				'RetireOriRegister: no `ori` register on this install '
				. '(already retired, never provisioned, or OpenRegister is absent); nothing to do.'
			);
			return;
		}

		$objects = $this->countObjects(registerId: $registerId);
		if ($objects === null) {
			$output->info('RetireOriRegister: could not count the register\'s objects; keeping it.');
			return;
		}

		if ($objects > 0) {
			$message = sprintf(
				'RetireOriRegister: the `ori` register still holds %d object(s); KEEPING it. '
				. 'Migrate them into decidiq first (see the decidiq `ori-adoption` importer), '
				. 'then re-run `occ maintenance:repair`.',
				$objects
			);
			$this->logger->warning($message, ['register' => self::REGISTER_SLUG, 'objects' => $objects]);
			$output->warning($message);
			return;
		}

		if ($this->deleteRegister(registerId: $registerId) === true) {
			$output->info('RetireOriRegister: the `ori` register was empty and has been retired.');
			return;
		}

		$output->info('RetireOriRegister: could not retire the `ori` register; it is unchanged.');
	}//end run()

	/**
	 * Resolve the numeric id of the `ori` register.
	 *
	 * A read failure and an absent register are deliberately collapsed to null
	 * here, because both mean the same thing to the caller: do nothing. The
	 * caller's message names all three possibilities rather than asserting one.
	 *
	 * @return int|null The register id, or null when absent/unreadable.
	 */
	private function findRegisterId(): ?int {
		try {
			$row = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug = ?',
				[self::REGISTER_SLUG]
			)->fetch();
		} catch (Exception $e) {
			$this->logger->info(
				'RetireOriRegister: could not look up the register; leaving it alone.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($row) === false || isset($row['id']) === false) {
			return null;
		}

		return (int)$row['id'];
	}//end findRegisterId()

	/**
	 * Count the objects still stored against this register.
	 *
	 * Objects live in per-pair shard tables named
	 * `<prefix>openregister_table_<registerId>_<schemaId>`, so the count is the
	 * sum over every table carrying this register's id. Returns null on failure
	 * — which the caller treats as "keep", never as "empty". An unreadable
	 * count must not be able to authorise a deletion.
	 *
	 * @param int $registerId The register whose objects to count.
	 *
	 * @return int|null Object count, or null when it cannot be established.
	 */
	private function countObjects(int $registerId): ?int {
		// Matched on the `openregister_table_` MARKER rather than on a computed
		// prefix: the Nextcloud table prefix is configurable per install, and a
		// guess that misses returns zero tables — which would read as "empty"
		// and authorise a deletion.
		try {
			$rows = $this->db->executeQuery(
				'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?',
				['public', '%openregister\_table\_' . $registerId . '\_%']
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RetireOriRegister: could not list the register\'s shard tables; keeping the register.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		$total = 0;
		foreach ($rows as $row) {
			$table = (string)($row['table_name'] ?? '');
			// Only ever a name this query returned, and shape-checked before it
			// reaches SQL: the count is unparameterisable, so the identifier has
			// to be provably ours.
			if (preg_match('/^[A-Za-z0-9_]+openregister_table_' . $registerId . '_[0-9]+$/', $table) !== 1) {
				continue;
			}

			try {
				$count = $this->db->executeQuery('SELECT COUNT(*) AS c FROM "' . $table . '"')->fetch();
			} catch (Exception $e) {
				$this->logger->warning(
					'RetireOriRegister: could not count a shard table; keeping the register.',
					['table' => $table, 'exception' => $e->getMessage()]
				);
				return null;
			}

			$total += (int)($count['c'] ?? 0);
		}

		return $total;
	}//end countObjects()

	/**
	 * Delete the register row.
	 *
	 * Only ever reached with a verified-empty register. The schemas and shard
	 * tables are left to OpenRegister's own housekeeping rather than dropped
	 * here — this step's remit is the register, and a repair step that issues
	 * DDL it does not own is how a rollback becomes impossible.
	 *
	 * @param int $registerId The register to delete.
	 *
	 * @return bool True when the row was deleted.
	 */
	private function deleteRegister(int $registerId): bool {
		try {
			$this->db->executeStatement(
				'DELETE FROM `*PREFIX*openregister_registers` WHERE id = ? AND slug = ?',
				[$registerId, self::REGISTER_SLUG]
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'RetireOriRegister: deleting the register failed.',
				['register' => self::REGISTER_SLUG, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end deleteRegister()
}//end class
