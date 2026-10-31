<?php

/**
 * Translatable type fields read as text in the status, obligation and ZGW lookups.
 *
 * A status label or description, an obligation title, and a decision or document
 * type name are declared `translatable`, so the store answers them as a language
 * map. Each reader below cast or compared that map as if it were a string.
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

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use OCA\Dossiq\Service\Zgw\BrcDocumentLinkRules;
use OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup;
use OCA\Dossiq\Service\ZgwBrcRulesService;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for translatable fields read in the lookups.
 *
 * @covers \OCA\Dossiq\Service\Transitions\StatusTypeLookup
 * @covers \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @covers \OCA\Dossiq\Service\Transitions\TransitionPreconditions
 * @covers \OCA\Dossiq\Service\ZgwBrcRulesService
 * @covers \OCA\Dossiq\Service\Zgw\BrcDocumentLinkRules
 * @covers \OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup
 */
class TranslatableNamesInLookupsTest extends TestCase {

	/**
	 * A status lookup whose row read answers the given row.
	 *
	 * @param array<string, mixed> $row The statusType row.
	 *
	 * @return StatusTypeLookup
	 */
	private function lookupAnswering(array $row): StatusTypeLookup {
		$lookup = $this->getMockBuilder(StatusTypeLookup::class)
			->setConstructorArgs([$this->createMock(SettingsService::class), $this->createMock(CaseTypeResolver::class)])
			->onlyMethods(['rowFor'])
			->getMock();
		$lookup->method('rowFor')->willReturn($row);

		return $lookup;
	}//end lookupAnswering()

	/**
	 * The announced label of a status is its public label as text.
	 *
	 * @return void
	 */
	public function testTheAnnouncedLabelIsReadAsText(): void {
		$lookup = $this->lookupAnswering(['publicLabel' => ['nl' => 'Uw verzoek wordt behandeld']]);

		self::assertSame('Uw verzoek wordt behandeld', $lookup->announcedLabelOf(statusTypeId: 'st-1'));
	}//end testTheAnnouncedLabelIsReadAsText()

	/**
	 * A status description is read as text.
	 *
	 * @return void
	 */
	public function testAStatusDescriptionIsReadAsText(): void {
		$store = new CaseStatusStore(
			settingsService: $this->createMock(SettingsService::class),
			statusTypeLookup: $this->lookupAnswering(['description' => ['nl' => 'Wij beoordelen uw verzoek.']]),
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertSame('Wij beoordelen uw verzoek.', $store->lookupStatusDescription(statusTypeId: 'st-1'));
	}//end testAStatusDescriptionIsReadAsText()

	/**
	 * A blocking obligation is named by its title as text.
	 *
	 * @return void
	 */
	public function testAnObligationIsNamedByItsTitleAsText(): void {
		$obligations = $this->createMock(ObligationService::class);
		$obligations->method('blocking')->willReturn([['kind' => 'zienswijze', 'title' => ['nl' => 'Zienswijze van de belanghebbende']]]);
		$preconditions = new TransitionPreconditions(
			obligations: $obligations,
			declaration: $this->createMock(ObligationDeclaration::class),
			evaluator: $this->createMock(DerivedStatusEvaluator::class),
		);

		$reason = (new ReflectionMethod($preconditions, 'obligationReason'))->invoke($preconditions, [], 'case-1', 'st-2', false);

		self::assertSame('Zienswijze van de belanghebbende', $reason);
	}//end testAnObligationIsNamedByItsTitleAsText()

	/**
	 * A case type that lists a decision type by its description matches a decision type whose name is a language map.
	 *
	 * @return void
	 */
	public function testADecisionTypeMatchesByItsNameAsText(): void {
		$rules = $this->getMockBuilder(ZgwBrcRulesService::class)
			->setConstructorArgs([$this->createMock(LoggerInterface::class), $this->createMock(SettingsService::class), $this->createMock(FieldValidator::class)])
			->onlyMethods(['findBySchemaKey'])
			->getMock();
		$rules->method('findBySchemaKey')->willReturn(['decisionTypes' => ['Omgevingsvergunning verleend']]);

		$matches = (new ReflectionMethod($rules, 'caseTypeListsDecisionType'))->invoke(
			$rules,
			'ct-1',
			'00000000-0000-4000-a000-000000000099',
			['name' => ['nl' => 'Omgevingsvergunning verleend']]
		);

		self::assertTrue($matches);
	}//end testADecisionTypeMatchesByItsNameAsText()

	/**
	 * The document type of a document carries its name as text.
	 *
	 * @return void
	 */
	public function testADocumentTypeNameIsReadAsText(): void {
		$validator = $this->createMock(FieldValidator::class);
		$validator->method('extractUuid')->willReturnCallback(static fn (string $url): string => basename($url));
		$rules = $this->getMockBuilder(BrcDocumentLinkRules::class)
			->setConstructorArgs([$this->createMock(LoggerInterface::class), $this->createMock(SettingsService::class), $validator])
			->onlyMethods(['findBySchemaKey'])
			->getMock();
		$rules->method('findBySchemaKey')->willReturnCallback(
			static fn (string $uuid, string $schemaKey): array => $schemaKey === 'document_schema'
				? ['documentType' => 'https://example.org/zaaktypen/00000000-0000-4000-a000-000000000002']
				: ['name' => ['nl' => 'Aanvraagformulier']]
		);

		$type = (new ReflectionMethod($rules, 'getDocumentTypeOfDocument'))->invoke(
			$rules,
			'https://example.org/documenten/00000000-0000-4000-a000-000000000001'
		);

		self::assertSame('Aanvraagformulier', $type['name']);
	}//end testADocumentTypeNameIsReadAsText()

	/**
	 * The search for same-named document types is made with the name as text, not with the map.
	 *
	 * @return void
	 */
	public function testTheSameNameSearchUsesTheNameAsText(): void {
		$lookup = $this->getMockBuilder(ZtcRelatedTypeLookup::class)
			->setConstructorArgs([$this->createMock(ZgwService::class)])
			->onlyMethods(['findIn'])
			->getMock();
		$lookup->method('findIn')->willReturn(['name' => ['nl' => 'Aanvraagformulier']]);
		$objectService = new class {
			/**
			 * The request parameters the search was built from.
			 *
			 * @var array<string, mixed>
			 */
			public array $asked = [];

			/**
			 * Record the parameters.
			 *
			 * @param array<string, mixed> $requestParams The filters.
			 * @param mixed                $register      The register.
			 * @param mixed                $schema        The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array {
				$this->asked = $requestParams;
				return $requestParams;
			}

			/**
			 * No rows.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query): array {
				return ['results' => []];
			}
		};

		(new ReflectionMethod($lookup, 'sameNameDocumentTypeUrls'))->invoke(
			$lookup,
			$objectService,
			['sourceRegister' => '1', 'sourceSchema' => '2'],
			'iot-1',
			'https://example.org'
		);

		self::assertSame('Aanvraagformulier', $objectService->asked['name']);
	}//end testTheSameNameSearchUsesTheNameAsText()
}//end class
