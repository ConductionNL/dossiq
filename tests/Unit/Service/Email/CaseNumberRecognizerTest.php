<?php

/**
 * The case-number recognizer: which patterns it accepts, which numbers it finds, and which cases it links.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Email\CaseNumberRecognizer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Email\CaseNumberRecognizer
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Support\SuppressesWarnings
 */
class CaseNumberRecognizerTest extends TestCase {

	/**
	 * The configured pattern.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The per-case read check.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $access;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The mailbox owner.
	 *
	 * @var IUser&MockObject
	 */
	private IUser $owner;

	/**
	 * Fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->access = $this->createMock(CaseAccessGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->owner = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The recognizer under test.
	 *
	 * @return CaseNumberRecognizer
	 */
	private function recognizer(): CaseNumberRecognizer {
		return new CaseNumberRecognizer(settingsService: $this->settings, caseAccess: $this->access, logger: $this->logger);
	}//end recognizer()

	/**
	 * A case row as OpenRegister returns it.
	 *
	 * @param string     $identifier The case number.
	 * @param int|string $register   The `@self` register.
	 * @param int|string $schema     The `@self` schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function caseRow(string $identifier, int|string $register=5, int|string $schema=7): array {
		return ['identifier' => $identifier, '@self' => ['id' => 'uuid-' . $identifier, 'register' => $register, 'schema' => $schema]];
	}//end caseRow()

	/**
	 * The default recognizer and a pattern with a group are usable.
	 *
	 * @return void
	 */
	public function testAValidPatternIsAccepted(): void {
		$recognizer = $this->recognizer();

		$this->assertNull($recognizer->validatePattern(pattern: CaseNumberRecognizer::DEFAULT_PATTERN));
		$this->assertNull($recognizer->validatePattern(pattern: '#(\d{4})#'));
		$this->assertNull($recognizer->validatePattern(pattern: '{ZK-(\d+)}i'));
	}//end testAValidPatternIsAccepted()

	/**
	 * Each unusable pattern is refused with its own reason.
	 *
	 * @return void
	 */
	public function testAnUnusablePatternIsRefusedNamingWhy(): void {
		$recognizer = $this->recognizer();

		$this->assertSame('The pattern is empty.', $recognizer->validatePattern(pattern: '   '));
		$this->assertSame('The pattern does not compile.', $recognizer->validatePattern(pattern: '/(unclosed/'));
		$this->assertSame('The pattern has no capture group for the case number.', $recognizer->validatePattern(pattern: '/\d{4}-\d{4}/'));
		$this->assertSame('The pattern has no capture group for the case number.', $recognizer->validatePattern(pattern: '/(?:\d{4})/'));
	}//end testAnUnusablePatternIsRefusedNamingWhy()

	/**
	 * With nothing configured, the default recognizer is used.
	 *
	 * @return void
	 */
	public function testAnEmptyConfigurationLoadsTheDefault(): void {
		$this->settings->method('getConfigValue')->with(CaseNumberRecognizer::PATTERN_KEY)->willReturn('  ');
		$this->logger->expects($this->never())->method('error');

		$this->assertSame(CaseNumberRecognizer::DEFAULT_PATTERN, $this->recognizer()->loadPattern());
	}//end testAnEmptyConfigurationLoadsTheDefault()

	/**
	 * A configured recognizer is used as it is.
	 *
	 * @return void
	 */
	public function testAConfiguredPatternIsLoaded(): void {
		$this->settings->method('getConfigValue')->willReturn(' /ZK-(\d+)/ ');

		$this->assertSame('/ZK-(\d+)/', $this->recognizer()->loadPattern());
	}//end testAConfiguredPatternIsLoaded()

	/**
	 * An unusable configured recognizer refuses the run and says why, never silently matching nothing.
	 *
	 * @return void
	 */
	public function testAnUnusableConfigurationRefusesTheRunAndIsLogged(): void {
		$this->settings->method('getConfigValue')->willReturn('/ZK-\d+/');
		$this->logger->expects($this->once())->method('error')->with(
			$this->logicalAnd(
				$this->stringContains(CaseNumberRecognizer::PATTERN_KEY),
				$this->stringContains('no capture group')
			)
		);

		$this->assertNull($this->recognizer()->loadPattern());
	}//end testAnUnusableConfigurationRefusesTheRunAndIsLogged()

