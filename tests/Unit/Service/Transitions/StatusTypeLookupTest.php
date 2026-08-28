<?php

/**
 * Unit tests for StatusTypeLookup — a status by id, and by name.
 *
 * The name→id direction is what a SHIPPED flow depends on: it can only carry a
 * status NAME, because statusType uuids are minted per installation. So a
 * lookup that quietly fails to resolve is a case that quietly stops moving.
 *
 * Both spellings of the case type's status list (`statusTypes` and the older
 * `statusses`) are exercised, because reading only one is how a case type ends
 * up with statuses that validate and cannot be resolved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StatusTypeLookupTest extends TestCase {

	/**
	 * A lookup over a fixed set of objects, keyed by id.
	 *
	 * @param array<string, array<string, mixed>> $objects The store contents.
	 * @param boolean                             $throws  Whether reads throw.
	 *
	 * @return StatusTypeLookup The lookup.
	 */
	private function lookup(array $objects, bool $throws = false): StatusTypeLookup {
		$objectService = new class($objects, $throws) {
			public function __construct(private array $objects, private bool $throws) {
			}

			public function find(string $id, string $register, string $schema): array {
				if ($this->throws === true) {
					throw new RuntimeException('unreadable');
				}

				return ($this->objects[$id] ?? []);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : $key)
		);

		return new StatusTypeLookup($settings);
	}//end lookup()

	public function testAnEmbeddedStatusResolvesByName(): void {
		$lookup = $this->lookup([
			'ct-1' => ['statusTypes' => [['id' => 's-2', 'name' => 'In behandeling']]],
		]);

		self::assertSame('s-2', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'In behandeling'));
	}//end testAnEmbeddedStatusResolvesByName()

	/**
	 * The name is authored by hand in seed data and in the UI, so the match is
	 * trimmed and case-insensitive.
	 */
	public function testTheMatchIsTrimmedAndCaseInsensitive(): void {
		$lookup = $this->lookup([
			'ct-1' => ['statusTypes' => [['id' => 's-2', 'name' => 'In behandeling']]],
		]);

		self::assertSame('s-2', $lookup->idForName(caseTypeId: 'ct-1', statusName: '  IN BEHANDELING '));
	}//end testTheMatchIsTrimmedAndCaseInsensitive()

	/**
	 * 🔴 A near miss returns NOTHING, so the caller can refuse.
	 *
	 * Fuzzy matching here would move a case to whichever status looked closest.
	 */
	public function testANearMissResolvesToNothing(): void {
		$lookup = $this->lookup([
			'ct-1' => ['statusTypes' => [['id' => 's-2', 'name' => 'In behandeling']]],
		]);

		self::assertSame('', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'In behandelin'));
	}//end testANearMissResolvesToNothing()

	/**
	 * A bare uuid in the list is resolved through the statusType object.
	 */
	public function testABareUuidIsResolvedThroughItsObject(): void {
		$lookup = $this->lookup([
			'ct-1' => ['statusTypes' => ['s-9']],
			's-9' => ['name' => 'Afgehandeld'],
		]);

		self::assertSame('s-9', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'Afgehandeld'));
	}//end testABareUuidIsResolvedThroughItsObject()

	/**
	 * 🔑 The OLDER spelling is read too.
	 */
	public function testTheLegacyStatussesSpellingIsRead(): void {
		$lookup = $this->lookup([
			'ct-1' => ['statusses' => [['id' => 's-3', 'name' => 'Ontvangen']]],
		]);

		self::assertSame('s-3', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'Ontvangen'));
	}//end testTheLegacyStatussesSpellingIsRead()

	public function testAnUnknownCaseTypeResolvesToNothing(): void {
		$lookup = $this->lookup([]);

		self::assertSame('', $lookup->idForName(caseTypeId: 'nope', statusName: 'Ontvangen'));
	}//end testAnUnknownCaseTypeResolvesToNothing()

	public function testEmptyArgumentsResolveToNothing(): void {
		$lookup = $this->lookup(['ct-1' => ['statusTypes' => [['id' => 's', 'name' => 'X']]]]);

		self::assertSame('', $lookup->idForName(caseTypeId: '', statusName: 'X'));
		self::assertSame('', $lookup->idForName(caseTypeId: 'ct-1', statusName: '  '));
	}//end testEmptyArgumentsResolveToNothing()

	public function testAnUnreadableStoreResolvesToNothingRatherThanThrowing(): void {
		$lookup = $this->lookup(['ct-1' => ['statusTypes' => [['id' => 's', 'name' => 'X']]]], throws: true);

		self::assertSame('', $lookup->idForName(caseTypeId: 'ct-1', statusName: 'X'));
	}//end testAnUnreadableStoreResolvesToNothingRatherThanThrowing()

	public function testTheIdToNameDirectionAlsoWorks(): void {
		$lookup = $this->lookup(['s-1' => ['name' => 'Bij commissie']]);

		self::assertSame('Bij commissie', $lookup->nameFor(statusTypeId: 's-1'));
		self::assertSame('', $lookup->nameFor(statusTypeId: ''));
	}//end testTheIdToNameDirectionAlsoWorks()

	public function testStatusesOfReturnsTheWholeMap(): void {
		$lookup = $this->lookup([
			'ct-1' => [
				'statusTypes' => [
					['id' => 's-1', 'name' => 'Ontvangen'],
					['id' => 's-2', 'name' => 'Afgehandeld'],
				],
			],
		]);

		self::assertSame(
			['s-1' => 'Ontvangen', 's-2' => 'Afgehandeld'],
			$lookup->statusesOf(caseTypeId: 'ct-1')
		);
	}//end testStatusesOfReturnsTheWholeMap()

	/**
	 * A malformed entry is skipped rather than producing an id of ''.
	 */
	public function testEntriesWithNoIdAreSkipped(): void {
		$lookup = $this->lookup([
			'ct-1' => ['statusTypes' => [['name' => 'Nameless'], ['id' => 's-1', 'name' => 'Real']]],
		]);

		self::assertSame(['s-1' => 'Real'], $lookup->statusesOf(caseTypeId: 'ct-1'));
	}//end testEntriesWithNoIdAreSkipped()
}//end class
