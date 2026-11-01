<?php

/**
 * Acting-user trace of the case transfer routes
 *
 * Drives the local initiate, the local accept/reject and the federated
 * accept/reject through the real CaseTransferService, with forged actor
 * fields in the request (through getParam() and getParams()) and an access
 * check that answers yes for the session user only. Every write must name
 * the session user, the stored initiator or the verified partner, and never
 * a value the caller sent.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/specs/federated-case-collaboration/spec.md#local-transfer-acceptreject-requires-case-access-pre-existing-gap-fix
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseFederationController;
use OCA\Dossiq\Controller\CaseSharingController;
use OCA\Dossiq\Service\CaseCollaborationService;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Tests\Support\MakesRecordedCaseTransfers;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\CaseSharingController
 * @covers \OCA\Dossiq\Controller\CaseFederationController
 * @covers \OCA\Dossiq\Service\CaseTransferService
 * @covers \OCA\Dossiq\Service\CaseTransfer\TransferStore
 */
class CaseTransferActingUserTest extends TestCase {
	use MakesRecordedCaseTransfers;

	private const FORGED = [
		'userId' => 'mallory',
		'requestedBy' => 'mallory',
		'owner' => 'mallory',
		'initiatedBy' => 'mallory',
		'createdBy' => 'mallory',
		'actor' => 'mallory',
	];

	private const PENDING = [
		'id' => 't-1',
		'caseId' => 'case-1',
		'targetOrganization' => 'org-b',
		'reason' => 'moving',
		'initiatedBy' => 'bob',
		'status' => 'pending',
		'custodyAuditTrail' => [],
	];

	/**
	 * Who the access check was asked about, in order.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $accessAsked = [];

	/**
	 * A local initiate records the session user, whatever actor the request names.
	 *
	 * @return void
	 */
	public function testInitiateRecordsTheSessionUserNotAForgedOne(): void {
		$response = $this->sharing(session: 'alice', params: self::FORGED + $this->initiateParams())->initiateTransfer();

		$this->assertSame(200, $response->getStatus());
		$saved = array_values($this->objects->saved);
		$this->assertCount(1, $saved);
		$this->assertSame('alice', $saved[0]['initiatedBy']);
		$this->assertSame('alice', $saved[0]['custodyAuditTrail'][0]['actor']);
		$this->assertSame('alice', $this->auditActor(action: 'case_transfer_initiated'));
		$this->assertSame([['case-1', 'alice']], $this->accessAsked);
		$this->assertStringNotContainsString('mallory', (string)json_encode($this->calls));
	}//end testInitiateRecordsTheSessionUserNotAForgedOne()

