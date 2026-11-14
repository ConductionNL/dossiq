<?php

/**
 * Dossiq Party Ticket Controller.
 *
 * The open pipelinq tickets of parties, read by the master entity a party
 * record shares with its pipelinq client:
 *
 *  - GET /api/parties/open-tickets?keys[]=...      one count per master entity (the organisation list)
 *  - GET /api/parties/open-tickets/list?key=...    one party's open tickets (the organisation page)
 *
 * Both answer `available: false` when pipelinq cannot answer, so a surface
 * leaves the tickets out instead of drawing zero. The tickets are read through
 * PipelinqGateway as the calling user, so a ticket the caller may not read is
 * neither listed nor counted.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/pipelinq-consumption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\PipelinqGatewayInterface;
use OCA\Dossiq\Kernel\Contracts\ReadableObjectsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Read parties' open pipelinq tickets by master entity.
 *
 * @spec openspec/specs/pipelinq-consumption/spec.md
 */
class PartyTicketController extends Controller {

	/**
	 * The most master entities one count read may name: a list page.
	 *
	 * @var int
	 */
	public const MAX_KEYS = 200;

	/**
	 * Constructor.
	 *
	 * @param string          $appName The app name.
	 * @param IRequest        $request The HTTP request.
	 * @param PipelinqGatewayInterface $gateway     The one seam that names pipelinq.
	 * @param ReadableObjectsInterface $readable    Whether a caller is signed in, and which master entities they may read.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PipelinqGatewayInterface $gateway,
		private readonly ReadableObjectsInterface $readable,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * One open ticket count per master entity the caller may read. A master
	 * entity that no party the caller may read carries is left out, as a missing one is.
	 *
	 * @return JSONResponse `{available, values: {ref: count}}`, or `{available: false}`.
	 *
	 * @spec openspec/specs/pipelinq-consumption/spec.md
	 */
	#[NoAdminRequired]
	public function openTicketCounts(): JSONResponse {
		$keys = $this->readableKeys();
		if ($keys instanceof JSONResponse) {
			return $keys;
		}

		if ($keys === []) {
			return new JSONResponse(['available' => true, 'values' => []]);
		}

		return $this->openTicketCountsOf(keys: $keys);
	}//end openTicketCounts()

	/**
	 * The requested parties the caller may read, or why they cannot be read.
	 *
	 * @return array|JSONResponse The readable master-entity keys, or the refusal.
	 */
	private function readableKeys(): array|JSONResponse {
		if ($this->readable->hasCaller() === false) {
			return new JSONResponse(['error' => 'Sign in to read these parties.'], Http::STATUS_UNAUTHORIZED);
		}

		$keys = $this->keys(raw: $this->request->getParam('keys', []));
		if (count($keys) > self::MAX_KEYS) {
			return new JSONResponse(['error' => 'Too many parties in one read.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$keys = $this->readable->readableMasterEntities(refs: $keys);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'The parties could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return $keys;
	}//end readableKeys()

	/**
	 * Count the open tickets of each party, or answer unavailable when the tickets cannot be read.
	 *
	 * @param array $keys The readable master-entity keys.
	 *
	 * @return JSONResponse
	 */
	private function openTicketCountsOf(array $keys): JSONResponse {
		$tickets = $this->gateway->openTicketsByMasterEntity(refs: $keys);
		if ($tickets === null) {
			return new JSONResponse(['available' => false]);
		}

		$values = [];
		foreach ($keys as $key) {
			$values[$key] = count(($tickets[$key] ?? []));
		}

		return new JSONResponse(['available' => true, 'values' => $values]);
	}//end openTicketCountsOf()

	/**
	 * One party's tickets: the open ones, or with `status` `closed` or `all`
	 * the handled ones too.
	 *
	 * @return JSONResponse `{available, results, total}`, or `{available: false}`.
	 *
	 * @spec openspec/specs/pipelinq-consumption/spec.md
	 * @spec openspec/changes/party-tickets-all-with-reference/specs/pipelinq-consumption/spec.md#requirement-a-partys-tickets-card-lists-its-handled-tickets-too
	 */
	#[NoAdminRequired]
	public function openTickets(): JSONResponse {
		if ($this->readable->hasCaller() === false) {
			return new JSONResponse(['error' => 'Sign in to read this party.'], Http::STATUS_UNAUTHORIZED);
		}

		$key = trim((string)$this->request->getParam('key', ''));
		if ($key === '') {
			return new JSONResponse(['available' => true, 'results' => [], 'total' => 0]);
		}

		$unreadable = $this->unreadableParty(key: $key);
		if ($unreadable !== null) {
			return $unreadable;
		}

		$tickets = $this->gateway->ticketsByMasterEntity(refs: [$key], status: $this->statusAsked());
		if ($tickets === null) {
			return new JSONResponse(['available' => false]);
		}

		$results = array_values(($tickets[$key] ?? []));

		return new JSONResponse(['available' => true, 'results' => $results, 'total' => count($results)]);
	}//end openTickets()

	/**
	 * The 503/404 answer when the party cannot be read or the caller may not read it, else null.
	 *
	 * @param string $key The party's master entity key.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/party-tickets-all-with-reference/specs/pipelinq-consumption/spec.md#requirement-a-partys-tickets-card-lists-its-handled-tickets-too
	 */
	private function unreadableParty(string $key): ?JSONResponse {
		try {
			$readable = $this->readable->readableMasterEntities(refs: [$key]);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'The party could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($readable === []) {
			return new JSONResponse(['error' => 'Party not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end unreadableParty()

	/**
	 * The ticket status asked for: `closed` or `all`, and `open` for anything else.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/party-tickets-all-with-reference/specs/pipelinq-consumption/spec.md#requirement-a-partys-tickets-card-lists-its-handled-tickets-too
	 */
	private function statusAsked(): string {
		$status = (string)$this->request->getParam('status', 'open');
		if (in_array($status, ['closed', 'all'], true) === false) {
			return 'open';
		}

		return $status;
	}//end statusAsked()

	/**
	 * The distinct, non-empty master entities a request names.
	 *
	 * @param mixed $raw The `keys` parameter.
	 *
	 * @return array<int, string> The keys.
	 */
	private function keys(mixed $raw): array {
		if (is_array($raw) === false) {
			$raw = [$raw];
		}

		$keys = array_map(static fn (mixed $key): string => trim((string)$key), array_filter($raw, 'is_scalar'));

		return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
	}//end keys()
}//end class
