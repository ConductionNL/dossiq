<?php

/**
 * Unit tests for WorkflowJsonProperty: a JSON-stored workflow property is
 * written as given when already a string, read back as a list from either an
 * array or a JSON string, and its steps are only the object entries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 */
class WorkflowJsonPropertyTest extends TestCase {

	public function testAStringIsStoredAsGivenAndAnythingElseAsJson(): void {
		$json = new WorkflowJsonProperty();

		$this->assertSame('[{"id":1}]', $json->encode('[{"id":1}]'));
		$this->assertSame('not json', $json->encode('not json'));
		$this->assertSame('[{"id":1}]', $json->encode([['id' => 1]]));
		$this->assertSame('null', $json->encode(null));
	}//end testAStringIsStoredAsGivenAndAnythingElseAsJson()

	public function testAListIsReadFromAnArrayOrAJsonString(): void {
		$json = new WorkflowJsonProperty();

		$this->assertSame([['id' => 1]], $json->decodeList([['id' => 1]]));
		$this->assertSame([['id' => 1], 'x'], $json->decodeList('[{"id":1},"x"]'));
		$this->assertSame(['a' => 1], $json->decodeList('{"a":1}'));
	}//end testAListIsReadFromAnArrayOrAJsonString()

	public function testAnythingUnreadableIsAnEmptyList(): void {
		$json = new WorkflowJsonProperty();

		$this->assertSame([], $json->decodeList(''));
		$this->assertSame([], $json->decodeList('not json'));
		$this->assertSame([], $json->decodeList('"a string"'));
		$this->assertSame([], $json->decodeList(42));
		$this->assertSame([], $json->decodeList(null));
	}//end testAnythingUnreadableIsAnEmptyList()

	public function testStepsAreTheObjectEntriesOfTheStepsProperty(): void {
		$json = new WorkflowJsonProperty();

		$this->assertSame([['id' => 's1'], ['id' => 's2']], $json->stepsOf(['steps' => '[{"id":"s1"},"junk",{"id":"s2"},3]']));
		$this->assertSame([['id' => 's1']], $json->stepsOf(['steps' => [['id' => 's1'], null]]));
		$this->assertSame([], $json->stepsOf([]));
	}//end testStepsAreTheObjectEntriesOfTheStepsProperty()
}//end class
