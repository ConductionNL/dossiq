<?php

/**
 * Golden record of reading one party's tickets.
 *
 * Records, per world, the status and body `openTickets` answers with and every
 * call it makes, so a refactor cannot change whose tickets a caller may read
 * or which status they are read under.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PartyTicketController;
use OCA\Dossiq\Service\Access\ReadableObjects;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for PartyTicketController::openTickets.
 *
 * @covers \OCA\Dossiq\Controller\PartyTicketController
 */
class PartyOpenTicketsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the endpoint distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testOpenTicketsAcrossWorlds(): void {
		$tickets = ['party-1' => [3 => ['id' => 't-1'], 7 => ['id' => 't-2']]];
		$worlds = [
			'not signed in' => [['key' => 'party-1'], ['caller' => false]],
			'no key' => [['key' => '  '], []],
			'the party cannot be read' => [['key' => 'party-1'], ['readable' => 'throw']],
			'a party the caller may not read' => [['key' => 'party-1'], ['readable' => []]],
			'pipelinq absent' => [['key' => 'party-1'], ['tickets' => null]],
			'open tickets' => [['key' => ' party-1 '], []],
			'closed tickets' => [['key' => 'party-1', 'status' => 'closed'], []],
			'all tickets' => [['key' => 'party-1', 'status' => 'all'], []],
			'an unknown status reads open' => [['key' => 'party-1', 'status' => 'archived'], []],
			'a party without tickets' => [['key' => 'party-1'], ['tickets' => []]],
		];

		$record = [];
		foreach ($worlds as $name => [$params, $world]) {
			$this->calls = [];
			$world += ['caller' => true, 'readable' => 'echo', 'tickets' => $tickets];
			$response = $this->controllerFor(params: $params, world: $world)->openTickets();
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'party-open-tickets', value: $record);
	}//end testOpenTicketsAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed> $world  The world.
	 *
	 * @return PartyTicketController
	 */
	private function controllerFor(array $params, array $world): PartyTicketController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$gateway = $this->createMock(PipelinqGateway::class);
		$gateway->method('ticketsByMasterEntity')->willReturnCallback(
			function (array $refs, string $status) use ($world): ?array {
				$this->calls[] = 'tickets ' . json_encode($refs) . ' ' . $status;
				return $world['tickets'];
			}
		);

		$readable = $this->createMock(ReadableObjects::class);
		$readable->method('hasCaller')->willReturn($world['caller']);
		$readable->method('readableMasterEntities')->willReturnCallback(
			function (array $refs) use ($world): array {
				$this->calls[] = 'readable ' . json_encode($refs);
				if ($world['readable'] === 'throw') {
					throw new RuntimeException('register offline');
				}

				return ($world['readable'] === 'echo') ? $refs : $world['readable'];
			}
		);

		return new PartyTicketController(appName: 'dossiq', request: $request, gateway: $gateway, readable: $readable);
	}//end controllerFor()
}//end class
