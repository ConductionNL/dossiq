<?php

/**
 * BeschikkingSuccession unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
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
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\Beschikking\BeschikkingSuccession;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StateMachineService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BeschikkingSuccession.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\BeschikkingSuccession
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\StateMachineService
 */
class BeschikkingSuccessionTest extends TestCase {

	/**
	 * Stored beschikkingen by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * What compose() was handed, per call.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $composed = [];

	/**
	 * The service under test.
	 *
	 * @var BeschikkingSuccession
	 */
	private BeschikkingSuccession $succession;

	/**
	 * Set up a repository over an array and a composer that numbers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'besch-1' => [
				'id' => 'besch-1',
				'caseId' => 'case-1',
				'reference' => 'B-2026-000123',
				'currentStatus' => 'sent',
				'decisionType' => 'toekenning',
				'templateId' => 'tpl-wmo-v1',
				'rationale' => 'origineel',
				'addressee' => ['name' => 'M. Jansen'],
			],
			'draft-1' => ['id' => 'draft-1', 'caseId' => 'case-1', 'currentStatus' => 'draft'],
		];
		$this->composed = [];

		/** @var BeschikkingRepository&MockObject $repository */
		$repository = $this->createMock(BeschikkingRepository::class);
		$repository->method('find')->willReturnCallback(fn (string $decisionId): ?array => ($this->store[$decisionId] ?? null));
		$repository->method('requireBeschikking')->willReturnCallback(
			function (string $decisionId): array {
				if (isset($this->store[$decisionId]) === false) {
					throw new \RuntimeException('not_found');
				}

				return $this->store[$decisionId];
			}
		);
		$repository->method('save')->willReturnCallback(
			function (array $decision): array {
				$this->store[$decision['id']] = $decision;
				return $decision;
			}
		);

		/** @var BeschikkingService&MockObject $decisions */
		$decisions = $this->createMock(BeschikkingService::class);
		$decisions->method('compose')->willReturnCallback(
			function (string $caseId, ?string $templateId = null, array $overrides = []): array {
				$this->composed[] = [$caseId, $templateId, $overrides];
				$id = 'besch-'.(count($this->store) + 1);
				$this->store[$id] = array_merge(
					$overrides,
					['id' => $id, 'caseId' => $caseId, 'currentStatus' => 'draft', 'reference' => 'B-2026-00012'.(count($this->store) + 3)]
				);

				return $this->store[$id];
			}
		);

		$stateMachine = new StateMachineService($this->createMock(SettingsService::class), $this->createMock(LoggerInterface::class));

		$this->succession = new BeschikkingSuccession($decisions, $repository, $stateMachine);
	}//end setUp()

	/**
	 * A correction is a new draft on the same case, pointing back; the original only gains the forward pointer.
	 *
	 * @return void
	 */
	public function testACorrectionIsANewNumberedSuccessor(): void {
		$before = $this->store['besch-1'];

		$successor = $this->succession->issue('besch-1', 'amendment', ['rationale' => 'herzien']);

		self::assertSame('amendment', $successor['decisionType']);
		self::assertSame('besch-1', $successor['supersedes']);
		self::assertSame('draft', $successor['currentStatus']);
		self::assertNotSame('B-2026-000123', $successor['reference']);
		self::assertSame(['case-1', 'tpl-wmo-v1'], array_slice($this->composed[0], 0, 2), 'Same case, same template.');
		self::assertSame(['name' => 'M. Jansen'], $successor['addressee'], 'The addressee carries over.');
		self::assertSame('herzien', $successor['rationale']);

		$after = $this->store['besch-1'];
		self::assertSame($successor['id'], $after['supersededBy']);
		unset($after['supersededBy']);
		self::assertSame($before, $after, 'Nothing but the pointer changed on the original.');
	}//end testACorrectionIsANewNumberedSuccessor()

	/**
	 * A withdrawal is a successor too.
	 *
	 * @return void
	 */
	public function testAWithdrawalIsASuccessor(): void {
		$successor = $this->succession->issue('besch-1', 'withdrawal');

		self::assertSame('withdrawal', $successor['decisionType']);
		self::assertSame('besch-1', $successor['supersedes']);
		self::assertSame('sent', $this->store['besch-1']['currentStatus']);
	}//end testAWithdrawalIsASuccessor()

	/**
	 * A replaced beschikking is refused a second successor, and the refusal names the one to correct.
	 *
	 * @return void
	 */
	public function testASupersededBeschikkingCannotBeSupersededTwice(): void {
		$first = $this->succession->issue('besch-1', 'amendment');

		try {
			$this->succession->issue('besch-1', 'amendment');
			self::fail('A second correction of the same beschikking must be refused.');
		} catch (RefusedException $refusal) {
			self::assertSame('already-superseded', $refusal->getRule());
			self::assertSame(RefusedException::STATUS_REFUSED, $refusal->getStatus());
			self::assertStringContainsString((string)$first['reference'], $refusal->getSentence());
		}

		self::assertCount(1, $this->composed, 'Nothing was composed for the refused attempt.');
	}//end testASupersededBeschikkingCannotBeSupersededTwice()

	/**
	 * A draft is changed, not corrected.
	 *
	 * @return void
	 */
	public function testADraftIsNotCorrectedByASuccessor(): void {
		try {
			$this->succession->issue('draft-1', 'amendment');
			self::fail('A draft must be refused a successor.');
		} catch (RefusedException $refusal) {
			self::assertSame('successor-of-a-draft', $refusal->getRule());
		}

		self::assertSame([], $this->composed);
	}//end testADraftIsNotCorrectedByASuccessor()

	/**
	 * Only the two successor kinds exist.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsRefused(): void {
		try {
			$this->succession->issue('besch-1', 'toekenning');
			self::fail('A successor must be an amendment or a withdrawal.');
		} catch (RefusedException $refusal) {
			self::assertSame('successor-kind-unknown', $refusal->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $refusal->getStatus());
		}
	}//end testAnUnknownKindIsRefused()
}//end class
