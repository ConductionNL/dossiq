<?php

/**
 * Translatable type names reach an English reader in English.
 *
 * The names a user reads (process mining phases, the intake catalogue, content
 * templates, role names, status descriptions, obligation titles) are read in the
 * reader's language; only stable key matches stay Dutch first.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Ai\CaseTypeAiFeatures;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Intake\RequestCatalogue;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer;
use OCA\Dossiq\Service\ProcessMining\TransitionMatrixBuilder;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for the reader's language on translatable type names.
 *
 * @covers \OCA\Dossiq\Service\Support\TranslatedText
 * @covers \OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer
 * @covers \OCA\Dossiq\Service\ProcessMining\TransitionMatrixBuilder
 * @covers \OCA\Dossiq\Service\Intake\RequestCatalogue
 * @covers \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @covers \OCA\Dossiq\Service\Transitions\TransitionPreconditions
 */
class TranslatableNamesForAnEnglishReaderTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * Text in English, for an English reader.
	 *
	 * @return TranslatedText
	 */
	private function english(): TranslatedText {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');

		return new TranslatedText(l10n: $l10n);
	}//end english()

	/**
	 * The process mining phases and transitions are named in English.
	 *
	 * @return void
	 */
	public function testProcessMiningNamesPhasesInEnglish(): void {
		$index = ['st-1' => ['name' => ['nl' => 'Publicatie', 'en' => 'Publication']], 'st-2' => ['name' => ['nl' => 'Besluit', 'en' => 'Decision']]];

		$stats = (new DwellTimeAnalyzer(dates: $this->caseDates(), text: $this->english()))
			->aggregateDwellStats([['caseId' => 'c1', 'statusId' => 'st-1', 'hours' => 4.0]], $index);
		$matrix = (new TransitionMatrixBuilder(text: $this->english()))
			->computeTransitionMatrix(['c1' => [['statusType' => 'st-2'], ['statusType' => 'st-1']]], $index);

		self::assertSame('Publication', $stats[0]['statusName']);
		self::assertSame('Decision', $matrix['matrix'][0]['fromName']);
	}//end testProcessMiningNamesPhasesInEnglish()

	/**
	 * The intake catalogue offers a request type by its English title.
	 *
	 * @return void
	 */
	public function testTheIntakeCatalogueIsInEnglish(): void {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('everyCaseType')->willReturn([
			['id' => 'ct-1', 'identifier' => 'complaint', 'title' => ['nl' => 'Klacht', 'en' => 'Complaint'], 'description' => ['nl' => 'Een klacht', 'en' => 'A complaint'], 'aiFeatures' => ['conversational-intake' => 'intake']],
		]);

		$entries = (new RequestCatalogue(caseTypes: $store, aiFeatures: new CaseTypeAiFeatures(), text: $this->english()))->entries();

		self::assertSame([['type' => 'complaint', 'title' => 'Complaint', 'description' => 'A complaint']], $entries);
	}//end testTheIntakeCatalogueIsInEnglish()

	/**
	 * A status description and an obligation title are read in English.
	 *
	 * @return void
	 */
	public function testAStatusDescriptionAndAnObligationTitleAreInEnglish(): void {
		$lookup = $this->getMockBuilder(StatusTypeLookup::class)
			->setConstructorArgs([$this->createMock(SettingsService::class), $this->createMock(CaseTypeResolver::class)])
			->onlyMethods(['rowFor'])
			->getMock();
		$lookup->method('rowFor')->willReturn(['description' => ['nl' => 'Wij beoordelen uw verzoek.', 'en' => 'We are assessing your request.']]);
		$store = new CaseStatusStore(
			settingsService: $this->createMock(SettingsService::class),
			statusTypeLookup: $lookup,
			logger: $this->createMock(LoggerInterface::class),
			text: $this->english(),
		);

		$obligations = $this->createMock(ObligationService::class);
		$obligations->method('blocking')->willReturn([['kind' => 'view', 'title' => ['nl' => 'Zienswijze', 'en' => 'Written view']]]);
		$preconditions = new TransitionPreconditions(
			obligations: $obligations,
			declaration: $this->createMock(ObligationDeclaration::class),
			evaluator: $this->createMock(DerivedStatusEvaluator::class),
			text: $this->english(),
		);

		self::assertSame('We are assessing your request.', $store->lookupStatusDescription(statusTypeId: 'st-1'));
		self::assertSame('Written view', (new ReflectionMethod($preconditions, 'obligationReason'))->invoke($preconditions, [], 'case-1', 'st-2', false));
	}//end testAStatusDescriptionAndAnObligationTitleAreInEnglish()

	/**
	 * Without a translator a map is read Dutch first, as before.
	 *
	 * @return void
	 */
	public function testWithoutATranslatorAMapIsReadDutchFirst(): void {
		self::assertSame('Klacht', (new TranslatedText())->forReader(value: ['en' => 'Complaint', 'nl' => 'Klacht']));
	}//end testWithoutATranslatorAMapIsReadDutchFirst()
}//end class
