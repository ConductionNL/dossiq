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
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ConflictOfInterestService;
use OCA\Dossiq\Service\MedewerkerIdentityResolverInterface;
use OCA\Dossiq\Service\Registry\PersonRelationReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\ConflictOfInterestService
 */
class ConflictOfInterestServiceTest extends TestCase {

	/**
	 * Build a bound identity resolver that resolves every user to $bsn.
	 *
	 * @param string|null $bsn The BSN to resolve to, or null for unresolvable.
	 *
	 * @return MedewerkerIdentityResolverInterface
	 */
	private function resolverFor(?string $bsn): MedewerkerIdentityResolverInterface {
		$resolver = $this->createMock(MedewerkerIdentityResolverInterface::class);
		$resolver->method('bsnFor')->willReturn($bsn);

		return $resolver;
	}//end resolverFor()

	/**
	 * A case with no applicant identity has nobody to conflict with.
	 *
	 * @return void
	 */
	public function testNoConflictWithoutApplicantIdentity(): void {
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
	public function testSelfDetected(): void {
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
	public function testIndeterminateIdentityBlocksRatherThanPasses(): void {
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
	public function testUnresolvableIdentityBlocks(): void {
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
	public function testThrowingResolverBlocks(): void {
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
	public function testClientSuppliedUserBsnIsIgnored(): void {
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
				'userBsn' => '999999999',
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
	public function testNoRawBsnIsReturned(): void {
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
	public function testRelationshipDetected(): void {
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
	public function testFailingRelationshipLookupBlocks(): void {
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
	public function testManualRegistrationOverridesAuto(): void {
		$svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));
		$svc->registerConflict('Z/2026/4', 'persoonlijk');

		$r = $svc->checkConflict('alice', 'Z/2026/4', ['applicantBsn' => '222']);

		self::assertTrue($r['conflict']);
		self::assertSame('persoonlijk', $r['reason']);
	}//end testManualRegistrationOverridesAuto()

	// testClearConflictUnblocks was removed with
	// ConflictOfInterestService::clearConflict(), which had no caller. The
	// manual-override map it cleared is request-scoped and filled only by
	// registerConflict(), which has no caller either; the override behaviour
	// that is still reachable is asserted by testManualRegistrationOverridesAuto
	// above.

	/**
	 * A relation read that answers the given result.
	 *
	 * @param array<string, mixed> $answer What the registry read answers.
	 *
	 * @return PersonRelationReader
	 */
	private function relations(array $answer): PersonRelationReader {
		$reader = $this->createMock(PersonRelationReader::class);
		$reader->method('read')->willReturn($answer);

		return $reader;
	}//end relations()

	/**
	 * The case worker's partner is the applicant: a conflict, named.
	 *
	 * This is why the resolver returns a BSN rather than a hash: the registry
	 * can only be asked by BSN. A hash-only seam would strand this capability.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-client-retired/specs/mandaat-matrix/spec.md#requirement-conflict-of-interest-detection
	 */
	public function testARelationToTheApplicantIsAConflict(): void {
		$reader = $this->createMock(PersonRelationReader::class);
		$reader->expects($this->once())->method('read')->with('111')->willReturn(
			[
				'available' => true,
				'reason' => '',
				'relations' => [
					['relation' => 'parent', 'burgerservicenummer' => '999'],
					['relation' => 'partner', 'burgerservicenummer' => '222'],
				],
			]
		);

		$svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class), $reader, $this->resolverFor('111'));
		$r = $svc->checkConflict('alice', 'Z/2026/7', ['applicantBsn' => '222']);

		self::assertTrue($r['conflict']);
		self::assertSame('partner', $r['reason']);
	}//end testARelationToTheApplicantIsAConflict()

	/**
	 * A relation to somebody else is no conflict, and the answer says the
	 * relation step ran.
	 *
	 * @return void
	 */
	public function testARelationToSomebodyElseIsNoConflict(): void {
		$reader = $this->relations(['available' => true, 'reason' => '', 'relations' => [['relation' => 'parent', 'burgerservicenummer' => '999']]]);

		$svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class), $reader, $this->resolverFor('111'));
		$r = $svc->checkConflict('alice', 'Z/2026/8', ['applicantBsn' => '222']);

		self::assertFalse($r['conflict']);
		self::assertSame(ConflictOfInterestService::RELATION_CHECK_DONE, $r['relationCheck']);
		self::assertArrayNotHasKey('relationCheckReason', $r);
	}//end testARelationToSomebodyElseIsNoConflict()

	/**
	 * Where the relation read cannot run (integriq absent, or the BRP source
	 * not switched on for relations) the step says "not available". It does
	 * not pass in silence: before this, a dormant client answered nothing and
	 * the result read exactly like a check that had found no relation.
	 *
	 * @param string|null $answered The reason the read gives, or null when no reader is bound.
	 * @param string $reason The reason expected in the answer.
	 *
	 * @return void
	 *
	 * @dataProvider notAvailable
	 */
	public function testARelationStepThatCannotRunSaysNotAvailable(?string $answered, string $reason): void {
		$reader = null;
		if ($answered !== null) {
			$reader = $this->relations(['available' => false, 'reason' => $answered, 'relations' => []]);
		}

		$svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class), $reader, $this->resolverFor('111'));
		$r = $svc->checkConflict('alice', 'Z/2026/6', ['applicantBsn' => '222']);

		self::assertFalse($r['conflict']);
		self::assertSame(ConflictOfInterestService::RELATION_CHECK_NOT_AVAILABLE, $r['relationCheck']);
		self::assertSame($reason, $r['relationCheckReason']);
	}//end testARelationStepThatCannotRunSaysNotAvailable()

	/**
	 * The ways the relation read is not there to be asked.
	 *
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function notAvailable(): array {
		return [
			'no reader bound' => [null, PersonRelationReader::REASON_NO_INTEGRIQ],
			'integriq not installed' => [PersonRelationReader::REASON_NO_INTEGRIQ, PersonRelationReader::REASON_NO_INTEGRIQ],
			'integriq without the read' => [PersonRelationReader::REASON_NO_READER, PersonRelationReader::REASON_NO_READER],
			'source not switched on' => ['relations-not-enabled', 'relations-not-enabled'],
			'no source' => ['no-source', 'no-source'],
		];
	}//end notAvailable()

	/**
	 * A relation read that is switched on and gets no answer is
	 * indeterminate: the question was asked and not answered. That blocks.
	 *
	 * @param string $reason The reason the read gave.
	 *
	 * @return void
	 *
	 * @dataProvider unanswered
	 */
	public function testARelationReadThatGetsNoAnswerBlocks(string $reason): void {
		$reader = $this->relations(['available' => false, 'reason' => $reason, 'relations' => []]);

		$svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class), $reader, $this->resolverFor('111'));
		$r = $svc->checkConflict('alice', 'Z/2026/9', ['applicantBsn' => '222']);

		self::assertTrue($r['conflict']);
		self::assertSame(ConflictOfInterestService::REASON_RELATION_INDETERMINATE, $r['reason']);
	}//end testARelationReadThatGetsNoAnswerBlocks()

	/**
	 * Reasons that mean "asked, not answered", including one this version does not know.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unanswered(): array {
		return [
			'unreachable' => [PersonRelationReader::REASON_UNREACHABLE],
			'invalid-identifier' => ['invalid-identifier'],
			'a reason from a later integriq' => ['something-new'],
			'no reason at all' => [''],
		];
	}//end unanswered()

	/**
	 * A relation read that throws blocks as well.
	 *
	 * @return void
	 */
	public function testARelationReadThatThrowsBlocks(): void {
		$reader = $this->createMock(PersonRelationReader::class);
		$reader->method('read')->willThrowException(new \RuntimeException('down'));

		$svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class), $reader, $this->resolverFor('111'));
		$r = $svc->checkConflict('alice', 'Z/2026/10', ['applicantBsn' => '222']);

		self::assertTrue($r['conflict']);
		self::assertSame(ConflictOfInterestService::REASON_RELATION_INDETERMINATE, $r['reason']);
	}//end testARelationReadThatThrowsBlocks()
}//end class
