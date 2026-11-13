<?php

/**
 * How the priority, attention, relation, deadline and case-type readers read a reference's id.
 *
 * Pins each private copy (referenceId(), reference(), ref(), text(), uuidOf()) before they share one helper: a
 * string is trimmed; an array gives its id, else its uuid, trimmed when it
 * is a string; anything else is an empty string. The copies hold no state,
 * so they are reached by reflection on an instance built without its
 * constructor.
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

use OCA\Dossiq\Listener\CaseInheritedDeadlineListener;
use OCA\Dossiq\Listener\CaseTypeParentCycleListener;
use OCA\Dossiq\Listener\RequesterRegistrySubscriptionListener;
use OCA\Dossiq\Service\CaseAttentionMarkerService;
use OCA\Dossiq\Service\CasePriorityService;
use OCA\Dossiq\Service\CaseType\PortalWithdrawalTarget;
use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use OCA\Dossiq\Service\Termijn\TermCaseTypeResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy reads the same id from the same reference.
 *
 * @covers \OCA\Dossiq\Listener\CaseInheritedDeadlineListener
 * @covers \OCA\Dossiq\Listener\CaseTypeParentCycleListener
 * @covers \OCA\Dossiq\Listener\RequesterRegistrySubscriptionListener
 * @covers \OCA\Dossiq\Service\CaseAttentionMarkerService
 * @covers \OCA\Dossiq\Service\CasePriorityService
 * @covers \OCA\Dossiq\Service\CaseType\PortalWithdrawalTarget
 * @covers \OCA\Dossiq\Service\Relation\CaseRelationCodec
 * @covers \OCA\Dossiq\Service\Termijn\TermCaseTypeResolver
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ReferenceIdCopiesTest extends TestCase {
	/**
	 * Every class carrying a copy, crossed with every kind of reference.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string}>
	 */
	public static function cases(): array {
		$classes = [
			CaseInheritedDeadlineListener::class => 'referenceId',
			CaseTypeParentCycleListener::class => 'referenceId',
			RequesterRegistrySubscriptionListener::class => 'reference',
			CaseAttentionMarkerService::class => 'referenceId',
			CasePriorityService::class => 'referenceId',
			PortalWithdrawalTarget::class => 'text',
			CaseRelationCodec::class => 'uuidOf',
			TermCaseTypeResolver::class => 'ref',
		];
		$references = [
			'a string, trimmed' => [' ct-1 ', 'ct-1'],
			'a blank string' => ['  ', ''],
			'an id' => [['id' => ' ct-2 ', 'uuid' => 'u'], 'ct-2'],
			'a uuid' => [['uuid' => 'u-1'], 'u-1'],
			'a null id falls to the uuid' => [['id' => null, 'uuid' => 'u-2'], 'u-2'],
			'an empty id does not fall to the uuid' => [['id' => '', 'uuid' => 'u-3'], ''],
			'a numeric id is not read' => [['id' => 5, 'uuid' => 'u-4'], ''],
			'an array without either' => [['name' => 'x'], ''],
			'an integer' => [7, ''],
			'null' => [null, ''],
			'an object' => [new stdClass(), ''],
		];
		$cases = [];
		foreach ($classes as $class => $method) {
			foreach ($references as $label => [$value, $expected]) {
				$cases[$class . ' ' . $label] = [$class . '::' . $method, $value, $expected];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The copy reads the expected id.
	 *
	 * @param string       $copy     The copy, as Class::method.
	 * @param mixed        $value    The reference.
	 * @param string       $expected The id it reads.
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
