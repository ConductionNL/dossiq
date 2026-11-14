<?php

/**
 * Unit tests for ZgwZrcZaakinformatieobjectRules: a zaakinformatieobject
 * needs a valid informatieobject URL whose type belongs to the case's type,
 * gets its relation text and registration date on create, and may not move to
 * another case or document afterwards.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\ZgwZrcZaakinformatieobjectRules;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ZgwZrcZaakinformatieobjectRules
 *
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\FieldValidator
 */
class ZgwZrcZaakinformatieobjectRulesTest extends TestCase {

	private const ZAAK = '11111111-1111-4111-8111-111111111111';

	private const DOC = '22222222-2222-4222-8222-222222222222';

	private const ZAAKTYPE = '33333333-3333-4333-8333-333333333333';

	private const DOCTYPE = '44444444-4444-4444-8444-444444444444';

	private const OTHER = '55555555-5555-4555-8555-555555555555';

	private const RELATION = 'Hoort bij, omgekeerd: kent';

	/** The fake store the last rules() call wired in. */
	private ?object $store = null;

	/**
	 * The rules over a store holding the case, the document and, when linked,
	 * the case type's informatieobjecttype relation.
	 *
	 * @param bool $linked    Whether the ZIOT relation exists.
	 * @param bool $withStore Whether a store is wired in at all.
	 */
	private function rules(bool $linked = true, bool $withStore = true): ZgwZrcZaakinformatieobjectRules {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => 'schema:' . $key);
		$rules = new ZgwZrcZaakinformatieobjectRules(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settings,
			fieldValidator: new FieldValidator(),
		);
		if ($withStore === false) {
			return $rules;
		}

		$rows = [
			self::ZAAK . '@schema:case_schema' => ['caseType' => 'https://zgw.example/zaaktypen/' . self::ZAAKTYPE],
			self::DOC . '@schema:document_schema' => ['documentType' => 'https://zgw.example/informatieobjecttypen/' . self::DOCTYPE],
		];
		$store = new class($rows, $linked) {
			/** @var list<array<string, mixed>> */
			public array $queries = [];

			public function __construct(private array $rows, private bool $linked) {
			}

			public function find(string $id, string $register, string $schema): ?array {
				return $this->rows[$id . '@' . $schema] ?? null;
			}

			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				return ['params' => $requestParams, 'register' => $register, 'schema' => $schema];
			}

