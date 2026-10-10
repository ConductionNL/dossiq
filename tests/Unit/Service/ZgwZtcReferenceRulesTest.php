<?php

/**
 * Characterisation tests for the reference resolution of ZgwZtcRulesService.
 *
 * Pins how the catalogue create rules (ztc-001 and the omschrijving/identificatie to uuid
 * resolution of zaaktypen, besluittypen and zaaktype-informatieobjecttypen) answer, so their
 * decomposition (method-decomposition) can be proven to change no outcome.
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
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ObjectService shape the ZTC rules call with named arguments.
 */
interface ZtcObjectServiceStub {
	public function find(string $id, string $register, string $schema): ?array;

	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * Characterisation tests for the ZTC reference resolution.
 *
 * @covers \OCA\Dossiq\Service\ZgwZtcRulesService
 * @covers \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @uses \OCA\Dossiq\Service\FieldValidator
 */
class ZgwZtcReferenceRulesTest extends TestCase {

	private const URL_UUID = '80000000-1111-2222-3333-444455556666';
	private const BARE_UUID = '81000000-1111-2222-3333-444455556666';
	private const KNOWN_UUID = '82000000-1111-2222-3333-444455556666';

	/**
	 * Build the service; searches answer from "<field>=<value>" => ids, finds from uuid => object.
	 *
	 * @param array<string, array<string>>|null $searches Search answers, or null for no context
	 * @param array<string, array>              $objects  Objects by uuid
	 *
	 * @return ZgwZtcRulesService
	 */
	private function buildService(?array $searches, array $objects=[]): ZgwZtcRulesService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('schema-x');

		$service = new ZgwZtcRulesService(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settings,
			fieldValidator: new FieldValidator()
		);

		if ($searches !== null) {
			$objectService = $this->createMock(ZtcObjectServiceStub::class);
			$objectService->method('find')->willReturnCallback(
				static fn (string $id, string $register, string $schema): ?array => $objects[$id] ?? null
			);
			$objectService->method('buildSearchQuery')->willReturnCallback(
				static fn (array $requestParams, string $register, string $schema): array => $requestParams
			);
			$objectService->method('searchObjectsPaginated')->willReturnCallback(
				static function (array $query) use ($searches): array {
					unset($query['_limit']);
					$key = array_key_first($query).'='.reset($query);
					$ids = $searches[$key] ?? [];
					return ['results' => array_map(static fn (string $id): array => ['id' => $id], $ids)];
				}
			);
			$service->setContext(objectService: $objectService, mappingConfig: ['sourceRegister' => '1']);
		}

		return $service;

	}//end buildService()

	/**
	 * Ztc-001: a selectielijstProcestype that does not resolve to a procestype is refused.
	 *
	 * @return void
	 */
	public function testUnresolvableProcestypeIsRefused(): void {
		$result = $this->buildService(searches: null)->rulesZaaktypenCreate(
			body: ['selectielijstProcestype' => 'http://127.0.0.1/api/v1/procestypen/1']
		);

		$this->assertFalse($result['valid']);
		$this->assertSame('selectielijstProcestype', $result['invalidParams'][0]['name']);
		$this->assertSame('invalid-resource', $result['invalidParams'][0]['code']);

	}//end testUnresolvableProcestypeIsRefused()

	/**
	 * Every reference form resolves: url to its uuid, name to every match, unmatched uuid as-is, junk dropped.
	 *
	 * @return void
	 */
	public function testZaaktypeReferencesResolve(): void {
		$service = $this->buildService(
			searches: [
				'name=Brief'          => ['iot-1', 'iot-2'],
				'name=Besluit'        => ['bt-1'],
				'identifier=ZT-DEEL'  => ['zt-deel'],
				'identifier=ZT-REL'   => ['zt-a', 'zt-b'],
			]
		);

		$result = $service->rulesZaaktypenCreate(
			body: [
				'informatieobjecttypen' => ['https://ztc.example.com/informatieobjecttypen/'.self::URL_UUID, 'Brief', self::BARE_UUID, 'Onbekend', '', 5],
				'besluittypen'          => ['Besluit'],
				'deelzaaktypen'         => ['ZT-DEEL', 'https://ztc.example.com/zaaktypen/geen-uuid'],
				'gerelateerdeZaaktypen' => [
					['caseType' => 'https://ztc.example.com/zaaktypen/'.self::URL_UUID, 'aardRelatie' => 'vervolg'],
					['caseType' => 'ZT-REL', 'aardRelatie' => 'bijdrage'],
					['caseType' => ''],
					['caseType' => 'Niets'],
				],
			]
		);

		$body = $result['enrichedBody'];
		$this->assertTrue($result['valid']);
		$this->assertSame([self::URL_UUID, 'iot-1', 'iot-2', self::BARE_UUID], $body['informatieobjecttypen']);
		$this->assertSame(['bt-1'], $body['besluittypen']);
		$this->assertSame(['zt-deel'], $body['deelzaaktypen']);
		$this->assertSame(
			[
				['caseType' => 'https://ztc.example.com/zaaktypen/'.self::URL_UUID, 'aardRelatie' => 'vervolg'],
				['caseType' => 'zt-a', 'aardRelatie' => 'bijdrage'],
				['caseType' => 'zt-b', 'aardRelatie' => 'bijdrage'],
			],
			$body['gerelateerdeZaaktypen']
		);
		$this->assertSame(
			[
				'subCaseTypes'     => ['zt-deel'],
				'decisionTypes'    => ['bt-1'],
				'relatedCaseTypes' => json_encode($body['gerelateerdeZaaktypen']),
			],
			$body['_directFields']
		);

	}//end testZaaktypeReferencesResolve()

