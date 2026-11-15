<?php

/**
 * A federated share asks the consent gate first, and a refusal is a 409, not a 502.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-consent-is-recorded-and-revoked-on-the-case-req-sdc-consent-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Controller\CaseFederationController;
use OCA\Dossiq\Kernel\Contracts\CaseCollaborationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseSharingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTransferServiceInterface;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\FederatedCaseShareService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The receiver is the party the consent must name; only an allowed share reaches the federation leaf.
 *
 * @covers \OCA\Dossiq\Service\CaseSharingService
 * @covers \OCA\Dossiq\Controller\CaseFederationController
 */
class FederatedShareNeedsConsentTest extends TestCase {

	/**
	 * The federation leaf.
	 *
	 * @var FederatedCaseShareService&MockObject
	 */
	private $federated;

	/**
	 * The sharing service with a gate answering the given verdict for case-1 and the receiver.
	 *
	 * @param array<string, mixed> $verdict The gate's verdict.
	 *
	 * @return CaseSharingService
	 */
	private function sharing(array $verdict): CaseSharingService {
		$this->federated = $this->createMock(FederatedCaseShareService::class);
		$gate = $this->createMock(CaseTransferConsentGate::class);
		$gate->expects($this->once())->method('assessSharing')
			->with('case-1', 'zorgpartner@cloud.example.nl')->willReturn($verdict);

		return new CaseSharingService(
			settingsService: $this->createMock(SettingsService::class),
			gateway: $this->createMock(OpenRegisterSharingGateway::class),
			accessPolicy: $this->createMock(CaseAccessPolicy::class),
			accessLinks: $this->createMock(CaseAccessLinkService::class),
			linkShares: $this->createMock(CaseLinkShares::class),
			federatedShares: $this->federated,
			consent: $gate,
			logger: new NullLogger(),
		);
	}//end sharing()

	/**
	 * Share case-1 with the receiver.
	 *
	 * @param CaseSharingService $sharing The service.
	 *
	 * @return array<string, mixed>
	 */
	private function share(CaseSharingService $sharing): array {
		return $sharing->createFederatedShare('case-1', 'zorgpartner@cloud.example.nl', ['title'], [], 'bekijken', 'pieter');
	}//end share()

	/**
	 * A refused consent stops the share before the federation leaf and says why.
	 *
	 * @return void
	 */
	public function testARefusedConsentNeverReachesTheLeaf(): void {
		$sharing = $this->sharing(['allowed' => false, 'rule' => 'consent-missing', 'sentence' => 'There is no valid consent for this party.']);
		$this->federated->expects($this->never())->method('createFederatedShare');

		$this->assertSame(
			['error' => 'There is no valid consent for this party.', 'rule' => 'consent-missing'],
			$this->share($sharing)
		);
	}//end testARefusedConsentNeverReachesTheLeaf()

	/**
	 * An allowed share is made by the federation leaf, as asked.
	 *
	 * @return void
	 */
	public function testAnAllowedShareIsMade(): void {
		$sharing = $this->sharing(['allowed' => true, 'rule' => '', 'sentence' => '']);
		$this->federated->expects($this->once())->method('createFederatedShare')
			->with('case-1', 'zorgpartner@cloud.example.nl', ['title'], [], 'bekijken', 'pieter')
			->willReturn(['id' => 'fed-1']);

		$this->assertSame(['id' => 'fed-1'], $this->share($sharing));
	}//end testAnAllowedShareIsMade()

	/**
	 * The controller answers a refusal with its rule as 409 and an upstream failure as 502.
	 *
	 * @return void
	 */
	public function testTheControllerTellsARefusalFromAFailure(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => (['caseId' => 'case-1', 'remoteCloudId' => 'zorgpartner@cloud.example.nl'][$key] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$sharing = $this->createMock(CaseSharingServiceInterface::class);
		$sharing->method('canUserAccessCase')->willReturn(true);
		$sharing->method('createFederatedShare')->willReturnOnConsecutiveCalls(
			['error' => 'There is no valid consent for this party.', 'rule' => 'consent-missing'],
			['error' => 'Federation is not available']
		);
		$controller = new CaseFederationController(
			request: $request,
			caseSharingService: $sharing,
			caseTransferService: $this->createMock(CaseTransferServiceInterface::class),
			collabService: $this->createMock(CaseCollaborationServiceInterface::class),
			userSession: $session,
			throttler: $this->createMock(IThrottler::class),
			logger: new NullLogger(),
		);

		$refused = $controller->createFederatedShare();
		$this->assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'There is no valid consent for this party.', 'rule' => 'consent-missing'],
			$refused->getData()
		);

		$broken = $controller->createFederatedShare();
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $broken->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Federation is not available'], $broken->getData());
	}//end testTheControllerTellsARefusalFromAFailure()
}//end class
