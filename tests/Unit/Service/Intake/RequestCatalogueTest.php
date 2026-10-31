<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-request-catalogue-is-the-case-types-declared-for-intake
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Ai\CaseTypeAiFeatures;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Intake\RequestCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue is the published case types declared for intake, one entry per
 * identifier, and names no request type of its own.
 *
 * @covers \OCA\Dossiq\Service\Intake\RequestCatalogue
 * @uses   \OCA\Dossiq\Service\Ai\CaseTypeAiFeatures
 */
class RequestCatalogueTest extends TestCase {

	/**
	 * Build the catalogue over these case types, with the real declaration reader.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The case types the store holds.
	 *
	 * @return RequestCatalogue
	 */
	private function catalogue(array $caseTypes): RequestCatalogue {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('everyCaseType')->willReturn($caseTypes);
		$store->method('rowId')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));

		return new RequestCatalogue($store, new CaseTypeAiFeatures());
	}//end catalogue()

	/**
	 * Only a published case type with a feature on the intake surface is offered,
	 * and its identifier is the type the intake tool files under.
	 *
	 * @return void
	 */
	public function testOnlyPublishedCaseTypesDeclaredForIntakeAreOffered(): void {
		$entries = $this->catalogue(
			[
				['id' => 'ct-1', 'identifier' => 'melding-openbare-ruimte', 'title' => 'Melding openbare ruimte', 'description' => 'Iets kapot op straat', 'aiFeatures' => ['conversational-intake' => 'intake']],
				['id' => 'ct-2', 'identifier' => 'bezwaar', 'title' => 'Bezwaar', 'aiFeatures' => ['document-summary' => 'case']],
				['id' => 'ct-3', 'identifier' => 'subsidie', 'title' => 'Subsidie', 'isDraft' => true, 'aiFeatures' => ['conversational-intake' => 'intake']],
				['id' => 'ct-4', 'identifier' => 'afval', 'title' => 'Afval', 'aiFeatures' => ['conversational-intake' => 'none']],
				['id' => 'ct-5', 'identifier' => 'afval-container', 'title' => 'Afvalcontainer', 'aiFeatures' => ['conversational-intake' => 'intake']],
			]
		)->entries();

		self::assertSame(
			[
				['type' => 'afval-container', 'title' => 'Afvalcontainer', 'description' => ''],
				['type' => 'melding-openbare-ruimte', 'title' => 'Melding openbare ruimte', 'description' => 'Iets kapot op straat'],
			],
			$entries
		);
	}//end testOnlyPublishedCaseTypesDeclaredForIntakeAreOffered()

	/**
	 * Two published versions of one request type are one entry, and a case type
	 * without an identifier is offered by its id.
	 *
	 * @return void
	 */
	public function testVersionsCollapseAndAnIdStandsInForAMissingIdentifier(): void {
		$intake = ['conversational-intake' => 'intake'];
		$entries = $this->catalogue(
			[
				['id' => 'ct-1', 'identifier' => 'melding', 'title' => 'Melding', 'aiFeatures' => $intake],
				['id' => 'ct-2', 'identifier' => 'melding', 'title' => 'Melding', 'aiFeatures' => $intake],
				['id' => 'ct-9', 'title' => 'Zonder identificatie', 'aiFeatures' => $intake],
			]
		)->entries();

		self::assertSame(['melding', 'ct-9'], array_column($entries, 'type'));
	}//end testVersionsCollapseAndAnIdStandsInForAMissingIdentifier()

	/**
	 * Nothing declared for intake means an empty catalogue, so the intake hands
	 * every conversation to a person rather than guessing.
	 *
	 * @return void
	 */
	public function testNothingDeclaredIsAnEmptyCatalogue(): void {
		self::assertSame([], $this->catalogue([['id' => 'ct-1', 'identifier' => 'x', 'title' => 'X']])->entries());
	}//end testNothingDeclaredIsAnEmptyCatalogue()
	/**
	 * A case type's title and description are translatable, so a stored row
	 * carries them as a language map; the catalogue reads them as text.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function testATranslatableTitleAndDescriptionAreReadAsText(): void {
		$entries = $this->catalogue(
			[
				['id' => 'ct-1', 'identifier' => 'melding', 'title' => ['nl' => 'Melding openbare ruimte'], 'description' => ['nl' => 'Iets kapot op straat'], 'aiFeatures' => ['conversational-intake' => 'intake']],
			]
		)->entries();

		self::assertSame([['type' => 'melding', 'title' => 'Melding openbare ruimte', 'description' => 'Iets kapot op straat']], $entries);
	}//end testATranslatableTitleAndDescriptionAreReadAsText()
}//end class
