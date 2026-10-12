<?php

/**
 * A received Woo case is armed only when its statutory term runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Woo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Woo\WooReceivedTerm;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\Dossiq\Woo\WooReceivedTerm
 * @uses   \OCA\Dossiq\Service\TermKind
 */
class WooReceivedTermTest extends TestCase {

	/**
	 * The service over a term service answering the given instances.
	 *
	 * @param array<int, array<string, mixed>>|null $instances The instances, or null to throw.
	 * @param bool                                  $engine    Whether the engine resolves.
	 * @param CaseTimeline|null                     $timeline  The timeline double.
	 *
	 * @return WooReceivedTerm
	 */
	private function term(?array $instances, bool $engine = true, ?CaseTimeline $timeline = null): WooReceivedTerm {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			fn (string $class): ?object => ($class === TermijnTimerService::ENGINE_CLASS && $engine === true) ? new stdClass() : null
		);
		$terms = $this->createMock(TermijnService::class);
		if ($instances === null) {
			$terms->method('instancesForCase')->willThrowException(new RuntimeException('store down'));
		} else {
			$terms->method('instancesForCase')->willReturn($instances);
		}

		return new WooReceivedTerm(settingsService: $settings, terms: $terms, timeline: $timeline);
	}//end term()

	/**
	 * A statutory instance with a timer whose end is the case deadline runs;
	 * a phase term in front of it does not confuse the read.
	 *
	 * @return void
	 */
	public function testARunningStatutoryTermIsNoRefusal(): void {
		$term = $this->term(
			[
				['kind' => 'phase', 'engineTimerId' => '', 'endDateCurrent' => '2026-12-01'],
				['kind' => 'statutory', 'engineTimerId' => 't1', 'endDateCurrent' => '2026-12-28'],
			]
		);

		self::assertTrue($term->isAvailable());
		self::assertSame('', $term->refusal(caseId: 'c1', deadline: '2026-12-28'));
	}//end testARunningStatutoryTermIsNoRefusal()

	/**
	 * Each way a term can fail to run has its own reason.
	 *
	 * @return void
	 */
	public function testEveryFailureNamesItsReason(): void {
		self::assertSame('the case has no statutory term.', $this->term([])->refusal(caseId: 'c1', deadline: '2026-12-28'));
		self::assertSame(
			'the term engine did not arm a timer.',
			$this->term([['kind' => 'statutory', 'engineTimerId' => '', 'endDateCurrent' => '2026-12-28']])->refusal(caseId: 'c1', deadline: '2026-12-28')
		);
		self::assertSame(
			'the case deadline is not the end date of its term.',
			$this->term([['engineTimerId' => 't1', 'endDateCurrent' => '2026-12-28']])->refusal(caseId: 'c1', deadline: '2026-12-25')
		);
		self::assertSame('its term could not be read.', $this->term(null)->refusal(caseId: 'c1', deadline: '2026-12-28'));
	}//end testEveryFailureNamesItsReason()

	/**
	 * Without the engine, or without a term service, no term can run.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineOrTheServiceItIsNotAvailable(): void {
		self::assertFalse($this->term([], engine: false)->isAvailable());

		$bare = new WooReceivedTerm(settingsService: $this->createMock(SettingsService::class));
		self::assertFalse($bare->isAvailable());
		self::assertSame('the term service is not available.', $bare->refusal(caseId: 'c1', deadline: ''));
	}//end testWithoutTheEngineOrTheServiceItIsNotAvailable()

	/**
	 * A term that did not start is noted on the case, internally.
	 *
	 * @return void
	 */
	public function testATermThatDidNotStartIsNotedInternally(): void {
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->expects(self::once())->method('record')->with(
			'c1',
			'termijngebeurtenis',
			'De wettelijke termijn is niet gestart: the case has no statutory term.',
			['event' => 'not-started', 'term' => 'statutory'],
			'internal'
		);

		$this->term([], timeline: $timeline)->noteNotStarted(caseId: 'c1', reason: 'the case has no statutory term.');
	}//end testATermThatDidNotStartIsNotedInternally()
}//end class
