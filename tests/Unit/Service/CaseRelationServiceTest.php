<?php

/**
 * CaseRelationService Unit Tests
 *
 * Covers the typed peer-relation contract: guards (invalid type, self,
 * duplicate, hierarchy-overlap, access), the one-sided write and the far side
 * read back from /used under the inverse label, delete-cleanup of counterparts
 * on both sides, and the promotion of direct writes into typed links.
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

use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Relation\CaseHierarchyOverlapGuard;
use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use OCA\Dossiq\Service\Relation\CaseRelationLabels;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CaseRelationService.
 *
 * @covers \OCA\Dossiq\Service\CaseRelationService
 *
 * @uses \OCA\Dossiq\Service\Relation\CaseHierarchyOverlapGuard
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationCodec
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationLabels
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationStore
 */
class CaseRelationServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					default => $default,
				};
			}
		);
	}//end setUp()

	/**
	 * Build a shared in-memory object service stub backed by a reference to
	 * a store array, supporting find() (RBAC: missing id => null) and
	 * saveObject() (writes back by id).
	 *
	 * @param array<string, array<string, mixed>> &$store Seed map (by reference).
	 *
	 * @return object
	 */
	private function makeObjectService(array &$store): object {
		return new class($store) {
			/**
			 * @param array<string, array<string, mixed>> $store Store reference.
			 */
			public function __construct(
				private array &$store,
			) {
			}//end __construct()

			/**
			 * Mimic OR find() — null when the id is unknown (RBAC fail-closed).
			 *
			 * @param string $id Object UUID.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema (ignored).
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}//end find()

			/**
			 * Mimic OR saveObject() — write the object back keyed by id.
			 *
			 * @param array<string, mixed> $object Object to persist.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema (ignored).
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null): array {
				$id = (string)($object['id'] ?? '');
				if ($id !== '') {
					$this->store[$id] = $object;
				}

				return $object;
			}//end saveObject()

			/**
			 * Mimic OR getObjectUses() — the cases this one references, read
			 * out of the typed reference properties, each carrying the
			 * relation block RelationHandler attaches.
			 *
			 * `displayLabel` is a SENTINEL that no dossiq-side table can
			 * produce. A service that recomputed the label from the type, or
			 * picked between label and inverseLabel itself, would not be able
			 * to produce this string and the assertion would fail.
			 *
			 * @param string $objectId Object UUID.
			 * @param array<string, mixed> $query Ignored.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function getObjectUses(
				string $objectId,
				array $query = [],
				bool $_rbac = true,
				bool $_multitenancy = true
			): array {
				$results = [];
				$source  = ($this->store[$objectId] ?? []);
				foreach (self::PROPERTIES as $type => $property) {
					foreach ((array)($source[$property] ?? []) as $uuid) {
						$target = ($this->store[(string)$uuid] ?? null);
						if ($target === null) {
							continue;
						}

						$results[] = ($target + [
							'relation' => [
								'property' => $property,
								'type' => $type,
								'direction' => 'outgoing',
								'label' => 'near-'.$type,
								'inverseLabel' => 'far-'.$type,
								'displayLabel' => 'near-'.$type,
							],
						]);
					}
				}

				return ['results' => $results, 'total' => count($results)];
			}//end getObjectUses()

			/**
			 * Mimic OR getObjectUsedBy() — the cases that reference this one.
			 *
			 * @param string $objectId Object UUID.
			 * @param array<string, mixed> $query Ignored.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function getObjectUsedBy(
				string $objectId,
				array $query = [],
				bool $_rbac = true,
				bool $_multitenancy = true
			): array {
				$results = [];
				foreach ($this->store as $candidate) {
					foreach (self::PROPERTIES as $type => $property) {
						if (in_array($objectId, (array)($candidate[$property] ?? []), true) === false) {
							continue;
						}

						$results[] = ($candidate + [
							'relation' => [
								'property' => $property,
								'type' => $type,
								'direction' => 'incoming',
								'label' => 'near-'.$type,
								'inverseLabel' => 'far-'.$type,
								'displayLabel' => 'far-'.$type,
							],
						]);
					}
				}

				return ['results' => $results, 'total' => count($results)];
			}//end getObjectUsedBy()

			/**
			 * The typed reference properties, mirroring the case schema.
			 *
			 * @var array<string, string>
			 */
			public const PROPERTIES = CaseRelationCodec::TYPED_PROPERTIES;
		};
	}//end makeObjectService()

	/**
	 * Build the service under test wired to a store.
	 *
	 * @param array<string, array<string, mixed>> &$store Store reference.
	 *
	 * @return CaseRelationService
	 */
	private function makeService(array &$store): CaseRelationService {
		$objectService = $this->makeObjectService($store);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		// The store, codec and hierarchy guard are real collaborators, not
		// mocks: every assertion below is about behaviour they inherited
		// verbatim from CaseRelationService, and the store is still driven
		// entirely by the mocked SettingsService above.
		$relationStore = new CaseRelationStore($this->settingsService, $this->logger);
		$codec         = new CaseRelationCodec();

		return new CaseRelationService(
			store: $relationStore,
			codec: $codec,
			hierarchyGuard: new CaseHierarchyOverlapGuard(),
			labels: new CaseRelationLabels(store: $relationStore, codec: $codec),
		);
	}//end makeService()

	/**
	 * Decode the relatedCases JSON string of a stored case.
	 *
	 * @param array<string, array<string, mixed>> $store Store.
	 * @param string $id Case id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function relationsOf(array $store, string $id): array {
		$raw = ($store[$id]['relatedCases'] ?? '');
		if (is_array($raw) === true) {
			return $raw;
		}

		return json_decode((string)$raw, true) ?? [];
	}//end relationsOf()

	/**
	 * Adding a relation writes it ONCE, on the case that declared it, into the
	 * property that carries its type. The counterpart is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testAddRelationIsWrittenOnceOnTheDeclaringCase(): void {
		$store = [
			'a' => ['id' => 'a', 'title' => 'Bezwaar'],
			'b' => ['id' => 'b', 'title' => 'Besluit'],
		];
		$service = $this->makeService($store);

		$result = $service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'subject', notes: 'Bezwaar over besluit');
		$this->assertTrue($result['ok']);

		$aRel = $this->relationsOf($store, 'a');
		$this->assertCount(1, $aRel);
		$this->assertSame('b', $aRel[0]['caseId']);
		$this->assertSame('subject', $aRel[0]['aardRelatie']);
		$this->assertSame('Bezwaar over besluit', $aRel[0]['notes']);

		// The link OpenRegister indexes.
		$this->assertSame(['b'], $store['a']['subjectCases']);

		// And nothing at all on the far side: it is read from /used.
		$this->assertCount(0, $this->relationsOf($store, 'b'));
		$this->assertSame([], ($store['b']['subjectCases'] ?? []));
	}//end testAddRelationIsWrittenOnceOnTheDeclaringCase()

	/**
	 * Each side reads the relation under the name that belongs to that side,
	 * taken from the row and not computed here.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testEachSideReadsItsOwnHalfOfTheLabelPair(): void {
		$store = [
			'a' => ['id' => 'a', 'title' => 'Bezwaar'],
			'b' => ['id' => 'b', 'title' => 'Besluit'],
		];
		$service = $this->makeService($store);
		$service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'vervolg');

		$near = $service->listRelations(caseId: 'a');
		$this->assertCount(1, $near);
		$this->assertSame('b', $near[0]['caseId']);
		$this->assertSame('near-vervolg', $near[0]['displayLabel']);
		$this->assertSame('outgoing', $near[0]['direction']);

		$far = $service->listRelations(caseId: 'b');
		$this->assertCount(1, $far);
		$this->assertSame('a', $far[0]['caseId']);
		$this->assertSame('far-vervolg', $far[0]['displayLabel']);
		$this->assertSame('incoming', $far[0]['direction']);
	}//end testEachSideReadsItsOwnHalfOfTheLabelPair()

	/**
	 * Scenario: Link a vergunning to the bezwaar it waits on.
	 *
	 * The pair is written once, on the case that declares it, and each side
	 * reads its own half: the vergunning waits on the bezwaar, and the bezwaar
	 * blocks the vergunning. That is what makes the moved-term offer possible,
	 * because the offer is made from the blocking side.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md#requirement-a-case-can-wait-on-another-case-req-rcl-10
	 */
	public function testACaseWaitsOnAnotherAndThatOtherBlocksIt(): void {
		$store = [
			'a' => ['id' => 'a', 'title' => 'Vergunning'],
			'b' => ['id' => 'b', 'title' => 'Bezwaar'],
		];
		$service = $this->makeService($store);

		$written = $service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'waitsOn');
		$this->assertTrue($written['ok']);

		$near = $service->listRelations(caseId: 'a');
		$this->assertCount(1, $near);
		$this->assertSame('b', $near[0]['caseId']);
		$this->assertSame('waitsOn', $near[0]['aardRelatie']);
		$this->assertSame('outgoing', $near[0]['direction']);

		$far = $service->listRelations(caseId: 'b');
		$this->assertCount(1, $far);
		$this->assertSame('a', $far[0]['caseId']);
		$this->assertSame('incoming', $far[0]['direction']);

		// The link is stored in the property the pair declares, which is the
		// one the offer reads back from the blocking side.
		$this->assertSame(['b'], $store['a']['blockingCases'] ?? []);
	}//end testACaseWaitsOnAnotherAndThatOtherBlocksIt()

	/**
	 * The same type declared from both cases is two contradictory statements,
	 * so the second one is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testTheSameTypeDeclaredFromBothSidesIsRefused(): void {
		$store = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];
		$service = $this->makeService($store);

		$this->assertTrue($service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'vervolg')['ok']);

		$mirrored = $service->addRelation(caseId: 'b', targetId: 'a', natureRelationship: 'vervolg');
		$this->assertFalse($mirrored['ok']);
		$this->assertSame('duplicate', $mirrored['reason']);
	}//end testTheSameTypeDeclaredFromBothSidesIsRefused()

	/**
	 * Self-relations are rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testSelfRelationRejected(): void {
		$store = ['a' => ['id' => 'a']];
		$service = $this->makeService($store);

		$result = $service->addRelation(caseId: 'a', targetId: 'a', natureRelationship: 'vervolg');
		$this->assertFalse($result['ok']);
		$this->assertSame('self_relation', $result['reason']);
	}//end testSelfRelationRejected()

	/**
	 * An invalid aardRelatie is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testInvalidAardRelatieRejected(): void {
		$store = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];
		$service = $this->makeService($store);

		$result = $service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'bogus');
		$this->assertFalse($result['ok']);
		$this->assertSame('invalid_aard_relatie', $result['reason']);
	}//end testInvalidAardRelatieRejected()

	/**
	 * A duplicate {caseId, aardRelatie} pair is rejected, but the same target
	 * with a DIFFERENT aardRelatie is accepted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testDuplicatePairRejectedDifferentTypeAccepted(): void {
		$store = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];
		$service = $this->makeService($store);

		$this->assertTrue($service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'vervolg')['ok']);

		$dup = $service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'vervolg');
		$this->assertFalse($dup['ok']);
		$this->assertSame('duplicate', $dup['reason']);

		$other = $service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'subject');
		$this->assertTrue($other['ok']);
		$this->assertCount(2, $this->relationsOf($store, 'a'));
	}//end testDuplicatePairRejectedDifferentTypeAccepted()

	/**
	 * A pair already linked through the hoofdzaak/deelzaak hierarchy cannot be
	 * additionally peer-linked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testHierarchyOverlapRejected(): void {
		// b is a deelzaak of a (parentCase = a).
		$store = [
			'a' => ['id' => 'a'],
			'b' => ['id' => 'b', 'parentCase' => 'a'],
		];
		$service = $this->makeService($store);

		$result = $service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'bijdrage');
		$this->assertFalse($result['ok']);
		$this->assertSame('hierarchy_overlap', $result['reason']);
	}//end testHierarchyOverlapRejected()

	/**
	 * Linking requires read access to BOTH cases; an unreadable target
	 * (find() returns null) is denied.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testAccessDeniedWhenTargetUnreadable(): void {
		// 'b' is intentionally absent → simulates no OR read access.
		$store = ['a' => ['id' => 'a']];
		$service = $this->makeService($store);

		$result = $service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'vervolg');
		$this->assertFalse($result['ok']);
		$this->assertSame('access_denied', $result['reason']);
	}//end testAccessDeniedWhenTargetUnreadable()

	/**
	 * Removing a relation from either side clears it from BOTH.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testRemovalIsTwoSided(): void {
		$store = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];
		$service = $this->makeService($store);

		$service->addRelation(caseId: 'a', targetId: 'b', natureRelationship: 'vervolg');
		$this->assertCount(1, $this->relationsOf($store, 'a'));
		$this->assertSame(['b'], $store['a']['followUpCases']);

		// Remove from b's side: b holds nothing, and the link on a still goes.
		$result = $service->removeRelation(caseId: 'b', targetId: 'a', natureRelationship: 'vervolg');
		$this->assertTrue($result['ok']);
		$this->assertCount(0, $this->relationsOf($store, 'a'));
		$this->assertSame([], $store['a']['followUpCases']);
		$this->assertCount(0, $service->listRelations(caseId: 'b'));
	}//end testRemovalIsTwoSided()

	/**
	 * Deleting a case removes its entries from all counterpart cases.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testCleanupForDeletedCaseRemovesCounterparts(): void {
		$store = [
			'x' => ['id' => 'x'],
			'p' => ['id' => 'p'],
			'q' => ['id' => 'q'],
			'r' => ['id' => 'r'],
		];
		$service = $this->makeService($store);

		// Declared from the OTHER side, which is the case the old mirror used
		// to cover by accident: nothing on x names p, q or r, so only /used
		// can find them.
		$service->addRelation(caseId: 'p', targetId: 'x', natureRelationship: 'bijdrage');
		$service->addRelation(caseId: 'q', targetId: 'x', natureRelationship: 'bijdrage');
		$service->addRelation(caseId: 'r', targetId: 'x', natureRelationship: 'bijdrage');

		$updated = $service->cleanupForDeletedCase(caseId: 'x');
		$this->assertSame(3, $updated);
		foreach (['p', 'q', 'r'] as $counterpart) {
			$this->assertCount(0, $this->relationsOf($store, $counterpart));
			$this->assertSame([], $store[$counterpart]['contributingCases']);
		}
	}//end testCleanupForDeletedCaseRemovesCounterparts()

	/**
	 * normalise() promotes a direct write to relatedCases into the typed
	 * property, so a relation that arrived over ZGW is visible to OpenRegister
	 * and reads from both ends. The ZGW inbound path relies on this.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testNormalisePromotesDirectWritesIntoTypedLinks(): void {
		// a has a relation written straight onto the field by the ZGW mapping.
		$store = [
			'a' => ['id' => 'a', 'relatedCases' => json_encode([['caseId' => 'b', 'aardRelatie' => 'subject']])],
			'b' => ['id' => 'b'],
		];
		$service = $this->makeService($store);

		$service->normalise(caseId: 'a');
		$this->assertSame(['b'], $store['a']['subjectCases']);

		// And b now reads it, under the far half of the pair, without anything
		// having been written onto b.
		$this->assertSame([], ($store['b']['subjectCases'] ?? []));
		$far = $service->listRelations(caseId: 'b');
		$this->assertCount(1, $far);
		$this->assertSame('far-subject', $far[0]['displayLabel']);
	}//end testNormalisePromotesDirectWritesIntoTypedLinks()

	/**
	 * listRelations decodes the JSON-encoded field into entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testListRelationsDecodes(): void {
		$store = [
			'a' => ['id' => 'a', 'relatedCases' => json_encode([['caseId' => 'b', 'aardRelatie' => 'vervolg', 'notes' => 't']])],
		];
		$service = $this->makeService($store);

		$rel = $service->listRelations(caseId: 'a');
		$this->assertCount(1, $rel);
		$this->assertSame('b', $rel[0]['caseId']);
		$this->assertSame('vervolg', $rel[0]['aardRelatie']);
		$this->assertSame('t', $rel[0]['notes']);
	}//end testListRelationsDecodes()
}//end class