	/**
	 * The default finds bare and tagged numbers once each, in order, and leaves look-alikes alone.
	 *
	 * @return void
	 */
	public function testTheDefaultFindsDistinctCaseNumbersInOrder(): void {
		$text = 'Re: [ZAAK-2026-000142] en 2025-0042, nogmaals 2026-000142; niet 12026-00421, tel 06-12345678, of 1899-1234.';

		$this->assertSame(
			['2026-000142', '2025-0042'],
			$this->recognizer()->extractCaseNumberCandidates(text: $text, pattern: CaseNumberRecognizer::DEFAULT_PATTERN)
		);
	}//end testTheDefaultFindsDistinctCaseNumbersInOrder()

	/**
	 * Empty text, text without a number, and a pattern that errors at run time find nothing.
	 *
	 * @return void
	 */
	public function testNothingIsFoundWhereThereIsNothingToFind(): void {
		$recognizer = $this->recognizer();

		$this->assertSame([], $recognizer->extractCaseNumberCandidates(text: '  ', pattern: CaseNumberRecognizer::DEFAULT_PATTERN));
		$this->assertSame([], $recognizer->extractCaseNumberCandidates(text: 'Geen nummer hier', pattern: CaseNumberRecognizer::DEFAULT_PATTERN));
		$this->assertSame([], $recognizer->extractCaseNumberCandidates(text: 'abc', pattern: '/(unclosed/'));
	}//end testNothingIsFoundWhereThereIsNothingToFind()

	/**
	 * An empty capture is not a candidate.
	 *
	 * @return void
	 */
	public function testAnEmptyCaptureIsNotACandidate(): void {
		$this->assertSame(['7'], $this->recognizer()->extractCaseNumberCandidates(text: 'a- b-7', pattern: '/-\s*(\d*)/'));
	}//end testAnEmptyCaptureIsNotACandidate()

	/**
	 * A number that resolves to exactly one readable case links it, with the case's own numeric ids.
	 *
	 * @return void
	 */
	public function testOneReadableCaseIsLinked(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'uuid-2026-0042', row: self::caseRow(identifier: '2026-0042'));
		$this->access->method('hasCaseReadAccess')->with('uuid-2026-0042', $this->owner)->willReturn(true);

