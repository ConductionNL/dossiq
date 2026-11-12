<?php

/**
 * How the dossier compiler and the objection hook read the uuid a reference names.
 *
 * Pins both private extractUuid() copies before they share one helper: a
 * string is trimmed; an array gives the first of id, uuid, @self.uuid, case
 * and target that is a set string, trimmed; anything else is empty.
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

use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\DossierCompiler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Both copies read the same uuid from the same reference.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook
 * @covers \OCA\Dossiq\Service\DossierCompiler
 *
 * @spec openspec/specs/case-management/spec.md
 */
class DossierReferenceUuidCopiesTest extends TestCase {
	/**
	 * Both copies, crossed with every kind of reference.
	 *
	 * @return array<string, array{0: class-string, 1: mixed, 2: string}>
	 */
	public static function cases(): array {
		$references = [
			'a string, trimmed' => [' c-1 ', 'c-1'],
			'an id' => [['id' => ' c-2 ', 'uuid' => 'u'], 'c-2'],
			'a uuid' => [['uuid' => 'u-1'], 'u-1'],
			'a dotted self uuid key' => [['@self.uuid' => 's-1'], 's-1'],
			'a case' => [['case' => 'k-1', 'target' => 't'], 'k-1'],
			'a target' => [['target' => 't-1'], 't-1'],
			'a numeric id is skipped' => [['id' => 5, 'uuid' => 'u-2'], 'u-2'],
			'a null id is skipped' => [['id' => null, 'case' => 'k-2'], 'k-2'],
			'an empty id is taken' => [['id' => '', 'uuid' => 'u-3'], ''],
			'a nested self is not read' => [['@self' => ['uuid' => 'n']], ''],
			'an integer' => [7, ''],
			'null' => [null, ''],
			'an object' => [new stdClass(), ''],
		];
		$cases = [];
		foreach ([BezwaarCreationHook::class, DossierCompiler::class] as $class) {
			foreach ($references as $label => [$value, $expected]) {
				$cases[$class . ' ' . $label] = [$class, $value, $expected];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The copy reads the expected uuid.
	 *
	 * @param class-string $class    The class carrying the copy.
	 * @param mixed        $value    The reference.
	 * @param string       $expected The uuid it reads.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheReferenceGivesTheExpectedUuid(string $class, mixed $value, string $expected): void {
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertSame(expected: $expected, actual: (new ReflectionMethod($subject, 'extractUuid'))->invoke($subject, $value));
	}//end testTheReferenceGivesTheExpectedUuid()
}//end class
