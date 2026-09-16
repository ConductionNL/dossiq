<?php

/**
 * External consultation link unit tests.
 *
 * The advisory body writes a comment as the link, `link:<uuid>`, and the
 * handler collects it onto the consultation. Three things can go wrong there
 * and all three are pinned below: a colleague's note being read as advice, the
 * same advice being recorded twice, and a consultation with no link answering
 * as though somebody had replied to it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Consultation
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
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\Consultation\ExternalConsultationLinkService;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fake of OpenRegister's NoteService, over a fixed list of comments.
 */
final class EclFakeNoteService {
	/**
	 * @param array<int, array<string, mixed>> $notes The comments on the case.
	 */
	public function __construct(private array $notes = []) {
	}//end __construct()

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getNotesForObject(string $objectUuid, int $limit = 50, int $offset = 0, ?string $visibility = null): array {
		unset($objectUuid, $limit, $offset, $visibility);
		return $this->notes;
	}//end getNotesForObject()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Consultation\ExternalConsultationLinkService
 *
 * @uses \OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway
 */
class ExternalConsultationLinkServiceTest extends TestCase {
	/**
	 * Assemble the service over the supplied comments and shares.
	 *
	 * @param array<int, array<string, mixed>> $notes The comments on the case.
	 * @param ConsultationService $consultations The consultation service.
	 * @param CaseSharingService $shares The case sharing service.
	 * @param CaseLinkShares|null $linkShares The share record store.
	 *
	 * @return ExternalConsultationLinkService
	 */
	private function makeService(
		array $notes,
		ConsultationService $consultations,
		CaseSharingService $shares,
		?CaseLinkShares $linkShares = null,
	): ExternalConsultationLinkService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new EclFakeNoteService($notes));

		$logger = $this->createMock(LoggerInterface::class);

