<?php

/**
 * Five read endpoints first read the requested object with the signed-in caller's own rights.
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
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseNextStatusController;
use OCA\Dossiq\Controller\CaseTypeTermsController;
use OCA\Dossiq\Controller\PartyTicketController;
use OCA\Dossiq\Controller\PersonReachabilityController;
use OCA\Dossiq\Service\Access\ReadableObjects;
use OCA\Dossiq\Service\Cases\CaseNextStatus;
use OCA\Dossiq\Service\CaseType\CaseTypeTerms;
use OCA\Dossiq\Service\People\PersonReachability;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * No session answers 401; an object the caller may not read answers 404, as a missing one does,
 * and the endpoint reads nothing further; a forged user id changes nothing; a failed read answers 503.
 *
 * @covers \OCA\Dossiq\Controller\CaseNextStatusController
 * @covers \OCA\Dossiq\Controller\CaseTypeTermsController
 * @covers \OCA\Dossiq\Controller\PartyTicketController
 * @covers \OCA\Dossiq\Controller\PersonReachabilityController
 */
final class ReadGuardedEndpointsTest extends TestCase {

	/**
	 * The signed-in caller, or null for no session.
	 *
	 * @var string|null
	 */
	private ?string $actingUid = 'bob';

	/**
	 * The objects the acting user may read, per schema.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $readableBy = [];

	/**
	 * Whether the readability read fails.
	 *
	 * @var bool
	 */
	private bool $readFails = false;

	/**
	 * Whether the endpoint went on to read its answer.
	 *
	 * @var bool
	 */
	private bool $answered = false;

	/**
	 * Whether the endpoint's own read finds nothing, as for a missing object.
	 *
	 * @var bool
	 */
	private bool $missing = false;

