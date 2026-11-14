<?php

/**
 * Which decision a remedy is lodged against, when it was made known, and what the answer carries.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Remedy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Remedy;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Remedy\RemedyLodging;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The newest decision, the first date it was made known on, a disabled remedy, and the answer's fallbacks.
 *
 * @covers \OCA\Dossiq\Service\Remedy\RemedyLodging
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class RemedyLodgingDecisionAndAnswerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * @var BezwaarCreationHook&MockObject
	 */
	private BezwaarCreationHook $hook;

	/**
	 * Build the service around the given object service and remedy declaration.
	 *
	 * @param object                    $objects The object service double.
	 * @param array<string, mixed>      $remedy  Overrides on the remedy declaration.
	 * @param CaseDateNormaliser|null   $dates   The normaliser, or a live one.
	 *
	 * @return RemedyLodging
	 */
	private function lodging(object $objects, array $remedy = [], ?CaseDateNormaliser $dates = null): RemedyLodging {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'case_schema' => 'case', 'decision_schema' => 'decision'][$key] ?? $default
		);

		$caseRemedy = $this->createMock(CaseRemedy::class);
		$caseRemedy->method('declarationOf')->willReturn(
			$remedy + ['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college', 'enabled' => true, 'caseType' => 'bezwaar-type']
		);

		$this->hook = $this->createMock(BezwaarCreationHook::class);

		return new RemedyLodging(
			settingsService: $settings,
			caseRemedy: $caseRemedy,
			objections: $this->hook,
			logger: $this->createMock(LoggerInterface::class),
			dates: ($dates ?? $this->caseDates()),
		);
	}//end lodging()

	/**
	 * The remedy is lodged against the newest decision, whatever order the search answers in.
	 *
	 * @return void
	 */
	public function testTheNewestDecisionIsTheContestedOne(): void {
		$objects            = new RemedyObjectServiceDouble();
		$objects->decisions = [
			['id' => 'decision-middle', 'decisionDate' => '2026-10-01'],
			['id' => 'decision-newest', 'decisionDate' => '2026-11-01'],
			['id' => 'decision-undated'],
			['id' => 'decision-oldest', 'decisionDate' => '2026-01-15'],
		];
		$lodging = $this->lodging(objects: $objects);
		$this->hook->expects($this->once())->method('onBezwaarCreated')
			->with('bezwaar-1', 'decision-newest', $this->anything())
			->willReturn(['id' => 'objection-1']);

		$lodging->lodge('woo-1', ['receivedDate' => '2026-11-10', 'receivedChannel' => 'form', 'grounds' => 'x']);
	}//end testTheNewestDecisionIsTheContestedOne()

	/**
	 * A remedy the case type switched off is refused with its own rule, and opens nothing.
	 *
	 * @return void
	 */
	public function testADisabledRemedyIsRefusedEvenWithACaseType(): void {
		$objects            = new RemedyObjectServiceDouble();
		$objects->decisions = [['id' => 'decision-1', 'decisionDate' => '2026-10-30']];
		$lodging            = $this->lodging(objects: $objects, remedy: ['enabled' => false]);
		$this->hook->expects($this->never())->method('onBezwaarCreated');

		try {
			$lodging->lodge('woo-1', ['receivedChannel' => 'brief', 'grounds' => 'x']);
			$this->fail('a disabled remedy was lodged');
		} catch (RefusedException $e) {
			$this->assertSame('remedy-no-case-type', $e->getRule());
			$this->assertSame(409, $e->getStatus());
		}

		$this->assertSame([], $objects->saved);
	}//end testADisabledRemedyIsRefusedEvenWithACaseType()

	/**
	 * The term runs from the publication date, else the delivery date, else the decision date.
	 *
	 * @return void
	 */
	public function testTheTermRunsFromTheFirstDateTheDecisionWasMadeKnown(): void {
		$lodging = $this->lodging(objects: new RemedyObjectServiceDouble());

		// Delivered 2026-11-02: 14 days end on 2026-11-16, whatever the decision date says.
		$delivered = ['deliveryDate' => '2026-11-02', 'decisionDate' => '2026-10-01'];
		$this->assertTrue($lodging->isTimely($delivered, 14, new DateTimeImmutable('2026-11-16')));
		$this->assertFalse($lodging->isTimely($delivered, 14, new DateTimeImmutable('2026-11-17')));

		// An unparseable publication date falls through to the next field.
		$fallback = ['publicationDate' => 'not a date', 'decisionDate' => '2026-10-01'];
		$this->assertTrue($lodging->isTimely($fallback, 14, new DateTimeImmutable('2026-10-15')));
		$this->assertFalse($lodging->isTimely($fallback, 14, new DateTimeImmutable('2026-10-16')));
	}//end testTheTermRunsFromTheFirstDateTheDecisionWasMadeKnown()

	/**
	 * A decision with no date it was made known on cannot make a remedy late.
	 *
	 * @return void
	 */
	public function testADecisionWithoutAnyDateIsAlwaysTimely(): void {
		$lodging = $this->lodging(objects: new RemedyObjectServiceDouble());

		$this->assertTrue($lodging->isTimely(['id' => 'decision-1'], 42, new DateTimeImmutable('2030-01-01')));
		$this->assertTrue($lodging->isTimely(['decisionDate' => '2026-01-01'], -1, new DateTimeImmutable('2030-01-01')));
	}//end testADecisionWithoutAnyDateIsAlwaysTimely()

	/**
	 * Without a received date the remedy starts today, in the administered zone.
	 *
	 * @return void
	 */
	public function testAnObjectionWithoutAReceivedDateStartsToday(): void {
		$dates              = $this->caseDatesFrozenAt();
		$objects            = new RemedyObjectServiceDouble();
		$objects->decisions = [['id' => 'decision-1', 'decisionDate' => $dates->today()->modify('-3 days')->format('Y-m-d')]];
		$lodging            = $this->lodging(objects: $objects, dates: $dates);
		$today              = $dates->today()->format('Y-m-d');
		$this->hook->expects($this->once())->method('onBezwaarCreated')
			->with('bezwaar-1', 'decision-1', $this->callback(static fn (array $p): bool => $p['receivedDate'] === $today && $p['isTimely'] === true))
			->willReturn(['id' => 'objection-1']);

		$answer = $lodging->lodge('woo-1', ['receivedChannel' => 'balie', 'grounds' => 'x']);

		$this->assertTrue($answer['isTimely']);
		$this->assertSame($today, $objects->saved[0][1]['startDate']);
	}//end testAnObjectionWithoutAReceivedDateStartsToday()

	/**
	 * Ids fall back to uuids, the deadline to `deadline`, and the title to the case id.
	 *
	 * @return void
	 */
	public function testTheAnswerFallsBackToUuidsAndTheCaseId(): void {
		$objects = new class extends RemedyObjectServiceDouble {
			/**
			 * The decided case is gone.
			 *
			 * @param mixed ...$arguments Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function find(mixed ...$arguments): array {
				throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
			}//end find()

			/**
			 * Save answering a uuid and a deadline instead of an id and a planned end date.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param mixed                $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, mixed $uuid = null): array {
				$this->saved[] = [(string)$schema, $object];
				return $object + ['uuid' => 'bezwaar-uuid', 'deadline' => '2027-02-01'];
			}//end saveObject()
		};
		$objects->decisions = [['id' => 'decision-1', 'decisionDate' => '2026-10-30']];
		$lodging            = $this->lodging(objects: $objects, remedy: ['kind' => 'beroep']);
		$this->hook->expects($this->once())->method('onBezwaarCreated')
			->with('bezwaar-uuid', 'decision-1', $this->anything())
			->willReturn(['uuid' => 'objection-uuid']);

		$answer = $lodging->lodge('woo-7', ['receivedDate' => '2026-11-03', 'receivedChannel' => 'email', 'grounds' => 'x']);

		$this->assertSame(['caseId' => 'bezwaar-uuid', 'objectionId' => 'objection-uuid', 'deadline' => '2027-02-01', 'isTimely' => true], $answer);
		$this->assertSame('Beroep: woo-7', $objects->saved[0][1]['title']);
	}//end testTheAnswerFallsBackToUuidsAndTheCaseId()

	/**
	 * The objection payload carries the computed receipt facts over what the caller sent.
	 *
	 * @return void
	 */
	public function testTheComputedReceiptFactsWinOverTheCallersOwn(): void {
		$objects            = new RemedyObjectServiceDouble();
		$objects->decisions = [['id' => 'decision-1', 'publicationDate' => '2026-11-02']];
		$lodging            = $this->lodging(objects: $objects);
		$this->hook->expects($this->once())->method('onBezwaarCreated')
			->with(
				'bezwaar-1',
				'decision-1',
				$this->callback(static fn (array $p): bool => $p['isTimely'] === false && $p['receivedDate'] === '2027-03-01' && $p['grounds'] === 'x')
			)
			->willReturn([]);

		$answer = $lodging->lodge('woo-1', ['receivedDate' => '2027-03-01', 'isTimely' => true, 'receivedChannel' => 'brief', 'grounds' => 'x']);

		$this->assertSame('', $answer['objectionId']);
		$this->assertFalse($answer['isTimely']);
	}//end testTheComputedReceiptFactsWinOverTheCallersOwn()
}//end class
