<?php

/**
 * Dossiq LHS Decision Table Lookup
 *
 * Answers "which intervention does the enforcement strategy prescribe for this
 * severity, behaviour and actor type" by evaluating the projected decision
 * table through OpenRegister, instead of by hand-indexing the matrix cells.
 *
 * WHY THIS EXISTS. The LHS matrix is a three-axis lookup yielding one value,
 * which is a decision table and nothing more exotic. OpenRegister already
 * carries one evaluator for those, whose own suite proves this exact shape
 * evaluates. dossiq indexed the cells into a "severity:behaviour:actorType"
 * dictionary and threw when the triple missed.
 *
 * That hand-rolled index was not merely duplicate work. It is what let the
 * shipped vocabulary split in half without anything noticing: the
 * recommendation schema offered an actorType no cell carried, and the
 * dictionary answered a miss the same way it answers genuinely bad input
 * (dossiq#1596). A declared table cannot hide that: its inputs are NAMED, so
 * the evaluator refuses what it cannot resolve rather than quietly missing.
 *
 * STORED IN OPENREGISTER (openregister#4572). The projected table lives in
 * OpenRegister's decision-tables register under `dossiq.lhs-matrix-<version>`
 * and is evaluated through its DecisionTableStore, the same store a flow step
 * names by `tableRef`.
 *
 * SOFT DEPENDENCY. An OpenRegister without the store, or an instance where the
 * projection was never run, must degrade to the matrix rather than take
 * enforcement recommendations down with it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Vth
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
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Vth;

use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates the projected LHS decision table in OpenRegister.
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */
class LhsDecisionTableLookup {

	/**
	 * The key a projected table carries before its version, before the dossiq prefix.
	 *
	 * @var string
	 */
	public const KEY_STEM = 'lhs-matrix-';

	/**
	 * Constructor.
	 *
	 * @param StoredDecisionTables $tables OpenRegister's decision-tables register.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly StoredDecisionTables $tables,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The stored key of the table projected from a matrix version.
	 *
	 * @param string $matrixVersion The matrix version.
	 *
	 * @return string The stored key, for example `dossiq.lhs-matrix-2`.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function referenceFor(string $matrixVersion): string {
		$version = trim($matrixVersion);
		if ($version === '') {
			$version = '1';
		}

		return $this->tables->keyFor(self::KEY_STEM . $version);
	}//end referenceFor()

	/**
	 * Look up the prescribed intervention for one triple.
	 *
	 * Null is the caller's signal to fall back to the matrix: no store, no
	 * table for this version, or a table that refuses the triple. A refusal by
	 * a table that EXISTS is logged as a warning, because a declared input the
	 * table cannot resolve is exactly the defect this lookup exists to surface.
	 *
	 * @param string $matrixVersion The matrix version the table was projected from.
	 * @param string $severity The severity.
	 * @param string $behaviour The behaviour.
	 * @param string $actorType The actor type.
	 *
	 * @return string|null The intervention, or null to fall back.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function intervention(
		string $matrixVersion,
		string $severity,
		string $behaviour,
		string $actorType,
	): ?string {
		$reference = $this->referenceFor(matrixVersion: $matrixVersion);
		try {
			$result = $this->tables->evaluate(
				reference: $reference,
				inputs: [
					'severity' => $severity,
					'behaviour' => $behaviour,
					'actorType' => $actorType,
				]
			);
		} catch (Throwable $e) {
			$this->logRefusal(reference: $reference, triple: $severity . ':' . $behaviour . ':' . $actorType, failure: $e);
			$result = null;
		}

		$intervention = ($result['outputs']['intervention'] ?? null);
		if (is_string($intervention) === true && $intervention !== '') {
			return $intervention;
		}

		return null;
	}//end intervention()

	/**
	 * Log why the table did not answer.
	 *
	 * A missing table is the ordinary state before the projection runs, so it
	 * is debug; anything else is a table that exists and refused.
	 *
	 * @param string $reference The stored key.
	 * @param string $triple The triple.
	 * @param Throwable $failure What the store threw.
	 *
	 * @return void
	 */
	private function logRefusal(string $reference, string $triple, Throwable $failure): void {
		$context = ['table' => $reference, 'triple' => $triple, 'exception' => $failure->getMessage()];
		if ($failure instanceof \UnexpectedValueException) {
			$this->logger->debug('Dossiq: no runnable LHS decision table in OpenRegister', $context);
			return;
		}

		$this->logger->warning('Dossiq: the LHS decision table refused a triple', $context);
	}//end logRefusal()
}//end class
