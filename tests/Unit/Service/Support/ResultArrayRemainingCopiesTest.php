<?php

/**
 * How the audit packet builder and the bulk job hand-off turn a result into an array.
 *
 * Pins both copies (toArray(), asArray()) before they read through
 * SearchesObjects: an array is
 * kept, an object's jsonSerialize() array is used, and anything else (an
 * object without it, a serialisation that is not an array, a scalar, null)
 * becomes an empty array. The copies hold no state, so they are reached by
 * reflection on an instance built without its constructor.
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

use ArrayObject;
use JsonSerializable;
use OCA\Dossiq\Service\Beschikking\AuditPacketBuilder;
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy answers the same array for the same result.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\AuditPacketBuilder
 * @covers \OCA\Dossiq\Service\Bulk\BulkJobHandoff
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ResultArrayRemainingCopiesTest extends TestCase {
	/**
	 * Every class carrying a copy, crossed with every kind of result.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: array<mixed>}>
	 */
	public static function cases(): array {
		$classes = [
			AuditPacketBuilder::class => 'toArray',
			BulkJobHandoff::class => 'asArray',
		];
		$cases = [];
		foreach ($classes as $class => $method) {
			foreach (self::results() as $kind => [$value, $expected]) {
				$cases[$class . ' ' . $kind] = [$class . '::' . $method, $value, $expected];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Each kind of result with the array it becomes.
	 *
	 * @return array<string, array{0: mixed, 1: array<mixed>}>
	 */
	private static function results(): array {
		$object = new stdClass();
		$object->id = 'plain';

		return [
			'array' => [['id' => 'a'], ['id' => 'a']],
			'empty array' => [[], []],
			'serialisable to an array' => [self::serialisable(value: ['id' => 'b']), ['id' => 'b']],
			'serialisable to a string' => [self::serialisable(value: 'b'), []],
			'object without jsonSerialize' => [$object, []],
			'array object without jsonSerialize' => [new ArrayObject(['id' => 'c']), []],
			'string' => ['abc', []],
			'integer' => [7, []],
			'null' => [null, []],
		];
	}//end results()

	/**
	 * A result whose jsonSerialize() answers the given value.
	 *
	 * @param mixed $value What jsonSerialize() answers.
	 *
	 * @return JsonSerializable The result.
	 */
	private static function serialisable(mixed $value): JsonSerializable {
		return new class ($value) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param mixed $value What jsonSerialize() answers.
			 */
			public function __construct(private readonly mixed $value) {
			}//end __construct()

			/**
			 * The configured value.
			 *
			 * @return mixed The value.
			 */
			public function jsonSerialize(): mixed {
				return $this->value;
			}//end jsonSerialize()
		};
	}//end serialisable()

	/**
	 * The copy turns the result into the expected array.
	 *
	 * @param string       $copy     The copy, as Class::method.
	 * @param mixed        $value    The result.
	 * @param array<mixed> $expected The array it becomes.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheResultBecomesTheExpectedArray(string $copy, mixed $value, array $expected): void {
		[$class, $method] = explode(separator: '::', string: $copy);
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertSame(expected: $expected, actual: (new ReflectionMethod($subject, $method))->invoke($subject, $value));
	}//end testTheResultBecomesTheExpectedArray()

}//end class
