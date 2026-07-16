<?php

/**
 * Unit tests for ConflictOfInterestService.
 *
 * The check previously gated on `$caseProperties['userBsn']`, which no caller
 * ever populated — so it returned "no conflict" unconditionally on every live
 * call. Worse, `$caseProperties` originates from the request body, so the
 * identity was client-controlled.
 *
 * These tests pin the fixed contract: identity is resolved SERVER-SIDE, a
 * client-supplied `userBsn` is ignored, and an unresolvable identity BLOCKS
 * rather than passing.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/authz-bypass-fixes/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ConflictOfInterestService;
use OCA\Procest\Service\External\Brp\BrpHaalCentraalAdapterInterface;
use OCA\Procest\Service\External\Brp\BrpLookupResult;
use OCA\Procest\Service\MedewerkerIdentityResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\ConflictOfInterestService
 */
class ConflictOfInterestServiceTest extends TestCase
{

    /**
     * Build a bound identity resolver that resolves every user to $bsn.
     *
     * @param string|null $bsn The BSN to resolve to, or null for unresolvable.
     *
     * @return MedewerkerIdentityResolverInterface
     */
    private function resolverFor(?string $bsn): MedewerkerIdentityResolverInterface
    {
        $resolver = $this->createMock(MedewerkerIdentityResolverInterface::class);
        $resolver->method('bsnFor')->willReturn($bsn);

        return $resolver;
    }//end resolverFor()

    /**
     * A case with no applicant identity has nobody to conflict with.
     *
     * @return void
     */
    public function testNoConflictWithoutApplicantIdentity(): void
    {
        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            null,
            $this->resolverFor('123'),
        );

        $r = $svc->checkConflict('alice', 'Z/2026/1');

