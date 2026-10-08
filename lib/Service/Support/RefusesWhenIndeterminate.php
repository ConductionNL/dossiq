<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Exception\RefusedException;
use Throwable;

/**
 * Saying "I could not find out" in one place.
 *
 * Two services reach a store that can be unreadable while deciding whether
 * someone may act: RoleResolverService reads the roles on a case, and
 * TenantAuthenticationService reads the tenant mandate matrix. Both used to
 * answer the empty value, and an empty value reads as "nobody holds that
 * role" or "you are not authorised" by the time it reaches a user.
 *
 * The refusal these raise instead carries status 503, which is neither yes
 * nor no. One copy is what stops the two drifting apart.
 */
trait RefusesWhenIndeterminate {
	/**
	 * Refuse, naming the rule and the sentence the user finally reads.
	 *
	 * @param string    $rule     The machine-readable rule name.
	 * @param string    $sentence The sentence written for the reader.
	 * @param Throwable $previous What actually went wrong underneath.
	 *
	 * @return never
	 *
	 * @throws RefusedException Always: that is the point of the call.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	private function refuseIndeterminate(string $rule, string $sentence, Throwable $previous): never {
		throw new RefusedException(
			rule: $rule,
			sentence: $sentence,
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end refuseIndeterminate()

	/**
	 * Read from a store, and refuse when the read fails rather than answer nothing.
	 *
	 * The catch that used to sit at each of these call sites returned the
	 * empty value, so "the register threw" and "there is nothing here"
	 * arrived as the same answer. Here the failure is logged with what was
	 * being read, and the caller is refused.
	 *
	 * @param callable $read     The read to attempt.
	 * @param string   $what     What was being read, for the log line.
	 * @param string   $rule     The machine-readable rule name.
	 * @param string   $sentence The sentence written for the reader.
	 *
	 * @return mixed Whatever the read answered.
	 *
	 * @throws RefusedException When the read throws anything at all.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	private function readOrRefuse(callable $read, string $what, string $rule, string $sentence): mixed {
		try {
			return $read();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: ' . $what . ' could not be read: ' . $e->getMessage(),
			);

			$this->refuseIndeterminate(rule: $rule, sentence: $sentence, previous: $e);
		}//end try
	}//end readOrRefuse()
}//end trait
