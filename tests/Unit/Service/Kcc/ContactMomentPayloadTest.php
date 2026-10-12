<?php

/**
 * ContactMomentPayload Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\ContactMomentPayload;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The payload on its own: no storage, no service.
 *
 * @covers \OCA\Dossiq\Service\Kcc\ContactMomentPayload
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ContactMomentPayloadTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * @return ContactMomentPayload
	 */
	private function payload(): ContactMomentPayload {
		return new ContactMomentPayload(dates: $this->caseDatesFrozenAt('2026-10-12T10:00:00+02:00'));
	}//end payload()

	/**
	 * Each enumerated field refuses a value outside its list and names the field.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: string}>
	 */
	public static function refusedCreates(): array {
		return [
			'no channel' => [[], 'Invalid channel'],
			'unknown channel' => [['channel' => 'pigeon'], 'Invalid channel'],
			'unknown direction' => [['channel' => 'phone', 'direction' => 'sideways'], 'Invalid direction'],
			'unknown outcome' => [['channel' => 'phone', 'outcome' => 'banana'], 'Invalid outcome'],
			'empty outcome' => [['channel' => 'phone', 'outcome' => ''], 'Invalid outcome'],
		];
	}//end refusedCreates()

	/**
	 * @param array<string,mixed> $data The request data.
	 * @param string $message The expected refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusedCreates')]
	public function testCreateRefusesAValueOutsideItsList(array $data, string $message): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage($message);
		$this->payload()->forCreate($data, 'agent1');
	}//end testCreateRefusesAValueOutsideItsList()

	/**
	 * Every channel and outcome on the list is accepted.
	 *
	 * @return void
	 */
	public function testCreateAcceptsEveryListedValue(): void {
		foreach (ContactMomentPayload::CHANNELS as $channel) {
			foreach (ContactMomentPayload::OUTCOMES as $outcome) {
				$made = $this->payload()->forCreate(['channel' => $channel, 'outcome' => $outcome], 'agent1');
				$this->assertSame([$channel, $outcome], [$made['channel'], $made['outcome']]);
			}
		}
	}//end testCreateAcceptsEveryListedValue()

	/**
	 * A given start is normalised to the administered zone; an unreadable one is refused.
	 *
	 * @return void
	 */
	public function testCreateNormalisesTheStart(): void {
		$made = $this->payload()->forCreate(['channel' => 'phone', 'startedAt' => '2026-10-12T07:30:00Z'], 'agent1');
		$this->assertSame('2026-10-12T09:30:00+02:00', $made['startedAt']);

		$this->expectException(\InvalidArgumentException::class);
		$this->payload()->forCreate(['channel' => 'phone', 'startedAt' => 'soon'], 'agent1');
	}//end testCreateNormalisesTheStart()

	/**
	 * Every pass-through field is copied as a string, and only those.
	 *
	 * @return void
	 */
	public function testCreateCopiesThePassthroughFields(): void {
		$fields = ['customerRef', 'customerName', 'customerPhone', 'customerEmail', 'assignedTeam', 'assignedDomain', 'case', 'linkedContactMoment'];
		$data = ['channel' => 'letter', 'id' => 'forged', 'durationSeconds' => 99];
		foreach ($fields as $index => $field) {
			$data[$field] = $index + 1;
		}

		$made = $this->payload()->forCreate($data, 'agent1');

		foreach ($fields as $index => $field) {
			$this->assertSame((string)($index + 1), $made[$field]);
		}

		$this->assertArrayNotHasKey('id', $made);
		$this->assertArrayNotHasKey('durationSeconds', $made);
	}//end testCreateCopiesThePassthroughFields()

	/**
	 * An update leaves a moment without an outcome alone and keeps unknown stored keys.
	 *
	 * @return void
	 */
	public function testMergedKeepsWhatItDoesNotOwn(): void {
		$existing = ['id' => 'm-1', 'channel' => 'phone', 'custom' => ['x' => 1]];

		$this->assertSame($existing, $this->payload()->merged($existing, ['channel' => 'email', 'id' => 'other']));
		$this->assertSame(
			$existing + ['assignedTeam' => '', 'tags' => []],
			$this->payload()->merged($existing, ['assignedTeam' => null, 'tags' => []])
		);
	}//end testMergedKeepsWhatItDoesNotOwn()

	/**
	 * The duration of an update is measured from the stored start.
	 *
	 * @return void
	 */
	public function testMergedMeasuresFromTheStoredStart(): void {
		$merged = $this->payload()->merged(
			['startedAt' => '2026-10-12T09:00:00+02:00', 'outcome' => 'open'],
			['endedAt' => '2026-10-12T07:01:00Z', 'startedAt' => '2020-01-01T00:00:00Z']
		);

		$this->assertSame('2026-10-12T09:00:00+02:00', $merged['startedAt']);
		$this->assertSame('2026-10-12T07:01:00Z', $merged['endedAt']);
		$this->assertSame(60, $merged['durationSeconds']);
	}//end testMergedMeasuresFromTheStoredStart()
}//end class
