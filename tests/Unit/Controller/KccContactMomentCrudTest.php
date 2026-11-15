<?php

/**
 * KccContactController: listing, reading, creating and updating contact moments.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\KccContactController;
use OCA\Dossiq\Kernel\Contracts\CallbackServiceInterface;
use OCA\Dossiq\Service\Kcc\ContactMomentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The agent is the signed-in user, an administrator reads everyone's moments,
 * request bookkeeping never reaches the service, and a refusal keeps its message.
 *
 * @covers \OCA\Dossiq\Controller\KccContactController
 */
class KccContactMomentCrudTest extends TestCase {

	/**
	 * The contact moment service.
	 *
	 * @var ContactMomentService&MockObject
	 */
	private $moments;

	/**
	 * The controller for a signed-in agent (or nobody), with the request parameters given.
	 *
	 * @param string|null          $uid    The agent, or null for no session.
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $admin  Whether the agent is an administrator.
	 *
	 * @return KccContactController
	 */
	private function controller(?string $uid='agent-1', array $params=[], bool $admin=false): KccContactController {
		$this->moments ??= $this->createMock(ContactMomentService::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $admin === true && $id === $uid);

		return new KccContactController(
			request: $request,
			contactMomentService: $this->moments,
			callbackService: $this->createMock(CallbackServiceInterface::class),
			userSession: $session,
			groupManager: $groups
		);
	}//end controller()

	/**
	 * The list passes the three filters, the agent and whether they are privileged.
	 *
	 * @return void
	 */
	public function testTheListFiltersByChannelOutcomeAndTeamForTheAgent(): void {
		$this->moments = $this->createMock(ContactMomentService::class);
		$this->moments->expects($this->once())->method('list')
			->with(['channel' => 'telefoon', 'outcome' => '', 'assignedTeam' => 'team-a'], 'agent-1', true)
			->willReturn([['id' => 'm-1']]);

		$response = $this->controller(params: ['channel' => 'telefoon', 'assignedTeam' => 'team-a'], admin: true)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 'm-1']]], $response->getData());
	}//end testTheListFiltersByChannelOutcomeAndTeamForTheAgent()

	/**
	 * A refused filter is 400 with the service's message.
	 *
	 * @return void
	 */
	public function testARefusedFilterIs400(): void {
		$this->moments = $this->createMock(ContactMomentService::class);
		$this->moments->method('list')->willThrowException(new OCSBadRequestException('Onbekend kanaal'));

		$response = $this->controller(params: ['channel' => 'duif'])->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Onbekend kanaal'], $response->getData());
	}//end testARefusedFilterIs400()

	/**
	 * Without a session every action is 401 and the service is not asked.
	 *
	 * @return void
	 */
	public function testWithoutASessionEveryActionIs401(): void {
		$this->moments = $this->createMock(ContactMomentService::class);
		$this->moments->expects($this->never())->method($this->anything());
		$controller = $this->controller(uid: null);

		foreach ([$controller->index(), $controller->create(), $controller->show(id: 'm-1'), $controller->update(id: 'm-1')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
		}
	}//end testWithoutASessionEveryActionIs401()

	/**
	 * Create sends the body without the request bookkeeping and answers 201; a refusal is 400.
	 *
	 * @return void
	 */
	public function testCreateDropsBookkeepingAndAnswers201(): void {
		$this->moments = $this->createMock(ContactMomentService::class);
		$this->moments->expects($this->exactly(2))->method('create')
			->with(['channel' => 'balie', 'subject' => 'Paspoort'], 'agent-1')
			->willReturnOnConsecutiveCalls(['id' => 'm-9', 'channel' => 'balie'], $this->throwException(new OCSBadRequestException('Onderwerp ontbreekt')));
		$params = ['channel' => 'balie', 'subject' => 'Paspoort', 'id' => 'x', 'uuid' => 'y', '@self' => [], '_route' => 'r'];

		$created = $this->controller(params: $params)->create();
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$this->assertSame(['id' => 'm-9', 'channel' => 'balie'], $created->getData());

		$refused = $this->controller(params: $params)->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'Onderwerp ontbreekt'], $refused->getData());
	}//end testCreateDropsBookkeepingAndAnswers201()

	/**
	 * Show reads as the agent; a moment the agent may not read, or that is gone, is 404.
	 *
	 * @return void
	 */
	public function testShowIsTheMomentOr404(): void {
		$this->moments = $this->createMock(ContactMomentService::class);
		$this->moments->method('get')->willReturnCallback(
			static function (string $id, string $agentId, bool $isPrivileged): array {
				if ($id === 'other') {
					throw new OCSBadRequestException('Contactmoment niet gevonden');
				}

				return ['id' => $id, 'agent' => $agentId, 'privileged' => $isPrivileged];
			}
		);

		$shown = $this->controller()->show(id: 'm-1');
		$this->assertSame(Http::STATUS_OK, $shown->getStatus());
		$this->assertSame(['id' => 'm-1', 'agent' => 'agent-1', 'privileged' => false], $shown->getData());

		$missing = $this->controller()->show(id: 'other');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Contactmoment niet gevonden'], $missing->getData());
	}//end testShowIsTheMomentOr404()

	/**
	 * Update sends the body without bookkeeping, as the agent; a refusal is 400.
	 *
	 * @return void
	 */
	public function testUpdateSendsTheBodyAsTheAgent(): void {
		$this->moments = $this->createMock(ContactMomentService::class);
		$this->moments->expects($this->exactly(2))->method('update')
			->with('m-1', ['outcome' => 'afgehandeld'], 'agent-1', true)
			->willReturnOnConsecutiveCalls(['id' => 'm-1', 'outcome' => 'afgehandeld'], $this->throwException(new OCSBadRequestException('Afgesloten')));
		$params = ['outcome' => 'afgehandeld', 'id' => 'm-1', '_route' => 'r'];

		$updated = $this->controller(params: $params, admin: true)->update(id: 'm-1');
		$this->assertSame(Http::STATUS_OK, $updated->getStatus());
		$this->assertSame(['id' => 'm-1', 'outcome' => 'afgehandeld'], $updated->getData());

		$refused = $this->controller(params: $params, admin: true)->update(id: 'm-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'Afgesloten'], $refused->getData());
	}//end testUpdateSendsTheBodyAsTheAgent()
}//end class
