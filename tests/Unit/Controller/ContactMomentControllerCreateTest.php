<?php

/**
 * ContactMomentController::create, characterised.
 *
 * Pins what recording a contact moment writes and answers: the stored
 * fields, the citizen resolved from the caller identification when none was
 * given, the voorblad returned redacted for the caller and the lookup
 * recorded only when a citizen is known, the refusal and its record, and a
 * refused write as a 400.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ContactMomentController;
use OCA\Dossiq\Service\BurgerIdentificationService;
use OCA\Dossiq\Service\CaseVoorbladService;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\DoorverbindingService;
use OCA\Dossiq\Service\Kcc\GuardedCitizenLookup;
use OCA\Dossiq\Service\QuickActionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The contact moment write, allowed and refused.
 *
 * @covers \OCA\Dossiq\Controller\ContactMomentController
 */
class ContactMomentControllerCreateTest extends TestCase {

	/**
	 * The contact moment service.
	 *
	 * @var ContactMomentService&MockObject
	 */
	private ContactMomentService&MockObject $contactMoments;

	/**
	 * The voorblad service.
	 *
	 * @var CaseVoorbladService&MockObject
	 */
	private CaseVoorbladService&MockObject $voorblad;

	/**
	 * The burger identification service.
	 *
	 * @var BurgerIdentificationService&MockObject
	 */
	private BurgerIdentificationService&MockObject $burgers;

	/**
	 * The guarded lookups.
	 *
	 * @var GuardedCitizenLookup&MockObject
	 */
	private GuardedCitizenLookup&MockObject $lookups;