	/**
	 * The keys the ticket gateway was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $askedRefs = [];

	/**
	 * The session of the acting user.
	 *
	 * @return IUserSession
	 */
	private function session(): IUserSession {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$uid = $this->actingUid;
		$session->method('getUser')->willReturnCallback(
			function () use ($uid): ?IUser {
				if ($uid === null) {
					return null;
				}

				$user = $this->createMock(originalClassName: IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);

		return $session;
	}//end session()

	/**
	 * Readability as OpenRegister answers it for the session's user: bob may read what `readableBy` lists.
	 *
	 * @param IUserSession $session The session the read runs under.
	 *
	 * @return ReadableObjects
	 */
	private function readable(IUserSession $session): ReadableObjects {
		$readable = $this->createMock(originalClassName: ReadableObjects::class);
		$allowed = function (string $schema, string $id) use ($session): bool {
			if ($this->readFails === true) {
				throw new RuntimeException('register unreachable');
			}

			return ($session->getUser()?->getUID() === 'bob' && in_array(trim($id), ($this->readableBy[$schema] ?? []), true) === true);
		};
		$readable->method('hasCaller')->willReturnCallback(static fn (): bool => $session->getUser() !== null);
		$readable->method('findsAsCaller')->willReturnCallback($allowed);
		$readable->method('readableMasterEntities')->willReturnCallback(
			static fn (array $refs): array => array_values(array_filter($refs, static fn (string $ref): bool => $allowed('masterEntity', $ref)))
		);

		return $readable;
	}//end readable()

	/**
	 * A request that forges another user id and carries the given parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest
	 */
	private function request(array $params = []): IRequest {
		$request = $this->createMock(originalClassName: IRequest::class);
		$params += ['userId' => 'admin', 'uid' => 'admin'];
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return $request;
	}//end request()

	/**
	 * Calls one endpoint as the current acting user.
	 *
	 * @param string $endpoint The endpoint: next-status, case-type-terms, reachability, ticket-list or ticket-counts.
	 * @param string $id       The requested object.
	 *
	 * @return JSONResponse
	 */
	private function call(string $endpoint, string $id): JSONResponse {
		$session = $this->session();
		$readable = $this->readable(session: $session);
		$answer = function (): ?array {
			$this->answered = true;
			return ($this->missing === true) ? null : ['answer' => true];
		};

		if ($endpoint === 'next-status') {
			$service = $this->createMock(originalClassName: CaseNextStatus::class);
			$service->method('forCase')->willReturnCallback($answer);
			return (new CaseNextStatusController(appName: 'dossiq', request: $this->request(), nextStatus: $service, readable: $readable))->show(caseId: $id);
		}

		if ($endpoint === 'case-type-terms') {
			$service = $this->createMock(originalClassName: CaseTypeTerms::class);
			$service->method('termsFor')->willReturnCallback($answer);
			return (new CaseTypeTermsController(appName: 'dossiq', request: $this->request(), terms: $service, readable: $readable))->index(id: $id);
		}

		if ($endpoint === 'reachability') {
			$service = $this->createMock(originalClassName: PersonReachability::class);
			$service->method('forPerson')->willReturnCallback($answer);
			return (new PersonReachabilityController(appName: 'dossiq', request: $this->request(), reachability: $service, readable: $readable))->show(id: $id);
		}

		$gateway = $this->createMock(originalClassName: PipelinqGateway::class);
		$tickets = function (array $refs) use ($answer): array {
			$answer();
			$this->askedRefs = $refs;
			return array_fill_keys($refs, [['id' => 't-1']]);
		};
		$gateway->method('openTicketsByMasterEntity')->willReturnCallback($tickets);
		$gateway->method('ticketsByMasterEntity')->willReturnCallback(static fn (array $refs, string $status): array => $tickets($refs));
		$controller = new PartyTicketController(
			appName: 'dossiq',
			request: $this->request(params: ['key' => $id, 'keys' => explode(',', $id)]),
			gateway: $gateway,
			readable: $readable
		);

		return ($endpoint === 'ticket-list') ? $controller->openTickets() : $controller->openTicketCounts();
	}//end call()

	/**
	 * The five endpoints, each with the schema its object lives in.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function endpoints(): array {
		return [
			'next status' => ['next-status', 'case'],
			'case type terms' => ['case-type-terms', 'caseType'],
			'reachability' => ['reachability', 'brpPerson'],
			'ticket list' => ['ticket-list', 'masterEntity'],
			'ticket counts' => ['ticket-counts', 'masterEntity'],
		];
	}//end endpoints()

	/**
	 * An object the acting user may read is answered.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $schema   The schema of its object.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testAReadableObjectIsAnswered(string $endpoint, string $schema): void {
		$this->readableBy = [$schema => ['o-1']];

		$response = $this->call(endpoint: $endpoint, id: 'o-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertTrue(condition: $this->answered);
	}//end testAReadableObjectIsAnswered()

	/**
	 * An object the acting user may not read answers as a missing one, and nothing further is read.
	 *
	 * The request forges `userId=admin`; the read runs as the session's user all the same.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $schema   The schema of its object.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testAnUnreadableObjectIsNotFoundAndNotRead(string $endpoint, string $schema): void {
		$this->readableBy = [$schema => ['o-1']];

		$response = $this->call(endpoint: $endpoint, id: 'o-2');

		self::assertFalse(condition: $this->answered);
		if ($endpoint === 'ticket-counts') {
			self::assertSame(expected: ['available' => true, 'values' => []], actual: $response->getData());
			return;
		}

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testAnUnreadableObjectIsNotFoundAndNotRead()

	/**
	 * Another signed-in user is refused what bob may read, whatever user id the request names.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $schema   The schema of its object.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testAnotherUserIsRefusedWhatBobMayRead(string $endpoint, string $schema): void {
		$this->readableBy = [$schema => ['o-1']];
		$this->actingUid = 'carla';

		$this->call(endpoint: $endpoint, id: 'o-1');

		self::assertFalse(condition: $this->answered);
	}//end testAnotherUserIsRefusedWhatBobMayRead()

	/**
	 * Without a session the endpoint answers 401 and reads nothing.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $schema   The schema of its object.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testNoSessionIsUnauthorised(string $endpoint, string $schema): void {
		$this->readableBy = [$schema => ['o-1']];
		$this->actingUid = null;

		$response = $this->call(endpoint: $endpoint, id: 'o-1');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		self::assertFalse(condition: $this->answered);
	}//end testNoSessionIsUnauthorised()

	/**
	 * A readability read that fails answers 503, not "not found".
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testAFailedReadIsUnavailable(string $endpoint): void {
		$this->readFails = true;

		$response = $this->call(endpoint: $endpoint, id: 'o-1');

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertFalse(condition: $this->answered);
	}//end testAFailedReadIsUnavailable()
	/**
	 * The endpoints with a not-found answer of their own, for an object that does not exist.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function endpointsWithAMissingAnswer(): array {
		return array_intersect_key(self::endpoints(), array_flip(['next status', 'case type terms', 'reachability']));
	}//end endpointsWithAMissingAnswer()

	/**
	 * An unreadable object and a missing one get the same 404 body, so the answer does not tell them apart.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $schema   The schema of its object.
	 *
	 * @return void
	 *
	 * @dataProvider endpointsWithAMissingAnswer
	 */
	public function testAnUnreadableObjectAnswersAsAMissingOne(string $endpoint, string $schema): void {
		$this->readableBy = [$schema => ['o-1']];
		$unreadable = $this->call(endpoint: $endpoint, id: 'o-2');

		$this->missing = true;
		$missing = $this->call(endpoint: $endpoint, id: 'o-1');

		self::assertTrue(condition: $this->answered, message: 'the missing object reached the endpoint\'s own read');
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		self::assertSame(expected: $missing->getStatus(), actual: $unreadable->getStatus());
		self::assertSame(expected: $missing->getData(), actual: $unreadable->getData());
	}//end testAnUnreadableObjectAnswersAsAMissingOne()

	/**
	 * Counts over readable and unreadable keys answer only the readable ones, and only those reach the gateway.
	 *
	 * @return void
	 */
	public function testCountsLeaveOutTheUnreadableKeys(): void {
		$this->readableBy = ['masterEntity' => ['o-1', 'o-3']];

		$response = $this->call(endpoint: 'ticket-counts', id: 'o-1,o-2,o-3');

		self::assertSame(expected: ['available' => true, 'values' => ['o-1' => 1, 'o-3' => 1]], actual: $response->getData());
		self::assertSame(expected: ['o-1', 'o-3'], actual: $this->askedRefs);
	}//end testCountsLeaveOutTheUnreadableKeys()
}//end class
