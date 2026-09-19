<?php

/**
 * A case share that cannot be recorded leaves no live link.
 *
 * 🔴 THE DEFECT. `createTokenShare()` mints the OpenRegister access link
 * first and writes the `caseShare` record second. When the write failed, the
 * link stayed published: live on the case, absent from the sharing tab, and
 * unrevokable there, because revoking reads the record. The caller was told
 * the share failed.
 *
 * Measured 2026-09-19 on a live instance. `case_share_schema` was never
 * written, so every attempt to share a case published a link and answered
 * 502 "Service unavailable". Nothing in that answer named the app-config key
 * that was missing, and nothing revoked the link.
 *
 * Both halves are asserted from the CALLER: the service is asked to mint a
 * share, and what is asserted is what it then asked its collaborators to do.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\FederatedCaseShareService;
use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\CaseSharingService
 *
 * @uses \OCA\Dossiq\Service\Sharing\CaseLinkShares
 */
class CaseShareMintRollsBackTest extends TestCase {

	/**
	 * The minted case link, as OpenRegister answers it.
	 *
	 * @var array<string, mixed>
	 */
	private const MINTED = [
		'id' => 4711,
		'uuid' => 'link-uuid',
		'url' => 'https://example.test/s/anchor',
		'expiresAt' => '2030-01-01',
	];

	/**
	 * @var CaseAccessLinkService&MockObject
	 */
	private CaseAccessLinkService $accessLinks;

	/**
	 * @var CaseLinkShares&MockObject
	 */
	private CaseLinkShares $linkShares;

	/**
	 * The service under test.
	 *
	 * @var CaseSharingService
	 */
	private CaseSharingService $service;

	/**
	 * Build the service with every collaborator doubled.
	 *
	 * `onlyMethods` throughout: a double that may invent a method the real
	 * class lacks can only ever pass.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->accessLinks = $this->getMockBuilder(CaseAccessLinkService::class)
			->disableOriginalConstructor()
			->onlyMethods(['mintCaseLink', 'mintFileLink'])
			->getMock();

		$this->linkShares = $this->getMockBuilder(CaseLinkShares::class)
			->disableOriginalConstructor()
			->onlyMethods(['store', 'revokeMinted'])
			->getMock();

		$this->service = new CaseSharingService(
			settingsService: $this->createMock(SettingsService::class),
			gateway: $this->createMock(OpenRegisterSharingGateway::class),
			accessPolicy: $this->createMock(CaseAccessPolicy::class),
			accessLinks: $this->accessLinks,
			linkShares: $this->linkShares,
			federatedShares: $this->createMock(FederatedCaseShareService::class),
			consent: $this->createMock(CaseTransferConsentGate::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A share whose record cannot be written withdraws the link it minted.
	 *
	 * The assertion is the `revokeMinted` expectation: the service was asked
	 * to pull link 4711, which is the id it had just been handed by the mint.
	 *
	 * @return void
	 */
	public function testAFailedRecordWithdrawsTheLinkThatWasAlreadyMinted(): void {
		$this->accessLinks->method('mintCaseLink')->willReturn(self::MINTED);

		$this->linkShares->method('store')->willReturn(
			[
				'error' => 'Sharing is not configured on this instance yet.',
				'reason' => CaseLinkShares::REASON_NOT_CONFIGURED,
			]
		);

		$revoked = [];
		$this->linkShares->expects($this->once())
			->method('revokeMinted')
			->willReturnCallback(
				function (array $link, array $documents, string $userId) use (&$revoked): array {
					$revoked = ['link' => $link, 'documents' => $documents, 'userId' => $userId];
					return [];
				}
			);

		$result = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Inzage',
			createdBy: 'alice',
		);

		$this->assertSame(4711, $revoked['link']['id']);
		$this->assertSame('alice', $revoked['userId']);
		$this->assertSame(CaseLinkShares::REASON_NOT_CONFIGURED, $result['reason']);
	}//end testAFailedRecordWithdrawsTheLinkThatWasAlreadyMinted()

	/**
	 * A link OpenRegister refuses to withdraw is named, never swallowed.
	 *
	 * A share reported as failed whose link still opens is the worst of the
	 * three outcomes, so the caller is told which ids are still live.
	 *
	 * @return void
	 */
	public function testALinkThatCouldNotBeWithdrawnIsNamedToTheCaller(): void {
		$this->accessLinks->method('mintCaseLink')->willReturn(self::MINTED);
		$this->linkShares->method('store')->willReturn(['error' => 'Could not record the share']);
		$this->linkShares->method('revokeMinted')->willReturn([4711]);

		$result = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Inzage',
			createdBy: 'alice',
		);

		$this->assertSame([4711], $result['linksNotRevoked']);
	}//end testALinkThatCouldNotBeWithdrawnIsNamedToTheCaller()

	/**
	 * A share that IS recorded withdraws nothing.
	 *
	 * The control. Without it, a rollback that fired on every mint would pass
	 * both assertions above while breaking every share on the instance.
	 *
	 * @return void
	 */
	public function testARecordedShareWithdrawsNothing(): void {
		$this->accessLinks->method('mintCaseLink')->willReturn(self::MINTED);
		$this->linkShares->method('store')->willReturn(['id' => 'share-1', 'caseId' => 'case-1']);

		$this->linkShares->expects($this->never())->method('revokeMinted');

		$result = $this->service->createTokenShare(
			caseId: 'case-1',
			label: 'Inzage',
			createdBy: 'alice',
		);

		$this->assertSame('https://example.test/s/anchor', $result['url']);
	}//end testARecordedShareWithdrawsNothing()
}//end class
