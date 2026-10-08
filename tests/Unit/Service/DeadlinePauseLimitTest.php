<?php

/**
 * The declared suspension maximum is a ceiling that refuses, and names it.
 *
 * Awb 4:5 bounds the pause as well as the extension, and the refusal answers
 * `{message, error}` with the status the rule chose, per ADR-050.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TERM-066: the declared suspension length is enforced.
 *
 * @covers \OCA\Dossiq\Service\DeadlinePauseService
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class DeadlinePauseLimitTest extends TestCase {
	use BindsTermFixtures;

	/**
	 * The store.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $termService;

	/**
	 * The declarations.
	 *
	 * @var TermDeclarationReader&MockObject
	 */
	private TermDeclarationReader $declarations;

	/**
	 * The service under test.
	 *
	 * @var DeadlinePauseService
	 */
	private DeadlinePauseService $service;

	/**
	 * Wire the service against a store holding one running term.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->declarations = $this->createMock(TermDeclarationReader::class);

		$this->termService->method('getTermijnInstance')->willReturn(
			[
				'id' => 't1',
				'case' => 'c1',
				'endDateCurrent' => '2026-11-01',
				'status' => 'lopend',
			]
		);
		$this->termService->method('updateTermijnInstance')->willReturnCallback(
			static fn (string $id, array $patch): array => array_merge(['id' => $id], $patch)
		);

		$this->service = new DeadlinePauseService(
			termService: $this->termService,
			timerService: null,
			declarations: $this->declarations,
		);
	}//end setUp()

	/**
	 * A suspension beyond the declared maximum is refused, naming the maximum.
	 *
	 * @return void
	 */
	public function testASuspensionBeyondTheDeclaredMaximumIsRefused(): void {
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['maxSuspensionDays' => 28])
		);

		try {
			$this->service->registerPauze('t1', 60, 'Aanvulling gevraagd');
			self::fail('Sixty days on a twenty-eight day maximum has to be refused.');
		} catch (RefusedException $refusal) {
			self::assertSame('suspension-beyond-declared-maximum', $refusal->getRule());
			self::assertStringContainsString('28', $refusal->getSentence());
			self::assertStringContainsString('60', $refusal->getSentence());
		}
	}//end testASuspensionBeyondTheDeclaredMaximumIsRefused()

	/**
	 * A suspension inside the maximum runs.
	 *
	 * @return void
	 */
	public function testASuspensionInsideTheMaximumIsAllowed(): void {
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['maxSuspensionDays' => 28])
		);

		$paused = $this->service->registerPauze('t1', 14, 'Aanvulling gevraagd');

		self::assertSame('paused', $paused['status']);
	}//end testASuspensionInsideTheMaximumIsAllowed()

	/**
	 * A case type that allows no suspension refuses every one of them.
	 *
	 * @return void
	 */
	public function testACaseTypeThatAllowsNoSuspensionRefuses(): void {
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['suspensionAllowed' => false])
		);

		try {
			$this->service->registerPauze('t1', 7, 'Aanvulling gevraagd');
			self::fail('A case type that forbids suspension has to refuse one.');
		} catch (RefusedException $refusal) {
			self::assertSame('suspension-not-allowed', $refusal->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $refusal->getStatus());
		}
	}//end testACaseTypeThatAllowsNoSuspensionRefuses()

	/**
	 * A case type declaring no maximum lets any length through, which is what
	 * this app did before the declaration existed.
	 *
	 * @return void
	 */
	public function testNoDeclaredMaximumMeansNoCeiling(): void {
		$this->declarations->method('forCase')->willReturn($this->declared([]));

		$paused = $this->service->registerPauze('t1', 90, 'Aanvulling gevraagd');

		self::assertSame('paused', $paused['status']);
	}//end testNoDeclaredMaximumMeansNoCeiling()
}//end class
