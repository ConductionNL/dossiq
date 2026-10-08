<?php

/**
 * The internal target: clocked apart, and never shown to the citizen.
 *
 * GLPI calls it an OLA beside the SLA. It is a team target, and the one thing
 * that must not happen to it is reaching a portal, because a number a citizen
 * reads is a promise whether or not anyone meant it that way.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-TERM-063: an internal target is clocked apart and never shown to the citizen.
 *
 * @covers \OCA\Dossiq\Service\CaseTermsService
 * @covers \OCA\Dossiq\Service\TermKind
 */
class InternalTargetTest extends TestCase {
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
	 * @var CaseTermsService
	 */
	private CaseTermsService $service;

	/**
	 * Wire the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->declarations = $this->createMock(TermDeclarationReader::class);
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnArgument(0);

		$this->written = [];
		$this->termService->method('saveTermInstance')->willReturnCallback(
			function (array $instance): array {
				$instance['id'] = ('written-' . count($this->written));
				$this->written[] = $instance;

				return $instance;
			}
		);
		$this->termService->method('instancesForCase')->willReturnCallback(
			fn (string $caseId): array => $this->instances
		);

		$this->service = new CaseTermsService(
			termService: $this->termService,
			declarations: $this->declarations,
			timers: $timers,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A statutory term of 56 days and an internal target of 30 both run.
	 *
	 * @return void
	 */
	public function testBothTermsRun(): void {
		$this->declarations->method('forCaseType')->willReturn(
			$this->declared(['leadTimeDays' => 56, 'internalTargetDays' => 30])
		);

		$this->service->bindForCase(
			caseId: 'c1',
			caseTypeId: 'ct1',
			start: new DateTimeImmutable('2026-09-01'),
		);

		self::assertCount(1, $this->written, 'The statutory term is bound by TermijnService; this call adds the internal one.');
		self::assertSame(TermKind::INTERNAL, $this->written[0]['kind']);
		self::assertSame('2026-10-01', $this->written[0]['endDateCurrent']);
	}//end testBothTermsRun()

	/**
	 * The internal target is not in the portal view of the case.
	 *
	 * @return void
	 */
	public function testTheInternalTargetIsNotInThePortalView(): void {
		$this->instances = [
			$this->instanceOf(kind: TermKind::STATUTORY, end: '2026-11-01'),
			$this->instanceOf(kind: TermKind::INTERNAL, end: '2026-10-01'),
		];

		$visible = $this->service->citizenTermsFor(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));

		self::assertCount(1, $visible);
		self::assertNotContains(
			TermKind::INTERNAL,
			array_column($visible, 'kind'),
			'A portal read that carried the team target would have made it a promise.'
		);
	}//end testTheInternalTargetIsNotInThePortalView()

	/**
	 * The handler read still carries it, marked as not citizen visible.
	 *
	 * @return void
	 */
	public function testTheHandlerReadCarriesItAndSaysItIsNotForTheCitizen(): void {
		$this->instances = [$this->instanceOf(kind: TermKind::INTERNAL, end: '2026-10-01')];

		$terms = $this->service->termsForCase(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));

		self::assertSame(TermKind::INTERNAL, $terms[0]['kind']);
		self::assertFalse($terms[0]['citizenVisible']);
	}//end testTheHandlerReadCarriesItAndSaysItIsNotForTheCitizen()

	/**
	 * No template the citizen receives quotes an internal target.
	 *
	 * The templates that reach an applicant are the four
	 * {@see TermijnNotificationService::TEMPLATES} names plus the request for
	 * information. None of them renders a term other than the statutory one,
	 * and this case is what notices when a new one does.
	 *
	 * @return void
	 */
	public function testNoCitizenTemplateQuotesTheInternalTarget(): void {
		$source = file_get_contents(__DIR__ . '/../../../lib/Service/TermijnNotificationService.php');

		self::assertIsString($source);
		self::assertStringNotContainsString(
			'internalTarget',
			$source,
			'A message to an applicant that quoted the internal target would turn a team number into a promise.'
		);
		self::assertStringNotContainsString("TermKind::INTERNAL", $source);
	}//end testNoCitizenTemplateQuotesTheInternalTarget()
}//end class
