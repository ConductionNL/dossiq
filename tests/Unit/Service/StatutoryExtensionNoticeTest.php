<?php

/**
 * Every statutory extension tells the requester, whichever route asked for it.
 *
 * Decision 166 (Q-dossiq-R1): Awb 4:14 lid 3 asks that the applicant is told
 * of any extension of the statutory term, not only a Woo one. The notice is
 * sent once, inside DeadlineExtensionService, so the Woo route and the
 * generic `termijn#verleng` share one method (ExtensionNotice::tell()).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\ExtensionNotice;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The notice part of an extension.
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
 */
class StatutoryExtensionNoticeTest extends TestCase {
	use BindsTermFixtures;
	use MakesCaseDateNormaliser;

	/**
	 * What the notice answers when it went out.
	 *
	 * @var array<string, string>
	 */
	private const SENT = ['noticeStatus' => 'sent', 'noticeChannel' => 'email', 'noticeReasonCode' => '', 'noticeReason' => ''];

	/**
	 * The notice.
	 *
	 * @var ExtensionNotice&MockObject
	 */
	private ExtensionNotice&MockObject $notice;

	/**
	 * Build the notice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->notice = $this->createMock(ExtensionNotice::class);
	}//end setUp()

	/**
	 * The service over a term of this kind.
	 *
	 * @param string $kind The term kind, '' for a term that names none (statutory).
	 * @param bool   $wired Whether the notice is wired.
	 *
	 * @return DeadlineExtensionService
	 */
	private function service(string $kind = '', bool $wired = true): DeadlineExtensionService {
		$term = ['id' => 't1', 'case' => 'c1', 'status' => 'lopend', 'endDateCurrent' => '2026-09-01', 'countExtensions' => 0, 'deadlineDefinition' => ''];
		if ($kind !== '') {
			$term['kind'] = $kind;
		}

		$terms = $this->createMock(TermijnService::class);
		$terms->method('getTermijnInstance')->willReturn($term);
		$terms->method('instancesForCase')->willReturn([$term]);
		$terms->method('updateTermijnInstance')->willReturnCallback(static fn (string $id, array $patch): array => array_merge($term, $patch));
		$declarations = $this->createMock(TermDeclarationReader::class);
		$declarations->method('forCase')->willReturn($this->declared(['extensionPeriodDays' => 42]));
		$settings = $this->createMock(SettingsService::class);
		$logger   = $this->createMock(LoggerInterface::class);

		$notice = null;
		if ($wired === true) {
			$notice = $this->notice;
		}

		return new DeadlineExtensionService(
			termService: $terms,
			dates: $this->caseDates(),
			timerService: null,
			declarations: $declarations,
			mirror: new CaseDeadlineMirror(settingsService: $settings, store: new TermInstanceStore(settingsService: $settings, logger: $logger), logger: $logger),
			notice: $notice,
		);
	}//end service()

	/**
	 * The generic route (termijn#verleng) tells the requester, with the reason and the new end.
	 *
	 * @return void
	 */
	public function testTheGenericRouteTellsTheRequester(): void {
		$this->notice->expects($this->once())->method('tell')->with('c1', 't1', 'Extra onderzoek nodig', '2026-10-01')->willReturn(self::SENT);

		$moved = $this->service()->requestExtension('t1', 'Extra onderzoek nodig', '2026-10-01');

		$this->assertSame(expected: '2026-10-01', actual: $moved['endDateCurrent']);
		$this->assertSame(expected: 'sent', actual: $moved['noticeStatus']);
	}//end testTheGenericRouteTellsTheRequester()

	/**
	 * Extending a case's statutory term by days tells once, and hands the answer on.
	 *
	 * @return void
	 */
	public function testAnExtensionByCaseTellsOnceAndSaysSo(): void {
		$this->notice->expects($this->once())->method('tell')->willReturn(self::SENT);

		$extended = $this->service()->extendStatutoryTermOfCase('c1', 'Veel documenten', 14);

		$this->assertSame(expected: self::SENT, actual: $extended['notice']);
	}//end testAnExtensionByCaseTellsOnceAndSaysSo()

	/**
	 * A team's own clock is not a promise to the requester, so nobody is told.
	 *
	 * @return void
	 */
	public function testANonStatutoryTermTellsNobody(): void {
		$this->notice->expects($this->never())->method('tell');

		$moved = $this->service(kind: 'internal')->requestExtension('t1', 'Planning schuift', '2026-10-01');

		$this->assertArrayNotHasKey(key: 'noticeStatus', array: $moved);
	}//end testANonStatutoryTermTellsNobody()

	/**
	 * No sender wired claims nothing, so a route's own fallback still answers.
	 *
	 * @return void
	 */
	public function testNoSenderClaimsNothing(): void {
		$extended = $this->service(wired: false)->extendStatutoryTermOfCase('c1', 'Veel documenten', 14);

		$this->assertSame(expected: [], actual: $extended['notice']);
		$this->assertArrayNotHasKey(key: 'noticeStatus', array: $extended['instance']);
	}//end testNoSenderClaimsNothing()
}//end class
