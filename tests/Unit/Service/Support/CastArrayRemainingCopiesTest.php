<?php

/**
 * How the case cover sheet, the call-plan router and the background object reader turn a result into an array.
 *
 * Pins each copy (toArray(), normalize()) before they share one helper: an
 * array is kept; an object with jsonSerialize() gives that value cast to an
 * array, whatever it is; any other object gives its public properties; and
 * anything else is empty. The copies hold no state, so they are reached by
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

use JsonSerializable;
use OCA\Dossiq\BackgroundJob\OpenRegisterObjects;
use OCA\Dossiq\Service\BelplanRoutingService;
use OCA\Dossiq\Service\CaseVoorbladService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy answers the same array for the same result.
 *
 * @covers \OCA\Dossiq\BackgroundJob\OpenRegisterObjects
 * @covers \OCA\Dossiq\Service\BelplanRoutingService
 * @covers \OCA\Dossiq\Service\CaseVoorbladService
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CastArrayRemainingCopiesTest extends TestCase {
	/**
	 * Every copy, crossed with every kind of result.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: array<mixed>}>
	 */
	public static function cases(): array {
		$copies = [
			OpenRegisterObjects::class => 'toArray',
			BelplanRoutingService::class => 'toArray',
			CaseVoorbladService::class => 'toArray',
		];
		$plain = new stdClass();
		$plain->id = 'plain';
		$results = [
			'array' => [['id' => 'a'], ['id' => 'a']],
			'serialisable to an array' => [self::serialisable(value: ['id' => 'b']), ['id' => 'b']],
			'serialisable to a string' => [self::serialisable(value: 'b'), ['b']],
			'serialisable to null' => [self::serialisable(value: null), []],
			'plain object' => [$plain, ['id' => 'plain']],
			'string' => ['abc', []],
			'integer' => [7, []],
			'null' => [null, []],
		];
		$cases = [];
		foreach ($copies as $class => $method) {
			foreach ($results as $kind => [$value, $expected]) {
				$cases[$class . ' ' . $kind] = [$class . '::' . $method, $value, $expected];
			}
		}

		return $cases;
	}//end cases()

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
