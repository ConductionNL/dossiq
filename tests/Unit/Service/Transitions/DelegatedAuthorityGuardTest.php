<?php

/**
 * DelegatedAuthorityGuard unit tests.
 *
 * The guard over a neutral fixture case type: a "fixture letter" whose
 * signing step names the act `sign-fixture-letter` and keeps its amount in
 * `amountCents`. decidiq is played by an object with the exact answer shape
 * of decidiq's DelegatedAuthorityCheck::check(), found through the same
 * duck-typed container lookup production uses.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delegated-authority-guard/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\ApprovalGuard;
use OCA\Dossiq\Service\Transitions\CapacityGuard;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use OCA\Dossiq\Service\Transitions\DelegatedAuthorityGuard;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\RequiredFieldGuard;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCA\Dossiq\Service\Transitions\StatusChecklistGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\DelegatedAuthorityGuard
 * @uses \OCA\Dossiq\Service\Transitions\GuardRegistry
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class DelegatedAuthorityGuardTest extends TestCase {

	/**
	 * The questions decidiq was asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * Build the guard over a container that does or does not hold decidiq.
	 *
	 * @param array<string, mixed>|null $answer What decidiq answers; null for no decidiq.
	 * @param bool                      $throws Whether decidiq throws.
	 *
	 * @return DelegatedAuthorityGuard
	 */
	private function guard(?array $answer, bool $throws=false): DelegatedAuthorityGuard {
		$asked = &$this->asked;
		$check = new class($answer, $throws, $asked) {
			/**
			 * @param array<string, mixed>|null        $answer
			 * @param bool                              $throws
			 * @param array<int, array<string, mixed>> $asked
			 */
			public function __construct(private ?array $answer, private bool $throws, private array &$asked) {
			}

			/**
			 * The decidiq DelegatedAuthorityCheck::check() signature.
			 *
			 * @return array<string, mixed>
			 */
			public function check(string $actor, string $act, ?float $amount=null, ?\DateTimeImmutable $at=null): array {
				$this->asked[] = ['actor' => $actor, 'act' => $act, 'amount' => $amount];
				if ($this->throws === true) {
					throw new RuntimeException('decidiq broke');
				}

				return (array)$this->answer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($answer, $throws, $check): object {
				if (($answer === null && $throws === false) || $id !== 'OCA\\Decidiq\\Service\\DelegatedAuthorityCheck') {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $check;
			}
		);

		return new DelegatedAuthorityGuard($container, $this->createMock(LoggerInterface::class), l10n: new SourceL10n());
	}//end guard()

	/**
	 * A fixture case: signatory alice, act and amount as case-type properties.
	 *
	 * @return array<string, mixed>
	 */
	private function fixtureCase(): array {
		return [
			'id'         => 'case-1',
			'signatory'  => 'alice',
			'properties' => [
				['name' => 'letterKind', 'value' => 'sign-fixture-letter'],
				['name' => 'amountCents', 'value' => '1250050'],
			],
		];
	}//end fixtureCase()

	/**
	 * An authorised answer passes and names the covering allocation; the
	 * question carries the signatory, the act from the property and euros.
	 *
	 * @return void
	 */
	public function testAnAuthorisedSignatoryPasses(): void {
		$guard  = $this->guard(answer: ['authorised' => true, 'reason' => 'authorised', 'allocation' => 'a-1']);
		$result = $guard->evaluate(
			guardConfig: ['type' => 'delegatedAuthorityGuard', 'actField' => 'letterKind', 'amountField' => 'amountCents'],
			case: $this->fixtureCase(),
			userId: 'bob'
		);

		self::assertTrue($result->passed);
		self::assertSame('a-1', $result->details['allocation']);
		self::assertSame([['actor' => 'alice', 'act' => 'sign-fixture-letter', 'amount' => 12500.5]], $this->asked);
	}//end testAnAuthorisedSignatoryPasses()

	/**
	 * Without a signatory on the case the current user is the actor, and a
	 * literal act needs no case field.
	 *
	 * @return void
	 */
	public function testTheCurrentUserActsWhenTheCaseNamesNobody(): void {
		$guard = $this->guard(answer: ['authorised' => true, 'reason' => 'authorised', 'allocation' => 'a-1']);
		$guard->evaluate(guardConfig: ['act' => 'sign-fixture-letter'], case: ['id' => 'case-1'], userId: 'bob');

		self::assertSame([['actor' => 'bob', 'act' => 'sign-fixture-letter', 'amount' => null]], $this->asked);
	}//end testTheCurrentUserActsWhenTheCaseNamesNobody()

	/**
	 * decidiq's refusal reasons each refuse with their own sentence.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsReason(): void {
		foreach (['no-allocation', 'over-ceiling', 'register-unreadable'] as $reason) {
			$result = $this->guard(answer: ['authorised' => false, 'reason' => $reason, 'allocation' => null])
				->evaluate(guardConfig: ['act' => 'sign-fixture-letter'], case: $this->fixtureCase(), userId: 'bob');

			self::assertFalse($result->passed, $reason);
			self::assertSame($reason, $result->details['authority']);
			self::assertNotSame('', (string)$result->failureMessage);
		}
	}//end testARefusalCarriesItsReason()

	/**
	 * No decidiq, a decidiq that throws, a missing act and an unreadable
	 * amount all refuse; none passes silently.
	 *
	 * @return void
	 */
	public function testEveryUncertainAnswerRefuses(): void {
		$absent = $this->guard(answer: null)->evaluate(guardConfig: ['act' => 'sign-fixture-letter'], case: $this->fixtureCase(), userId: 'bob');
		self::assertFalse($absent->passed);
		self::assertSame('register-absent', $absent->details['authority']);

		$broken = $this->guard(answer: null, throws: true)->evaluate(guardConfig: ['act' => 'sign-fixture-letter'], case: $this->fixtureCase(), userId: 'bob');
		self::assertFalse($broken->passed);
		self::assertSame('register-unreadable', $broken->details['authority']);

		$authorised = ['authorised' => true, 'reason' => 'authorised', 'allocation' => 'a-1'];
		$noAct      = $this->guard(answer: $authorised)->evaluate(guardConfig: ['actField' => 'missing'], case: $this->fixtureCase(), userId: 'bob');
		self::assertFalse($noAct->passed);
		self::assertSame('no-act', $noAct->details['authority']);

		$case             = $this->fixtureCase();
		$case['amountCents'] = 'twelve';
		$badAmount        = $this->guard(answer: $authorised)->evaluate(guardConfig: ['act' => 'x', 'amountField' => 'amountCents'], case: $case, userId: 'bob');
		self::assertFalse($badAmount->passed);
		self::assertSame('amount-unreadable', $badAmount->details['authority']);
	}//end testEveryUncertainAnswerRefuses()

	/**
	 * The registry answers the new key and the legacy one; the legacy key
	 * reads its act from the case property stored definitions relied on.
	 *
	 * @return void
	 */
	public function testTheRegistryAnswersTheLegacyKeyWithItsAct(): void {
		$registry = new GuardRegistry(
			$this->createMock(ChecklistGuard::class),
			$this->createMock(RequiredFieldGuard::class),
			$this->createMock(RequiredDocumentGuard::class),
			$this->createMock(RoleGuard::class),
			$this->guard(answer: ['authorised' => true, 'reason' => 'authorised', 'allocation' => 'a-1']),
			$this->createMock(StatusChecklistGuard::class),
			$this->createMock(CapacityGuard::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ApprovalGuard::class),
		);

		self::assertTrue($registry->knows('delegatedAuthorityGuard'));
		self::assertTrue($registry->knows('mandaatGuard'));

		$case               = $this->fixtureCase();
		$case['properties'][] = ['name' => 'mandaatCategorie', 'value' => 'VTH-M-04'];
		$registry->evaluateAll(guards: [['type' => 'mandaatGuard']], case: $case, userId: 'bob');

		self::assertSame('VTH-M-04', ($this->asked[0]['act'] ?? null));
	}//end testTheRegistryAnswersTheLegacyKeyWithItsAct()
}//end class
