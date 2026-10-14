<?php

/**
 * Unit tests for DrcObjectLinkRules.
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
use OCA\Dossiq\Service\Zgw\DrcDocumentRelations;
use OCA\Dossiq\Service\Zgw\DrcObjectLinkRules;
use OCA\Dossiq\Service\Zgw\ZgwRuleLookups;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The search half of the ObjectService, as the object link rules call it.
 */
interface DrcObjectLinkSearchStub {
	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * The create rules of an objectinformatieobject, with the real relations behind them.
 *
 * @covers \OCA\Dossiq\Service\Zgw\DrcObjectLinkRules
 * @uses \OCA\Dossiq\Service\Zgw\DrcDocumentRelations
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DrcObjectLinkRulesTest extends TestCase {
	private const DOCUMENT = '71000000-1111-4222-8333-444455556666';

	private const CASE = '72000000-1111-4222-8333-444455556666';

	private const DOCUMENT_URL = 'https://drc.example.com/enkelvoudiginformatieobjecten/' . self::DOCUMENT;

	private const CASE_URL = 'https://zrc.example.com/zaken/' . self::CASE;

	private const DECISION_URL = 'https://brc.example.com/besluiten/' . self::CASE;

	/**
	 * Every query the rules and their relations sent.
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

	/**
	 * Build the rules, with a search that answers from its query when one is given.
	 *
	 * @param \Closure|null $answer Maps a query to a search result, or null for no context.
	 * @param array $mappingConfig The mapping configuration.
	 * @param array<string, string> $schemas Schema per settings key; a missing key answers its own name.
	 *
	 * @return DrcObjectLinkRules
	 */
	private function rules(?\Closure $answer, array $mappingConfig = ['sourceRegister' => '5'], array $schemas = []): DrcObjectLinkRules {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($schemas[$key] ?? $key));
		$logger = $this->createMock(LoggerInterface::class);

		$lookups = new ZgwRuleLookups(logger: $logger, settingsService: $settings);
		$parts   = ['lookups' => $lookups, 'results' => new ZgwRuleResults(), 'settingsService' => $settings, 'fieldValidator' => new FieldValidator(), 'logger' => $logger];
		$rules   = new DrcObjectLinkRules(...$parts, relations: new DrcDocumentRelations(...$parts));
		if ($answer === null) {
			return $rules;
		}

		$search = $this->createMock(DrcObjectLinkSearchStub::class);
		$search->method('buildSearchQuery')->willReturnCallback(
			static fn (array $requestParams, string $register, string $schema): array => ($requestParams + ['@schema' => $schema])
		);
		$search->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($answer): array {
				$this->queries[] = $query;
				return $answer($query);
			}
		);
		$lookups->setContext(objectService: $search, mappingConfig: $mappingConfig);