			public function searchObjectsPaginated(array $query): array {
				$this->queries[] = $query;
				return ['results' => $this->linked === true ? [['id' => 'ziot-1']] : []];
			}
		};
		$this->store = $store;
		$rules->setContext(objectService: $store, mappingConfig: ['sourceRegister' => '1']);

		return $rules;
	}//end rules()

	private function body(): array {
		return ['case' => 'https://zgw.example/zaken/' . self::ZAAK, 'informatieobject' => 'https://zgw.example/documenten/' . self::DOC];
	}//end body()

	public function testACreateWithALinkedTypeIsEnrichedWithRelationAndDate(): void {
		$before = date('Y-m-d');
		$result = $this->rules()->rulesZaakinformatieobjectenCreate($this->body());
		$after = date('Y-m-d');

		$this->assertTrue($result['valid']);
		$this->assertSame(200, $result['status']);
		$this->assertSame(self::RELATION, $result['enrichedBody']['natureRelationshipDisplay']);
		$this->assertContains($result['enrichedBody']['registrationDate'], [$before, $after]);
		$this->assertSame($this->body()['case'], $result['enrichedBody']['case']);
	}//end testACreateWithALinkedTypeIsEnrichedWithRelationAndDate()

	public function testACreateWhoseDocumentTypeIsNotOnTheCaseTypeIsRefused(): void {
		$detail = 'Het informatieobjecttype van het informatieobject hoort niet bij het zaaktype van de zaak.';

		$this->assertSame(
			[
				'valid' => false,
				'status' => 400,
				'detail' => $detail,
				'invalidParams' => [['name' => 'nonFieldErrors', 'code' => 'missing-zaaktype-informatieobjecttype-relation', 'reason' => $detail]],
				'enrichedBody' => [],
			],
			$this->rules(linked: false)->rulesZaakinformatieobjectenCreate($this->body())
		);
	}//end testACreateWhoseDocumentTypeIsNotOnTheCaseTypeIsRefused()

	public function testTheRelationIsLookedUpByCaseTypeAndDocumentType(): void {
		$rules = $this->rules();
		$rules->rulesZaakinformatieobjectenCreate($this->body());

		$this->assertSame(
			[['params' => ['caseType' => self::ZAAKTYPE, 'informatieobjecttype' => self::DOCTYPE, '_limit' => 1], 'register' => 'schema:register', 'schema' => 'schema:zaaktype_informatieobjecttype_schema']],
			$this->store->queries
		);
	}//end testTheRelationIsLookedUpByCaseTypeAndDocumentType()

	public function testAnUnknownDocumentOrCaseSkipsTheTypeCheck(): void {
		$rules = $this->rules(linked: false);

		$this->assertTrue($rules->rulesZaakinformatieobjectenCreate(['case' => $this->body()['case'], 'informatieobject' => 'https://zgw.example/documenten/' . self::OTHER])['valid']);
		$this->assertTrue($rules->rulesZaakinformatieobjectenCreate(['case' => 'https://zgw.example/zaken/' . self::OTHER, 'informatieobject' => $this->body()['informatieobject']])['valid']);
	}//end testAnUnknownDocumentOrCaseSkipsTheTypeCheck()

	public function testAnInvalidInformatieobjectUrlIsRefused(): void {
		$result = $this->rules()->rulesZaakinformatieobjectenCreate(['informatieobject' => 'not a url']);

		$this->assertFalse($result['valid']);
		$this->assertSame(400, $result['status']);
		$this->assertSame('De informatieobject URL is ongeldig.', $result['detail']);
		$this->assertSame('bad-url', $result['invalidParams'][0]['code']);
	}//end testAnInvalidInformatieobjectUrlIsRefused()

	public function testWithoutAStoreTheTypeCheckIsSkipped(): void {
		$result = $this->rules(withStore: false)->rulesZaakinformatieobjectenCreate($this->body());

		$this->assertTrue($result['valid']);
		$this->assertSame(self::RELATION, $result['enrichedBody']['natureRelationshipDisplay']);
	}//end testWithoutAStoreTheTypeCheckIsSkipped()

	public function testAnUpdateMayNotMoveToAnotherCase(): void {
		$result = $this->rules()->rulesZaakinformatieobjectenUpdate(
			['case' => 'https://zgw.example/zaken/' . self::OTHER],
			['case' => 'https://zgw.example/zaken/' . self::ZAAK]
		);

		$this->assertFalse($result['valid']);
		$this->assertSame('Het veld case mag niet gewijzigd worden.', $result['detail']);
		$this->assertSame(['name' => 'case', 'code' => 'wijzigen-niet-toegelaten', 'reason' => 'Het veld case mag niet gewijzigd worden.'], $result['invalidParams'][0]);
	}//end testAnUpdateMayNotMoveToAnotherCase()

	public function testAPatchMayNotMoveToAnotherDocumentStoredUnderDocument(): void {
		$result = $this->rules()->rulesZaakinformatieobjectenPatch(
			['informatieobject' => 'https://zgw.example/documenten/' . self::OTHER],
			['document' => self::DOC, 'informatieobject' => 'https://zgw.example/documenten/' . self::OTHER]
		);

		$this->assertFalse($result['valid']);
		$this->assertSame('Het veld informatieobject mag niet gewijzigd worden.', $result['detail']);
	}//end testAPatchMayNotMoveToAnotherDocumentStoredUnderDocument()

	public function testAnUpdateKeepingCaseAndDocumentIsValidWithTheRelationText(): void {
		$body = $this->body() + ['beschrijving' => 'Nieuw'];

		$result = $this->rules()->rulesZaakinformatieobjectenUpdate($body, ['case' => 'https://zgw.example/zaken/' . self::ZAAK, 'document' => self::DOC]);

		$this->assertTrue($result['valid']);
		$this->assertSame($body + ['natureRelationshipDisplay' => self::RELATION], $result['enrichedBody']);
		$this->assertTrue($this->rules()->rulesZaakinformatieobjectenUpdate(['case' => 'https://zgw.example/zaken/' . self::OTHER])['valid']);
	}//end testAnUpdateKeepingCaseAndDocumentIsValidWithTheRelationText()
}//end class
