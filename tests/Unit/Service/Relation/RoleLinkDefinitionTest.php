<?php

/**
 * A relation role is read, or refused with a sentence that names what is wrong.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\Relation\RoleLinkDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\RoleLinkDefinition
 */
class RoleLinkDefinitionTest extends TestCase {

	/**
	 * A role with only a key points at a case, writes `vervolg` and opens nothing.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$role = new RoleLinkDefinition(config: ['role' => 'follows']);

		self::assertSame(RoleLinkDefinition::TARGET_CASE, $role->target);
		self::assertSame('vervolg', $role->natureRelationship);
		self::assertSame('follows', $role->label);
		self::assertFalse($role->requiredAtIntake);
		self::assertFalse($role->opensRecord());
		self::assertNull($role->recordActorField);
	}//end testDefaults()

	/**
	 * Malformed roles, and the word each refusal must name.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function malformed(): array {
		return [
			'not an object'           => ['reviews', 'must be an object'],
			'no key'                  => [['label' => 'x'], '`role`'],
			'unknown target'          => [['role' => 'r', 'target' => 'document'], "'document'"],
			'object without schema'   => [['role' => 'r', 'target' => 'object'], '`targetSchema`'],
			'unknown relation type'   => [['role' => 'r', 'natureRelationship' => 'parent'], "'parent'"],
			'record is not an object' => [['role' => 'r', 'record' => 'review'], '`record`'],
		];
	}//end malformed()

	/**
	 * A malformed role is refused naming the field.
	 *
	 * @param mixed  $config The entry.
	 * @param string $names  What the refusal names.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testAMalformedRoleIsRefused(mixed $config, string $names): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($names);
		new RoleLinkDefinition(config: $config);
	}//end testAMalformedRoleIsRefused()
}//end class
