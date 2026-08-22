<?php

/**
 * Parafering Audit Listener
 *
 * Subscribes to ParafeerTransitionEvent and emits one OpenRegister audit-trail
 * entry per emitted transition. Per ADR-022 (apps consume OR abstractions) and
 * the `consume-or-audit-trail-fleet-wide` umbrella, parafering transitions are
 * recorded through OR's hash-chained, natively-immutable audit trail rather
 * than a parallel `paraferingAuditEntry` object store. The application services
 * NEVER write audit entries directly — every audit row flows through this
 * single listener so additional consumers (SIEM streaming, e-Depot push) can
 * attach without modifying the routing services.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/parafering-audit-via-or/spec.md
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Event\ParafeerTransitionEvent;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listener that emits an OR audit-trail entry for each parafering transition.
 *
 * @implements IEventListener<ParafeerTransitionEvent>
 */
class ParaferingAuditListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper OR audit-trail writer (hash-chained, immutable)
	 * @param SettingsService $settingsService Dossiq settings bridge (resolves the voorstel ObjectEntity)
	 * @param LoggerInterface $logger PSR-3 logger
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a ParafeerTransitionEvent.
	 *
	 * Resolves the voorstel ObjectEntity from OR and writes a namespaced
	 * (`@@AUDIT@@{action}`) audit-trail entry carrying the transition
	 * context in the `changed` JSON column. Audit-write failures are swallowed —
	 * they MUST NOT propagate back to the routing service.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-audit-via-or/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ParafeerTransitionEvent) === false) {
			return;
		}

		try {
			$object = $this->resolveProposalEntity(proposalId: $event->getVoorstelId());
			if ($object === null) {
				$this->logger->warning(
					'Dossiq: ParaferingAuditListener could not resolve voorstel ObjectEntity; audit entry skipped',
					['proposal' => $event->getVoorstelId()],
				);
				return;
			}

			// FROZEN PREFIX — deliberately still `procest.`, not `dossiq.`.
			// This string is written into OpenRegister's append-only,
			// hash-chained audit trail and every existing entry already carries
			// it. `getAuditTrail()` filters by that exact prefix, so renaming it
			// would SPLIT the legal approval trail in two: a reader would see
			// only the entries written after the rename and would be shown a
			// partial sign-off history as though it were complete. Nothing
			// errors. Moving this requires a data migration over the existing
			// audit rows, which OR's immutability rules do not permit.
			$action = 'procest.parafering.' . $event->getAction();
			$context = $this->buildContext(event: $event);

			$this->auditTrailMapper->createAuditTrailEntry(
				object: $object,
				action: $action,
				context: $context,
			);
		} catch (Throwable $e) {
			// Swallow — audit-write failures MUST NOT propagate back to the
			// routing service. Detectable via OR's audit-trail-immutable
			// mutation log and this error log entry.
			$this->logger->error(
				'Dossiq: ParaferingAuditListener failed',
				[
					'proposal' => $event->getVoorstelId(),
					'action' => $event->getAction(),
					'exception' => $e->getMessage(),
				],
			);
		}//end try
	}//end handle()

	/**
	 * Build the audit `$context` array persisted in the OR `changed` JSON column.
	 *
	 * @param ParafeerTransitionEvent $event The transition event
	 *
	 * @return array<string, mixed>
	 */
	private function buildContext(ParafeerTransitionEvent $event): array {
		$context = [
			'parafeerrouteId' => $event->getVoorstelId(),
			'paraffeerstapId' => $event->getStep(),
			'fromState' => null,
			'toState' => $event->getAction(),
			'actorUuid' => $event->getActor(),
			'actorRole' => $event->getActorRole(),
		];

		$reason = $event->getReason();
		if ($reason !== null && $reason !== '') {
			$context['comment'] = $reason;
		}

		return $context;
	}//end buildContext()

	/**
	 * Resolve the OR ObjectEntity for a voorstel id/slug.
	 *
	 * Returns null when OR is unavailable, the register/schema config is
	 * missing, or the object cannot be loaded — the caller logs and skips.
	 *
	 * @param string $proposalId The voorstel UUID/slug
	 *
	 * @return ObjectEntity|null
	 */
	private function resolveProposalEntity(string $proposalId): ?ObjectEntity {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('voorstel_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		$entity = $objectService->find($proposalId, register: $register, schema: $schema);
		if ($entity instanceof ObjectEntity) {
			return $entity;
		}

		return null;
	}//end resolveVoorstelEntity()
}//end class
