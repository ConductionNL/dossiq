<?php

/**
 * Acting-user trace of the case share routes
 *
 * Drives the token share, the partner share and the share revoke through the
 * real CaseSharingService, with forged actor fields in the request (through
 * getParam() and getParams()) and an access policy that answers yes for the
 * session user only. Every link, record and revoke must name the session
 * user, never a value the caller sent.
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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseSharingController;
use OCA\Dossiq\Service\CaseTransferService;
use OCA\Dossiq\Tests\Support\MakesRecordedCaseShares;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\CaseSharingController
 * @covers \OCA\Dossiq\Service\CaseSharingService
 */
class CaseShareActingUserTest extends TestCase {
	use MakesRecordedCaseShares;

	private const FORGED = [
		'userId' => 'mallory',
		'requestedBy' => 'mallory',
		'owner' => 'mallory',
		'createdBy' => 'mallory',
		'revokedBy' => 'mallory',
		'actor' => 'mallory',
	];

	/**
	 * A token share mints and records every link for the session user.
	 *
	 * @return void
	 */
	public function testTokenShareActsForTheSessionUserOnly(): void {
		$params = self::FORGED + ['caseId' => 'case-1', 'label' => 'For the lawyer', 'sharedDocuments' => ['f-1']];
		$response = $this->controller(session: 'alice', params: $params, behaviour: [])->createShare();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['alice'], $this->argument(call: 'CaseAccessLinkService.mintCaseLink', name: 'userId'));
		$this->assertSame(['alice'], $this->argument(call: 'CaseAccessLinkService.mintFileLink', name: 'userId'));
		$this->assertSame(['alice'], $this->argument(call: 'CaseLinkShares.store', name: 'createdBy'));
		$this->assertSame([['case-1', 'alice']], $this->argument(call: 'CaseAccessPolicy.canUserAccessCase', name: '*'));
		$this->assertStringNotContainsString('mallory', (string)json_encode($this->calls));
	}//end testTokenShareActsForTheSessionUserOnly()

	/**
	 * A token share that cannot be recorded withdraws its links as the session user.
	 *
	 * @return void
	 */
	public function testAnUnrecordedTokenShareIsWithdrawnByTheSessionUser(): void {
		$params = self::FORGED + ['caseId' => 'case-1', 'sharedDocuments' => ['f-1']];
		$this->controller(session: 'alice', params: $params, behaviour: ['stored' => ['error' => 'no schema']])->createShare();

		$this->assertSame(['alice'], $this->argument(call: 'CaseLinkShares.revokeMinted', name: 'userId'));
		$this->assertStringNotContainsString('mallory', (string)json_encode($this->calls));
	}//end testAnUnrecordedTokenShareIsWithdrawnByTheSessionUser()

	/**
	 * A partner share is recorded as created by the session user.
	 *
	 * @return void
	 */
	public function testPartnerShareActsForTheSessionUserOnly(): void {
		$params = self::FORGED + ['caseId' => 'case-1', 'shareType' => 'partner', 'partnerId' => 'org-b'];
		$response = $this->controller(session: 'alice', params: $params, behaviour: [])->createShare();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('alice', $this->objects->saved[0]['createdBy']);
		$this->assertStringNotContainsString('mallory', (string)json_encode($this->calls));
	}//end testPartnerShareActsForTheSessionUserOnly()

	/**
	 * A caller without access cannot share by naming someone who has it.
	 *
	 * @return void
	 */
	public function testShareRefusesACallerWhoForgesAnAuthorisedOwner(): void {
		$forged = array_map(static fn (): string => 'alice', self::FORGED);
		foreach (['token', 'partner'] as $type) {
			$params = $forged + ['caseId' => 'case-1', 'shareType' => $type, 'partnerId' => 'org-b'];
			$response = $this->controller(session: 'mallory', params: $params, behaviour: [])->createShare();

			$this->assertSame(403, $response->getStatus(), $type);
			$this->assertSame([['CaseAccessPolicy.canUserAccessCase' => ['caseId' => 'case-1', 'userId' => 'mallory']]], $this->calls, $type);
		}
	}//end testShareRefusesACallerWhoForgesAnAuthorisedOwner()

	/**
	 * A revoke records the session user as revoker and pulls links as them.
	 *
	 * @return void
	 */
	public function testRevokeActsForTheSessionUserOnly(): void {
		$stored = ['find' => ['share-7' => ['id' => 'share-7', 'caseId' => 'case-1', 'status' => 'active']]];
		$response = $this->controller(session: 'alice', params: self::FORGED, behaviour: $stored)->revokeShare('share-7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('alice', $this->objects->saved[0]['revokedBy']);
		$this->assertSame(['alice'], $this->argument(call: 'CaseLinkShares.revokeLinksOf', name: 'userId'));
		$this->assertStringNotContainsString('mallory', (string)json_encode($this->calls));
	}//end testRevokeActsForTheSessionUserOnly()

	/**
	 * A caller without access to the share's case cannot revoke it.
	 *
	 * @return void
	 */
	public function testRevokeRefusesACallerWithoutAccess(): void {
		$forged = array_map(static fn (): string => 'alice', self::FORGED);
		$stored = ['find' => ['share-7' => ['id' => 'share-7', 'caseId' => 'case-1', 'status' => 'active']]];
		$response = $this->controller(session: 'mallory', params: $forged, behaviour: $stored)->revokeShare('share-7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->objects->saved);
		$this->assertSame([], $this->argument(call: 'CaseLinkShares.revokeLinksOf', name: 'userId'));
	}//end testRevokeRefusesACallerWithoutAccess()

	/**
	 * The sharing controller over the recorded sharing service.
	 *
	 * @param string               $session   The session uid.
	 * @param array<string, mixed> $params    The request parameters.
	 * @param array<string, mixed> $behaviour How the collaborators answer.
	 *
	 * @return CaseSharingController
	 */
	private function controller(string $session, array $params, array $behaviour): CaseSharingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($session);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new CaseSharingController(
			request: $request,
			caseSharingService: $this->recordedSharingService(behaviour: $behaviour),
			caseTransferService: $this->createMock(CaseTransferService::class),
			userSession: $userSession,
		);
	}//end controller()

	/**
	 * One argument of every recorded call to one method, or all its arguments for '*'.
	 *
	 * @param string $call The recorded call name.
	 * @param string $name The argument name.
	 *
	 * @return array<int, mixed>
	 */
	private function argument(string $call, string $name): array {
		$found = [];
		foreach ($this->calls as $entry) {
			if (isset($entry[$call]) === true) {
				$found[] = ($name === '*') ? array_values($entry[$call]) : $entry[$call][$name];
			}
		}

		return $found;
	}//end argument()
}//end class
