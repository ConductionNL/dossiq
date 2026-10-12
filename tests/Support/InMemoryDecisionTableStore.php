<?php

/**
 * An in-memory stand-in for OpenRegister's DecisionTableStore.
 *
 * Finds a table by key the way the real store does (a missing key throws
 * UnexpectedValueException) and evaluates it with OpenRegister's own
 * DecisionTableEvaluator, the verbatim copy under tests/Stubs, so a test
 * cannot agree with a table the real engine would refuse.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator;
use UnexpectedValueException;

/**
 * Stores tables by key and evaluates them with the real evaluator.
 */
final class InMemoryDecisionTableStore {

	/**
	 * The references asked for, in order.
	 *
	 * @var list<string>
	 */
	public array $asked = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $tables Tables by stored key.
	 */
	public function __construct(
		private array $tables = [],
	) {
	}//end __construct()

	/**
	 * The table a reference names.
	 *
	 * @param string $reference The stored key.
	 *
	 * @return array<string, mixed> The table.
	 *
	 * @throws UnexpectedValueException When no table carries the key.
	 */
	public function find(string $reference): array {
		$this->asked[] = $reference;
		if (array_key_exists($reference, $this->tables) === false) {
			throw new UnexpectedValueException(sprintf('No decision table has the key or id "%s".', $reference));
		}

		return $this->tables[$reference];
	}//end find()

	/**
	 * Evaluate the table a reference names.
	 *
	 * @param string $reference The stored key.
	 * @param array<string, mixed> $inputs The inputs.
	 *
	 * @return array<string, mixed> outputs, matchedRuleIds, hitPolicy, tableKey.
	 */
	public function evaluate(string $reference, array $inputs): array {
		$table = $this->find(reference: $reference);
		$result = (new DecisionTableEvaluator(new UnaryTestEvaluator()))->evaluate($table, $inputs);

		return [
			'outputs' => $result['outputs'],
			'matchedRuleIds' => $result['matchedRuleIds'],
			'hitPolicy' => $result['hitPolicy'],
			'tableKey' => (string)($table['key'] ?? ''),
		];
	}//end evaluate()
}//end class
