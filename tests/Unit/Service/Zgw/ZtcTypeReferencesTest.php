<?php

/**
 * Unit tests for ZtcTypeReferences.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\ZgwRuleLookups;
use OCA\Dossiq\Service\Zgw\ZtcTypeReferences;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The search half of the ObjectService, as the reference resolution calls it.
 */
interface ZtcReferenceSearchStub {
	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * Reference lists of a type, resolved to uuids, each search asserted with its schema.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZtcTypeReferences
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZtcTypeReferencesTest extends TestCase {
	private const UUID = '80000000-1111-4222-8333-444455556666';

	private const OTHER = '81000000-1111-4222-8333-444455556666';

	/**
	 * Every search sent, as "<schema>:<field>=<value>".
	 *
	 * @var array<int, string>
	 */
	private array $searched = [];

	/**
	 * Build the references over searches answered from "<schema>:<field>=<value>" => ids.
	 *
	 * @param array<string, array<string>>|null $answers The search answers, or null for no context.
	 * @param array $mappingConfig The mapping configuration.
	 * @param string|null $schema One schema for every key, or null for the key's own name.
	 *
	 * @return ZtcTypeReferences
	 */
	private function references(?array $answers, array $mappingConfig = ['sourceRegister' => '5'], ?string $schema = null): ZtcTypeReferences {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($schema ?? $key));

		$lookups    = new ZgwRuleLookups(logger: $this->createMock(LoggerInterface::class), settingsService: $settings);
		$references = new ZtcTypeReferences(lookups: $lookups, settingsService: $settings, fieldValidator: new FieldValidator());
		if ($answers === null) {
			return $references;
		}

		$search = $this->createMock(ZtcReferenceSearchStub::class);
		$search->method('buildSearchQuery')->willReturnCallback(
			static fn (array $requestParams, string $register, string $schema): array => [$schema . ':' . array_key_first($requestParams) . '=' . reset($requestParams)]
		);
		$search->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($answers): array {
				$this->searched[] = $query[0];
				return ['results' => array_map(static fn (string $id): array => ['id' => $id], ($answers[$query[0]] ?? []))];
			}
		);
		$lookups->setContext(objectService: $search, mappingConfig: $mappingConfig);

		return $references;
	}//end references()

	/**
	 * A URL gives its uuid without a search, a name every match, an unmatched uuid itself, and
	 * anything else nothing.
	 *
	 * @return void
	 */
	public function testEveryReferenceFormResolves(): void {
		$references = $this->references(answers: ['document_type_schema:name=Brief' => ['iot-1', 'iot-2']]);

		$body = $references->resolveTypeReferences(
			body: ['informatieobjecttypen' => ['https://ztc.example.com/iot/' . self::UUID, 'Brief', self::OTHER, 'Onbekend', 'https://ztc.example.com/iot/geen', '', 7], 'a' => 1],
			field: 'informatieobjecttypen',
			schemaKey: 'document_type_schema',
			lookupField: 'name'
		);

		$this->assertSame(['informatieobjecttypen' => [self::UUID, 'iot-1', 'iot-2', self::OTHER], 'a' => 1], $body);
		$this->assertSame(
			[
				'document_type_schema:name=Brief',
				'document_type_schema:name=' . self::OTHER,
				'document_type_schema:name=Onbekend',
				'document_type_schema:name=https://ztc.example.com/iot/geen',
			],
			$this->searched
		);
	}//end testEveryReferenceFormResolves()

	/**
	 * A list that is none, or one that cannot be looked up, is left as it was sent.
	 *
	 * @return void
	 */
	public function testAListIsLeftAloneWhenItCannotBeResolved(): void {
		$resolve = static fn (ZtcTypeReferences $references, array $body): array => $references->resolveTypeReferences(
			body: $body,
			field: 'besluittypen',
			schemaKey: 'decision_type_schema',
			lookupField: 'name'
		);
		$body = ['besluittypen' => ['Besluit']];

		$this->assertSame($body, $resolve($this->references(answers: null), $body));
		$this->assertSame($body, $resolve($this->references(answers: [], mappingConfig: []), $body));
		$this->assertSame($body, $resolve($this->references(answers: [], schema: ''), $body));
		$this->assertSame(['besluittypen' => 'geen lijst'], $resolve($this->references(answers: []), ['besluittypen' => 'geen lijst']));
		$this->assertSame([], $resolve($this->references(answers: []), []));
		$this->assertSame([], $this->searched);
	}//end testAListIsLeftAloneWhenItCannotBeResolved()

	/**
	 * A related zaaktype named by URL is kept, one named by identificatie becomes a relation per
	 * match, and one that names nothing is dropped.
	 *
	 * @return void
	 */
	public function testRelatedZaaktypenResolve(): void {
		$references = $this->references(answers: ['case_type_schema:identifier=ZT-REL' => ['zt-a', 'zt-b']]);
		$url = 'http://ztc.example.com/zaaktypen/' . self::UUID;

		$body = $references->resolveGerelateerdeZaaktypen(
			body: [
				'gerelateerdeZaaktypen' => [
					['caseType' => $url, 'aardRelatie' => 'vervolg'],
					['caseType' => 'ZT-REL', 'aardRelatie' => 'bijdrage'],
					['caseType' => ''],
					['caseType' => ['geen tekst']],
					['aardRelatie' => 'zonder type'],
					['caseType' => 'Niets'],
				],
			]
		);

		$this->assertSame(
			[
				['caseType' => $url, 'aardRelatie' => 'vervolg'],
				['caseType' => 'zt-a', 'aardRelatie' => 'bijdrage'],
				['caseType' => 'zt-b', 'aardRelatie' => 'bijdrage'],
			],
			$body['gerelateerdeZaaktypen']
		);
		$this->assertSame(['case_type_schema:identifier=ZT-REL', 'case_type_schema:identifier=Niets'], $this->searched);
		$this->assertSame(['x' => 1], $this->references(answers: null)->resolveGerelateerdeZaaktypen(body: ['x' => 1]));
	}//end testRelatedZaaktypenResolve()

	/**
	 * Only lists are copied to the direct fields, under their own name, JSON where asked.
	 *
	 * @return void
	 */
	public function testListsBecomeDirectFields(): void {
		$references = $this->references(answers: null);

		$this->assertSame(
			['a' => ['x'], 'b' => [['k' => 1]], 'c' => 'geen lijst', '_directFields' => ['one' => ['x'], 'two' => '[{"k":1}]']],
			$references->withDirectFields(
				body: ['a' => ['x'], 'b' => [['k' => 1]], 'c' => 'geen lijst'],
				map: ['a' => 'one', 'b' => 'two', 'c' => 'three', 'd' => 'four'],
				jsonFields: ['two']
			)
		);
		$this->assertSame(['c' => 'geen lijst'], $references->withDirectFields(body: ['c' => 'geen lijst'], map: ['c' => 'three']));
	}//end testListsBecomeDirectFields()
}//end class