        self::assertFalse($r['conflict']);
    }//end testNoConflictWithoutApplicantIdentity()

    /**
     * A genuine conflict is DETECTED — the worker is the applicant.
     *
     * @return void
     */
    public function testSelfDetected(): void
    {
        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            null,
            $this->resolverFor('123'),
        );

        $r = $svc->checkConflict('alice', 'Z/2026/2', ['applicantBsn' => '123']);

        self::assertTrue($r['conflict']);
        self::assertSame('self', $r['reason']);
    }//end testSelfDetected()

    /**
     * THE FAIL-OPEN. An applicant is known but the case worker's identity
     * cannot be resolved (no resolver bound — the shipped default). The check
     * cannot be performed, so it MUST block rather than report "no conflict".
     *
     * @return void
     */
    public function testIndeterminateIdentityBlocksRatherThanPasses(): void
    {
        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));

        $r = $svc->checkConflict('alice', 'Z/2026/9', ['applicantBsn' => '123']);

        self::assertTrue($r['conflict'], 'An unresolvable conflict check must not report "no conflict"');
        self::assertSame(ConflictOfInterestService::REASON_IDENTITY_INDETERMINATE, $r['reason']);
    }//end testIndeterminateIdentityBlocksRatherThanPasses()

    /**
     * A resolver that cannot establish the identity also blocks.
     *
     * @return void
     */
    public function testUnresolvableIdentityBlocks(): void
    {
        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            null,
            $this->resolverFor(null),
        );

        $r = $svc->checkConflict('alice', 'Z/2026/10', ['applicantBsn' => '123']);

        self::assertTrue($r['conflict']);
        self::assertSame(ConflictOfInterestService::REASON_IDENTITY_INDETERMINATE, $r['reason']);
    }//end testUnresolvableIdentityBlocks()

    /**
     * A throwing resolver is indeterminate, not "no conflict".
     *
     * @return void
     */
    public function testThrowingResolverBlocks(): void
    {
        $resolver = $this->createMock(MedewerkerIdentityResolverInterface::class);
        $resolver->method('bsnFor')->willThrowException(new \RuntimeException('HR system down'));

        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            null,
            $resolver,
        );

        $r = $svc->checkConflict('alice', 'Z/2026/11', ['applicantBsn' => '123']);

        self::assertTrue($r['conflict']);
        self::assertSame(ConflictOfInterestService::REASON_IDENTITY_INDETERMINATE, $r['reason']);
    }//end testThrowingResolverBlocks()

    /**
     * Client-supplied identity in caseProperties MUST NOT influence the result.
     *
     * The old code took `userBsn` straight from the (client-controlled) request
     * body. Here the client claims to be someone else entirely; the resolver is
     * authoritative and still detects the self-conflict.
     *
     * @return void
     */
    public function testClientSuppliedUserBsnIsIgnored(): void
    {
        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            null,
            $this->resolverFor('123'),
        );

        $r = $svc->checkConflict(
            'alice',
            'Z/2026/12',
            [
                // A caller trying to dodge the check by claiming a different BSN.
                'userBsn'      => '999999999',
                'applicantBsn' => '123',
            ]
        );

        self::assertTrue($r['conflict'], 'Client-supplied userBsn must not be able to suppress a real conflict');
        self::assertSame('self', $r['reason']);
    }//end testClientSuppliedUserBsnIsIgnored()

    /**
     * No raw BSN may appear in the returned payload.
     *
     * @return void
     */
    public function testNoRawBsnIsReturned(): void
    {
        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            null,
            $this->resolverFor('123'),
        );

        $r = $svc->checkConflict('alice', 'Z/2026/13', ['applicantBsn' => '123']);

        self::assertStringNotContainsString('123', json_encode($r));
    }//end testNoRawBsnIsReturned()

    /**
     * A relationship lookup detects a conflict.
     *
     * @return void
     */
    public function testRelationshipDetected(): void
    {
        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            null,
            $this->resolverFor('111'),
        );
        $svc->setRelationshipLookup(
            static fn (string $u, string $a): ?string => ($u === '111' && $a === '222') ? 'spouse' : null
        );

        $r = $svc->checkConflict('alice', 'Z/2026/3', ['applicantBsn' => '222']);

        self::assertTrue($r['conflict']);
        self::assertSame('spouse', $r['reason']);
    }//end testRelationshipDetected()

    /**
     * A failing relationship lookup is indeterminate, not "no conflict".
     *
     * @return void
     */
    public function testFailingRelationshipLookupBlocks(): void
    {
        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            null,
            $this->resolverFor('111'),
        );
        $svc->setRelationshipLookup(
            static function (string $u, string $a): ?string {
                throw new \RuntimeException('BRP unreachable');
            }
        );

        $r = $svc->checkConflict('alice', 'Z/2026/14', ['applicantBsn' => '222']);

        self::assertTrue($r['conflict']);
        self::assertSame(ConflictOfInterestService::REASON_IDENTITY_INDETERMINATE, $r['reason']);
    }//end testFailingRelationshipLookupBlocks()

    /**
     * A manually-registered conflict trumps automatic detection — and does not
     * need identity resolution at all.
     *
     * @return void
     */
    public function testManualRegistrationOverridesAuto(): void
    {
        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));
        $svc->registerConflict('Z/2026/4', 'persoonlijk');

        $r = $svc->checkConflict('alice', 'Z/2026/4', ['applicantBsn' => '222']);

        self::assertTrue($r['conflict']);
        self::assertSame('persoonlijk', $r['reason']);
    }//end testManualRegistrationOverridesAuto()

    /**
     * Clearing a manual conflict unblocks (no applicant => no conflict).
     *
     * @return void
     */
    public function testClearConflictUnblocks(): void
    {
        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));
        $svc->registerConflict('Z/2026/5', 'persoonlijk');
        $svc->clearConflict('Z/2026/5');

        $r = $svc->checkConflict('alice', 'Z/2026/5');

        self::assertFalse($r['conflict']);
    }//end testClearConflictUnblocks()

    /**
     * A dormant BRP adapter yields no relation — with identity resolved, that is
     * a genuine "no conflict" rather than an unanswered question.
     *
     * @return void
     */
    public function testDormantBrpAdapterLeavesConflictOpen(): void
    {
        $brp = $this->createMock(BrpHaalCentraalAdapterInterface::class);
        $brp->method('lookup')->willReturn(
            new BrpLookupResult(
                lookupStatus: 'LOOKUP_DEFERRED',
                persoon: [],
                dormant: true,
            )
        );

        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            $brp,
            $this->resolverFor('111'),
        );

        $r = $svc->checkConflict('alice', 'Z/2026/6', ['applicantBsn' => '222']);

        self::assertFalse($r['conflict']);
    }//end testDormantBrpAdapterLeavesConflictOpen()

    /**
     * An active BRP adapter detects a family relation.
     *
     * This is why the resolver returns a BSN rather than a hash: Haal Centraal
     * can only be queried by BSN. A hash-only seam would strand this capability.
     *
     * @return void
     */
    public function testActiveBrpAdapterDetectsRelation(): void
    {
        $brp = $this->createMock(BrpHaalCentraalAdapterInterface::class);
        $brp->method('lookup')->willReturn(
            new BrpLookupResult(
                lookupStatus: 'FOUND',
                persoon: [
                    'relaties' => [
                        ['burgerservicenummer' => '222', 'relatie' => 'partner'],
                    ],
                ],
                dormant: false,
            )
        );

        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            $brp,
            $this->resolverFor('111'),
        );

        $r = $svc->checkConflict('alice', 'Z/2026/7', ['applicantBsn' => '222']);

        self::assertTrue($r['conflict']);
        self::assertSame('partner', $r['reason']);
    }//end testActiveBrpAdapterDetectsRelation()

    /**
     * A BRP relation to a different person is not a conflict.
     *
     * @return void
     */
    public function testBrpAdapterRelationOnlyFiresWhenBsnMatches(): void
    {
        $brp = $this->createMock(BrpHaalCentraalAdapterInterface::class);
        $brp->method('lookup')->willReturn(
            new BrpLookupResult(
                lookupStatus: 'FOUND',
                persoon: [
                    'relaties' => [
                        ['burgerservicenummer' => '999', 'relatie' => 'parent'],
                    ],
                ],
                dormant: false,
            )
        );

        $svc = new ConflictOfInterestService(
            $this->createMock(LoggerInterface::class),
            $brp,
            $this->resolverFor('111'),
        );

        $r = $svc->checkConflict('alice', 'Z/2026/8', ['applicantBsn' => '222']);

        self::assertFalse($r['conflict']);
    }//end testBrpAdapterRelationOnlyFiresWhenBsnMatches()
}//end class
