<?php

/**
 * StatusType checklist schema unit tests.
 *
 * A property added to the register JSON is inert until the schema's own
 * `version` moves: OpenRegister fast-skips a schema whose version did not
 * change, so a checklist authored on a case type would be dropped on import
 * and the engine would read an empty list forever. The version assertion here
 * is therefore part of the property, not decoration around it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Unit\Fixtures\ShippedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * The shipped `statusType.checklist` property, as the importer sees it.
 *
 * @uses \OCA\Dossiq\Tests\Unit\Fixtures\ShippedRegisterSchema
 */
class StatusChecklistSchemaTest extends TestCase {
	/**
	 * The statusType schema declares the checklist property.
	 *
	 * @return void
	 */
	public function testStatusTypeDeclaresChecklist(): void {
		self::assertContains('checklist', ShippedRegisterSchema::declaredPropertyNames('statusType'));
	}//end testStatusTypeDeclaresChecklist()

	/**
	 * The property is a list of `{title, required}` objects, title mandatory.
	 *
	 * @return void
	 */
	public function testChecklistIsAListOfTitleAndRequired(): void {
		$checklist = ShippedRegisterSchema::schema('statusType')['properties']['checklist'];

		self::assertSame('array', $checklist['type']);
		self::assertSame('object', $checklist['items']['type']);
		self::assertSame(['title'], $checklist['items']['required']);
		self::assertSame('string', $checklist['items']['properties']['title']['type']);
		self::assertSame('boolean', $checklist['items']['properties']['required']['type']);
		self::assertFalse($checklist['items']['properties']['required']['default']);
	}//end testChecklistIsAListOfTitleAndRequired()

	/**
	 * The description says the tasks are created on entry.
	 *
	 * The list is authored on the case type's JSON until the authoring surface
	 * lands, so the description is the only place that says what happens.
	 *
	 * @return void
	 */
	public function testChecklistDescriptionSaysTasksAreCreatedOnEntry(): void {
		$checklist = ShippedRegisterSchema::schema('statusType')['properties']['checklist'];

		self::assertSame('Checklist', $checklist['title']);
		self::assertStringContainsString('task', (string)$checklist['description']);
		self::assertStringContainsString('enters this status', (string)$checklist['description']);
	}//end testChecklistDescriptionSaysTasksAreCreatedOnEntry()

	/**
	 * The schema version moved past the 1.0.0 that shipped without checklist.
	 *
	 * @return void
	 */
	public function testStatusTypeVersionMovedWithTheProperty(): void {
		$version = (string)ShippedRegisterSchema::schema('statusType')['version'];

		self::assertNotSame('1.0.0', $version, 'A new property on an unchanged version is never imported');
		self::assertSame(1, version_compare($version, '1.0.0'));
	}//end testStatusTypeVersionMovedWithTheProperty()

	/**
	 * A round trip: a checklist authored on a status survives `asStored()`.
	 *
	 * `asStored()` drops every property the schema does not declare, which is
	 * exactly what the live store does. A checklist that comes back out of it
	 * is a checklist the store will hand the engine.
	 *
	 * @return void
	 */
	public function testAnAuthoredChecklistSurvivesTheStore(): void {
		$stored = ShippedRegisterSchema::asStored(
			[
				'id' => 'status-1',
				'name' => 'Intake',
				'caseType' => 'ct-1',
				'order' => 1,
				'checklist' => [
					['title' => 'Check the objection is on time', 'required' => true],
					['title' => 'Confirm receipt to the objector', 'required' => false],
				],
			],
			'statusType'
		);

		self::assertCount(2, $stored['checklist']);
		self::assertSame('Check the objection is on time', $stored['checklist'][0]['title']);
		self::assertTrue($stored['checklist'][0]['required']);
	}//end testAnAuthoredChecklistSurvivesTheStore()
}//end class