		$cases = $this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $store, register: 'dossiq', schema: 'case', owner: $this->owner);

		$this->assertSame([['uuid' => 'uuid-2026-0042', 'identifier' => '2026-0042', 'registerId' => 5, 'schemaId' => 7]], $cases);
	}//end testOneReadableCaseIsLinked()

	/**
	 * Two candidates naming the same case link it once.
	 *
	 * @return void
	 */
	public function testTheSameCaseIsLinkedOnce(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'uuid-2026-0042', row: self::caseRow(identifier: '2026-0042'));
		$this->access->method('hasCaseReadAccess')->willReturn(true);

		$cases = $this->recognizer()->resolveCases(candidates: ['2026-0042', '2026-0042'], objectService: $store, register: 'dossiq', schema: 'case', owner: $this->owner);

		$this->assertCount(1, $cases);
	}//end testTheSameCaseIsLinkedOnce()

	/**
	 * A case the owner may not read is not linked, whatever the subject says.
	 *
	 * @return void
	 */
	public function testACaseTheOwnerMayNotReadIsNotLinked(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'uuid-2026-0042', row: self::caseRow(identifier: '2026-0042'));
		$this->access->method('hasCaseReadAccess')->willReturn(false);

		$this->assertSame([], $this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $store, register: 'dossiq', schema: 'case', owner: $this->owner));
	}//end testACaseTheOwnerMayNotReadIsNotLinked()

	/**
	 * Two visible cases with one number link to neither, and say so.
	 *
	 * @return void
	 */
	public function testAnAmbiguousNumberLinksNothingAndIsLogged(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'a', row: self::caseRow(identifier: '2026-0042'));
		$store->seed(schema: 'case', uuid: 'b', row: self::caseRow(identifier: '2026-0042'));
		$this->access->expects($this->never())->method('hasCaseReadAccess');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('more than one case'),
			$this->callback(static fn (array $context): bool => $context['case'] === '2026-0042' && $context['matches'] === 2)
		);

		$this->assertSame([], $this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $store, register: 'dossiq', schema: 'case', owner: $this->owner));
	}//end testAnAmbiguousNumberLinksNothingAndIsLogged()

	/**
	 * A number nobody uses links nothing, silently.
	 *
	 * @return void
	 */
	public function testAnUnknownNumberLinksNothingSilently(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame([], $this->recognizer()->resolveCases(candidates: ['2026-9999'], objectService: new InMemoryRegister(), register: 'dossiq', schema: 'case', owner: $this->owner));
	}//end testAnUnknownNumberLinksNothingSilently()

	/**
	 * A loose search hit is held to exact equality, and a row without an id is not linked.
	 *
	 * @return void
	 */
	public function testOnlyAnExactMatchWithAnIdIsLinked(): void {
		$loose = new class () {
			/**
			 * Answers every search with a near miss and a row without an id.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return [['identifier' => $filters['identifier'] . '-1', '@self' => ['id' => 'near']]];
			}
		};
		$noId = new class () {
			/**
			 * Answers with an exact row that carries no id.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return [['identifier' => $filters['identifier']]];
			}
		};
		$this->access->expects($this->never())->method('hasCaseReadAccess');

		$this->assertSame([], $this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $loose, register: 'dossiq', schema: 'case', owner: $this->owner));
		$this->assertSame([], $this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $noId, register: 'dossiq', schema: 'case', owner: $this->owner));
	}//end testOnlyAnExactMatchWithAnIdIsLinked()

	/**
	 * The search asks OpenRegister for the owner's organisation explicitly, with RBAC on.
	 *
	 * @return void
	 */
	public function testTheSearchIsScopedToTheOwnersOrganisation(): void {
		$recording = new class () {
			/**
			 * The filters of the last search.
			 *
			 * @var array<string, mixed>
			 */
			public array $filters = [];

			/**
			 * Records the filters and finds nothing.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->filters = $filters;
				return [];
			}
		};

		$this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $recording, register: 'dossiq', schema: 'case', owner: $this->owner);

		$this->assertSame(
			['identifier' => '2026-0042', '_limit' => 10, '_rbac' => true, '_multitenancy' => true, '_multitenancy_explicit' => true],
			$recording->filters
		);
	}//end testTheSearchIsScopedToTheOwnersOrganisation()

	/**
	 * A search that fails links nothing and is logged.
	 *
	 * @return void
	 */
	public function testAFailingSearchLinksNothingAndIsLogged(): void {
		$failing = new class () {
			/**
			 * Fails.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				throw new RuntimeException('index down');
			}
		};
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('resolving a case number failed: index down'));

		$this->assertSame([], $this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $failing, register: 'dossiq', schema: 'case', owner: $this->owner));
	}//end testAFailingSearchLinksNothingAndIsLogged()

	/**
	 * Without a numeric register or schema on the row or in the configuration, the case is not linked to id 0.
	 *
	 * @return void
	 */
	public function testACaseWithoutNumericIdsIsNotLinkedToZero(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'uuid-2026-0042', row: self::caseRow(identifier: '2026-0042', register: 'dossiq', schema: 'case'));
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('carries no numeric register or schema id'));

		$this->assertSame([], $this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $store, register: 'dossiq', schema: 'case', owner: $this->owner));
	}//end testACaseWithoutNumericIdsIsNotLinkedToZero()

	/**
	 * The configured numeric ids are used when the row carries none, and a row without `@self` falls back to `id`.
	 *
	 * @return void
	 */
	public function testConfiguredNumericIdsFillInForTheRow(): void {
		$numeric = new class () {
			/**
			 * Answers the numeric-path search with a row that has no `@self`.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query): array {
				return [['identifier' => $query['identifier'], 'id' => 'plain-id']];
			}
		};
		$this->access->method('hasCaseReadAccess')->with('plain-id', $this->owner)->willReturn(true);

		$cases = $this->recognizer()->resolveCases(candidates: ['2026-0042'], objectService: $numeric, register: '12', schema: '34', owner: $this->owner);

		$this->assertSame([['uuid' => 'plain-id', 'identifier' => '2026-0042', 'registerId' => 12, 'schemaId' => 34]], $cases);
	}//end testConfiguredNumericIdsFillInForTheRow()
}//end class
