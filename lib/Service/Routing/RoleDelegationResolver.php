<?php

/**
 * Procest role delegation resolver.
 *
 * Owns the `role.delegate` substitution step of role routing: given the raw
 * participants a routing strategy produced and the case's role records, it
 * swaps each participant for its delegate when — and only when — an active
 * `delegateFrom`/`delegateUntil` window covers the evaluation moment. Split
 * out of RoleResolverService so that service keeps rule normalisation,
 * strategy dispatch and caching, while the delegation window arithmetic and
 * its cycle guard live in one auditable place.
 *
 * Substitution is deliberately single-hop and cycle-safe: a delegate that
 * points back at an already-visited participant is logged and refused rather
 * than followed, so a mis-configured delegation chain can never route a task
 * to an unbounded set of users.
 *
 * @category Service
 * @package  OCA\Procest\Service\Routing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Routing;

use DateTimeImmutable;
use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Substitutes participants for their active delegates, cycle-safe.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/role-based-step-routing/spec.md
 */
class RoleDelegationResolver {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger (records refused delegation cycles).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Substitute delegates inside an active delegation window; break cycles.
	 *
	 * @param array<int, string> $participants Raw resolver output.
	 * @param array<int, array<string, mixed>> $roles All case roles.
	 *
	 * @return array<int, string> The participants with active delegates substituted in.
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function apply(array $participants, array $roles): array {
		$now = new DateTimeImmutable('now');
		$byUser = [];
		foreach ($roles as $role) {
			$participant = (string)($role['participant'] ?? '');
			if ($participant !== '') {
				$byUser[$participant] = $role;
			}
		}

		$result = [];
		foreach ($participants as $participant) {
			$result[] = $this->resolveDelegate(
				participant: $participant,
				byUser: $byUser,
				now: $now,
			);
		}

		return $result;
	}//end apply()

	/**
	 * Resolve one participant to its active delegate (single hop, cycle-safe).
	 *
	 * @param string $participant The original participant.
	 * @param array<string, array<string, mixed>> $byUser Case roles indexed by participant.
	 * @param DateTimeImmutable $now The evaluation moment.
	 *
	 * @return string The delegate when an active window applies, else the participant.
	 */
	private function resolveDelegate(string $participant, array $byUser, DateTimeImmutable $now): string {
		$resolved = $participant;
		$visited = [$participant => true];
		while (isset($byUser[$resolved]) === true) {
			$role = $byUser[$resolved];
			$from = (string)($role['delegateFrom'] ?? '');
			$until = (string)($role['delegateUntil'] ?? '');
			$delegate = (string)($role['delegate'] ?? '');
			if ($delegate === '' || $from === '' || $until === '') {
				break;
			}

			try {
				$fromAt = new DateTimeImmutable($from);
				$untilAt = new DateTimeImmutable($until);
			} catch (Throwable $e) {
				break;
			}

			if ($now < $fromAt || $now > $untilAt) {
				break;
			}

			if (isset($visited[$delegate]) === true) {
				$this->logger->warning(
					'Procest: delegation cycle detected',
					[
						'event' => 'RoleRoutingDelegationCycle',
						'original' => $participant,
						'delegate' => $delegate,
						'app' => Application::APP_ID,
					],
				);
				break;
			}

			$visited[$delegate] = true;
			$resolved = $delegate;

			// Per spec: break after exactly one hop.
			break;
		}//end while

		return $resolved;
	}//end resolveDelegate()
}//end class
