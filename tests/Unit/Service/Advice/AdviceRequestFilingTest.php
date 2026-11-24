<?php

/**
 * Advice Request Filing Test
 *
 * Pins what filing a new advice request writes on its own: the request saved
 * under the configured schema, the decidesk Decision raised for the saved id
 * with its reference stored back, and a decidesk that does not answer failing
 * the filing closed with no reference stored.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Advice
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Advice;

use OCA\Dossiq\Service\Advice\AdviceRequestFiling;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for AdviceRequestFiling.
 */
#[CoversClass(AdviceRequestFiling::class)]
final class AdviceRequestFilingTest extends TestCase {
	/**
	 * Every save the object store received: [object, schema, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string, 2: ?string}>
	 */
	private array $saves = [];

	/**
	 * An object store that records each save and answers the first with $answer.
	 *
	 * @param array<string, mixed> $answer What the first save answers.
	 *
	 * @return object
	 */
	private function store(array $answer): object {
		$saves = &$this->saves;
		return new class($answer, $saves) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $answer What the first save answers.
			 * @param array<int, mixed>    $saves  The recorded saves, by reference.
			 */
			public function __construct(private array $answer, private array &$saves) {
			}//end __construct()

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param object               $options The save options.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, object $options): array {
				$this->saves[] = [$object, $options->target->schema, $options->uuid];
				return $this->answer;
			}//end saveObject()
		};
	}//end store()

	/**
	 * A filing over this delegation, with the schema configured as `advies-v2`.
	 *
	 * @param AdviceDelegationService $delegation The decidesk delegation.
	 *
	 * @return AdviceRequestFiling
	 */
	private function filing(AdviceDelegationService $delegation): AdviceRequestFiling {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'advies_aanvraag_schema' ? 'advies-v2' : '')
		);

		return new AdviceRequestFiling(delegation: $delegation, settingsService: $settings, logger: new NullLogger());
	}//end filing()

	/**
	 * The request is saved under the configured schema, decided on its own id, and the reference stored on it.
	 *
	 * @return void
	 */
	public function testTheSavedRequestCarriesItsDecisionReference(): void {
		$delegation = $this->createMock(originalClassName: AdviceDelegationService::class);
		$delegation->expects($this->once())->method('raiseAdviceDecision')
			->with('adviesAanvraag', 'advice-1', $this->anything())
			->willReturn('decision-7');

		$saved = $this->filing(delegation: $delegation)->file(
			advice: [
				'objectService' => $this->store(answer: ['id' => 'advice-1', 'status' => 'requested']),
				'register' => 'reg',
				'data' => ['question' => 'Is de kap nodig?'],
				'payload' => ['case' => 'case-1', 'advisor' => 'alice', 'status' => 'requested'],
			]
		);

		self::assertSame(expected: ['id' => 'advice-1', 'status' => 'requested'], actual: $saved);
		self::assertCount(expectedCount: 2, haystack: $this->saves);
		self::assertSame(expected: 'advies-v2', actual: $this->saves[0][1]);
		self::assertNull(actual: $this->saves[0][2]);
		self::assertSame(expected: 'decision-7', actual: $this->saves[1][0]['decisionRef']);
		self::assertSame(expected: 'advice-1', actual: $this->saves[1][2]);
	}//end testTheSavedRequestCarriesItsDecisionReference()

	/**
	 * A decidesk that does not answer fails the filing closed, and no reference is stored.
	 *
	 * @return void
	 */
	public function testAnUnansweredDecisionFailsClosed(): void {
		$delegation = $this->createMock(originalClassName: AdviceDelegationService::class);
		$delegation->method('raiseAdviceDecision')->willThrowException(new RuntimeException('decidesk down'));

		try {
			$this->filing(delegation: $delegation)->file(
				advice: [
					'objectService' => $this->store(answer: ['id' => 'advice-1']),
					'register' => 'reg',
					'data' => [],
					'payload' => ['case' => 'case-1', 'advisor' => 'alice'],
				]
			);
			self::fail(message: 'The filing did not fail closed.');
		} catch (RuntimeException $e) {
			self::assertSame(expected: 'Decision service unavailable: decidesk down', actual: $e->getMessage());
		}

		self::assertCount(expectedCount: 1, haystack: $this->saves);
	}//end testAnUnansweredDecisionFailsClosed()
}//end class
