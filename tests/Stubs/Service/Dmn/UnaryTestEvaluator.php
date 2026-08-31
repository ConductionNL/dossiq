<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for OpenRegister's unary-test evaluator.
 *
 * dossiq reads VALID_TYPES from it when validating a decision table's declared
 * column types. Mirrors openregister lib/Service/Dmn/UnaryTestEvaluator.php.
 *
 * The grammar itself is NOT reimplemented here: it is tested in openregister
 * against the real class (tests/Unit/Service/Dmn/UnaryTestEvaluatorTest.php,
 * the matrix that used to live in this repo). A stub that reimplemented it
 * would be a second grammar to keep in step, which is the duplication this
 * whole change exists to remove.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Dmn
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * Stub of OpenRegister's UnaryTestEvaluator.
 */
class UnaryTestEvaluator {

	/**
	 * The column types the real evaluator accepts.
	 *
	 * @var array<int, string>
	 */
	public const VALID_TYPES = ['string', 'number', 'boolean', 'date'];

	/**
	 * Whether a value satisfies a unary test.
	 *
	 * @param string $expression The cell text.
	 * @param mixed  $value      The runtime value.
	 * @param string $type       The declared column type.
	 *
	 * @return boolean Always false in the stub; mock it where behaviour matters.
	 */
	public function matches(string $expression, mixed $value, string $type): bool {
		return false;

	}//end matches()

	/**
	 * Coerce a value to its declared type.
	 *
	 * @param mixed  $value The runtime value.
	 * @param string $type  The declared column type.
	 *
	 * @return string|float|boolean|integer The value, uncoerced in the stub.
	 */
	public function coerce(mixed $value, string $type): string|float|bool|int {
		return '';

	}//end coerce()

}//end class
