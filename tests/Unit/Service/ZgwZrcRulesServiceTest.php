<?php

/**
 * ZgwZrcRulesService Unit Tests
 *
 * Tests for the ZRC (Zaken API) business rule validation and enrichment service.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwZrcRulesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService shape used by ZgwZrcRulesService.
 *
 * Defined here so the unit tests can `createMock(ObjectServiceStub::class)`
 * and get a mock whose method signatures match the named-arg calls in
 * ZgwRulesBase. Using `getMockBuilder(\stdClass::class)->addMethods([...])`
 * generates parameter-less stubs, which throws
 * "Error: Unknown named parameter" when production code passes named
 * arguments. This interface declares the real parameter names so named-arg
 * calls resolve correctly.
 */
interface ObjectServiceStub {
	public function find(string $id, string $register, string $schema): ?array;

	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * Unit tests for ZgwZrcRulesService.
 *
 * @covers \OCA\Dossiq\Service\ZgwZrcRulesService
 * @covers \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @uses \OCA\Dossiq\Service\FieldValidator
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Zgw\ZrcCaseFieldRules
 * @uses \OCA\Dossiq\Service\Zgw\ZrcCaseRelationRules
 * @uses \OCA\Dossiq\Service\Zgw\ZrcCaseTypeDefaults
 */
class ZgwZrcRulesServiceTest extends TestCase {

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The service under test.
	 *
	 * @var ZgwZrcRulesService
	 */
	private ZgwZrcRulesService $service;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settingsService = $this->createMock(SettingsService::class);

		// FieldValidator is a pure, stateless utility — use the real
		// implementation so the service exercises genuine format validation.
		// ZgwZrcRulesService has no constructor of its own — it INHERITS
		// ZgwRulesBase(logger, settingsService, fieldValidator). An earlier sweep
		// read "no __construct in this file" as "takes no arguments" and dropped
		// the two inherited ones, which is why 21 tests died with
		// ArgumentCountError on ZgwRulesBase::__construct().
		$this->service = new ZgwZrcRulesService(
			logger: $this->logger,
			settingsService: $this->settingsService,
			fieldValidator: new \OCA\Dossiq\Service\FieldValidator()
		);

	}//end setUp()

	// -------------------------------------------------------------------------
	// Task 6.3 — Error code fix tests (zrc-010, zrc-013a, zrc-002, zrc-015)
	// -------------------------------------------------------------------------

	/**
	 * Test zrc-010: communicatiekanaal valid URL without UUID → invalid-resource.
	 *
	 * @return void
	 */
	public function testCommunicatiekanaalCollectionUrlReturnsInvalidResource(): void {
		$body = ['communicatiekanaal' => 'https://example.com/kanalen'];
		$result = $this->service->rulesZakenCreate($body);

		$this->assertFalse($result['valid']);
		$invalidParams = $result['invalidParams'];
		$this->assertNotEmpty($invalidParams);
		$code = $invalidParams[0]['code'];
		$this->assertSame('invalid-resource', $code);

	}//end testCommunicatiekanaalCollectionUrlReturnsInvalidResource()

	/**
	 * Test zrc-010: completely invalid URL → bad-url.
	 *
	 * @return void
	 */
	public function testCommunicatiekanaalInvalidUrlReturnsBadUrl(): void {
		$body = ['communicatiekanaal' => 'not-a-url'];
		$result = $this->service->rulesZakenCreate($body);

		$this->assertFalse($result['valid']);
		$code = $result['invalidParams'][0]['code'];
		$this->assertSame('bad-url', $code);

	}//end testCommunicatiekanaalInvalidUrlReturnsBadUrl()

