<?php

/**
 * The journal entry and outcome of a case-type rebind.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\RebindMove;
use PHPUnit\Framework\TestCase;

/**
 * What a rebind writes to the case's journal and reports back.
 *
 * @covers \OCA\Dossiq\Service\Cases\RebindMove
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
class RebindMoveTest extends TestCase {

	/**
	 * What one rebind applied.
	 *
	 * @return array<string, mixed> The applied facts.
	 */
	private static function applied(): array {
		return [
			'sourceId' => 'type-old',
			'fromTitle' => 'Omgevingsvergunning v1',
			'toTitle' => 'Omgevingsvergunning v2',
			'actorUid' => 'alice',
			'run' => ['migrated' => true, 'reason' => 'run-moved'],
			'terms' => ['kept' => 2],
			'impact' => [
				'dropped' => [['name' => 'oldField', 'value' => 'x']],
				'ported' => [['source' => 'a', 'target' => 'b', 'value' => '1', 'newValue' => 1]],
				'required' => [['name' => 'newRequired'], ['name' => 'another']],
			],
		];
	}//end applied()

	/**
	 * The subject.
	 *
	 * @return RebindMove The move.
	 */
	private static function move(): RebindMove {
		return new RebindMove(caseId: 'case-1', targetCaseTypeId: 'type-new', targetStatusId: 'status-3', reason: 'Wrong type at intake');
	}//end move()

	/**
	 * The entry is appended after the entries a JSON journal already holds, with both bindings and the impact.
	 *
	 * @return void
	 */
	public function testTheEntryIsAppendedToAJsonJournal(): void {
		$case = ['id' => 'case-1', 'activity' => json_encode([['type' => 'note', 'text' => 'first']])];

		$out = self::move()->journal($case, self::applied());

		self::assertSame('case-1', $out['id']);
		$entries = json_decode($out['activity'], true);
		self::assertCount(2, $entries);
		self::assertSame(['type' => 'note', 'text' => 'first'], $entries[0]);

		$entry = $entries[1];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $entry['timestamp']);
		unset($entry['timestamp']);
		self::assertSame(
			[
				'type' => 'case-type-rebind',
				'fromCaseType' => 'type-old',
				'fromCaseTypeTitle' => 'Omgevingsvergunning v1',
				'toCaseType' => 'type-new',
				'toCaseTypeTitle' => 'Omgevingsvergunning v2',
				'status' => 'status-3',
				'reason' => 'Wrong type at intake',
				'actor' => 'alice',
				'droppedProperties' => [['name' => 'oldField', 'value' => 'x']],
				'portedProperties' => [['from' => 'a', 'to' => 'b', 'value' => '1', 'newValue' => 1]],
				'answeredProperties' => ['newRequired', 'another'],
			],
			$entry
		);
	}//end testTheEntryIsAppendedToAJsonJournal()

	/**
	 * A journal held as a list is kept as it is, and the result is written back as JSON.
	 *
	 * @return void
	 */
	public function testAListJournalIsKept(): void {
		$case = ['activity' => [['type' => 'note'], ['type' => 'claim']]];

		$entries = json_decode(self::move()->journal($case, self::applied())['activity'], true);

		self::assertSame(['note', 'claim', 'case-type-rebind'], array_column($entries, 'type'));
	}//end testAListJournalIsKept()

	/**
	 * Journals that hold nothing readable.
	 *
	 * @return array<string, array{0: mixed}> The raw values.
	 */
	public static function unreadableJournalProvider(): array {
		return [
			'absent' => [null],
			'blank string' => ['   '],
			'not json' => ['{broken'],
			'json scalar' => ['"text"'],
			'a number' => [42],
		];
	}//end unreadableJournalProvider()

	/**
	 * An unreadable journal starts over with only this entry.
	 *
	 * @param mixed $raw The case's activity.
	 *
	 * @dataProvider unreadableJournalProvider
	 *
	 * @return void
	 */
	public function testAnUnreadableJournalStartsWithThisEntry(mixed $raw): void {
		$case = ($raw === null) ? [] : ['activity' => $raw];

		$entries = json_decode(self::move()->journal($case, self::applied())['activity'], true);

		self::assertCount(1, $entries);
		self::assertSame('case-type-rebind', $entries[0]['type']);
	}//end testAnUnreadableJournalStartsWithThisEntry()

	/**
	 * The outcome names both bindings, the run, the terms and the property changes by name.
	 *
	 * @return void
	 */
	public function testTheOutcomeReportsWhatTheRebindApplied(): void {
		self::assertSame(
			[
				'rebound' => true,
				'from' => 'type-old',
				'to' => 'type-new',
				'status' => 'status-3',
				'reason' => 'Wrong type at intake',
				'run' => ['moved' => true, 'reason' => 'run-moved'],
				'terms' => ['kept' => 2],
				'properties' => [
					'dropped' => ['oldField'],
					'ported' => [['from' => 'a', 'to' => 'b']],
					'answered' => ['newRequired', 'another'],
				],
			],
			self::move()->outcome(self::applied())
		);
	}//end testTheOutcomeReportsWhatTheRebindApplied()
}//end class
