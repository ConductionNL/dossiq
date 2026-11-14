<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseModelConverter;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * A whole caseModel into a case-plan definition, built on its own.
 *
 * @covers \OCA\Dossiq\Service\CaseModelConverter
 * @uses \OCA\Dossiq\Service\SentryConverter
 * @uses \OCA\Dossiq\Service\PlanItemIndex
 */
class CaseModelConverterTest extends TestCase {

	/**
	 * The flat list nests under its stage in declared order, with the title as name.
	 *
	 * @return void
	 */
	public function testNestsChildrenUnderTheirStageInDeclaredOrder(): void {
		$definition = (new CaseModelConverter())->convertModel(
			caseModel: [
				'title' => ' Vergunning ',
				'planItems' => [
					['id' => 'b', 'type' => 'humanTask', 'parentId' => 's', 'discretionary' => true],
					['id' => 's', 'type' => 'stage', 'name' => 'Behandeling'],
					['id' => 'a', 'type' => 'milestone', 'parentId' => 's', 'description' => ' klaar ', 'authorization' => ['role', 7]],
				],
			]
		);

		$this->assertSame(expected: ['name' => 'Vergunning'], actual: $definition['settings']);
		$this->assertCount(expectedCount: 1, haystack: $definition['items']);
		$stage = $definition['items'][0];
		$this->assertSame(expected: ['s', 'stage', 'Behandeling', true], actual: [$stage['key'], $stage['type'], $stage['name'], $stage['required']]);
		$this->assertSame(expected: ['b', 'a'], actual: array_column($stage['children'], 'key'));
		$this->assertSame(expected: [false, true], actual: [$stage['children'][0]['required'], $stage['children'][0]['discretionary']]);
		$this->assertSame(expected: 'klaar', actual: $stage['children'][1]['description']);
		$this->assertSame(expected: ['role', '7'], actual: $stage['children'][1]['authorization']);
	}//end testNestsChildrenUnderTheirStageInDeclaredOrder()

	/**
	 * Entry criteria go through the sentry converter, named by the plan item.
	 *
	 * @return void
	 */
	public function testConvertsEntryCriteriaThroughTheSentryConverter(): void {
		$definition = (new CaseModelConverter())->convertModel(
			caseModel: ['planItems' => [['id' => 't', 'type' => 'humanTask', 'entryCriteria' => [['ifPart' => ['field' => 'ok', 'operator' => 'truthy']]]]]]
		);

		$this->assertSame(expected: [['if' => ['!!' => ['var' => 'ok']]]], actual: $definition['items'][0]['entryCriteria']);
		$this->assertSame(expected: [], actual: $definition['items'][0]['exitCriteria']);
	}//end testConvertsEntryCriteriaThroughTheSentryConverter()

	/**
	 * A parent that is not a stage is refused by name.
	 *
	 * @return void
	 */
	public function testRefusesAParentThatIsNotAStage(): void {
		$this->expectException(exception: UnexpectedValueException::class);
		$this->expectExceptionMessage(message: "caseModel plan item 'b' names parent 'a', which is not a stage; only a stage nests.");

		(new CaseModelConverter())->convertModel(
			caseModel: ['planItems' => [['id' => 'a', 'type' => 'humanTask'], ['id' => 'b', 'type' => 'humanTask', 'parentId' => 'a']]]
		);
	}//end testRefusesAParentThatIsNotAStage()

	/**
	 * A repeated id is refused.
	 *
	 * @return void
	 */
	public function testRefusesARepeatedId(): void {
		$this->expectException(exception: UnexpectedValueException::class);
		$this->expectExceptionMessage(message: "caseModel repeats plan-item id 'a'; ids are unique within a model.");

		(new CaseModelConverter())->convertModel(caseModel: ['planItems' => [['id' => 'a', 'type' => 'stage'], ['id' => ' a ', 'type' => 'stage']]]);
	}//end testRefusesARepeatedId()

	/**
	 * A discretionary milestone is refused.
	 *
	 * @return void
	 */
	public function testRefusesADiscretionaryMilestone(): void {
		$this->expectException(exception: UnexpectedValueException::class);
		$this->expectExceptionMessage(message: "caseModel plan item 'm' is a discretionary milestone");

		(new CaseModelConverter())->convertModel(caseModel: ['planItems' => [['id' => 'm', 'type' => 'milestone', 'discretionary' => true]]]);
	}//end testRefusesADiscretionaryMilestone()
}//end class
