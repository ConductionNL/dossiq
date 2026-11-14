<?php

/**
 * Unit tests for MandaatVerifier: a mandate group covers a decision when its
 * level, case types, decision types and amount ceiling all fit; the scheme is
 * found by case type; and an approver's level is the first covering group
 * their id starts with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\MandaatVerifier;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\MandaatVerifier
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class MandaatVerifierTest extends TestCase {

	private SettingsService&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function verifier(): MandaatVerifier {
		return new MandaatVerifier($this->settings, $this->logger);
	}//end verifier()

	/**
	 * @return array<string, mixed>
	 */
	private function regeling(): array {
		return [
			'mandateGroups' => [
				['level' => 'medewerker', 'caseTypes' => ['vergunning'], 'decisionTypes' => ['toekenning'], 'to_amount' => 1000],
				['level' => 'teamleider', 'caseTypes' => [], 'decisionTypes' => [], 'to_amount' => '25000'],
				['level' => 'directeur'],
			],
		];
	}//end regeling()

	public function testAGroupCoversADecisionWithinItsScopeAndCeiling(): void {
		$verifier = $this->verifier();

		$this->assertTrue($verifier->verifyMandaat($this->regeling(), 'medewerker', 1000.0, 'toekenning', 'vergunning'));
		$this->assertFalse($verifier->verifyMandaat($this->regeling(), 'medewerker', 1000.01, 'toekenning', 'vergunning'));
		$this->assertFalse($verifier->verifyMandaat($this->regeling(), 'medewerker', 10.0, 'afwijzing', 'vergunning'));
		$this->assertFalse($verifier->verifyMandaat($this->regeling(), 'medewerker', 10.0, 'toekenning', 'subsidie'));
	}//end testAGroupCoversADecisionWithinItsScopeAndCeiling()

	public function testEmptyScopesCoverEverythingAndNoCeilingMeansNoLimit(): void {
		$verifier = $this->verifier();

		$this->assertTrue($verifier->verifyMandaat($this->regeling(), 'teamleider', 25000.0, 'afwijzing', 'subsidie'));
		$this->assertFalse($verifier->verifyMandaat($this->regeling(), 'teamleider', 25000.5, 'afwijzing', 'subsidie'));
		$this->assertTrue($verifier->verifyMandaat($this->regeling(), 'directeur', 9999999.0, 'afwijzing', 'subsidie'));
		$this->assertFalse($verifier->verifyMandaat($this->regeling(), 'wethouder', 1.0, 'afwijzing', 'subsidie'));
		$this->assertFalse($verifier->verifyMandaat([], 'directeur', 1.0, 'afwijzing', 'subsidie'));
	}//end testEmptyScopesCoverEverythingAndNoCeilingMeansNoLimit()

	public function testTheApproversLevelIsTheFirstCoveringGroupTheirIdStartsWith(): void {
		$verifier = $this->verifier();
		$decision = ['feeAmount' => '5000', 'decisionType' => 'toekenning', 'caseType' => 'vergunning'];

		$this->assertSame('teamleider', $verifier->resolveNiveauForUser($this->regeling(), $decision, 'teamleider-zuid'));
		$this->assertNull($verifier->resolveNiveauForUser($this->regeling(), $decision, 'medewerker-1'));
		$this->assertSame('medewerker', $verifier->resolveNiveauForUser($this->regeling(), ['feeAmount' => 500] + $decision, 'medewerker-1'));
		$this->assertNull($verifier->resolveNiveauForUser(['mandateGroups' => [['level' => '']]], $decision, 'anyone'));
	}//end testTheApproversLevelIsTheFirstCoveringGroupTheirIdStartsWith()

	/**
	 * Wire a store answering the given schemes.
	 *
	 * @param list<mixed>|null $rows The schemes, or null to throw.
	 */
	private function store(?array $rows): void {
		$this->settings->method('getObjectService')->willReturn(
			new class($rows) {
				public function __construct(private ?array $rows) {
				}

				public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
					if ($this->rows === null) {
						throw new RuntimeException('index down');
					}

					return $this->rows;
				}
			}
		);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'dossiq', 'mandaat_regeling_schema' => 'mandaat'][$key] ?? '');
	}//end store()

	public function testTheSchemeIsTheFirstNamingTheCaseType(): void {
		$subsidie = ['title' => 'Subsidie', 'mandateGroups' => [['caseTypes' => ['subsidie']]]];
		$vergunning = ['title' => 'Vergunning', 'mandateGroups' => [['caseTypes' => ['bezwaar']], ['caseTypes' => ['vergunning']]]];
		$this->store([$subsidie, 'junk', $vergunning]);

		$this->assertSame($vergunning, $this->verifier()->resolveMandaatRegeling('vergunning'));
		$this->assertSame([], $this->verifier()->resolveMandaatRegeling('melding'));
		$this->assertSame($subsidie, $this->verifier()->resolveMandaatRegeling(''));
	}//end testTheSchemeIsTheFirstNamingTheCaseType()

	public function testAnUnconfiguredStoreHasNoScheme(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->assertSame([], $this->verifier()->resolveMandaatRegeling('vergunning'));
	}//end testAnUnconfiguredStoreHasNoScheme()

	public function testAnUnreadableSchemeIsRefusedAsIndeterminateNotAsNoMandate(): void {
		$this->store(null);
		$this->logger->expects($this->once())->method('error')->with('BeschikkingService: resolveMandaatRegeling failed', ['exception' => 'index down']);

		try {
			$this->verifier()->resolveMandaatRegeling('vergunning');
			$this->fail('Expected a RefusedException.');
		} catch (RefusedException $e) {
			$this->assertSame('mandaat-regeling-unreadable', $e->getRule());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			$this->assertSame('The mandate scheme could not be read, so this approval cannot be checked right now.', $e->getSentence());
		}
	}//end testAnUnreadableSchemeIsRefusedAsIndeterminateNotAsNoMandate()
}//end class
