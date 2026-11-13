<?php

/**
 * Six readers that take a stored value as an array, decoding a JSON string first.
 *
 * Each of them reads a stored value the same way before doing its own work: a
 * JSON string is decoded, and anything that is then not an array counts as an
 * empty array. So for each one, a value stored as JSON answers exactly what
 * the same value stored as an array answers, and every value that is not an
 * array (a JSON scalar, invalid JSON, an empty or blank string, null, a
 * number, an object) answers what an empty array answers. These pin that, on
 * a value that makes each reader do real work.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Repair\MigrateCasePlanStrings;
use OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration;
use OCA\Dossiq\Service\Intake\AdmissibilityJudgement;
use OCA\Dossiq\Service\SociaalDomein\CasePlanReview;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Service\Vth\LhsRecommendationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

class StoredArrayCopiesTest extends TestCase {

	/**
	 * Every reader and a stored array that makes it do real work.
	 *
	 * @return array<string, array{0: string, 1: array<mixed>}> Per reader: its name and the array.
	 */
	public static function readers(): array {
		return [
			'admissibility declaration' => ['admissibility', ['enabled' => true, 'inadmissibleResultType' => ' rt-1 ', 'moment' => 'intake']],
			'remedy clause declaration' => ['remedy', ['kind' => ' Bezwaar ', 'termDays' => 42, 'enabled' => false, 'label' => ' Bezwaar ']],
			'lhs matrix cells'          => ['lhs', [['severity' => 'B', 'behaviour' => 'b2', 'actorType' => 'burger', 'm' => 1], 'junk', ['severity' => 'A']]],
			'status checklist'          => ['checklist', [['title' => ' Stukken ', 'required' => 1], ['title' => ''], 'junk', ['title' => 'Advies']]],
			'case plan reviews'         => ['reviews', ['x' => ['at' => '2026-01-01'], 'junk', ['at' => '2026-02-01']]],
			'case plan texts'           => ['texts', ['een', ' twee ', '', 3]],
		];
	}//end readers()

	/**
	 * What a reader answers for a stored value.
	 *
	 * @param string $reader The reader.
	 * @param mixed  $stored The stored value.
	 *
	 * @return mixed The answer.
	 */
	private function read(string $reader, mixed $stored): mixed {
		return match ($reader) {
			'admissibility' => $this->bare(class: AdmissibilityJudgement::class)
				->declarationFor(caseType: [AdmissibilityJudgement::DECLARATION => $stored]),
			'remedy'        => $this->bare(class: RemedyClauseDeclaration::class)
				->declarationFor(caseType: [RemedyClauseDeclaration::DECLARATION => $stored]),
			'lhs'           => $this->private(object: $this->bare(class: LhsRecommendationService::class), method: 'indexCells', arguments: [$stored]),
			'checklist'     => $this->checklist(stored: $stored),
			'reviews'       => $this->private(object: $this->bare(class: CasePlanReview::class), method: 'reviewsOf', arguments: [['reviews' => $stored]]),
			'texts'         => $this->private(
				object: $this->bare(class: MigrateCasePlanStrings::class),
				method: 'textsOf',
				arguments: [['goals' => $stored], 'goals']
			),
		};
	}//end read()

	/**
	 * An instance without its constructor.
	 *
	 * @param class-string $class The class.
	 *
	 * @return object The instance.
	 */
	private function bare(string $class): object {
		return (new ReflectionClass($class))->newInstanceWithoutConstructor();
	}//end bare()

	/**
	 * What a private method answers.
	 *
	 * @param object       $object    The instance.
	 * @param string       $method    The method.
	 * @param array<mixed> $arguments Its arguments.
	 *
	 * @return mixed The answer.
	 */
	private function private(object $object, string $method, array $arguments): mixed {
		return (new ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
	}//end private()

	/**
	 * The checklist a status row storing $stored answers.
	 *
	 * @param mixed $stored The stored checklist.
	 *
	 * @return mixed The items.
	 */
	private function checklist(mixed $stored): mixed {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn(['checklist' => $stored]);
		$checklist = $this->bare(class: StatusChecklist::class);
		(function () use ($lookup): void {
			$this->statusTypeLookup = $lookup;
		})->call($checklist);

		return $checklist->itemsFor(statusTypeId: 'st-1');
	}//end checklist()

	/**
	 * A value stored as JSON answers what the same value stored as an array answers.
	 *
	 * @param string       $reader The reader.
	 * @param array<mixed> $stored The stored array.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testJsonAnswersAsTheArrayDoes(string $reader, array $stored): void {
		$answer = $this->read(reader: $reader, stored: $stored);

		self::assertNotSame($this->read(reader: $reader, stored: []), $answer, 'the sample must make the reader do real work');
		self::assertSame($answer, $this->read(reader: $reader, stored: (string)json_encode($stored)));
	}//end testJsonAnswersAsTheArrayDoes()

	/**
	 * Every value that is not an array answers what an empty array answers.
	 *
	 * @param string $reader The reader.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testANonArrayAnswersAsAnEmptyArrayDoes(string $reader): void {
		$empty = $this->read(reader: $reader, stored: []);
		foreach (['"text"', '42', 'true', 'null', '{not json', '', '   ', null, 7, 1.5, false, new stdClass()] as $stored) {
			self::assertSame($empty, $this->read(reader: $reader, stored: $stored), 'stored ' . var_export($stored, true));
		}
	}//end testANonArrayAnswersAsAnEmptyArrayDoes()
}//end class
