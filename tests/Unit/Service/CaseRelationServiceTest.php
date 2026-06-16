<?php

/**
 * CaseRelationService Unit Tests
 *
 * Covers the typed peer-relation contract: guards (invalid type, self,
 * duplicate, hierarchy-overlap, access), symmetric two-sided add/remove,
 * delete-cleanup of counterparts, and normalisation after direct writes.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\CaseRelationService;
use OCA\Procest\Service\DeelzaakService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CaseRelationService.
 *
 * @covers \OCA\Procest\Service\CaseRelationService
 */
class CaseRelationServiceTest extends TestCase
{

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
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key, string $default=''): string {
                return match ($key) {
                    'register' => 'procest',
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
    private function makeObjectService(array &$store): object
    {
        return new class($store) {
            /**
             * @param array<string, array<string, mixed>> $store Store reference.
             */
            public function __construct(private array &$store)
            {
            }//end __construct()

            /**
             * Mimic OR find() — null when the id is unknown (RBAC fail-closed).
             *
             * @param string $id       Object UUID.
             * @param mixed  $register Register (ignored).
             * @param mixed  $schema   Schema (ignored).
             *
             * @return array<string, mixed>|null
             */
            public function find(string $id, $register=null, $schema=null): ?array
            {
                return ($this->store[$id] ?? null);
            }//end find()

            /**
             * Mimic OR saveObject() — write the object back keyed by id.
             *
             * @param array<string, mixed> $object   Object to persist.
             * @param mixed                $register Register (ignored).
             * @param mixed                $schema   Schema (ignored).
             *
             * @return array<string, mixed>
             */
            public function saveObject(array $object, $register=null, $schema=null): array
            {
                $id = (string) ($object['id'] ?? '');
                if ($id !== '') {
                    $this->store[$id] = $object;
                }

                return $object;
            }//end saveObject()
        };
    }//end makeObjectService()


    /**
     * Build the service under test wired to a store.
     *
     * @param array<string, array<string, mixed>> &$store Store reference.
     *
     * @return CaseRelationService
     */
    private function makeService(array &$store): CaseRelationService
    {
        $objectService = $this->makeObjectService($store);
        $this->settingsService->method('getObjectService')->willReturn($objectService);

        return new CaseRelationService(
            settingsService: $this->settingsService,
            deelzaakService: $this->createMock(DeelzaakService::class),
            logger: $this->logger,
        );
    }//end makeService()


    /**
     * Decode the relatedCases JSON string of a stored case.
     *
     * @param array<string, array<string, mixed>> $store Store.
     * @param string                              $id    Case id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function relationsOf(array $store, string $id): array
    {
        $raw = ($store[$id]['relatedCases'] ?? '');
        if (is_array($raw) === true) {
            return $raw;
        }

        return json_decode((string) $raw, true) ?? [];
    }//end relationsOf()


    /**
     * Adding a relation writes a symmetric entry into BOTH cases.
     *
     * @return void
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function testAddRelationIsTwoSided(): void
    {
        $store   = [
            'a' => ['id' => 'a', 'title' => 'Bezwaar'],
            'b' => ['id' => 'b', 'title' => 'Besluit'],
        ];
        $service = $this->makeService($store);

        $result = $service->addRelation(caseId: 'a', targetId: 'b', aardRelatie: 'onderwerp', toelichting: 'Bezwaar over besluit');
        $this->assertTrue($result['ok']);

        $aRel = $this->relationsOf($store, 'a');
        $bRel = $this->relationsOf($store, 'b');
        $this->assertCount(1, $aRel);
        $this->assertSame('b', $aRel[0]['caseId']);
        $this->assertSame('onderwerp', $aRel[0]['aardRelatie']);
        $this->assertSame('Bezwaar over besluit', $aRel[0]['toelichting']);
        $this->assertCount(1, $bRel);
        $this->assertSame('a', $bRel[0]['caseId']);
        $this->assertSame('onderwerp', $bRel[0]['aardRelatie']);
    }//end testAddRelationIsTwoSided()


    /**
     * Self-relations are rejected.
     *
     * @return void
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function testSelfRelationRejected(): void
    {
        $store   = ['a' => ['id' => 'a']];
        $service = $this->makeService($store);

        $result = $service->addRelation(caseId: 'a', targetId: 'a', aardRelatie: 'vervolg');
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
    public function testInvalidAardRelatieRejected(): void
    {
        $store   = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];
        $service = $this->makeService($store);

        $result = $service->addRelation(caseId: 'a', targetId: 'b', aardRelatie: 'bogus');
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
    public function testDuplicatePairRejectedDifferentTypeAccepted(): void
    {
        $store   = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];
        $service = $this->makeService($store);

        $this->assertTrue($service->addRelation(caseId: 'a', targetId: 'b', aardRelatie: 'vervolg')['ok']);

        $dup = $service->addRelation(caseId: 'a', targetId: 'b', aardRelatie: 'vervolg');
        $this->assertFalse($dup['ok']);
        $this->assertSame('duplicate', $dup['reason']);

        $other = $service->addRelation(caseId: 'a', targetId: 'b', aardRelatie: 'onderwerp');
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
    public function testHierarchyOverlapRejected(): void
    {
        // b is a deelzaak of a (parentCase = a).
        $store   = [
            'a' => ['id' => 'a'],
            'b' => ['id' => 'b', 'parentCase' => 'a'],
        ];
        $service = $this->makeService($store);

        $result = $service->addRelation(caseId: 'a', targetId: 'b', aardRelatie: 'bijdrage');
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
    public function testAccessDeniedWhenTargetUnreadable(): void
    {
        // 'b' is intentionally absent → simulates no OR read access.
        $store   = ['a' => ['id' => 'a']];
        $service = $this->makeService($store);

        $result = $service->addRelation(caseId: 'a', targetId: 'b', aardRelatie: 'vervolg');
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
    public function testRemovalIsTwoSided(): void
    {
        $store   = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];
        $service = $this->makeService($store);

        $service->addRelation(caseId: 'a', targetId: 'b', aardRelatie: 'vervolg');
        $this->assertCount(1, $this->relationsOf($store, 'a'));
        $this->assertCount(1, $this->relationsOf($store, 'b'));

        // Remove from b's side.
        $result = $service->removeRelation(caseId: 'b', targetId: 'a', aardRelatie: 'vervolg');
        $this->assertTrue($result['ok']);
        $this->assertCount(0, $this->relationsOf($store, 'a'));
        $this->assertCount(0, $this->relationsOf($store, 'b'));
    }//end testRemovalIsTwoSided()


    /**
     * Deleting a case removes its entries from all counterpart cases.
     *
     * @return void
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function testCleanupForDeletedCaseRemovesCounterparts(): void
    {
        $store   = [
            'x' => ['id' => 'x'],
            'p' => ['id' => 'p'],
            'q' => ['id' => 'q'],
            'r' => ['id' => 'r'],
        ];
        $service = $this->makeService($store);

        $service->addRelation(caseId: 'x', targetId: 'p', aardRelatie: 'bijdrage');
        $service->addRelation(caseId: 'x', targetId: 'q', aardRelatie: 'bijdrage');
        $service->addRelation(caseId: 'x', targetId: 'r', aardRelatie: 'bijdrage');

        $updated = $service->cleanupForDeletedCase(caseId: 'x');
        $this->assertSame(3, $updated);
        $this->assertCount(0, $this->relationsOf($store, 'p'));
        $this->assertCount(0, $this->relationsOf($store, 'q'));
        $this->assertCount(0, $this->relationsOf($store, 'r'));
    }//end testCleanupForDeletedCaseRemovesCounterparts()


    /**
     * normalise() restores the inverse entry after a direct (asymmetric) write
     * to relatedCases — the ZGW inbound path relies on this.
     *
     * @return void
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function testNormaliseRestoresSymmetry(): void
    {
        // a has a direct (one-sided) relation to b; b has none yet.
        $store   = [
            'a' => ['id' => 'a', 'relatedCases' => json_encode([['caseId' => 'b', 'aardRelatie' => 'onderwerp']])],
            'b' => ['id' => 'b'],
        ];
        $service = $this->makeService($store);

        $service->normalise(caseId: 'a');
        $bRel = $this->relationsOf($store, 'b');
        $this->assertCount(1, $bRel);
        $this->assertSame('a', $bRel[0]['caseId']);
        $this->assertSame('onderwerp', $bRel[0]['aardRelatie']);
    }//end testNormaliseRestoresSymmetry()


    /**
     * listRelations decodes the JSON-encoded field into entries.
     *
     * @return void
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function testListRelationsDecodes(): void
    {
        $store   = [
            'a' => ['id' => 'a', 'relatedCases' => json_encode([['caseId' => 'b', 'aardRelatie' => 'vervolg', 'toelichting' => 't']])],
        ];
        $service = $this->makeService($store);

        $rel = $service->listRelations(caseId: 'a');
        $this->assertCount(1, $rel);
        $this->assertSame('b', $rel[0]['caseId']);
        $this->assertSame('vervolg', $rel[0]['aardRelatie']);
        $this->assertSame('t', $rel[0]['toelichting']);
    }//end testListRelationsDecodes()
}//end class