	/**
	 * Without a context the arrays pass through untouched, and still become direct fields.
	 *
	 * @return void
	 */
	public function testWithoutContextArraysPassThrough(): void {
		$result = $this->buildService(searches: null)->rulesZaaktypenCreate(
			body: ['deelzaaktypen' => ['ZT-DEEL'], 'informatieobjecttypen' => ['Brief']]
		);

		$this->assertSame(['Brief'], $result['enrichedBody']['informatieobjecttypen']);
		$this->assertSame(['subCaseTypes' => ['ZT-DEEL']], $result['enrichedBody']['_directFields']);

	}//end testWithoutContextArraysPassThrough()

	/**
	 * A body without reference arrays gets no direct fields.
	 *
	 * @return void
	 */
	public function testNoArraysNoDirectFields(): void {
		$result = $this->buildService(searches: [])->rulesZaaktypenCreate(body: ['omschrijving' => 'x', 'besluittypen' => 'geen lijst']);

		$this->assertArrayNotHasKey('_directFields', $result['enrichedBody']);
		$this->assertSame('geen lijst', $result['enrichedBody']['besluittypen']);

	}//end testNoArraysNoDirectFields()

	/**
	 * A besluittype resolves its informatieobjecttypen and zaaktypen into direct fields.
	 *
	 * @return void
	 */
	public function testBesluittypeReferencesResolve(): void {
		$result = $this->buildService(searches: ['name=Brief' => ['iot-1'], 'identifier=ZT-1' => ['zt-1']])
			->rulesBesluittypenCreate(body: ['informatieobjecttypen' => ['Brief'], 'zaaktypen' => ['ZT-1']]);

		$this->assertSame(
			['documentTypes' => ['iot-1'], 'caseTypes' => ['zt-1']],
			$result['enrichedBody']['_directFields']
		);

	}//end testBesluittypeReferencesResolve()

	/**
	 * A ZIOT keeps a URL and a known bare uuid, and resolves a name or an unknown uuid by name.
	 *
	 * @return void
	 */
	public function testZiotInformatieobjecttypeResolution(): void {
		$service = $this->buildService(
			searches: ['name=Brief' => ['iot-1'], 'name='.self::BARE_UUID => ['iot-by-uuid-name']],
			objects: [self::KNOWN_UUID => ['id' => self::KNOWN_UUID]]
		);
		$resolve = static fn (string $ref): mixed => $service->rulesZaaktypeinformatieobjecttypenCreate(
			body: ['informatieobjecttype' => $ref]
		)['enrichedBody']['informatieobjecttype'];

		$url = 'https://ztc.example.com/informatieobjecttypen/'.self::URL_UUID;
		$this->assertSame($url, $resolve($url));
		$this->assertSame('https://ztc.example.com/geen-uuid', $resolve('https://ztc.example.com/geen-uuid'));
		$this->assertSame(self::KNOWN_UUID, $resolve(self::KNOWN_UUID));
		$this->assertSame('iot-by-uuid-name', $resolve(self::BARE_UUID));
		$this->assertSame('iot-1', $resolve('Brief'));
		$this->assertSame('Onbekend', $resolve('Onbekend'));

	}//end testZiotInformatieobjecttypeResolution()

	/**
	 * A ZIOT without a context is left alone.
	 *
	 * @return void
	 */
	public function testZiotWithoutContextIsLeftAlone(): void {
		$result = $this->buildService(searches: null)->rulesZaaktypeinformatieobjecttypenCreate(
			body: ['informatieobjecttype' => 'Brief']
		);

		$this->assertTrue($result['valid']);
		$this->assertSame('Brief', $result['enrichedBody']['informatieobjecttype']);

	}//end testZiotWithoutContextIsLeftAlone()
}//end class
