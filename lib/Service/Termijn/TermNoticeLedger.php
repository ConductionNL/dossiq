<?php

/**
 * Dossiq TermNoticeLedger.
 *
 * One row per term notice, keyed so the database refuses a second claim. This
 * is what keeps two triggers on one deadline (an engine rung and the daily
 * sweep, or a retried queued job) from mailing the citizen twice: the first
 * caller inserts the key, every other caller's insert fails on the unique
 * index and reads the outcome instead.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Claims, outcomes and releases of term notices.
 *
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
 */
class TermNoticeLedger {

	/**
	 * The table, without the instance prefix.
	 */
	public const TABLE = 'dossiq_term_notices';

	/**
	 * A claim whose send has not finished.
	 */
	public const PENDING = 'pending';

	/**
	 * A notice that left.
	 */
	public const SENT = 'sent';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Claim a notice key. False when somebody holds it already.
	 *
	 * @param string $key      The notice key (64 hex characters).
	 * @param string $template The template, for a reader of the table.
	 * @param string $instance The term instance, for a reader of the table.
	 * @param int    $now      Unix time.
	 *
	 * @return bool True when this caller holds the claim now.
	 *
	 * @throws DbException On any database failure other than the duplicate key.
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function claim(string $key, string $template, string $instance, int $now): bool {
		$query = $this->db->getQueryBuilder();
		$query->insert(self::TABLE)
			->values(
				[
					'notice_key' => $query->createNamedParameter($key),
					'template' => $query->createNamedParameter(substr($template, 0, 64)),
					'instance_ref' => $query->createNamedParameter(substr($instance, 0, 64)),
					'outcome' => $query->createNamedParameter(self::PENDING),
					'updated_at' => $query->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				]
			);

		try {
			$query->executeStatement();
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}

			throw $e;
		}

		return true;
	}//end claim()

	/**
	 * The outcome on a key, or null when nobody holds it.
	 *
	 * @param string $key The notice key.
	 *
	 * @return array{outcome: string, at: int}|null The row.
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function find(string $key): ?array {
		$query = $this->db->getQueryBuilder();
		$query->select('outcome', 'updated_at')
			->from(self::TABLE)
			->where($query->expr()->eq('notice_key', $query->createNamedParameter($key)));

		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (is_array($row) === false) {
			return null;
		}

		return ['outcome' => (string)$row['outcome'], 'at' => (int)$row['updated_at']];
	}//end find()

	/**
	 * Write how a claimed notice ended.
	 *
	 * @param string $key     The notice key.
	 * @param string $outcome `sent`, or `refused:<code>`.
	 * @param int    $now     Unix time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function settle(string $key, string $outcome, int $now): void {
		$query = $this->db->getQueryBuilder();
		$query->update(self::TABLE)
			->set('outcome', $query->createNamedParameter(substr($outcome, 0, 64)))
			->set('updated_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('notice_key', $query->createNamedParameter($key)));
		$query->executeStatement();
	}//end settle()

	/**
	 * Give a claim back, so a later run may try again.
	 *
	 * @param string $key The notice key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function release(string $key): void {
		$query = $this->db->getQueryBuilder();
		$query->delete(self::TABLE)
			->where($query->expr()->eq('notice_key', $query->createNamedParameter($key)));
		$query->executeStatement();
	}//end release()
}//end class