	/**
	 * Test zrc-013a: hoofdzaak not found returns does-not-exist (not no_match).
	 *
	 * @return void
	 */
	public function testHoofdzaakNotFoundReturnsDoesNotExist(): void {
		$zaaktypeUuid = 'aabbccdd-1111-2222-3333-444455556666';
		$hoofdzaakUuid = 'ccddccdd-5555-6666-7777-888899990000';

		// Zaaktype lookup returns a valid (published) zaaktype; hoofdzaak lookup returns null.
		$objectService = $this->createMock(ObjectServiceStub::class);
		$objectService->method('find')->willReturnCallback(
			static function (string $id, string $register, string $schema) use ($zaaktypeUuid): ?array {
				if ($id === $zaaktypeUuid) {
					return [
						'id' => $zaaktypeUuid,
						'isDraft' => false,
					];
				}

				return null;
			}
		);

		$this->settingsService
			->method('getConfigValue')
			->willReturn('schema-case');

		$this->service->setContext(
			objectService: $objectService,
			mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
		);

		$body = [
			'caseType' => "http://example.com/zaaktypen/{$zaaktypeUuid}",
			'hoofdzaak' => "http://example.com/zaken/{$hoofdzaakUuid}",
		];

		$result = $this->service->rulesZakenCreate($body);

		$this->assertFalse($result['valid']);
		$code = $result['invalidParams'][0]['code'] ?? '';
		$this->assertSame('does-not-exist', $code);

	}//end testHoofdzaakNotFoundReturnsDoesNotExist()

	// -------------------------------------------------------------------------
	// Task 6.4 — Side effect tests (vertrouwelijkheidaanduiding override)
	// -------------------------------------------------------------------------

	/**
	 * Test zrc-009: zaaktype vertrouwelijkheidaanduiding overrides incoming value.
	 *
	 * @return void
	 */
	public function testVertrouwelijkheidaanduidingAlwaysOverridesFromZaaktype(): void {
		$zaaktypeUuid = 'aabbccdd-1111-2222-3333-444455556666';

		$objectService = $this->createMock(ObjectServiceStub::class);

		// find() returns zaaktype with confidentiality = 'vertrouwelijk'.
		$objectService->method('find')->willReturn(
			[
				'id' => $zaaktypeUuid,
				'confidentiality' => 'vertrouwelijk',
				'isDraft' => false,
			]
		);

		$objectService->method('buildSearchQuery')->willReturn([]);
		$objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 0]);

		$this->settingsService
			->method('getConfigValue')
			->willReturnCallback(
				static function (string $key): string {
					return match ($key) {
						'case_type_schema' => 'schema-ct',
						default => 'schema-x',
					};
				}
			);

		$this->service->setContext(
			objectService: $objectService,
			mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
		);

		$body = [
			'caseType' => "http://example.com/zaaktypen/{$zaaktypeUuid}",
			'bronorganisatie' => '000000000',
			'vertrouwelijkheidaanduiding' => 'openbaar',
			// Incoming says openbaar, zaaktype says vertrouwelijk.
		];

		$result = $this->service->rulesZakenCreate($body);

		$enrichedBody = $result['enrichedBody'];
		$this->assertSame('vertrouwelijk', $enrichedBody['vertrouwelijkheidaanduiding']);

	}//end testVertrouwelijkheidaanduidingAlwaysOverridesFromZaaktype()

	/**
	 * Test zrc-009: incoming value preserved when zaaktype has no confidentiality.
	 *
	 * @return void
	 */
	public function testVertrouwelijkheidaanduidingFallsBackToIncomingWhenZaaktypeHasNone(): void {
		$zaaktypeUuid = 'aabbccdd-1111-2222-3333-444455556666';

		$objectService = $this->createMock(ObjectServiceStub::class);

		// Zaaktype has no confidentiality field.
		$objectService->method('find')->willReturn(
			[
				'id' => $zaaktypeUuid,
				'isDraft' => false,
			]
		);

		$objectService->method('buildSearchQuery')->willReturn([]);
		$objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 0]);

		$this->settingsService
			->method('getConfigValue')
			->willReturnCallback(
				static function (string $key): string {
					return match ($key) {
						'case_type_schema' => 'schema-ct',
						default => 'schema-x',
					};
				}
			);

		$this->service->setContext(
			objectService: $objectService,
			mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
		);

		$body = [
			'caseType' => "http://example.com/zaaktypen/{$zaaktypeUuid}",
			'bronorganisatie' => '000000000',
			'vertrouwelijkheidaanduiding' => 'intern',
		];

		$result = $this->service->rulesZakenCreate($body);

		$enrichedBody = $result['enrichedBody'];
		$this->assertSame('intern', $enrichedBody['vertrouwelijkheidaanduiding']);

	}//end testVertrouwelijkheidaanduidingFallsBackToIncomingWhenZaaktypeHasNone()
}//end class