		return $rules;
	}//end rules()

	/**
	 * Whether a query is the drc-004 lookup, which names the object before the document.
	 *
	 * @param array $query The query.
	 *
	 * @return bool
	 */
	private static function isCounterpartLookup(array $query): bool {
		return in_array(array_key_first($query), ['case', 'decision'], true);
	}//end isCounterpartLookup()

	/**
	 * A refusal on the object field.
	 *
	 * @param string $detail The detail.
	 * @param string $code The code.
	 * @param string $reason The reason.
	 * @param string $name The field the refusal names.
	 *
	 * @return array
	 */
	private function refusal(string $detail, string $code, string $reason, string $name = 'object'): array {
		return [
			'valid' => false,
			'status' => 400,
			'detail' => $detail,
			'invalidParams' => [['name' => $name, 'code' => $code, 'reason' => $reason]],
			'enrichedBody' => [],
		];
	}//end refusal()

	/**
	 * Drc-002: the object URL is a URL, holds a uuid and points at what the objectType says.
	 *
	 * @return void
	 */
	public function testTheObjectUrlIsCheckedAgainstItsType(): void {
		$rules = $this->rules(answer: null);

		$this->assertSame(
			$this->refusal(detail: 'De object URL is ongeldig.', code: 'bad-url', reason: 'De object URL is ongeldig.'),
			$rules->rulesObjectinformatieobjectenCreate(body: ['object' => 'geen url'])
		);
		// A URL without a uuid is already no valid URL here, so it gets the same refusal.
		$this->assertSame(
			$this->refusal(detail: 'De object URL is ongeldig.', code: 'bad-url', reason: 'De object URL is ongeldig.'),
			$rules->rulesObjectinformatieobjectenCreate(body: ['object' => 'https://zrc.example.com/zaken'])
		);
		$this->assertSame(
			$this->refusal(detail: 'De object URL wijst niet naar een zaak.', code: 'invalid-resource', reason: 'De object URL wijst niet naar een zaak resource.'),
			$rules->rulesObjectinformatieobjectenCreate(body: ['object' => self::DECISION_URL, 'objectType' => 'case'])
		);
		$this->assertSame(
			$this->refusal(detail: 'De object URL wijst niet naar een besluit.', code: 'invalid-resource', reason: 'De object URL wijst niet naar een besluit resource.'),
			$rules->rulesObjectinformatieobjectenCreate(body: ['object' => self::CASE_URL, 'objectType' => 'decision'])
		);
	}//end testTheObjectUrlIsCheckedAgainstItsType()

	/**
	 * The informatieobject URL is the rules service's to check: these rules take it as given.
	 *
	 * @return void
	 */
	public function testTheDocumentUrlIsNotCheckedHere(): void {
		$result = $this->rules(answer: null)->rulesObjectinformatieobjectenCreate(body: ['informatieobject' => 'geen url']);

		$this->assertTrue($result['valid']);
	}//end testTheDocumentUrlIsNotCheckedHere()

	/**
	 * Without a context, a document or an object nothing is looked up and the body passes as sent.
	 *
	 * @return void
	 */
	public function testNothingIsLookedUpWithoutContextDocumentOrObject(): void {
		$body = ['informatieobject' => self::DOCUMENT_URL, 'object' => self::CASE_URL, 'objectType' => 'case'];
		$hit  = static fn (array $query): array => ['total' => 1];

		$this->assertSame(
			['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body],
			$this->rules(answer: null)->rulesObjectinformatieobjectenCreate(body: $body)
		);
		$this->assertTrue($this->rules(answer: $hit)->rulesObjectinformatieobjectenCreate(body: ['object' => self::CASE_URL])['valid']);
		$this->assertTrue($this->rules(answer: $hit)->rulesObjectinformatieobjectenCreate(body: ['informatieobject' => self::DOCUMENT_URL])['valid']);
		$this->assertTrue($this->rules(answer: $hit)->rulesObjectinformatieobjectenCreate(body: [])['valid']);
		$this->assertSame([], $this->queries);
	}//end testNothingIsLookedUpWithoutContextDocumentOrObject()

	/**
	 * Drc-003 runs before drc-004, and the relations read in the same context as these rules.
	 *
	 * @return void
	 */
	public function testADuplicateIsRefusedBeforeTheCounterpartIsLookedUp(): void {
		$result = $this->rules(answer: static fn (array $query): array => ['total' => 1])->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => self::DOCUMENT_URL, 'object' => self::CASE_URL, 'objectType' => 'case']
		);

		$this->assertSame('unique', $result['invalidParams'][0]['code']);
		$this->assertCount(1, $this->queries);
		$this->assertSame('document_link_schema', $this->queries[0]['@schema']);
	}//end testADuplicateIsRefusedBeforeTheCounterpartIsLookedUp()

	/**
	 * Drc-004: a pair without its ZaakInformatieObject or BesluitInformatieObject is inconsistent.
	 *
	 * @return void
	 */
	public function testAMissingCounterpartIsInconsistent(): void {
		$none = static fn (array $query): array => ['results' => []];
		$zaak = 'Er bestaat geen ZaakInformatieObject in de Zaken API voor deze combinatie.';
		$besluit = 'Er bestaat geen BesluitInformatieObject in de Besluiten API voor deze combinatie.';

		$this->assertSame(
			$this->refusal(detail: $zaak, code: 'inconsistent-relation', reason: $zaak, name: 'nonFieldErrors'),
			$this->rules(answer: $none)->rulesObjectinformatieobjectenCreate(
				body: ['informatieobject' => self::DOCUMENT_URL, 'object' => self::CASE_URL, 'objectType' => 'case']
			)
		);
		$this->assertSame(
			['case' => self::CASE, 'document' => self::DOCUMENT, '_limit' => 1, '@schema' => 'case_document_schema'],
			end($this->queries)
		);
		$this->assertSame(
			$this->refusal(detail: $besluit, code: 'inconsistent-relation', reason: $besluit, name: 'nonFieldErrors'),
			$this->rules(answer: $none)->rulesObjectinformatieobjectenCreate(
				body: ['informatieobject' => self::DOCUMENT_URL, 'object' => self::DECISION_URL, 'objectType' => 'decision']
			)
		);
		$this->assertSame(
			['decision' => self::CASE, 'document' => self::DOCUMENT, '_limit' => 1, '@schema' => 'decision_document_schema'],
			end($this->queries)
		);
	}//end testAMissingCounterpartIsInconsistent()

	/**
	 * Drc-004 passes when the counterpart is found, and when its lookup fails.
	 *
	 * @return void
	 */
	public function testAFoundCounterpartOrAFailedLookupPasses(): void {
		$body  = ['informatieobject' => self::DOCUMENT_URL, 'object' => self::CASE_URL, 'objectType' => 'case'];
		$found = static fn (array $query): array => ['total' => (int)self::isCounterpartLookup(query: $query)];
		$down  = static function (array $query): array {
			if (self::isCounterpartLookup(query: $query) === true) {
				throw new \RuntimeException('down');
			}

			return ['total' => 0];
		};

		$this->assertTrue($this->rules(answer: $found)->rulesObjectinformatieobjectenCreate(body: $body)['valid']);
		$this->assertTrue($this->rules(answer: $down)->rulesObjectinformatieobjectenCreate(body: $body)['valid']);
	}//end testAFoundCounterpartOrAFailedLookupPasses()

	/**
	 * Drc-004 is skipped without an objectType, for an unknown one, and without a schema or register.
	 *
	 * @return void
	 */
	public function testTheCounterpartLookupNeedsATypeASchemaAndARegister(): void {
		$none = static fn (array $query): array => ['total' => 0];
		$body = ['informatieobject' => self::DOCUMENT_URL, 'object' => self::CASE_URL];

		$this->assertTrue($this->rules(answer: $none)->rulesObjectinformatieobjectenCreate(body: $body)['valid']);
		$this->assertTrue($this->rules(answer: $none)->rulesObjectinformatieobjectenCreate(body: ($body + ['objectType' => 'verzoek']))['valid']);
		$this->assertTrue(
			$this->rules(answer: $none, schemas: ['case_document_schema' => ''])
				->rulesObjectinformatieobjectenCreate(body: ($body + ['objectType' => 'case']))['valid']
		);
		$this->assertTrue($this->rules(answer: $none, mappingConfig: [])->rulesObjectinformatieobjectenCreate(body: ($body + ['objectType' => 'case']))['valid']);
		$this->assertSame([], array_filter($this->queries, [self::class, 'isCounterpartLookup']));
	}//end testTheCounterpartLookupNeedsATypeASchemaAndARegister()
}//end class
