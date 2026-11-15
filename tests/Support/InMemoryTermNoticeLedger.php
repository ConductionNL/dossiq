<?php

/**
 * Dossiq InMemoryTermNoticeLedger.
 *
 * The term notice ledger without a database: the same claim, settle and
 * release rules over an array, so a test can run two triggers against one
 * notice and see the unique key hold.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Support
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

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Termijn\TermNoticeLedger;

/**
 * Claims by key, in memory.
 */
final class InMemoryTermNoticeLedger extends TermNoticeLedger {

	/**
	 * Rows by key: outcome and when it was last touched.
	 *
	 * @var array<string, array{outcome: string, template: string, instance: string, at: int}>
	 */
	public array $rows = [];

	/**
	 * No database here.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * Claim a key.
	 *
	 * @param string $key      The notice key.
	 * @param string $template The template.
	 * @param string $instance The term instance.
	 * @param int    $now      Unix time.
	 *
	 * @return bool True when this caller holds it now.
	 */
	public function claim(string $key, string $template, string $instance, int $now): bool {
		if (isset($this->rows[$key]) === true) {
			return false;
		}

		$this->rows[$key] = ['outcome' => self::PENDING, 'template' => $template, 'instance' => $instance, 'at' => $now];

		return true;
	}//end claim()

	/**
	 * The outcome on a key.
	 *
	 * @param string $key The notice key.
	 *
	 * @return array{outcome: string, at: int}|null The row, or null.
	 */
	public function find(string $key): ?array {
		if (isset($this->rows[$key]) === false) {
			return null;
		}

		return ['outcome' => $this->rows[$key]['outcome'], 'at' => $this->rows[$key]['at']];
	}//end find()

	/**
	 * Write the outcome.
	 *
	 * @param string $key     The notice key.
	 * @param string $outcome The outcome.
	 * @param int    $now     Unix time.
	 *
	 * @return void
	 */
	public function settle(string $key, string $outcome, int $now): void {
		if (isset($this->rows[$key]) === true) {
			$this->rows[$key]['outcome'] = $outcome;
			$this->rows[$key]['at'] = $now;
		}
	}//end settle()

	/**
	 * Give a key back.
	 *
	 * @param string $key The notice key.
	 *
	 * @return void
	 */
	public function release(string $key): void {
		unset($this->rows[$key]);
	}//end release()
}//end class