	/**
	 * A caller without access cannot initiate by naming someone who has it.
	 *
	 * @return void
	 */
	public function testInitiateRefusesACallerWhoForgesAnAuthorisedOwner(): void {
		$forged = array_map(static fn (): string => 'alice', self::FORGED);
		$response = $this->sharing(session: 'mallory', params: $forged + $this->initiateParams())->initiateTransfer();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->calls);
		$this->assertSame([['case-1', 'mallory']], $this->accessAsked);
	}//end testInitiateRefusesACallerWhoForgesAnAuthorisedOwner()

	/**
	 * Without a session nothing is asked or written.
	 *
	 * @return void
	 */
	public function testInitiateWithoutASessionWritesNothing(): void {
		$response = $this->sharing(session: null, params: self::FORGED + $this->initiateParams())->initiateTransfer();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->accessAsked);
	}//end testInitiateWithoutASessionWritesNothing()

	/**
	 * A local accept moves custody for the stored initiator and stays local,
	 * even when the request names a remote and another actor.
	 *
	 * @return void
	 */
	public function testLocalAcceptActsForTheStoredInitiatorNotTheRequest(): void {
		$params = self::FORGED + ['action' => 'accept', 'remoteCloudId' => 'evil@forged.example', 'sharedWith' => 'evil@forged.example'];
		$response = $this->sharing(session: 'alice', params: $params, stored: self::PENDING)->handleTransfer('t-1');

		$this->assertSame(200, $response->getStatus());
		$saved = $this->objects->saved['t-1'];
		$this->assertSame('accepted', $saved['status']);
		$this->assertSame(['event' => 'accepted', 'actor' => '', 'actorType' => 'local', 'cloudId' => ''], array_diff_key($saved['custodyAuditTrail'][0], ['timestamp' => 1]));
		$this->assertSame('bob', $this->custodyMovedBy());
		$this->assertSame('local', $this->auditActor(action: 'case_transfer_accepted'));
		$this->assertSame([['case-1', 'alice']], $this->accessAsked);
		$this->assertStringNotContainsString('mallory', (string)json_encode($this->calls));
		$this->assertStringNotContainsString('evil@', (string)json_encode($this->calls));
	}//end testLocalAcceptActsForTheStoredInitiatorNotTheRequest()

	/**
	 * A caller without access to the transfer's case cannot accept it.
	 *
	 * @return void
	 */
	public function testLocalAcceptRefusesACallerWithoutAccess(): void {
		$forged = array_map(static fn (): string => 'alice', self::FORGED);
		$response = $this->sharing(session: 'mallory', params: $forged + ['action' => 'accept'], stored: self::PENDING)->handleTransfer('t-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->objects->saved);
		$this->assertNull($this->custodyMovedBy());
		$this->assertSame([['case-1', 'mallory']], $this->accessAsked);
	}//end testLocalAcceptRefusesACallerWithoutAccess()

	/**
	 * A local reject records the reason, stays local and moves no custody.
	 *
	 * @return void
	 */
	public function testLocalRejectKeepsTheReasonAndNoForgedActor(): void {
		$params = self::FORGED + ['action' => 'reject', 'reason' => 'not ours', 'remoteCloudId' => 'evil@forged.example'];
		$response = $this->sharing(session: 'alice', params: $params, stored: self::PENDING)->handleTransfer('t-1');

		$this->assertSame(200, $response->getStatus());
		$saved = $this->objects->saved['t-1'];
		$this->assertSame('rejected', $saved['status']);
		$this->assertSame('not ours', $saved['rejectionReason']);
		$this->assertSame('local', $saved['custodyAuditTrail'][0]['actorType']);
		$this->assertNull($this->custodyMovedBy());
		$this->assertStringNotContainsString('mallory', (string)json_encode($this->calls));
		$this->assertStringNotContainsString('evil@', (string)json_encode($this->calls));
	}//end testLocalRejectKeepsTheReasonAndNoForgedActor()

	/**
	 * A remote accept acts for the partner the token resolved to, not for a
	 * remote or actor named in the request.
	 *
	 * @return void
	 */
	public function testFederatedAcceptActsForTheVerifiedPartnerOnly(): void {
		$params = self::FORGED + ['action' => 'accept', 'remoteCloudId' => 'evil@forged.example', 'sharedWith' => 'evil@forged.example'];
		$response = $this->federation(params: $params, behaviour: [])->handleFederatedTransfer('tok', 't-1');

		$this->assertSame(200, $response->getStatus());
		$entry = $this->objects->saved['t-1']['custodyAuditTrail'][0];
		$this->assertSame(['partner@remote.example', 'remote', 'partner@remote.example'], [$entry['actor'], $entry['actorType'], $entry['cloudId']]);
		$this->assertSame('partner@remote.example', $this->custodyMovedBy());
		$this->assertSame('partner@remote.example', $this->auditActor(action: 'case_transfer_accepted'));
		$this->assertStringNotContainsString('mallory', (string)json_encode($this->calls));
		$this->assertStringNotContainsString('evil@', (string)json_encode($this->calls));
	}//end testFederatedAcceptActsForTheVerifiedPartnerOnly()

	/**
	 * A remote reject acts for the verified partner too.
	 *
	 * @return void
	 */
	public function testFederatedRejectActsForTheVerifiedPartnerOnly(): void {
		$params = self::FORGED + ['action' => 'reject', 'reason' => 'no', 'remoteCloudId' => 'evil@forged.example'];
		$response = $this->federation(params: $params, behaviour: [])->handleFederatedTransfer('tok', 't-1');

		$this->assertSame(200, $response->getStatus());
		$saved = $this->objects->saved['t-1'];
		$this->assertSame(['rejected', 'no', 'partner@remote.example'], [$saved['status'], $saved['rejectionReason'], $saved['custodyAuditTrail'][0]['actor']]);
		$this->assertNull($this->custodyMovedBy());
		$this->assertStringNotContainsString('evil@', (string)json_encode($this->calls));
	}//end testFederatedRejectActsForTheVerifiedPartnerOnly()

	/**
	 * A token that resolves to nothing reads and writes nothing.
	 *
	 * @return void
	 */
	public function testFederatedCallWithAnUnresolvedTokenWritesNothing(): void {
		$params = self::FORGED + ['action' => 'accept', 'remoteCloudId' => 'partner@remote.example'];
		$response = $this->federation(params: $params, behaviour: ['resolve' => null])->handleFederatedTransfer('tok', 't-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->objects->saved);
		$this->assertSame([['TransferShareBroker.resolveTransferShare' => ['shareToken' => 'tok', 'transferId' => 't-1']]], $this->calls);
	}//end testFederatedCallWithAnUnresolvedTokenWritesNothing()

	/**
	 * The fields a valid initiate sends.
	 *
	 * @return array<string, string>
	 */
	private function initiateParams(): array {
		return [
			'caseId' => 'case-1',
			'sourceOrganization' => 'org-a',
			'targetOrganization' => 'org-b',
			'reason' => 'moving',
			'requestedDate' => '2026-11-01',
		];
	}//end initiateParams()

	/**
	 * The sharing controller over the recorded transfer service.
	 *
	 * @param string|null          $session The session uid, or null for none.
	 * @param array<string, mixed> $params  The request parameters.
	 * @param array<string, mixed> $stored  The transfer already stored, if any.
	 *
	 * @return CaseSharingController
	 */
	private function sharing(?string $session, array $params, array $stored = []): CaseSharingController {
		$behaviour = ($stored === []) ? [] : ['find' => ['t-1' => $stored]];
		return new CaseSharingController(
			request: $this->request(params: $params),
			caseSharingService: $this->accessFor(),
			caseTransferService: $this->recordedTransferService(behaviour: $behaviour),
			userSession: $this->session(uid: $session),
		);
	}//end sharing()

	/**
	 * The federation controller over the recorded transfer service, with no session.
	 *
	 * @param array<string, mixed> $params    The request parameters.
	 * @param array<string, mixed> $behaviour Extra collaborator behaviour.
	 *
	 * @return CaseFederationController
	 */
	private function federation(array $params, array $behaviour): CaseFederationController {
		return new CaseFederationController(
			request: $this->request(params: $params),
			caseSharingService: $this->accessFor(),
			caseTransferService: $this->recordedTransferService(behaviour: $behaviour + ['find' => ['t-1' => self::PENDING]]),
			collabService: $this->createMock(CaseCollaborationService::class),
			userSession: $this->session(uid: null),
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end federation()

	/**
	 * A request answering the same parameters through getParam() and getParams().
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);
		return $request;
	}//end request()

	/**
	 * A sharing service whose access check says yes for alice only.
	 *
	 * @return CaseSharingService
	 */
	private function accessFor(): CaseSharingService {
		$this->accessAsked = [];
		$sharing = $this->createMock(CaseSharingService::class);
		$sharing->method('canUserAccessCase')->willReturnCallback(
			function (string $caseId, string $userId): bool {
				$this->accessAsked[] = [$caseId, $userId];
				return $userId === 'alice';
			}
		);
		return $sharing;
	}//end accessFor()

	/**
	 * A session for one uid, or none.
	 *
	 * @param string|null $uid The uid.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * Who the custody move named, or null when custody did not move.
	 *
	 * @return string|null
	 */
	private function custodyMovedBy(): ?string {
		foreach ($this->calls as $call) {
			if (isset($call['CaseCustodyChain.move']) === true) {
				return $call['CaseCustodyChain.move']['movedBy'];
			}
		}

		return null;
	}//end custodyMovedBy()

	/**
	 * The actor of the audit event with one action.
	 *
	 * @param string $action The action.
	 *
	 * @return string|null
	 */
	private function auditActor(string $action): ?string {
		foreach ($this->calls as $call) {
			if (($call['TenantAuditTrailService.emit']['payload']['action'] ?? '') === $action) {
				return $call['TenantAuditTrailService.emit']['payload']['actor'];
			}
		}

		return null;
	}//end auditActor()
}//end class
