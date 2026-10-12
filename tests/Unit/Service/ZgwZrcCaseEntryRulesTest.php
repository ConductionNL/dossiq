<?php

/**
 * ZRC zaak create, update and patch rules: characterisation
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwZrcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ObjectService shape the entry rules call with named arguments.
 */
interface CaseEntryObjectServiceStub {
	public function find(string $id, string $register, string $schema): mixed;
}//end interface

/**
 * Pins what the three zaak entry rules add to a body before the field rules run.
 *
 * Written against ZgwZrcRulesService while it was one class, and green there
 * first; the bodies below did not change when the class was split by concern.
 *
 * @covers \OCA\Dossiq\Service\ZgwZrcRulesService
 * @covers \OCA\Dossiq\Service\Zgw\ZrcCaseTypeDefaults
 * @uses \OCA\Dossiq\Service\Zgw\ZrcCaseFieldRules
 * @uses \OCA\Dossiq\Service\Zgw\ZrcCaseRelationRules
 * @uses \OCA\Dossiq\Service\Zgw\ZrcSubResourceRules
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\FieldValidator
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZgwZrcCaseEntryRulesTest extends TestCase {
	private const TYPE_UUID = 'aabbccdd-1111-2222-3333-444455556666';

	private const TYPE_URL = 'http://example.com/catalogi/v1/zaaktypen/' . self::TYPE_UUID;

	/**
	 * Build the service; with a zaaktype it gets a context that answers find() with it.
	 *
	 * @param array|\Throwable|null $caseType The stored zaaktype, a lookup failure, or null for no context.
	 * @param array<string,string> $config Config values by key; a missing key answers 'schema-x'.
	 * @param array $mapping The mapping config.
	 *
	 * @return ZgwZrcRulesService
	 */
	private function buildService(
		array|\Throwable|null $caseType,
		array $config = [],
		array $mapping = ['sourceRegister' => '1', 'sourceSchema' => '2'],
	): ZgwZrcRulesService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $config[$key] ?? 'schema-x'
		);

		$service = new ZgwZrcRulesService(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settings,
			fieldValidator: new FieldValidator(),
		);
		if ($caseType === null) {
			return $service;
		}

		$objectService = $this->createMock(CaseEntryObjectServiceStub::class);
		$objectService->method('find')->willReturnCallback(
			static function (string $id) use ($caseType): mixed {
				if ($caseType instanceof \Throwable) {
					throw $caseType;
				}

				return $id === self::TYPE_UUID ? $caseType : null;
			}
		);
		$service->setContext(objectService: $objectService, mappingConfig: $mapping);

		return $service;
	}//end buildService()

	/**
	 * Without a context a create still gets its identificatie, archiefstatus and intake channel.
	 *
	 * @return void
	 */
	public function testCreateDefaultsWithoutContext(): void {
		$result = $this->buildService(caseType: null)->rulesZakenCreate(['caseType' => self::TYPE_URL, 'bronorganisatie' => '000000000']);

		$this->assertTrue($result['valid']);
		$body = $result['enrichedBody'];
		$this->assertMatchesRegularExpression('/^ZAAK-[0-9A-Z]+-[0-9A-F]{6}$/', $body['identificatie']);
		$this->assertSame('nog_te_archiveren', $body['archiefstatus']);
		$this->assertSame('zgw-api', $body['intakeChannel']);
		$this->assertArrayNotHasKey('assignee', $body);
		$this->assertArrayNotHasKey('vertrouwelijkheidaanduiding', $body);
	}//end testCreateDefaultsWithoutContext()

	/**
	 * Given values are kept: no generated identificatie, no default over a stated one.
	 *
	 * @return void
	 */
	public function testCreateKeepsWhatTheBodyStates(): void {
		$result = $this->buildService(caseType: null)->rulesZakenCreate(
			['identificatie' => 'Z-1', 'archiefstatus' => 'gearchiveerd', 'archiefnominatie' => 'blijvend_bewaren', 'archiefactiedatum' => '2030-01-01', 'intakeChannel' => 'balie']
		);

		$body = $result['enrichedBody'];
		$this->assertSame('Z-1', $body['identificatie']);
		$this->assertSame('gearchiveerd', $body['archiefstatus']);
		$this->assertSame('balie', $body['intakeChannel']);
	}//end testCreateKeepsWhatTheBodyStates()

	/**
	 * Zrc-001: a concept zaaktype, a failing lookup and a non-url are each refused in their own words.
	 *
	 * @return void
	 */
	public function testCreateRefusesAnUnusableZaaktype(): void {
		$concept = $this->buildService(caseType: ['id' => self::TYPE_UUID])->rulesZakenCreate(['caseType' => self::TYPE_URL]);
		$this->assertFalse($concept['valid']);
		$this->assertSame(400, $concept['status']);
		$this->assertSame('CaseType is nog in concept.', $concept['detail']);

		$failing = $this->buildService(caseType: new \RuntimeException('gone'))->rulesZakenCreate(['caseType' => self::TYPE_URL]);
		$this->assertSame('De caseType URL is ongeldig.', $failing['detail']);

		$notAUrl = $this->buildService(caseType: ['id' => self::TYPE_UUID, 'isDraft' => false])->rulesZakenCreate(['caseType' => 'zaaktype-7']);
		$this->assertSame('De caseType URL is ongeldig.', $notAUrl['detail']);
	}//end testCreateRefusesAnUnusableZaaktype()

	/**
	 * A zaak without a handler gets the zaaktype's default; the legacy field counts too.
	 *
	 * @return void
	 */
	public function testCreateStampsTheDefaultAssignee(): void {
		$legacy = $this->buildService(caseType: ['id' => self::TYPE_UUID, 'isDraft' => false, 'defaultAssignee' => 'team-vergunningen']);
		$this->assertSame('team-vergunningen', $legacy->rulesZakenCreate(['caseType' => self::TYPE_URL])['enrichedBody']['assignee']);

		$kept = $legacy->rulesZakenCreate(['caseType' => self::TYPE_URL, 'assignee' => 'jan']);
		$this->assertSame('jan', $kept['enrichedBody']['assignee']);

		$none = $this->buildService(caseType: ['id' => self::TYPE_UUID, 'isDraft' => false]);
		$this->assertArrayNotHasKey('assignee', $none->rulesZakenCreate(['caseType' => self::TYPE_URL])['enrichedBody']);
	}//end testCreateStampsTheDefaultAssignee()

	/**
	 * No register in the mapping: the zaaktype is not read, so nothing is stamped or derived.
	 *
	 * @return void
	 */
	public function testCreateWithoutARegisterReadsNoZaaktype(): void {
		$service = $this->buildService(
			caseType: ['id' => self::TYPE_UUID, 'isDraft' => false, 'defaultAssignee' => 'team', 'confidentiality' => 'geheim'],
			mapping: [],
		);

		$body = $service->rulesZakenCreate(['caseType' => self::TYPE_URL])['enrichedBody'];

		$this->assertArrayNotHasKey('assignee', $body);
		$this->assertArrayNotHasKey('vertrouwelijkheidaanduiding', $body);
	}//end testCreateWithoutARegisterReadsNoZaaktype()

	/**
	 * Zrc-009 reads three stored spellings, in this order of preference.
	 *
	 * @return void
	 */
	public function testConfidentialityIsReadFromThreeSpellings(): void {
		$base = ['id' => self::TYPE_UUID, 'isDraft' => false];
		$cases = [
			'intern' => ['confidentiality' => 'intern', 'confidentialityDesignation' => 'geheim', 'vertrouwelijkheidaanduiding' => 'openbaar'],
			'geheim' => ['confidentialityDesignation' => 'geheim', 'vertrouwelijkheidaanduiding' => 'openbaar'],
			'openbaar' => ['vertrouwelijkheidaanduiding' => 'openbaar'],
		];
		foreach ($cases as $expected => $stored) {
			$body = $this->buildService(caseType: $base + $stored)->rulesZakenUpdate(
				['caseType' => self::TYPE_URL, 'vertrouwelijkheidaanduiding' => 'zeer_geheim'],
				['id' => 'z-1']
			)['enrichedBody'];
			$this->assertSame($expected, $body['vertrouwelijkheidaanduiding']);
		}
	}//end testConfidentialityIsReadFromThreeSpellings()

	/**
	 * A PUT that omits identificatie or bronorganisatie carries the stored ones forward.
	 *
	 * @return void
	 */
	public function testUpdateCarriesTheStoredIdentityForward(): void {
		$service = $this->buildService(caseType: null);

		$english = $service->rulesZakenUpdate(['omschrijving' => 'x'], ['identifier' => 'Z-1', 'sourceOrganisation' => '111111111'])['enrichedBody'];
		$this->assertSame(['omschrijving' => 'x', 'identificatie' => 'Z-1', 'bronorganisatie' => '111111111'], $english);

		$dutch = $service->rulesZakenUpdate([], ['identificatie' => 'Z-2', 'bronorganisatie' => '222222222'])['enrichedBody'];
		$this->assertSame(['identificatie' => 'Z-2', 'bronorganisatie' => '222222222'], $dutch);

		$this->assertSame([], $service->rulesZakenUpdate([], ['identifier' => ''])['enrichedBody']);
		$this->assertSame([], $service->rulesZakenUpdate([], null)['enrichedBody']);

		$stated = $service->rulesZakenUpdate(['identificatie' => 'Z-1', 'bronorganisatie' => '9'], ['identifier' => 'Z-1', 'sourceOrganisation' => '1']);
		$this->assertSame('9', $stated['enrichedBody']['bronorganisatie']);
	}//end testUpdateCarriesTheStoredIdentityForward()

	/**
	 * A PATCH without a zaaktype reads the stored one, puts it in the body and derives from it.
	 *
	 * @return void
	 */
	public function testPatchFallsBackToTheStoredZaaktype(): void {
		$service = $this->buildService(caseType: ['id' => self::TYPE_UUID, 'isDraft' => false, 'confidentiality' => 'intern']);

		$body = $service->rulesZakenPatch(['omschrijving' => 'x'], ['caseType' => self::TYPE_URL])['enrichedBody'];
		$this->assertSame(['omschrijving' => 'x', 'caseType' => self::TYPE_URL, 'vertrouwelijkheidaanduiding' => 'intern'], $body);

		$this->assertSame(['omschrijving' => 'x'], $service->rulesZakenPatch(['omschrijving' => 'x'], ['caseType' => ''])['enrichedBody']);
		$this->assertSame(['omschrijving' => 'x'], $service->rulesZakenPatch(['omschrijving' => 'x'], null)['enrichedBody']);
	}//end testPatchFallsBackToTheStoredZaaktype()

	/**
	 * A patch never stamps an assignee and never defaults the archive or intake fields.
	 *
	 * @return void
	 */
	public function testOnlyCreateAppliesTheCreateDefaults(): void {
		$service = $this->buildService(caseType: ['id' => self::TYPE_UUID, 'isDraft' => false, 'defaultAssignee' => 'team']);

		foreach (['rulesZakenUpdate', 'rulesZakenPatch'] as $rule) {
			$body = $service->{$rule}(['caseType' => self::TYPE_URL], ['id' => 'z-1'])['enrichedBody'];
			$this->assertSame(['caseType' => self::TYPE_URL], $body);
		}
	}//end testOnlyCreateAppliesTheCreateDefaults()
}//end class
