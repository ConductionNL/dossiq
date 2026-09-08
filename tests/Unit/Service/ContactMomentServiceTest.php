<?php

/**
 * ContactMomentService Unit Tests.
 *
 * Covers the case reference a contact moment carries: `case` is written
 * through, `relatedCases` is seeded from it when empty, and the KCC fields a
 * form logged from the case leaves out are defaulted.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Typed stub for the OpenRegister ObjectService.
 *
 * ContactMomentService calls saveObject() with named arguments; a magic mock
 * rejects those, so the mock is generated from this signature instead.
 */
interface ContactMomentObjectServiceStub {
	/**
	 * Save or update an object.
	 *
	 * @param array<string,mixed> $object   Object data.
	 * @param string              $register Register slug.
	 * @param string              $schema   Schema slug.
	 * @param string|null         $uuid     Optional object UUID for updates.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array;
}//end interface

/**
 * Unit tests for ContactMomentService::createContactMoment().
 *
 * @covers \OCA\Dossiq\Service\ContactMomentService
 */
class ContactMomentServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The record handed to saveObject by the last create call.
	 *
	 * @var array<string,mixed>
	 */
	private array $saved = [];

	/**
	 * The service under test.
	 *
	 * @var ContactMomentService
	 */
	private ContactMomentService $service;

	/**
	 * Set up the service with a capturing ObjectService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$objectService = $this->createMock(ContactMomentObjectServiceStub::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->saved = $object;
				return $object;
			}
		);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['contactmoment_schema', '', 'contactmoment'],
		]);

		$this->service = new ContactMomentService(
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Sign a user in for the tests that rely on the employee default.
	 *
	 * @param string $uid The signed-in user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A contact logged on a case carries the case and seeds relatedCases.
	 *
	 * @return void
	 */
	public function testCaseSeedsRelatedCasesWhenTheListIsEmpty(): void {
		$this->signIn('handler');

		$result = $this->service->createContactMoment([
			'notificationChannel' => 'phone',
			'direction' => 'inbound',
			'summary' => 'Asked about the hearing date',
			'case' => 'case-uuid-1',
		]);

		$this->assertSame('case-uuid-1', $this->saved['case']);
		$this->assertSame(['case-uuid-1'], $this->saved['relatedCases']);
		$this->assertSame('case-uuid-1', $result['case']);
	}//end testCaseSeedsRelatedCasesWhenTheListIsEmpty()

	/**
	 * A KCC write that fills relatedCases keeps its own list.
	 *
	 * @return void
	 */
	public function testAFilledRelatedCasesListIsLeftAlone(): void {
		$this->signIn('handler');

		$this->service->createContactMoment([
			'notificationChannel' => 'phone',
			'kccEmployeeId' => 'kcc-agent',
			'relatedCases' => ['case-a', 'case-b'],
		]);

		$this->assertSame(['case-a', 'case-b'], $this->saved['relatedCases']);
		$this->assertArrayNotHasKey('case', $this->saved);
	}//end testAFilledRelatedCasesListIsLeftAlone()

	/**
	 * A case and a filled list together leave the list untouched.
	 *
	 * @return void
	 */
	public function testACaseDoesNotOverwriteAFilledList(): void {
		$this->signIn('handler');

		$this->service->createContactMoment([
			'notificationChannel' => 'email',
			'case' => 'case-uuid-1',
			'relatedCases' => ['case-a'],
		]);

		$this->assertSame('case-uuid-1', $this->saved['case']);
		$this->assertSame(['case-a'], $this->saved['relatedCases']);
	}//end testACaseDoesNotOverwriteAFilledList()

	/**
	 * Without a case, no case is written and the list stays empty.
	 *
	 * @return void
	 */
	public function testNoCaseWritesNoCase(): void {
		$this->signIn('handler');

		$this->service->createContactMoment([
			'notificationChannel' => 'balie',
			'summary' => 'Walk-in at the counter',
		]);

		$this->assertArrayNotHasKey('case', $this->saved);
		$this->assertSame([], $this->saved['relatedCases']);
	}//end testNoCaseWritesNoCase()

	/**
	 * The form payload, channel and direction and summary only, saves.
	 *
	 * @return void
	 */
	public function testTheFormPayloadSavesWithTheKccFieldsDefaulted(): void {
		$this->signIn('admin');

		$this->service->createContactMoment([
			'notificationChannel' => 'phone',
			'direction' => 'inbound',
			'summary' => 'Asked about the hearing date',
			'case' => 'case-uuid-1',
		]);

		$this->assertSame('admin', $this->saved['kccEmployeeId']);
		$this->assertSame('non_geidentificeerd', $this->saved['identificationMethod']);
		$this->assertSame('informatieverzoek', $this->saved['nature']);
		$this->assertSame('inbound', $this->saved['direction']);
		$this->assertSame('Asked about the hearing date', $this->saved['summary']);
	}//end testTheFormPayloadSavesWithTheKccFieldsDefaulted()

	/**
	 * A supplied employee, method and nature are never overwritten.
	 *
	 * @return void
	 */
	public function testSuppliedKccFieldsWin(): void {
		$this->signIn('admin');

		$this->service->createContactMoment([
			'notificationChannel' => 'phone',
			'kccEmployeeId' => 'kcc-agent',
			'identificationMethod' => 'digid',
			'nature' => 'statusverzoek',
		]);

		$this->assertSame('kcc-agent', $this->saved['kccEmployeeId']);
		$this->assertSame('digid', $this->saved['identificationMethod']);
		$this->assertSame('statusverzoek', $this->saved['nature']);
	}//end testSuppliedKccFieldsWin()

	/**
	 * With nobody signed in the employee stays required.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallStillNeedsAnEmployee(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/required/i');

		$this->service->createContactMoment([
			'notificationChannel' => 'phone',
			'case' => 'case-uuid-1',
		]);
	}//end testAnAnonymousCallStillNeedsAnEmployee()
}//end class
