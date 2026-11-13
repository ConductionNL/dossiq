<?php

/**
 * How the merge store and the term and deadline listeners read the id a reference names.
 *
 * Pins the four copies (referencedId(), referenced()) before they share one
 * helper: a string is trimmed; an array gives its id cast to a string and
 * trimmed, or an empty string; anything else is empty.
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

use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Listener\OneStatutoryTermPerCaseListener;
use OCA\Dossiq\Listener\TermStatusClockListener;
use OCA\Dossiq\Service\Cases\CaseMergeStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy reads the same id from the same reference.
 *
 * @covers \OCA\Dossiq\Listener\DeadlineCaseCreatedListener
 * @covers \OCA\Dossiq\Listener\OneStatutoryTermPerCaseListener
 * @covers \OCA\Dossiq\Listener\TermStatusClockListener
 * @covers \OCA\Dossiq\Service\Cases\CaseMergeStore
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ReferencedIdCopiesTest extends TestCase {
	/**
	 * Every copy, crossed with every kind of reference.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string}>
	 */
	public static function cases(): array {
		$copies = [
			CaseMergeStore::class . '::referencedId',
			DeadlineCaseCreatedListener::class . '::referenced',
			OneStatutoryTermPerCaseListener::class . '::referenced',
			TermStatusClockListener::class . '::referenced',
		];
		$references = [
			'a string, trimmed' => [' c-1 ', 'c-1'],
			'an id, trimmed' => [['id' => ' c-2 ', 'uuid' => 'u'], 'c-2'],
			'a numeric id, cast' => [['id' => 5], '5'],
			'a uuid alone is not read' => [['uuid' => 'u-1'], ''],
			'a null id' => [['id' => null], ''],
			'an integer' => [7, ''],
			'null' => [null, ''],
			'an object' => [new stdClass(), ''],
		];
		$cases = [];
		foreach ($copies as $copy) {
			foreach ($references as $label => [$value, $expected]) {
				$cases[$copy . ' ' . $label] = [$copy, $value, $expected];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The copy reads the expected id.
	 *
	 * @param string $copy     The copy, as Class::method.
	 * @param mixed  $value    The reference.
	 * @param string $expected The id it reads.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheReferenceGivesTheExpectedId(string $copy, mixed $value, string $expected): void {
		[$class, $method] = explode(separator: '::', string: $copy);
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertSame(expected: $expected, actual: (new ReflectionMethod($subject, $method))->invoke($subject, $value));
	}//end testTheReferenceGivesTheExpectedId()
}//end class