	/**
	 * The signed-in user.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->contactMoments = $this->createMock(ContactMomentService::class);
		$this->voorblad = $this->createMock(CaseVoorbladService::class);
		$this->burgers = $this->createMock(BurgerIdentificationService::class);
		$this->lookups = $this->createMock(GuardedCitizenLookup::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('kcc-1');
	}//end setUp()

	/**
	 * The controller over these request parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $signedIn Whether a user is signed in.
	 *
	 * @return ContactMomentController
	 */
	private function controller(array $params, bool $signedIn = true): ContactMomentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->user : null);

		return new ContactMomentController(
			appName: 'dossiq',
			request: $request,
			contactMomentService: $this->contactMoments,
			caseVoorbladService: $this->voorblad,
			quickActionService: $this->createMock(QuickActionService::class),
			transferService: $this->createMock(DoorverbindingService::class),
			burgerService: $this->burgers,
			userSession: $session,
			lookups: $this->lookups,
		);
	}//end controller()

	/**
	 * A known citizen: the fields stored, the voorblad redacted, the lookup recorded.
	 *
	 * @return void
	 */
	public function testAKnownCitizensMomentIsStoredWithTheirVoorblad(): void {
		$this->lookups->method('isAllowed')->willReturn(true);
		$this->burgers->expects($this->never())->method('lookupByIdentifier');
		$this->contactMoments->expects($this->once())->method('createContactMoment')->with(
			[
				'notificationChannel' => 'telefoon',
				'direction' => 'inbound',
				'callerIdentification' => '0612345678',
				'nature' => 'informatieverzoek',
				'summary' => 'Vraag over vergunning',
				'kccEmployeeId' => 'kcc-1',
				'transcript' => '',
				'geidentificeerdeBurgerId' => 'BSN-1',
				'identificationMethod' => 'digid',
			]
		)->willReturn(['id' => 'cm-1']);
		$this->voorblad->method('getCaseVoorblad')->with('BSN-1')->willReturn(['burgerId' => 'BSN-1', 'bsn' => '123']);
		$this->lookups->method('redactForCaller')->willReturnCallback(
			fn (IUser $user, array $payload): array => array_diff_key($payload, ['bsn' => true])
		);
		$this->lookups->expects($this->once())->method('recordAnswer')->with($this->user, 'BSN-1');

		$response = $this->controller(
			[
				'notificationChannel' => 'telefoon',
				'callerIdentification' => '0612345678',
				'summary' => 'Vraag over vergunning',
				'geidentificeerdeBurgerId' => 'BSN-1',
				'identificationMethod' => 'digid',
			]
		)->create();

		$this->assertSame(['contactmoment' => ['id' => 'cm-1'], 'voorblad' => ['burgerId' => 'BSN-1']], $response->getData());
	}//end testAKnownCitizensMomentIsStoredWithTheirVoorblad()

	/**
	 * Without a citizen, the caller identification resolves one by identification questions.
	 *
	 * @return void
	 */
	public function testTheCallerIdentificationResolvesTheCitizen(): void {
		$this->lookups->method('isAllowed')->willReturn(true);
		$this->burgers->method('lookupByIdentifier')->with('0612345678')->willReturn('BSN-2');
		$this->contactMoments->expects($this->once())->method('createContactMoment')->with(
			$this->callback(
				fn (array $data): bool => $data['geidentificeerdeBurgerId'] === 'BSN-2' && $data['identificationMethod'] === 'identificatievragen'
			)
		)->willReturn(['id' => 'cm-2']);
		$this->voorblad->method('getCaseVoorblad')->willReturn(['burgerId' => 'BSN-2']);
		$this->lookups->method('redactForCaller')->willReturnArgument(1);
		$this->lookups->expects($this->once())->method('recordAnswer')->with($this->user, 'BSN-2');

		$this->assertSame(['burgerId' => 'BSN-2'], $this->controller(['callerIdentification' => '0612345678'])->create()->getData()['voorblad']);
	}//end testTheCallerIdentificationResolvesTheCitizen()

	/**
	 * An unresolved caller is stored without a citizen, and nothing is looked up or recorded.
	 *
	 * @return void
	 */
	public function testAnUnresolvedCallerHasNoVoorblad(): void {
		$this->lookups->method('isAllowed')->willReturn(true);
		$this->burgers->method('lookupByIdentifier')->willReturn('');
		$this->contactMoments->expects($this->once())->method('createContactMoment')->with(
			$this->callback(
				fn (array $data): bool => $data['geidentificeerdeBurgerId'] === null && $data['identificationMethod'] === 'non_geidentificeerd'
			)
		)->willReturn(['id' => 'cm-3']);
		$this->voorblad->expects($this->never())->method('getCaseVoorblad');
		$this->lookups->expects($this->never())->method('recordAnswer');

		$this->assertSame(['contactmoment' => ['id' => 'cm-3'], 'voorblad' => null], $this->controller(['callerIdentification' => '0699'])->create()->getData());
	}//end testAnUnresolvedCallerHasNoVoorblad()

	/**
	 * Refusals: 401 without a session, 403 recorded without the KCC role, 400 on a refused write.
	 *
	 * @return void
	 */
	public function testTheRefusals(): void {
		$this->contactMoments->expects($this->never())->method('createContactMoment');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller([], false)->create()->getStatus());

		$this->lookups->method('isAllowed')->willReturn(false);
		$this->lookups->expects($this->once())->method('recordRefusal')->with('kcc-1', 'BSN-3');
		$refused = $this->controller(['geidentificeerdeBurgerId' => 'BSN-3'])->create();
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $refused->getData());
	}//end testTheRefusals()

	/**
	 * A refused write is a 400 with its message, and no voorblad is read.
	 *
	 * @return void
	 */
	public function testARefusedWriteIs400(): void {
		$this->lookups->method('isAllowed')->willReturn(true);
		$this->contactMoments->method('createContactMoment')->willThrowException(new RuntimeException('summary required'));
		$this->voorblad->expects($this->never())->method('getCaseVoorblad');

		$response = $this->controller(['geidentificeerdeBurgerId' => 'BSN-1'])->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'summary required'], $response->getData());
	}//end testARefusedWriteIs400()
}//end class
