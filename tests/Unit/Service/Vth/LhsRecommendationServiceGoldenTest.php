<?php

/**
 * Characterisation tests for LhsRecommendationService::recommend and
 * ::override: the row each persists, the lookup order (decision table, then
 * the matrix), and every refusal, allowed and refused alike.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/enforcement-lhs/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Vth;

use OCA\Dossiq\Service\Vth\LhsDecisionTableLookup;
use OCA\Dossiq\Service\Vth\LhsRecommendationService;
use OCA\Dossiq\Service\Vth\LhsRecommendationStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LhsRecommendationServiceGoldenTest extends TestCase {

	/**
	 * Every store and lookup call, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	private function service(?string $tableAnswer, array $stored = [], bool $signedIn = true): LhsRecommendationService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('insp-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$store = $this->createMock(LhsRecommendationStore::class);
		$store->method('loadMatrix')->willReturnCallback(function (?int $version): array {
			$this->calls[] = ['loadMatrix', $version];
			return ['version' => '4', 'cells' => [['severity' => 'gering', 'behaviour' => 'goedwillend', 'actorType' => 'burger', 'intervention' => 'warning']]];
		});
		$store->method('persistRecommendation')->willReturnCallback(function (array $row, ?string $id = null): array {
			$this->calls[] = ['persist', $row, $id];
			return $row + ['id' => 'saved'];
		});
		$store->method('loadRecommendation')->willReturnCallback(function (string $id) use ($stored): array {
			$this->calls[] = ['loadRecommendation', $id];
			return $stored;
		});

		$lookup = $this->createMock(LhsDecisionTableLookup::class);
		$lookup->method('intervention')->willReturnCallback(function (string $matrixVersion, string $severity, string $behaviour, string $actorType) use ($tableAnswer): ?string {
			$this->calls[] = ['table', $matrixVersion, $severity, $behaviour, $actorType];
			return $tableAnswer;
		});

		return new LhsRecommendationService($session, $store, $lookup);
	}//end service()

	private function refusal(\Closure $call): string {
		try {
			$call();
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		self::fail('Nothing was refused.');

		return '';
	}//end refusal()

	public function testARecommendationFromTheTableIsPersistedWithItsInspection(): void {
		$row = $this->service('bestuursdwang')->recommend('case-1', 'ernstig', 'calculerend', 'bedrijf', 7, 'insp-r');

		self::assertSame(
			[
				['loadMatrix', 7],
				['table', '4', 'ernstig', 'calculerend', 'bedrijf'],
				['persist', [
					'case' => 'case-1',
					'inspection' => 'insp-r',
					'severity' => 'ernstig',
					'behaviour' => 'calculerend',
					'actorType' => 'bedrijf',
					'matrixVersion' => 4,
					'recommendedIntervention' => 'bestuursdwang',
					'finalIntervention' => 'bestuursdwang',
					'override' => false,
					'recommendedBy' => 'insp-1',
				], null],
			],
			$this->calls
		);
		self::assertSame('saved', $row['id']);
	}//end testARecommendationFromTheTableIsPersistedWithItsInspection()

	public function testTheMatrixAnswersWhenTheTableDoesNotAndNoInspectionIsLeftOut(): void {
		$row = $this->service(null)->recommend('case-1', 'gering', 'goedwillend', 'burger');

		self::assertSame('warning', $row['recommendedIntervention']);
		self::assertArrayNotHasKey('inspection', $row);
		self::assertSame(['case', 'severity', 'behaviour', 'actorType', 'matrixVersion', 'recommendedIntervention', 'finalIntervention', 'override', 'recommendedBy', 'id'], array_keys($row));
		self::assertSame(['loadMatrix', null], $this->calls[0]);
	}//end testTheMatrixAnswersWhenTheTableDoesNotAndNoInspectionIsLeftOut()

	public function testRecommendRefusesWithoutASessionOrACell(): void {
		self::assertSame('Authenticatie vereist voor LHS-aanbeveling', $this->refusal(fn () => $this->service('x', signedIn: false)->recommend('c', 'a', 'b', 'c')));
		self::assertSame([], $this->calls);
		self::assertSame('Geen LHS-cel gevonden voor combinatie a:b:c', $this->refusal(fn () => $this->service(null)->recommend('c', 'a', 'b', 'c')));
	}//end testRecommendRefusesWithoutASessionOrACell()

	public function testAnOverrideDownIsWrittenOverTheStoredRow(): void {
		$stored = ['id' => 'rec-1', 'severity' => 'gering', 'recommendedIntervention' => 'bestuursdwang', 'finalIntervention' => 'bestuursdwang', 'override' => false];
		$row = $this->service(null, $stored)->override(' rec-1 ', 'warning', 'Gemotiveerde afwijking van de ladder.', 'inspector');

		self::assertSame(['loadRecommendation', 'rec-1'], $this->calls[0]);
		self::assertSame(
			['persist', [
				'id' => 'rec-1',
				'severity' => 'gering',
				'recommendedIntervention' => 'bestuursdwang',
				'finalIntervention' => 'warning',
				'override' => true,
				'overrideJustification' => 'Gemotiveerde afwijking van de ladder.',
				'overrideBy' => 'insp-1',
				'overrideAuthority' => 'inspector',
			], 'rec-1'],
			$this->calls[1]
		);
		self::assertSame('warning', $row['finalIntervention']);
	}//end testAnOverrideDownIsWrittenOverTheStoredRow()

	public function testAnEscalationNeedsAManager(): void {
		$stored = ['recommendedIntervention' => 'warning'];
		self::assertSame('Verzwaring vereist managerrol', $this->refusal(fn () => $this->service(null, $stored)->override('r', 'bestuursdwang', str_repeat('x', 20), 'inspector')));

		$this->calls = [];
		$row = $this->service(null, $stored)->override('r', 'bestuursdwang', str_repeat('x', 20), 'manager');
		self::assertSame('manager', $row['overrideAuthority']);

		$equal = $this->service(null, $stored)->override('r', 'warning', str_repeat('x', 20), 'anything');
		self::assertSame('inspector', $equal['overrideAuthority']);
	}//end testAnEscalationNeedsAManager()

	public function testOverrideRefusesInTheOrderTheChecksRun(): void {
		self::assertSame('Authenticatie vereist voor override', $this->refusal(fn () => $this->service(null, signedIn: false)->override('', 'x', '', 'manager')));
		self::assertSame('Motivatie afwijking moet minimaal 20 tekens bevatten', $this->refusal(fn () => $this->service(null)->override('', 'x', str_repeat('x ', 19), 'manager')));
		self::assertSame('Recommendation ID ontbreekt voor override', $this->refusal(fn () => $this->service(null)->override('  ', 'x', str_repeat('x', 20), 'manager')));
		self::assertSame([], $this->calls);
		self::assertSame('Ongeldige interventie: verbannen', $this->refusal(fn () => $this->service(null, ['recommendedIntervention' => 'warning'])->override('r', 'verbannen', str_repeat('x', 20), 'manager')));
	}//end testOverrideRefusesInTheOrderTheChecksRun()
}//end class
