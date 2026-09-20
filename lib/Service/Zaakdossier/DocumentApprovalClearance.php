<?php

/**
 * Whether a document's approval route has finished, as decidiq answers it.
 *
 * 🔑 dossiq READS, decidiq DECIDES. The route, its steps, who may act on each
 * one, what a step's silence means and when the whole thing has concluded are
 * decidiq's, and they are not reimplemented here. This class asks one
 * question, gets one answer, and turns it into the sentence a handler reads
 * beside the document.
 *
 * 🔴 A DOCUMENT WITH NO ROUTE IS NOT A DOCUMENT WAITING ON ONE. Most documents
 * on most instances have never been near an approval route, and refusing them
 * would break the forward-only lifecycle for everybody to serve a feature
 * almost nobody uses. So the absent answers are all permissive and they are
 * distinguishable from each other: decidiq not installed, decidiq installed
 * and this document has no route, and this document has a route that has
 * cleared all read as "go ahead", and only an OPEN route refuses.
 *
 * 🔴 THE LOOKUP IS DUCK-TYPED, WHICH IS WHY IT GOES THROUGH FleetAppId. A
 * container `get()` on a class that is not there THROWS, and an
 * `isInstalled('decidesk')` against an instance running `decidiq` returns
 * false without erroring, which would make this guard silently permit
 * everything. `FleetAppId` asks for both identities, newest first.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads decidiq's clearance answer for one document.
 *
 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
 */
class DocumentApprovalClearance {

	/**
	 * The clearance answer for a document nobody routed, and for an instance
	 * with no decidiq.
	 *
	 * `routed` is what separates the two from a route that HAS cleared, which
	 * answers the same `cleared: true`. The Files-tab marker needs that
	 * difference: "no marker" and "approved" are different rows.
	 *
	 * @var array{routed: bool, cleared: bool, waitingOn: array<int, array<string, mixed>>}
	 */
	public const NOT_ROUTED = ['routed' => false, 'cleared' => true, 'waitingOn' => []];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The service container, for the
	 *                                      duck-typed decidiq lookup.
	 * @param LoggerInterface $logger The logger. A decidiq that throws costs
	 *                                its own answer and nothing else: a
	 *                                document lifecycle that stopped working
	 *                                because a sibling app raised would be a
	 *                                worse failure than the one this guards.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What decidiq says about one document's approval routes.
	 *
	 * @param string $documentId The informatieobject UUID.
	 * @param string $schema The subject schema slug decidiq knows it under.
	 *
	 * @return array{routed: bool, cleared: bool, waitingOn: array<int, array<string, mixed>>}
	 *         The answer, or NOT_ROUTED when decidiq cannot answer.
	 *
	 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
	 */
	public function forDocument(string $documentId, string $schema = 'informatieobject'): array {
		$documentId = trim($documentId);
		if ($documentId === '') {
			return self::NOT_ROUTED;
		}

		$routeService = FleetAppId::getService(
			container: $this->container,
			canonical: 'decidiq',
			relative: 'Service\\ApprovalRouteService'
		);
		$clearanceService = FleetAppId::getService(
			container: $this->container,
			canonical: 'decidiq',
			relative: 'Service\\SubjectClearanceService'
		);

		if ($routeService === null || $clearanceService === null) {
			// No decidiq on this instance. Every document is unrouted, which
			// is the state every document was in before this change.
			return self::NOT_ROUTED;
		}

		try {
			$routes = $routeService->routesWithStagesFor(subject: $documentId);
			if (is_array($routes) === false || $routes === []) {
				return self::NOT_ROUTED;
			}

			$clearance = $clearanceService->clearanceFor(routes: $routes);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq could not read the approval clearance of document ' . $documentId . ': ' . $e->getMessage(),
				['app' => 'dossiq', 'document' => $documentId, 'schema' => $schema]
			);

			return self::NOT_ROUTED;
		}

		return [
			'routed' => true,
			'cleared' => (($clearance['cleared'] ?? false) === true),
			'waitingOn' => (array)($clearance['waitingOn'] ?? []),
		];
	}//end forDocument()

	/**
	 * The sentence a refusal carries: the route and the step it waits on.
	 *
	 * "Not cleared" on its own makes somebody open decidiq and read three
	 * screens to learn one name, which is the reason decidiq's own clearance
	 * answer names them and this one passes them through rather than
	 * summarising them away.
	 *
	 * @param array{routed: bool, cleared: bool, waitingOn: array<int, array<string, mixed>>} $clearance The answer.
	 *
	 * @return string The reason, or the empty string when nothing is waiting.
	 *
	 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
	 */
	public function describe(array $clearance): string {
		$waiting = (array)($clearance['waitingOn'] ?? []);
		if ($waiting === []) {
			return '';
		}

		$parts = [];
		foreach ($waiting as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$route = trim((string)($entry['routeName'] ?? ($entry['route'] ?? '')));
			$step = trim((string)($entry['stageName'] ?? ''));
			$actor = trim((string)($entry['actor'] ?? ''));

			$part = $route;
			if ($part === '') {
				$part = 'an approval route';
			}

			if ($step !== '') {
				$part .= ', step "' . $step . '"';
			}

			if ($actor !== '') {
				$part .= ', waiting on ' . $actor;
			}

			$parts[] = $part;
		}

		if ($parts === []) {
			return '';
		}

		return implode('; ', $parts);
	}//end describe()
}//end class