		return new ExternalConsultationLinkService(
			consultations: $consultations,
			shares: $shares,
			linkShares: ($linkShares ?? $this->createMock(CaseLinkShares::class)),
			gateway: new OpenRegisterSharingGateway($appManager, $container, $logger),
			logger: $logger,
		);
	}//end makeService()

	/**
	 * One share on the case, carrying a link and an advisory body.
	 *
	 * @param int $lastCollected The note already collected.
	 *
	 * @return array<string, mixed>
	 */
	private function share(int $lastCollected = 0): array {
		return [
			'id' => 'share-1',
			'caseId' => 'case-1',
			'shareType' => 'link',
			'accessLinkId' => 7,
			'accessLinkUuid' => 'uuid-7',
			'advisoryBody' => 'Brandweer Midden-Nederland',
			'consultationId' => 'cn-1',
			'lastCollectedNote' => $lastCollected,
			'state' => 'live',
		];
	}//end share()

	/**
	 * Inviting an advisory body mints a read-and-comment link that expires on
	 * the consultation's own deadline, and names the body on the share.
	 *
	 * @return void
	 */
	public function testInviteMintsACommentLinkOnTheDeadline(): void {
		$consultations = $this->createMock(ConsultationService::class);
		$consultations->method('getConsultation')->willReturn(
			[
				'id' => 'cn-1',
				'parentCase' => 'case-1',
				'adviceAuthority' => 'Brandweer Midden-Nederland',
				'latestResponseDate' => '2030-03-01',
			]
		);

		$shares = $this->createMock(CaseSharingService::class);
		$shares->expects($this->once())
			->method('createTokenShare')
			->with(
				'case-1',
				'Brandweer Midden-Nederland',
				'anja',
				'2030-03-01',
				['read', 'comment'],
				null,
				[],
				['advisoryBody' => 'Brandweer Midden-Nederland', 'consultationId' => 'cn-1']
			)
			->willReturn(['share' => $this->share(), 'url' => 'https://example.test/l/abc']);

		$service = $this->makeService([], $consultations, $shares);

		$invited = $service->invite(consultationId: 'cn-1', userId: 'anja');

		self::assertSame('https://example.test/l/abc', $invited['url']);
	}//end testInviteMintsACommentLinkOnTheDeadline()

	/**
	 * A consultation that names no advisory body has nobody to invite, and
	 * nothing is published on the way to finding that out.
	 *
	 * @return void
	 */
	public function testInviteRefusesAConsultationWithNoAdvisoryBody(): void {
		$consultations = $this->createMock(ConsultationService::class);
		$consultations->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'parentCase' => 'case-1', 'adviceAuthority' => '']
		);

		$shares = $this->createMock(CaseSharingService::class);
		$shares->expects($this->never())->method('createTokenShare');

		$service = $this->makeService([], $consultations, $shares);

		$this->expectException(RuntimeException::class);
		$service->invite(consultationId: 'cn-1', userId: 'anja');
	}//end testInviteRefusesAConsultationWithNoAdvisoryBody()

	/**
	 * The comment written as the link becomes the consultation's response,
	 * verbatim, with the advisory body named on it.
	 *
	 * @return void
	 */
	public function testCommentBecomesTheResponse(): void {
		$consultations = $this->createMock(ConsultationService::class);
		$consultations->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'parentCase' => 'case-1']
		);
		$consultations->expects($this->once())
			->method('submitResponse')
			->with(
				'cn-1',
				[
					'advice' => 'positive',
					'notes' => 'Brandweer Midden-Nederland: Geen bezwaar tegen de vergunning.',
				]
			)
			->willReturn(['id' => 'cn-1', 'advice' => 'positive']);

		$shares = $this->createMock(CaseSharingService::class);
		$linkShares = $this->createMock(CaseLinkShares::class);
		$linkShares->method('listForCase')->willReturn([$this->share()]);
		$linkShares->expects($this->once())->method('markCollected')->with('share-1', 31)->willReturn(true);

		$service = $this->makeService(
			[
				[
					'id' => 31,
					'message' => 'Geen bezwaar tegen de vergunning.',
					'actorType' => 'openregister_links',
					'actorId' => 'link:uuid-7',
				],
			],
			$consultations,
			$shares,
			$linkShares
		);

		$collected = $service->collect(consultationId: 'cn-1', advice: 'positive');

		self::assertTrue($collected['collected']);
		self::assertSame('Brandweer Midden-Nederland', $collected['advisoryBody']);
		self::assertSame(31, $collected['noteId']);
	}//end testCommentBecomesTheResponse()

	/**
	 * A colleague's note on the same case is not advice, and neither is a
	 * comment written through a different link.
	 *
	 * @return void
	 */
	public function testOtherActorsAreNotAdvice(): void {
		$consultations = $this->createMock(ConsultationService::class);
		$consultations->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'parentCase' => 'case-1']
		);
		$consultations->expects($this->never())->method('submitResponse');

		$shares = $this->createMock(CaseSharingService::class);
		$linkShares = $this->createMock(CaseLinkShares::class);
		$linkShares->method('listForCase')->willReturn([$this->share()]);
		$linkShares->expects($this->never())->method('markCollected');

		$service = $this->makeService(
			[
				[
					'id' => 40,
					'message' => 'Even gebeld met de aanvrager.',
					'actorType' => 'users',
					'actorId' => 'anja',
				],
				[
					'id' => 41,
					'message' => 'Advies van een andere link.',
					'actorType' => 'openregister_links',
					'actorId' => 'link:uuid-99',
				],
			],
			$consultations,
			$shares,
			$linkShares
		);

		self::assertSame(['collected' => false], $service->collect(consultationId: 'cn-1', advice: 'positive'));
	}//end testOtherActorsAreNotAdvice()

	/**
	 * A comment already collected is not collected again, so a handler who
	 * presses the button twice does not record one answer twice.
	 *
	 * @return void
	 */
	public function testACollectedCommentIsNotCollectedTwice(): void {
		$consultations = $this->createMock(ConsultationService::class);
		$consultations->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'parentCase' => 'case-1']
		);
		$consultations->expects($this->never())->method('submitResponse');

		$shares = $this->createMock(CaseSharingService::class);
		$linkShares = $this->createMock(CaseLinkShares::class);
		$linkShares->method('listForCase')->willReturn([$this->share(lastCollected: 31)]);

		$service = $this->makeService(
			[
				[
					'id' => 31,
					'message' => 'Geen bezwaar tegen de vergunning.',
					'actorType' => 'openregister_links',
					'actorId' => 'link:uuid-7',
				],
			],
			$consultations,
			$shares,
			$linkShares
		);

		self::assertSame(['collected' => false], $service->collect(consultationId: 'cn-1', advice: 'positive'));
	}//end testACollectedCommentIsNotCollectedTwice()

	/**
	 * The newest comment wins when the body wrote more than once.
	 *
	 * @return void
	 */
	public function testTheNewestCommentIsTheAdvice(): void {
		$consultations = $this->createMock(ConsultationService::class);
		$consultations->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'parentCase' => 'case-1']
		);
		$consultations->method('submitResponse')->willReturn(['id' => 'cn-1']);

		$shares = $this->createMock(CaseSharingService::class);
		$linkShares = $this->createMock(CaseLinkShares::class);
		$linkShares->method('listForCase')->willReturn([$this->share()]);
		$linkShares->method('markCollected')->willReturn(true);

		$service = $this->makeService(
			[
				[
					'id' => 31,
					'message' => 'Eerste reactie.',
					'actorType' => 'openregister_links',
					'actorId' => 'link:uuid-7',
				],
				[
					'id' => 45,
					'message' => 'Definitief advies.',
					'actorType' => 'openregister_links',
					'actorId' => 'link:uuid-7',
				],
			],
			$consultations,
			$shares,
			$linkShares
		);

		self::assertSame(45, $service->collect(consultationId: 'cn-1', advice: 'positive')['noteId']);
	}//end testTheNewestCommentIsTheAdvice()

	/**
	 * A consultation that was never published has no advice to collect, and
	 * says so rather than answering "nothing new".
	 *
	 * @return void
	 */
	public function testCollectRefusesAConsultationWithNoLink(): void {
		$consultations = $this->createMock(ConsultationService::class);
		$consultations->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'parentCase' => 'case-1']
		);

		$shares = $this->createMock(CaseSharingService::class);
		$linkShares = $this->createMock(CaseLinkShares::class);
		$linkShares->method('listForCase')->willReturn([]);

		$service = $this->makeService([], $consultations, $shares, $linkShares);

		$this->expectException(RuntimeException::class);
		$service->collect(consultationId: 'cn-1', advice: 'positive');
	}//end testCollectRefusesAConsultationWithNoLink()
}//end class
