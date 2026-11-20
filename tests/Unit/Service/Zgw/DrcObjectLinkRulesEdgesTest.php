<?php

/**
 * DrcObjectLinkRules edges: the counterpart search as it reaches the store, and a document URL without a uuid.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\DrcDocumentRelations;
use OCA\Dossiq\Service\Zgw\DrcObjectLinkRules;
use OCA\Dossiq\Service\Zgw\ZgwRuleLookups;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The drc-004 counterpart lookup, through the RelationSearch it builds, against a recording store.
 *
 * @covers \OCA\Dossiq\Service\Zgw\DrcObjectLinkRules
 * @covers \OCA\Dossiq\Service\Zgw\RelationSearch
 * @uses \OCA\Dossiq\Service\Zgw\DrcDocumentRelations
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\FieldValidator
 * @uses \OCA\Dossiq\Service\Zgw\DocumentLink
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class DrcObjectLinkRulesEdgesTest extends TestCase {
	private const DOCUMENT = '71000000-1111-4222-8333-444455556666';

	private const CASE = '72000000-1111-4222-8333-444455556666';

	private const CASE_URL = 'https://zrc.example.com/zaken/' . self::CASE;

	/**
	 * Every buildSearchQuery call, as [requestParams, register, schema].
	 *
	 * @var array<int, array>
	 */
	private array $built = [];

	/**
	 * The rules in a context whose store records each search and finds nothing.
	 *
	 * @return DrcObjectLinkRules
	 */
	private function rules(): DrcObjectLinkRules {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => 'schema-' . $key);
		$lookups = new ZgwRuleLookups(settingsService: $settings);
		$parts = [
			'lookups' => $lookups,
			'results' => new ZgwRuleResults(logger: $this->createMock(LoggerInterface::class)),
			'settingsService' => $settings,
			'fieldValidator' => new FieldValidator(),
		];

		$built = &$this->built;
		$store = new class($built) {
			/**
			 * @param array<int, array> $built The search log.
			 */
			public function __construct(private array &$built) {
			}

			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				$this->built[] = [$requestParams, $register, $schema];
				return ['schema' => $schema];
			}

			public function searchObjectsPaginated(array $query): array {
				return ['results' => []];
			}
		};
		$lookups->setContext(objectService: $store, mappingConfig: ['sourceRegister' => '5']);

		return new DrcObjectLinkRules(...$parts, relations: new DrcDocumentRelations(...$parts));
	}//end rules()

	/**
	 * The counterpart is searched in the context's register and the type's relation schema,
	 * by object uuid and document uuid, one row at most.
	 *
	 * @return void
	 */
	public function testTheCounterpartSearchCarriesRegisterSchemaAndBothUuids(): void {
		$result = $this->rules()->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => 'https://drc.example.com/eio/' . self::DOCUMENT, 'object' => self::CASE_URL, 'objectType' => 'case']
		);

		$this->assertSame('inconsistent-relation', $result['invalidParams'][0]['code']);
		$this->assertSame(
			[['case' => self::CASE, 'document' => self::DOCUMENT, '_limit' => 1], '5', 'schema-case_document_schema'],
			end($this->built)
		);
	}//end testTheCounterpartSearchCarriesRegisterSchemaAndBothUuids()

	/**
	 * A document URL without a uuid names no counterpart, so drc-004 is skipped and the body passes.
	 *
	 * @return void
	 */
	public function testADocumentUrlWithoutAUuidSkipsTheCounterpartSearch(): void {
		$body = ['informatieobject' => 'https://drc.example.com/eio/geen-uuid', 'object' => self::CASE_URL, 'objectType' => 'case'];

		$result = $this->rules()->rulesObjectinformatieobjectenCreate(body: $body);

		$this->assertSame(['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body], $result);
		$this->assertNotSame([], $this->built, 'the uniqueness check still ran');
		$this->assertSame([], array_filter($this->built, static fn (array $call): bool => array_key_first($call[0]) === 'case'), 'no counterpart search ran');
	}//end testADocumentUrlWithoutAUuidSkipsTheCounterpartSearch()
}//end class
