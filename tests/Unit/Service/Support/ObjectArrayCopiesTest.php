<?php

/**
 * How the routing controller, the role resolver and the recommendation store turn a result into an array.
 *
 * Pins each toArray() copy before they share one helper: an array is kept; an
 * object gives its jsonSerialize() array, else its toArray() array, else its
 * public properties; anything that is not an object or array is empty. The
 * copies hold no state, so they are reached by reflection on an instance
 * built without its constructor.
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
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\Vth\LhsRecommendationStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy answers the same array for the same result.
 *
 * @covers \OCA\Dossiq\Controller\RoutingController
 * @covers \OCA\Dossiq\Service\RoleResolverService
 * @covers \OCA\Dossiq\Service\Vth\LhsRecommendationStore
 *
 * @spec openspec/specs/case-management/spec.md
 * @uses \OCA\Dossiq\Service\Support\ObjectArray
 */
class ObjectArrayCopiesTest extends TestCase {
	/**
	 * Every class carrying a copy, crossed with every kind of result.
	 *
	 * @return array<string, array{0: class-string, 1: mixed, 2: array<mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach ([RoutingController::class, RoleResolverService::class, LhsRecommendationStore::class] as $class) {
			foreach (self::results() as $kind => [$value, $expected]) {
				$cases[$class . ' ' . $kind] = [$class, $value, $expected];
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
		$plain = new stdClass();
		$plain->id = 'plain';

		return [
			'array' => [['id' => 'a'], ['id' => 'a']],
			'serialisable to an array' => [self::answering(serialised: ['id' => 'b'], listed: ['id' => 'x']), ['id' => 'b']],
			'serialisable to a string, listable' => [self::answering(serialised: 'b', listed: ['id' => 'c']), ['id' => 'c']],
			'serialisable to a string, listed as a string' => [self::answering(serialised: 'b', listed: 'c'), ['shown' => 'yes', 'serialised' => 'b', 'listed' => 'c']],
			'plain object' => [$plain, ['id' => 'plain']],
			'string' => ['abc', []],
			'integer' => [7, []],
			'null' => [null, []],
		];
	}//end results()

	/**
	 * A result answering the given jsonSerialize() and toArray() values, its properties public.
	 *
	 * @param mixed $serialised What jsonSerialize() answers.
	 * @param mixed $listed     What toArray() answers.
	 *
	 * @return object The result.
	 */
	private static function answering(mixed $serialised, mixed $listed): object {
		return new class ($serialised, $listed) {
			/**
			 * The one public property, seen when the object is cast.
			 *
			 * @var string
			 */
			public string $shown = 'yes';

			/**
			 * Constructor.
			 *
			 * @param mixed $serialised What jsonSerialize() answers.
			 * @param mixed $listed     What toArray() answers.
			 */
			public function __construct(public readonly mixed $serialised, public readonly mixed $listed) {
			}//end __construct()

			/**
			 * The configured serialisation.
			 *
			 * @return mixed The value.
			 */
			public function jsonSerialize(): mixed {
				return $this->serialised;
			}//end jsonSerialize()

			/**
			 * The configured listing.
			 *
			 * @return mixed The value.
			 */
			public function toArray(): mixed {
				return $this->listed;
			}//end toArray()
		};
	}//end answering()

	/**
	 * The copy turns the result into the expected array.
	 *
	 * @param class-string $class    The class carrying the copy.
	 * @param mixed        $value    The result.
	 * @param array<mixed> $expected The array it becomes.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheResultBecomesTheExpectedArray(string $class, mixed $value, array $expected): void {
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertSame(expected: $expected, actual: (new ReflectionMethod($subject, 'toArray'))->invoke($subject, $value));
	}//end testTheResultBecomesTheExpectedArray()
}//end class
