<?php

/**
 * Characterisation tests for the zaak field rules of ZgwZrcRulesService.
 *
 * Pins every branch of the shared create/update/patch field validation
 * (zrc-002, zrc-010 to zrc-015, zrc-022) through the public rules methods, so
 * the decomposition of that method (method-decomposition) can be proven to
 * change no outcome: same refusal, same code, same detail, same enriched body.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwZrcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ObjectService shape the rules base calls with named arguments.
 */
interface CaseFieldObjectServiceStub {
	public function find(string $id, string $register, string $schema): ?array;
}//end interface

/**
 * Characterisation tests for the zaak field rules.
 *
 * @covers \OCA\Dossiq\Service\ZgwZrcRulesService
 * @covers \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @uses \OCA\Dossiq\Service\FieldValidator
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 */
class ZgwZrcCaseFieldRulesTest extends TestCase {

	private const CASE_TYPE_UUID = 'aabbccdd-1111-2222-3333-444455556666';
	private const SELF_UUID = 'eeeeeeee-1111-2222-3333-444455556666';
	private const MAIN_UUID = 'ccddccdd-5555-6666-7777-888899990000';
	private const PRODUCT_A = 'https://producten.example.com/api/producten/11111111-aaaa-bbbb-cccc-000000000001';
	private const PRODUCT_B = 'https://producten.example.com/api/producten/11111111-aaaa-bbbb-cccc-000000000002';

	/**
	 * The service under test, without an ObjectService context.
	 *
	 * @var ZgwZrcRulesService
	 */
	private ZgwZrcRulesService $service;

	/**
	 * Set up a service with no OpenRegister context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->buildService(objects: null);

	}//end setUp()

	/**
	 * Build the service, optionally with an ObjectService that answers from a uuid map.
	 *
	 * @param array<string, array>|null $objects Objects by uuid, or null for no context
	 *
	 * @return ZgwZrcRulesService
	 */
	private function buildService(?array $objects): ZgwZrcRulesService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('schema-x');

		$service = new ZgwZrcRulesService(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settings,
			fieldValidator: new FieldValidator()
		);

		if ($objects !== null) {
			$objectService = $this->createMock(CaseFieldObjectServiceStub::class);
			$objectService->method('find')->willReturnCallback(
				static function (string $id, string $register, string $schema) use ($objects): ?array {
					return $objects[$id] ?? null;
				}
			);
			$service->setContext(
				objectService: $objectService,
				mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
			);
		}

