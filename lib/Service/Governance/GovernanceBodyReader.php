<?php

/**
 * Reads a governance body back from decidiq, with a permanent local fallback.
 *
 * Dossiq raises its committees in decidiq as governance bodies
 * (CommitteeDelegationService). Until this reader existed it still read them
 * from its own copy, so a committee archived in decidiq kept accepting new
 * referrals here. This reader asks decidiq first, over its read seam
 * (`GovernanceBodyStateRequestedEvent`, decidiq#1694), and lays decidiq's answer
 * over the local row: decidiq is the authority for `active`, the name and the
 * roster.
 *
 * 🔴 THREE ANSWERS, KEPT APART. decidiq not answering (absent, or its read
 * failed) is not decidiq saying "no such body". The first falls back to the
 * local row with no error, because a live committee must not read as gone.
 * The second also falls back, because a committee not yet migrated has no body
 * to read. Only a FOUND body overrides the local row.
 *
 * 🔑 THE KEY IS THE ONE THE WRITE USES. decidiq's own id when the row carries
 * `governanceBodyId`, else (`dossiq`, the row's id), which is exactly the pair
 * CommitteeDelegationService raised the body under. A different key would read
 * nothing and fall back forever.
 *
 * Reading through decidiq's register directly is forbidden (ADR-022/066), so
 * the read goes over the typed event seam, the same way the write does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Governance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md#requirement-req-mcd-003-reads-resolve-from-decidiq-falling-back-locally
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Governance;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a local governance body row through decidiq.
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md#requirement-req-mcd-003-reads-resolve-from-decidiq-falling-back-locally
 */
class GovernanceBodyReader {

	/**
	 * decidiq's read event. One spelling only: it was added after the
	 * OCA\Decidesk rename.
	 *
	 * @var string
	 */
	private const STATE_EVENT = '\\OCA\\Decidiq\\Event\\GovernanceBodyStateRequestedEvent';

	/**
	 * This app's id as decidiq knows it. Frozen: it is half of the key the
	 * write raised the body under (CommitteeDelegationService::SOURCE_APP).
	 *
	 * @var string
	 */
	private const SOURCE_APP = 'dossiq';

	/**
	 * Where a resolved row says its fields came from.
	 *
	 * @var string
	 */
	public const SOURCE_KEY = 'governanceBodySource';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Carries the read to decidiq.
	 * @param LoggerInterface  $logger          Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The local row with decidiq's answer laid over it, or the local row as it
	 * is when decidiq cannot or does not answer.
	 *
	 * @param array<string, mixed> $row The local committee row.
	 *
	 * @return array<string, mixed> The resolved row, carrying SOURCE_KEY `decidiq` or `local`.
	 *
	 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md#requirement-req-mcd-003-reads-resolve-from-decidiq-falling-back-locally
	 */
	public function resolve(array $row): array {
		$body = $this->ask(row: $row);
		if ($body === null) {
			$row[self::SOURCE_KEY] = 'local';
			return $row;
		}

		if (is_bool($body['active'] ?? null) === true) {
			$row['active'] = $body['active'];
		}

		$name = trim((string)($body['name'] ?? ''));
		if ($name !== '') {
			$row['name'] = $name;
		}

		if (is_array($body['members'] ?? null) === true) {
			$row = $this->withRoster(row: $row, members: $body['members']);
		}

		$row['governanceBodyId'] = (string)($body['id'] ?? ($row['governanceBodyId'] ?? ''));
		$row[self::SOURCE_KEY] = 'decidiq';

		return $row;
	}//end resolve()

	/**
	 * Ask decidiq for the body, or null when it cannot or does not answer.
	 *
	 * @param array<string, mixed> $row The local row.
	 *
	 * @return array<string, mixed>|null The body.
	 */
	private function ask(array $row): ?array {
		$eventClass = self::STATE_EVENT;
		if (class_exists($eventClass) === false) {
			return null;
		}

		$reference = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		$bodyId = trim((string)($row['governanceBodyId'] ?? ''));
		if ($reference === '' && $bodyId === '') {
			return null;
		}

		try {
			$event = new $eventClass(self::SOURCE_APP, $reference, $bodyId);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq governance bodies: the read from decidiq failed; using the local row',
				['externalReference' => $reference, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($event->isHandled() === false || $event->isFound() === false) {
			return null;
		}

		return $event->getGovernanceBody();
	}//end ask()

	/**
	 * Rebuild the local roster fields from decidiq's members: one chair, one
	 * secretary, and the plain members in order.
	 *
	 * @param array<string, mixed>   $row     The local row.
	 * @param array<int, mixed>      $members decidiq's members.
	 *
	 * @return array<string, mixed> The row with chair, secretary and members from decidiq.
	 */
	private function withRoster(array $row, array $members): array {
		$chair = '';
		$secretary = '';
		$plain = [];
		foreach ($members as $member) {
			if (is_array($member) === false || trim((string)($member['uid'] ?? '')) === '') {
				continue;
			}

			$uid = trim((string)$member['uid']);
			$role = (string)($member['role'] ?? 'member');
			if ($role === 'chair' && $chair === '') {
				$chair = $uid;
				continue;
			}

			if ($role === 'secretary' && $secretary === '') {
				$secretary = $uid;
				continue;
			}

			// The local member shape: {uid, displayName, role, external}, with
			// role one of member or deputy (the chair has its own field).
			$plainRole = 'member';
			if ($role === 'deputy') {
				$plainRole = 'deputy';
			}

			$plain[] = [
				'uid' => $uid,
				'displayName' => (string)($member['name'] ?? ''),
				'role' => $plainRole,
				'external' => (($member['external'] ?? false) === true),
			];
		}

		$row['chair'] = $chair;
		$row['secretary'] = $secretary;
		$row['members'] = $plain;

		return $row;
	}//end withRoster()
}//end class
