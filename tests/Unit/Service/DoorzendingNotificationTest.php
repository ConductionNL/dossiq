<?php

/**
 * Awb 2:3 tells the applicant. An internal move tells them nothing.
 *
 * Both halves are requirements, and the second one is the easier to get wrong:
 * a notifier that announced every handover would be correct on the law and
 * wrong on the product, because the citizen learns that our messages are noise
 * and the next one they ignore is the one with the deadline in it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\Transfer\DoorzendingNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who is told a case moved, and who is deliberately not.
 *
 * @covers \OCA\Dossiq\Service\Transfer\DoorzendingNotifier
 * @uses \OCA\Dossiq\Service\TermijnNotificationService
 * @uses \OCA\Dossiq\Service\Termijn\TermLetters
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
class DoorzendingNotificationTest extends TestCase {

	/**
	 * One case carrying an address.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE_ROW = [
		'id' => 'case-1',
		'identifier' => 'ZAAK-2026-0001',
		'contactEmail' => 'aanvrager@example.org',
	];

	/**
	 * A doorzending tells the applicant where the case went.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	public function testADoorzendingTellsTheApplicant(): void {
		$notifications = $this->createMock(originalClassName: TermijnNotificationService::class);
		$notifications->expects(self::once())
			->method('sendTermijnNotification')
			->with(
				DoorzendingNotifier::TEMPLATE,
				'',
				'aanvrager@example.org',
				self::callback(
					static fn (array $context): bool => ($context['destination'] === 'toezicht'
						&& $context['case'] === 'ZAAK-2026-0001')
				),
			)
			->willReturn(['subject' => 'Uw zaak ZAAK-2026-0001 is doorgestuurd']);

		$result = $this->notifier(notifications: $notifications)->announce(
			case: self::CASE_ROW,
			transfer: ['doorzending' => true, 'targetTeam' => 'toezicht'],
		);

		self::assertTrue($result['announced']);
	}//end testADoorzendingTellsTheApplicant()

	/**
	 * An internal move sends nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	public function testAnInternalMoveIsNotAnnounced(): void {
		$notifications = $this->createMock(originalClassName: TermijnNotificationService::class);
		$notifications->expects(self::never())->method('sendTermijnNotification');

		$result = $this->notifier(notifications: $notifications)->announce(
			case: self::CASE_ROW,
			transfer: ['doorzending' => false, 'targetTeam' => 'toezicht'],
		);

		self::assertFalse($result['announced']);
		self::assertSame('internal-move', $result['reason']);
	}//end testAnInternalMoveIsNotAnnounced()

	/**
	 * A case with no address says so rather than reporting a message it never sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	public function testACaseWithNoAddressReportsThatNobodyWasTold(): void {
		$notifications = $this->createMock(originalClassName: TermijnNotificationService::class);
		$notifications->expects(self::never())->method('sendTermijnNotification');

		$contacts = $this->createMock(originalClassName: CaseContactDirectory::class);
		$contacts->method('collectAddresses')->willReturn([]);

		$notifier = new DoorzendingNotifier(
			notifications: $notifications,
			contacts: $contacts,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$result = $notifier->announce(
			case: ['id' => 'case-1'],
			transfer: ['doorzending' => true, 'targetTeam' => 'toezicht'],
		);

		self::assertFalse($result['announced'], 'An empty recipient is a message that goes nowhere and reports success.');
		self::assertSame('no-address', $result['reason']);
	}//end testACaseWithNoAddressReportsThatNobodyWasTold()

	/**
	 * The Dutch and English wordings both name where the case went.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	public function testTheWordingNamesTheDestinationInBothLanguages(): void {
		$service = new TermijnNotificationService(
			termService: $this->createMock(originalClassName: \OCA\Dossiq\Service\TermijnService::class),
			router: $this->createMock(originalClassName: \OCA\Dossiq\Service\BerichtenboxRoutingService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$dutch = $service->renderTemplate(
			type: DoorzendingNotifier::TEMPLATE,
			instance: [],
			context: ['case' => 'ZAAK-1', 'destination' => 'Toezicht', 'locale' => 'nl'],
		);
		$english = $service->renderTemplate(
			type: DoorzendingNotifier::TEMPLATE,
			instance: [],
			context: ['case' => 'ZAAK-1', 'destination' => 'Toezicht', 'locale' => 'en'],
		);

		self::assertStringContainsString('Toezicht', $dutch['body']);
		self::assertStringContainsString('doorgestuurd', $dutch['subject']);
		self::assertStringContainsString('Toezicht', $english['body']);
		self::assertStringNotContainsString(
			'termijn',
			$english['body'],
			'The receiving party owns the term from here; quoting ours would be a promise on their behalf.',
		);
	}//end testTheWordingNamesTheDestinationInBothLanguages()

	/**
	 * A notifier over a case that carries one address.
	 *
	 * @param TermijnNotificationService $notifications The sender double.
	 *
	 * @return DoorzendingNotifier The service under test.
	 */
	private function notifier(TermijnNotificationService $notifications): DoorzendingNotifier {
		$contacts = $this->createMock(originalClassName: CaseContactDirectory::class);
		$contacts->method('collectAddresses')->willReturn(['aanvrager@example.org']);

		return new DoorzendingNotifier(
			notifications: $notifications,
			contacts: $contacts,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end notifier()
}//end class