		return $service;

	}//end buildService()

	/**
	 * Assert a refusal with one invalid param.
	 *
	 * @param array  $result The rules result
	 * @param string $field  Expected field name
	 * @param string $code   Expected code
	 * @param string $detail Expected detail
	 *
	 * @return void
	 */
	private function assertRefused(array $result, string $field, string $code, string $detail): void {
		$this->assertFalse($result['valid']);
		$this->assertSame(400, $result['status']);
		$this->assertSame($detail, $result['detail']);
		$this->assertSame($field, $result['invalidParams'][0]['name']);
		$this->assertSame($code, $result['invalidParams'][0]['code']);

	}//end assertRefused()

	/**
	 * Zrc-002: a changed identificatie on update is refused.
	 *
	 * @return void
	 */
	public function testIdentificatieChangeOnUpdateIsRefused(): void {
		$result = $this->service->rulesZakenUpdate(
			body: ['identificatie' => 'ZAAK-2'],
			existingObject: ['identifier' => 'ZAAK-1']
		);

		$this->assertFalse($result['valid']);
		$this->assertSame('identificatie', $result['invalidParams'][0]['name']);

	}//end testIdentificatieChangeOnUpdateIsRefused()

	/**
	 * Zrc-002: the same identificatie on update passes.
	 *
	 * @return void
	 */
	public function testSameIdentificatieOnUpdatePasses(): void {
		$result = $this->service->rulesZakenUpdate(
			body: ['identificatie' => 'ZAAK-1'],
			existingObject: ['identificatie' => 'ZAAK-1']
		);

		$this->assertTrue($result['valid']);

	}//end testSameIdentificatieOnUpdatePasses()

	/**
	 * Zrc-011: a relevanteAndereZaken entry without a resource url is refused, naming its index.
	 *
	 * @return void
	 */
	public function testRelevanteAndereZakenBadUrlNamesTheIndex(): void {
		$result = $this->service->rulesZakenCreate(
			body: [
				'relevanteAndereZaken' => [
					['url' => 'https://zaken.example.com/zaken/'.self::MAIN_UUID],
					['url' => 'https://zaken.example.com/zaken'],
				],
			]
		);

		$this->assertRefused(
			result: $result,
			field: 'relevanteAndereZaken.1.url',
			code: 'bad-url',
			detail: 'relevanteAndereZaken bevat een ongeldige URL.'
		);

	}//end testRelevanteAndereZakenBadUrlNamesTheIndex()

	/**
	 * Zrc-012: opschorting without indicatie and reden lists both.
	 *
	 * @return void
	 */
	public function testOpschortingWithoutIndicatieAndRedenListsBoth(): void {
		$result = $this->service->rulesZakenCreate(body: ['suspension' => []]);

		$this->assertRefused(
			result: $result,
			field: 'opschorting.indicatie',
			code: 'required',
			detail: 'Opschorting vereist indicatie en reden.'
		);
		$this->assertSame('opschorting.reden', $result['invalidParams'][1]['name']);
		$this->assertCount(2, $result['invalidParams']);

	}//end testOpschortingWithoutIndicatieAndRedenListsBoth()

	/**
	 * Zrc-012: complete opschorting passes.
	 *
	 * @return void
	 */
	public function testCompleteOpschortingPasses(): void {
		$result = $this->service->rulesZakenCreate(
			body: ['suspension' => ['indicatie' => true, 'reason' => 'wacht op stukken']]
		);

		$this->assertTrue($result['valid']);

	}//end testCompleteOpschortingPasses()

	/**
	 * Zrc-012: verlenging without duur is refused on duur alone.
	 *
	 * @return void
	 */
	public function testVerlengingWithoutDuurIsRefused(): void {
		$result = $this->service->rulesZakenCreate(body: ['verlenging' => ['reason' => 'complex']]);

		$this->assertRefused(
			result: $result,
			field: 'verlenging.duur',
			code: 'required',
			detail: 'Verlenging vereist reden en duur.'
		);
		$this->assertCount(1, $result['invalidParams']);

	}//end testVerlengingWithoutDuurIsRefused()

	/**
	 * Zrc-012: verlenging without reden and duur lists both.
	 *
	 * @return void
	 */
	public function testVerlengingWithoutRedenAndDuurListsBoth(): void {
		$result = $this->service->rulesZakenCreate(body: ['verlenging' => []]);

		$this->assertSame('verlenging.reden', $result['invalidParams'][0]['name']);
		$this->assertSame('verlenging.duur', $result['invalidParams'][1]['name']);

	}//end testVerlengingWithoutRedenAndDuurListsBoth()

	/**
	 * Zrc-013: a hoofdzaak url without a resource is refused.
	 *
	 * @return void
	 */
	public function testHoofdzaakBadUrlIsRefused(): void {
		$result = $this->service->rulesZakenCreate(body: ['hoofdzaak' => 'https://zaken.example.com/zaken']);

		$this->assertRefused(
			result: $result,
			field: 'hoofdzaak',
			code: 'bad-url',
			detail: 'De hoofdzaak URL is ongeldig.'
		);

	}//end testHoofdzaakBadUrlIsRefused()

	/**
	 * Zrc-013d: a zaak cannot be its own hoofdzaak.
	 *
	 * @return void
	 */
	public function testZaakCannotBeItsOwnHoofdzaak(): void {
		$result = $this->service->rulesZakenUpdate(
			body: ['hoofdzaak' => 'https://zaken.example.com/zaken/'.self::SELF_UUID],
			existingObject: ['id' => self::SELF_UUID]
		);

		$this->assertRefused(
			result: $result,
			field: 'hoofdzaak',
			code: 'self-forbidden',
			detail: 'Een zaak kan niet zijn eigen hoofdzaak zijn.'
		);

	}//end testZaakCannotBeItsOwnHoofdzaak()

	/**
	 * Zrc-013d: the self check also reads the @self id.
	 *
	 * @return void
	 */
	public function testSelfHoofdzaakReadsTheSelfBlock(): void {
		$result = $this->service->rulesZakenUpdate(
			body: ['hoofdzaak' => 'https://zaken.example.com/zaken/'.self::SELF_UUID],
			existingObject: ['@self' => ['id' => self::SELF_UUID]]
		);

		$this->assertSame('self-forbidden', $result['invalidParams'][0]['code']);

	}//end testSelfHoofdzaakReadsTheSelfBlock()

	/**
	 * Zrc-013c: a hoofdzaak that is itself a deelzaak is refused.
	 *
	 * @return void
	 */
	public function testDeelzaakOfDeelzaakIsRefused(): void {
		$service = $this->buildService(
			objects: [self::MAIN_UUID => ['id' => self::MAIN_UUID, 'parentCase' => 'https://zaken.example.com/zaken/x']]
		);

		$result = $service->rulesZakenUpdate(
			body: ['hoofdzaak' => 'https://zaken.example.com/zaken/'.self::MAIN_UUID],
			existingObject: ['id' => self::SELF_UUID]
		);

		$this->assertRefused(
			result: $result,
			field: 'hoofdzaak',
			code: 'deelzaak-als-hoofdzaak',
			detail: 'Een deelzaak van een deelzaak is niet toegestaan.'
		);

	}//end testDeelzaakOfDeelzaakIsRefused()

	/**
	 * Zrc-014: nvt with a laatsteBetaaldatum is refused on create.
	 *
	 * @return void
	 */
	public function testBetalingNvtWithDateIsRefusedOnCreate(): void {
		$result = $this->service->rulesZakenCreate(
			body: ['betalingsindicatie' => 'nvt', 'laatsteBetaaldatum' => '2026-01-01T00:00:00Z']
		);

		$this->assertRefused(
			result: $result,
			field: 'laatsteBetaaldatum',
			code: 'betaling-nvt',
			detail: 'Als betalingsindicatie "nvt" is, mag laatsteBetaaldatum niet gezet worden.'
		);

	}//end testBetalingNvtWithDateIsRefusedOnCreate()

	/**
	 * Zrc-014: switching to nvt on update clears the stored payment date.
	 *
	 * @return void
	 */
	public function testBetalingNvtOnUpdateClearsTheStoredDate(): void {
		$result = $this->service->rulesZakenPatch(
			body: ['betalingsindicatie' => 'nvt'],
			existingObject: ['lastPaymentDate' => '2026-01-01T00:00:00Z']
		);

		$this->assertTrue($result['valid']);
		$this->assertArrayHasKey('laatsteBetaaldatum', $result['enrichedBody']);
		$this->assertNull($result['enrichedBody']['laatsteBetaaldatum']);

	}//end testBetalingNvtOnUpdateClearsTheStoredDate()

	/**
	 * Zrc-014: nvt stored on the existing zaak plus a new date also clears the date.
	 *
	 * @return void
	 */
	public function testStoredNvtWithNewDateOnUpdateClearsIt(): void {
		$result = $this->service->rulesZakenUpdate(
			body: ['laatsteBetaaldatum' => '2026-02-02T00:00:00Z'],
			existingObject: ['betalingsindicatie' => 'nvt']
		);

		$this->assertTrue($result['valid']);
		$this->assertNull($result['enrichedBody']['laatsteBetaaldatum']);

	}//end testStoredNvtWithNewDateOnUpdateClearsIt()

	/**
	 * Zrc-014: a payment indication other than nvt keeps the date.
	 *
	 * @return void
	 */
	public function testBetalingGeheelKeepsTheDate(): void {
		$result = $this->service->rulesZakenCreate(
			body: ['betalingsindicatie' => 'geheel', 'laatsteBetaaldatum' => '2026-01-01T00:00:00Z']
		);

		$this->assertTrue($result['valid']);
		$this->assertSame('2026-01-01T00:00:00Z', $result['enrichedBody']['laatsteBetaaldatum']);

	}//end testBetalingGeheelKeepsTheDate()

	/**
	 * Zrc-015: a product that is not a url is refused.
	 *
	 * @return void
	 */
	public function testProductThatIsNotAUrlIsRefused(): void {
		$service = $this->buildService(
			objects: [self::CASE_TYPE_UUID => ['id' => self::CASE_TYPE_UUID, 'productsOrServices' => [self::PRODUCT_A]]]
		);

		$result = $service->rulesZakenUpdate(
			body: [
				'caseType' => 'https://ztc.example.com/zaaktypen/'.self::CASE_TYPE_UUID,
				'productenOfDiensten' => ['geen-url'],
			],
			existingObject: null
		);

		$this->assertRefused(
			result: $result,
			field: 'productenOfDiensten',
			code: 'invalid-products-services',
			detail: 'productenOfDiensten bevat een ongeldige URL.'
		);
		$this->assertSame("'geen-url' is geen geldige URL.", $result['invalidParams'][0]['reason']);

	}//end testProductThatIsNotAUrlIsRefused()

	/**
	 * Zrc-015: a product the zaaktype does not offer is refused.
	 *
	 * @return void
	 */
	public function testProductOutsideTheZaaktypeIsRefused(): void {
		$service = $this->buildService(
			objects: [self::CASE_TYPE_UUID => ['id' => self::CASE_TYPE_UUID, 'productenOfDiensten' => json_encode([self::PRODUCT_A])]]
		);

		$result = $service->rulesZakenUpdate(
			body: [
				'caseType' => 'https://ztc.example.com/zaaktypen/'.self::CASE_TYPE_UUID,
				'productenOfDiensten' => [self::PRODUCT_A, self::PRODUCT_B],
			],
			existingObject: null
		);

		$this->assertRefused(
			result: $result,
			field: 'productenOfDiensten',
			code: 'invalid-products-services',
			detail: 'productenOfDiensten bevat een waarde die niet in het zaaktype voorkomt.'
		);
		$this->assertSame(
			"Product '".self::PRODUCT_B."' is niet toegestaan voor dit zaaktype.",
			$result['invalidParams'][0]['reason']
		);

	}//end testProductOutsideTheZaaktypeIsRefused()

	/**
	 * Zrc-015: a zaaktype with no products allows any product, even a non-url.
	 *
	 * @return void
	 */
	public function testZaaktypeWithoutProductsAllowsAnything(): void {
		$service = $this->buildService(
			objects: [self::CASE_TYPE_UUID => ['id' => self::CASE_TYPE_UUID, 'productsAndServices' => []]]
		);

		$result = $service->rulesZakenUpdate(
			body: [
				'caseType' => 'https://ztc.example.com/zaaktypen/'.self::CASE_TYPE_UUID,
				'productenOfDiensten' => ['geen-url'],
			],
			existingObject: null
		);

		$this->assertTrue($result['valid']);

	}//end testZaaktypeWithoutProductsAllowsAnything()

	/**
	 * Zrc-015: products offered by the zaaktype pass.
	 *
	 * @return void
	 */
	public function testOfferedProductsPass(): void {
		$service = $this->buildService(
			objects: [self::CASE_TYPE_UUID => ['id' => self::CASE_TYPE_UUID, 'productsOrServices' => [self::PRODUCT_A, self::PRODUCT_B]]]
		);

		$result = $service->rulesZakenUpdate(
			body: [
				'caseType' => 'https://ztc.example.com/zaaktypen/'.self::CASE_TYPE_UUID,
				'productenOfDiensten' => [self::PRODUCT_B],
			],
			existingObject: null
		);

		$this->assertTrue($result['valid']);

	}//end testOfferedProductsPass()

	/**
	 * Zrc-015: without a context the product check is skipped.
	 *
	 * @return void
	 */
	public function testProductCheckIsSkippedWithoutContext(): void {
		$result = $this->service->rulesZakenUpdate(
			body: ['productenOfDiensten' => ['geen-url']],
			existingObject: null
		);

		$this->assertTrue($result['valid']);

	}//end testProductCheckIsSkippedWithoutContext()

	/**
	 * Zrc-022: archiefstatus other than nog_te_archiveren needs archiefnominatie.
	 *
	 * @return void
	 */
	public function testArchiefstatusNeedsArchiefnominatie(): void {
		$result = $this->service->rulesZakenCreate(body: ['archiefstatus' => 'gearchiveerd']);

		$this->assertRefused(
			result: $result,
			field: 'archiefnominatie',
			code: 'archiefnominatie-not-set',
			detail: 'archiefnominatie is vereist als archiefstatus niet "nog_te_archiveren" is.'
		);

	}//end testArchiefstatusNeedsArchiefnominatie()

	/**
	 * Zrc-022: archiefstatus with a nominatie still needs archiefactiedatum.
	 *
	 * @return void
	 */
	public function testArchiefstatusNeedsArchiefactiedatum(): void {
		$result = $this->service->rulesZakenCreate(
			body: ['archiefstatus' => 'gearchiveerd', 'archiefnominatie' => 'vernietigen']
		);

		$this->assertRefused(
			result: $result,
			field: 'archiefactiedatum',
			code: 'archiefactiedatum-not-set',
			detail: 'archiefactiedatum is vereist als archiefstatus niet "nog_te_archiveren" is.'
		);

	}//end testArchiefstatusNeedsArchiefactiedatum()

	/**
	 * Zrc-022: a complete archive block passes and the create defaults land in the body.
	 *
	 * @return void
	 */
	public function testCompleteArchiveBlockPassesWithCreateDefaults(): void {
		$result = $this->service->rulesZakenCreate(
			body: [
				'identificatie'     => 'ZAAK-9',
				'archiefstatus'     => 'gearchiveerd',
				'archiefnominatie'  => 'vernietigen',
				'archiefactiedatum' => '2040-01-01',
			]
		);

		$this->assertTrue($result['valid']);
		$this->assertSame('zgw-api', $result['enrichedBody']['intakeChannel']);
		$this->assertSame('gearchiveerd', $result['enrichedBody']['archiefstatus']);

	}//end testCompleteArchiveBlockPassesWithCreateDefaults()

	/**
	 * The first failing rule wins: communicatiekanaal is checked before opschorting.
	 *
	 * @return void
	 */
	public function testRulesRunInSpecOrder(): void {
		$result = $this->service->rulesZakenCreate(
			body: ['communicatiekanaal' => 'not-a-url', 'suspension' => [], 'archiefstatus' => 'gearchiveerd']
		);

		$this->assertSame('communicatiekanaal', $result['invalidParams'][0]['name']);

	}//end testRulesRunInSpecOrder()
	/**
	 * Zrc-016..020: a type of another zaaktype is refused with zaaktype-mismatch, for each sub-resource.
	 *
	 * @return void
	 */
	public function testSubResourceTypeOfAnotherZaaktypeIsRefused(): void {
		$typeUuid = '99999999-1111-2222-3333-444455556666';
		$service  = $this->buildService(
			objects: [
				self::SELF_UUID => ['id' => self::SELF_UUID, 'caseType' => 'https://ztc.example.com/zaaktypen/'.self::CASE_TYPE_UUID],
				$typeUuid       => ['id' => $typeUuid, 'caseType' => 'https://ztc.example.com/zaaktypen/'.self::MAIN_UUID],
			]
		);
		$caseUrl  = 'https://zaken.example.com/zaken/'.self::SELF_UUID;
		$typeUrl  = 'https://ztc.example.com/types/'.$typeUuid;

		$results = [
			'statustype'    => $service->rulesStatussenCreate(body: ['case' => $caseUrl, 'statustype' => $typeUrl]),
			'resultaattype' => $service->rulesResultatenCreate(body: ['case' => $caseUrl, 'resultaattype' => $typeUrl]),
			'roltype'       => $service->rulesRollenCreate(body: ['case' => $caseUrl, 'roltype' => $typeUrl]),
			'eigenschap'    => $service->rulesZaakeigenschappenCreate(body: ['case' => $caseUrl, 'eigenschap' => $typeUrl]),
		];

		foreach ($results as $field => $result) {
			$this->assertRefused(
				result: $result,
				field: 'nonFieldErrors',
				code: 'zaaktype-mismatch',
				detail: "Het {$field} hoort niet bij het zaaktype van de zaak."
			);
		}

	}//end testSubResourceTypeOfAnotherZaaktypeIsRefused()

	/**
	 * Zrc-016: an unknown type is refused the same way as a mismatching one.
	 *
	 * @return void
	 */
	public function testUnknownSubResourceTypeIsRefused(): void {
		$service = $this->buildService(
			objects: [self::SELF_UUID => ['id' => self::SELF_UUID, 'caseType' => 'https://ztc.example.com/zaaktypen/'.self::CASE_TYPE_UUID]]
		);

		$result = $service->rulesStatussenCreate(
			body: [
				'case'       => 'https://zaken.example.com/zaken/'.self::SELF_UUID,
				'statustype' => 'https://ztc.example.com/statustypen/'.self::MAIN_UUID,
			]
		);

		$this->assertSame('zaaktype-mismatch', $result['invalidParams'][0]['code']);

	}//end testUnknownSubResourceTypeIsRefused()

	/**
	 * Zrc-016: a type of the zaak's own zaaktype passes; an unknown zaak skips the check.
	 *
	 * @return void
	 */
	public function testMatchingTypePassesAndUnknownZaakSkips(): void {
		$service = $this->buildService(
			objects: [
				self::SELF_UUID => ['id' => self::SELF_UUID, 'caseType' => 'https://ztc.example.com/zaaktypen/'.self::CASE_TYPE_UUID],
				self::MAIN_UUID => ['id' => self::MAIN_UUID, 'caseType' => 'https://ztc.example.com/zaaktypen/'.self::CASE_TYPE_UUID],
			]
		);

		$matching = $service->rulesStatussenCreate(
			body: [
				'case'       => 'https://zaken.example.com/zaken/'.self::SELF_UUID,
				'statustype' => 'https://ztc.example.com/statustypen/'.self::MAIN_UUID,
			]
		);
		$unknownZaak = $service->rulesStatussenCreate(
			body: [
				'case'       => 'https://zaken.example.com/zaken/12345678-0000-0000-0000-000000000000',
				'statustype' => 'https://ztc.example.com/statustypen/'.self::MAIN_UUID,
			]
		);
		$zaakWithoutType = $this->buildService(objects: [self::SELF_UUID => ['id' => self::SELF_UUID]])->rulesStatussenCreate(
			body: [
				'case'       => 'https://zaken.example.com/zaken/'.self::SELF_UUID,
				'statustype' => 'https://ztc.example.com/statustypen/'.self::MAIN_UUID,
			]
		);

		$this->assertTrue($matching['valid']);
		$this->assertTrue($unknownZaak['valid']);
		$this->assertTrue($zaakWithoutType['valid']);

	}//end testMatchingTypePassesAndUnknownZaakSkips()
}//end class
