<?php

/**
 * How the approval gates, routing, the flow projection and subsidies read a stored list of rows.
 *
 * Pins the four copies (rowsOf(), decodeSteps(), decodeList()) before they
 * share one helper: a JSON string is decoded first; anything that is then
 * not an array is an empty list; otherwise the array rows are kept, in
 * order, re-indexed, and every other entry dropped.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Controller\RoutingController;
use OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator;
use OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy reads the same rows from the same stored value.
 *
 * @covers \OCA\Dossiq\Controller\RoutingController
 * @covers \OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator
 * @covers \OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration
 * @covers \OCA\Dossiq\Service\Subsidie\SubsidieService
 *
 * @spec openspec/specs/case-management/spec.md
 * @uses \OCA\Dossiq\Service\Support\RowList
 */
class RowListCopiesTest extends TestCase {
	/**
	 * Every copy, crossed with every kind of stored value.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: array<int, array<mixed>>}>
	 */
	public static function cases(): array {
		$copies = [
			ApprovalGateDeclaration::class . '::rowsOf',
			RoutingController::class . '::decodeSteps',
			AutomaticActionFlowMigrator::class . '::decodeList',
			SubsidieService::class . '::rowsOf',
		];
		$values = [
			'a JSON list' => ['[{"a":1},5,{"b":2}]', [['a' => 1], ['b' => 2]]],
			'a JSON object of rows' => ['{"x":{"a":1},"y":"no"}', [['a' => 1]]],
			'a JSON scalar' => ['5', []],
			'invalid JSON' => ['{nope', []],
			'an empty string' => ['', []],
			'an array, re-indexed' => [['k' => ['a' => 1], 'j' => 'no', 'm' => ['b' => 2]], [['a' => 1], ['b' => 2]]],
			'an empty array' => [[], []],
			'null' => [null, []],
			'an integer' => [7, []],
			'an object' => [new stdClass(), []],
		];
		$cases = [];
		foreach ($copies as $copy) {
			foreach ($values as $label => [$value, $expected]) {
				$cases[$copy . ' ' . $label] = [$copy, $value, $expected];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The copy reads the expected rows.
	 *
	 * @param string                       $copy     The copy, as Class::method.
	 * @param mixed                        $value    The stored value.
	 * @param array<int, array<mixed>>     $expected The rows.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheValueGivesTheExpectedRows(string $copy, mixed $value, array $expected): void {
		[$class, $method] = explode(separator: '::', string: $copy);
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertSame(expected: $expected, actual: (new ReflectionMethod($subject, $method))->invoke($subject, $value));
	}//end testTheValueGivesTheExpectedRows()
}//end class
