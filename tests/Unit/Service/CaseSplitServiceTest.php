<?php

/**
 * A split divides. It does not duplicate, and it does not take what is not offered.
 *
 * 🔴 THE ASSERTION THAT CARRIES ROW 2.35 is that the moved document is no
 * longer on the first case. `CaseCopyService` already carries a file across
 * whole, and a "split" that did the same would produce two cases both claiming
 * the same document. A test that only checked the document arrived on the
 * second case would pass against exactly that, which is the behaviour the row
 * is rated `partial` for.
 *
 * 🔴 AND THAT A ROW ON ANOTHER CASE IS REFUSED. Without that check a handler
 * could move somebody else's document onto their own case by passing its id,
 * and OpenRegister's audit trail would record dossiq doing it on their behalf.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\CaseSplitService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Moving, refusing, and relating both ways.
 *
 * @covers \OCA\Dossiq\Service\CaseSplitService
 */
class CaseSplitServiceTest extends TestCase {

	/**
	 * Everything the fake store was asked to write.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $writes = [];

	/**
	 * Every relation the fake relation service was asked to write.
	 *
	 * @var array<int, array{from: string, to: string, nature: string}>
	 */
	private array $relations = [];

	/**
	 * Reset the logs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->writes = [];
		$this->relations = [];
	}//end setUp()

	/**
	 * Build the service over an in-memory store.
	 *
	 * @param array<string, array<string, mixed>> $rows     Rows by id.
	 * @param array<int, string>|null             $divides  What the case type allows.
	 *
	 * @return CaseSplitService The service under test.
	 */
	private function makeService(array $rows, ?array $divides = null): CaseSplitService {
		$writes = &$this->writes;

		$objectService = new class($rows, $writes) {
			/**
			 * @param array<string, array<string, mixed>> $rows   Rows by id.
			 * @param array<int, array<string, mixed>>    $writes Write log.
			 */
			public function __construct(private array $rows, private array &$writes) {
			}//end __construct()

			/**
			 * Find one row by id.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register (ignored).
			 * @param mixed  $schema   The schema (ignored).
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				return ($this->rows[$id] ?? null);
			}//end find()

			/**
			 * Record a write.
			 *
			 * @param array<string, mixed> $object   The row.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $uuid     The id.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(
				array $object,
				string $register = '',
				string $schema = '',
				string $uuid = '',
			): array {
				$this->writes[] = ['schema' => $schema, 'object' => $object];
				$this->rows[$uuid] = $object;

				return $object;
			}//end saveObject()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'register' ? 'dossiq' : $default)
		);

		$caseTypes = $this->getMockBuilder(CaseTypeResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['effectiveCaseType'])
			->getMock();
		$caseTypes->method('effectiveCaseType')->willReturn(
			$divides === null ? [] : ['splitDivides' => $divides]
		);

		$relations = &$this->relations;
		$relationService = $this->getMockBuilder(CaseRelationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['addRelation'])
			->getMock();
		$relationService->method('addRelation')->willReturnCallback(
			static function (string $caseId, string $targetId, string $nature) use (&$relations): array {
				$relations[] = ['from' => $caseId, 'to' => $targetId, 'nature' => $nature];

				return [];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new CaseSplitService($settings, $caseTypes, $relationService, $l10n, new NullLogger());
	}//end makeService()

	/**
	 * A moved document leaves the first case.
	 *
	 * @return void
	 */
	public function testAMovedDocumentLeavesTheFirstCase(): void {
		$service = $this->makeService(
			['doc-1' => ['id' => 'doc-1', 'case' => 'case-a', 'title' => 'Bouwtekening']]
		);

		$moved = $service->moveChosen(
			sourceCaseId: 'case-a',
			targetCaseId: 'case-b',
			documentIds: ['doc-1'],
			partyIds: [],
		);

		$this->assertSame(1, $moved['documents']);
		$this->assertSame([], $moved['refused']);
		$this->assertCount(1, $this->writes);
		// 🔴 THE ONE THIS FILE EXISTS FOR. The row now names the SECOND case and
		// no longer the first; a copy would leave it on both.
		$this->assertSame('case-b', $this->writes[0]['object']['case']);
		$this->assertSame('caseDocument', $this->writes[0]['schema']);
		$this->assertSame('Bouwtekening', $this->writes[0]['object']['title']);
	}//end testAMovedDocumentLeavesTheFirstCase()

	/**
	 * A party moves the same way, from its own schema.
	 *
	 * @return void
	 */
	public function testAPartyMovesToo(): void {
		$service = $this->makeService(
			['role-1' => ['id' => 'role-1', 'case' => 'case-a', 'name' => 'Gemachtigde']]
		);

		$moved = $service->moveChosen(
			sourceCaseId: 'case-a',
			targetCaseId: 'case-b',
			documentIds: [],
			partyIds: ['role-1'],
		);

		$this->assertSame(1, $moved['parties']);
		$this->assertSame('role', $this->writes[0]['schema']);
		$this->assertSame('case-b', $this->writes[0]['object']['case']);
	}//end testAPartyMovesToo()

	/**
	 * A row on somebody else's case is refused and never written.
	 *
	 * @return void
	 */
	public function testARowOnAnotherCaseIsRefused(): void {
		$service = $this->makeService(
			['doc-9' => ['id' => 'doc-9', 'case' => 'someone-elses-case']]
		);

		$moved = $service->moveChosen(
			sourceCaseId: 'case-a',
			targetCaseId: 'case-b',
			documentIds: ['doc-9'],
			partyIds: [],
		);

		$this->assertSame(0, $moved['documents']);
		$this->assertSame(['doc-9'], $moved['refused']);
		$this->assertSame(
			[],
			$this->writes,
			'a document on another case must not be moved by passing its id'
		);
	}//end testARowOnAnotherCaseIsRefused()

	/**
	 * Both halves name each other.
	 *
	 * @return void
	 */
	public function testBothHalvesNameEachOther(): void {
		$this->makeService([])->relate(sourceCaseId: 'case-a', targetCaseId: 'case-b');

		$this->assertCount(2, $this->relations);
		$this->assertSame(
			[
				['from' => 'case-a', 'to' => 'case-b', 'nature' => 'vervolg'],
				['from' => 'case-b', 'to' => 'case-a', 'nature' => 'vervolg'],
			],
			$this->relations,
			'a relation written one way only leaves a case reachable from its sibling and not back'
		);
	}//end testBothHalvesNameEachOther()

	/**
	 * A case type that declares nothing allows everything this app can divide.
	 *
	 * @return void
	 */
	public function testAnUndeclaredCaseTypeAllowsEverything(): void {
		// Shipping this change must not narrow a case type nobody has
		// administered yet.
		$this->assertSame(
			CaseSplitService::DIVISIBLE,
			$this->makeService([])->divisibleFor(caseTypeId: 'type-1')
		);
		$this->assertSame(
			CaseSplitService::DIVISIBLE,
			$this->makeService([], divides: [])->divisibleFor(caseTypeId: 'type-1')
		);
	}//end testAnUndeclaredCaseTypeAllowsEverything()

	/**
	 * A case type that forbids dividing documents refuses, naming the rule.
	 *
	 * @return void
	 */
	public function testACaseTypeCanForbidDividingDocuments(): void {
		$service = $this->makeService([], divides: ['parties']);

		$this->assertSame(['parties'], $service->divisibleFor(caseTypeId: 'type-1'));

		// The control first: what IS allowed must still pass, or "refused"
		// could mean the guard refuses everything.
		$service->assertAllowed(caseTypeId: 'type-1', kinds: ['parties']);

		$this->expectException(RefusedException::class);
		$service->assertAllowed(caseTypeId: 'type-1', kinds: ['documents']);
	}//end testACaseTypeCanForbidDividingDocuments()

	/**
	 * The refusal names what was refused, not just that something was.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheRule(): void {
		$service = $this->makeService([], divides: ['parties']);

		try {
			$service->assertAllowed(caseTypeId: 'type-1', kinds: ['documents']);
			$this->fail('a forbidden division must be refused');
		} catch (RefusedException $e) {
			$this->assertSame('split_not_divisible', $e->getRule());
			// A handler told "not allowed" goes looking for a permission; one
			// told which declaration refused them goes to the case type.
			$this->assertStringContainsString('documents', $e->getSentence());
			$this->assertSame(409, $e->getStatus());
		}
	}//end testTheRefusalNamesTheRule()

	/**
	 * Tasks are not in the divisible set, and that is deliberate.
	 *
	 * @return void
	 */
	public function testTasksAreNotDivisibleHere(): void {
		// dossiq has had no task table since `remove-casetask`. A task is the
		// workflow engine's record and moving one is the engine's act, so
		// offering it here would be a promise this app cannot keep.
		$this->assertNotContains('tasks', CaseSplitService::DIVISIBLE);

		$this->expectException(RefusedException::class);
		$this->makeService([], divides: ['documents', 'parties'])
			->assertAllowed(caseTypeId: 'type-1', kinds: ['tasks']);
	}//end testTasksAreNotDivisibleHere()
}//end class
