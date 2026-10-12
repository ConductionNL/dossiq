<?php

/**
 * Unit tests for reading back what a case write answered.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-intake-answers-with-the-case-number-and-deadline-req-sws-011
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use JsonSerializable;
use OCA\Dossiq\Woo\WooWrittenCase;
use PHPUnit\Framework\TestCase;

/**
 * The id, number and dates of a written case, read from every shape the object service answers.
 *
 * The fixture is a neutral case: nothing in it belongs to one procedure.
 *
 * @covers \OCA\Dossiq\Woo\WooWrittenCase
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-intake-answers-with-the-case-number-and-deadline-req-sws-011
 */
class WrittenCaseTest extends TestCase {

	/**
	 * A neutral written case.
	 *
	 * @return array<string, mixed>
	 */
	private function row(): array {
		return [
			'id' => 'case-1',
			'identifier' => 'Z-2026-000123',
			'deadline' => '2026-11-09',
			'receivedAt' => '2026-10-12T09:00:00+00:00',
			'termStartsAt' => '2026-10-13',
			'title' => 'Not part of the answer',
		];
	}//end row()

	/**
	 * An array answer gives the id and the four values the confirmation names.
	 *
	 * @return void
	 */
	public function testAnArrayAnswerIsReadAsItIs(): void {
		$read = (new WooWrittenCase())->read(saved: $this->row());

		self::assertSame('case-1', $read['id']);
		self::assertSame(
			[
				'identifier' => 'Z-2026-000123',
				'deadline' => '2026-11-09',
				'receivedAt' => '2026-10-12T09:00:00+00:00',
				'termStartsAt' => '2026-10-13',
			],
			$read['answer']
		);
	}//end testAnArrayAnswerIsReadAsItIs()

	/**
	 * The id in `@self` wins over a top-level id, and `uuid` is the last resort.
	 *
	 * @return void
	 */
	public function testTheIdComesFromSelfThenIdThenUuid(): void {
		$case = new WooWrittenCase();

		self::assertSame('self-id', $case->read(saved: ['@self' => ['id' => 'self-id'], 'id' => 'top', 'uuid' => 'u'])['id']);
		self::assertSame('top', $case->read(saved: ['id' => 'top', 'uuid' => 'u'])['id']);
		self::assertSame('u', $case->read(saved: ['uuid' => 'u'])['id']);
	}//end testTheIdComesFromSelfThenIdThenUuid()

	/**
	 * An entity that serialises to JSON is read through that.
	 *
	 * @return void
	 */
	public function testAnEntityIsReadThroughJsonSerialize(): void {
		$row = $this->row();
		$entity = new class ($row) implements JsonSerializable {

			/**
			 * Keep the row.
			 *
			 * @param array<string, mixed> $row The stored row.
			 */
			public function __construct(private readonly array $row) {
			}//end __construct()

			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}//end jsonSerialize()

			/**
			 * The entity's own uuid, which wins over the row's id.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'entity-uuid';
			}//end getUuid()
		};

		$read = (new WooWrittenCase())->read(saved: $entity);

		self::assertSame('entity-uuid', $read['id']);
		self::assertSame('Z-2026-000123', $read['answer']['identifier']);
	}//end testAnEntityIsReadThroughJsonSerialize()

	/**
	 * An entity with only `getObject()` is read through that.
	 *
	 * @return void
	 */
	public function testAnEntityIsReadThroughGetObject(): void {
		$row = $this->row();
		$entity = new class ($row) {

			/**
			 * Keep the row.
			 *
			 * @param array<string, mixed> $row The stored row.
			 */
			public function __construct(private readonly array $row) {
			}//end __construct()

			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->row;
			}//end getObject()
		};

		$read = (new WooWrittenCase())->read(saved: $entity);

		self::assertSame('case-1', $read['id']);
		self::assertSame('2026-11-09', $read['answer']['deadline']);
	}//end testAnEntityIsReadThroughGetObject()

	/**
	 * A value the case does not carry, or carries blank, is left out; nothing is made up.
	 *
	 * @return void
	 */
	public function testABlankOrMissingValueIsLeftOut(): void {
		$read = (new WooWrittenCase())->read(saved: ['id' => 'case-2', 'identifier' => '  ', 'deadline' => '2026-11-09']);

		self::assertSame(['deadline' => '2026-11-09'], $read['answer']);
	}//end testABlankOrMissingValueIsLeftOut()

	/**
	 * Something that is no row at all answers an empty id and an empty answer.
	 *
	 * @return void
	 */
	public function testSomethingUnreadableAnswersNothing(): void {
		$case = new WooWrittenCase();

		self::assertSame(['id' => '', 'answer' => []], $case->read(saved: null));
		self::assertSame(['id' => '', 'answer' => []], $case->read(saved: 'not a row'));
		self::assertSame(['id' => '', 'answer' => []], $case->read(saved: new \stdClass()));
	}//end testSomethingUnreadableAnswersNothing()
}//end class
