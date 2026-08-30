<?php

/**
 * Dossiq Bezwaar/Beroep Legal-Hold Listener.
 *
 * Places an OpenRegister legal hold on a case when an Awb bezwaar/beroep
 * proceeding is registered against it, and releases the hold when the
 * proceeding reaches its final outcome. This replaces the retired
 * ArchivalTriggerService `opgeschort-juridische-procedure` trigger status:
 * dossiq owns only the Awb domain signal (a proceeding opened/closed); the
 * hold storage and its enforcement (OR's retention evaluator and destruction
 * jobs skip held objects) belong to OpenRegister per ADR-022.
 *
 * The OpenRegister LegalHoldService and object mapper are resolved lazily by
 * FQN so dossiq carries no compile-time dependency on the optional
 * OpenRegister app; when they are absent the listener is a safe no-op.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Translates Awb bezwaar/beroep lifecycle events into OpenRegister legal holds.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
class BezwaarLegalHoldListener implements IEventListener {

	/**
	 * OpenRegister LegalHoldService FQN (resolved lazily).
	 *
	 * @var string
	 */
	private const LEGAL_HOLD_SERVICE = 'OCA\OpenRegister\Service\Archival\LegalHoldService';

	/**
	 * OpenRegister object mapper FQN (resolved lazily).
	 *
	 * @var string
	 */
	private const OBJECT_MAPPER = 'OCA\OpenRegister\Db\MagicMapper';

	/**
	 * Schemas whose creation opens an Awb proceeding (places a hold).
	 *
	 * `beroep` (appeal, Awb hoofdstuk 8) opens a proceeding exactly as
	 * `bezwaar`/`objection` do, and its terminal artefact `appealDecision` is
	 * already listed in {@see PROCEEDING_CLOSED_SCHEMAS}. It was missing here,
	 * so a case under appeal was never held and stayed destruction-eligible
	 * while the appeal was still running — the release side was wired up and
	 * the place side was not.
	 *
	 * @var array<int, string>
	 */
	private const PROCEEDING_OPENED_SCHEMAS = ['objection', 'objectionProceeding', 'beroep'];

	/**
	 * Schemas whose creation ends an Awb proceeding (releases a hold).
	 *
	 * `bezwaarDecision` (beslissing op bezwaar) and `appealDecision`
	 * (beroepsbeslissing) are the Awb-specific terminal artefacts.
	 *
	 * @var array<int, string>
	 */
	private const PROCEEDING_CLOSED_SCHEMAS = ['bezwaarDecision', 'appealDecision'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (OR services resolved lazily).
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister object event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		$schemaSlug = $this->resolveSchemaSlug(payload: $payload);
		$opens = in_array($schemaSlug, self::PROCEEDING_OPENED_SCHEMAS, true);
		$closes = in_array($schemaSlug, self::PROCEEDING_CLOSED_SCHEMAS, true);
		if ($opens === false && $closes === false) {
			return;
		}

		$caseId = $this->resolveCaseId(schemaSlug: $schemaSlug, payload: $payload);
		if ($caseId === '') {
			return;
		}

		if ($opens === true) {
			$this->applyHold(
				caseId: $caseId,
				place: true,
				reason: 'Awb-procedure (' . $schemaSlug . ') geregistreerd — archivering opgeschort'
			);
			return;
		}

		$this->applyHold(
			caseId: $caseId,
			place: false,
			reason: 'Awb-procedure (' . $schemaSlug . ') afgehandeld — archivering hervat'
		);
	}//end handle()

	/**
	 * Place or release a legal hold on the case via OpenRegister.
	 *
	 * Idempotent: a hold is only placed when none is active, and only
	 * released when one is active. Fails safe — any resolution error leaves
	 * the case in its current hold state and is logged.
	 *
	 * @param string $caseId The case UUID to hold/release.
	 * @param bool $place True to place a hold, false to release.
	 * @param string $reason Human-readable reason recorded on the hold.
	 *
	 * @return void
	 */
	private function applyHold(string $caseId, bool $place, string $reason): void {
		$legalHoldService = $this->resolveOr(fqn: self::LEGAL_HOLD_SERVICE);
		$caseObject = $this->resolveCaseObject(caseId: $caseId);
		if ($legalHoldService === null || $caseObject === null) {
			// This early return used to be completely silent, which is how a dead
			// compliance control went unnoticed: no hold, no error, no log line.
			$this->logger->warning(
				'Dossiq legal-hold: NOT applied, collaborator or case unresolved',
				[
					'app' => Application::APP_ID,
					'caseId' => $caseId,
					'place' => $place,
					'haveService' => ($legalHoldService !== null),
					'haveCaseObject' => ($caseObject !== null),
				]
			);
			return;
		}

		try {
			$hasHold = (bool)$legalHoldService->hasActiveHold($caseObject);
			if ($place === true && $hasHold === false) {
				$legalHoldService->placeHold($caseObject, $reason);
			} elseif ($place === false && $hasHold === true) {
				$legalHoldService->releaseHold($caseObject, $reason);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq legal-hold: could not apply hold',
				[
					'app' => Application::APP_ID,
					'caseId' => $caseId,
					'place' => $place,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end applyHold()

	/**
	 * Resolve the case ObjectEntity for a UUID via the OR object mapper.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return object|null The ObjectEntity, or null when unavailable.
	 */
	private function resolveCaseObject(string $caseId): ?object {
		$objectMapper = $this->resolveOr(fqn: self::OBJECT_MAPPER);
		if ($objectMapper === null) {
			return null;
		}

		try {
			// MagicMapper has no findByUuid(); calling it raised a fatal Error that
			// the \Throwable catch below swallowed on EVERY invocation, so no Awb
			// legal hold was ever placed. RBAC and multitenancy are disabled because
			// this runs inside an event handler where there is no session user or
			// active organisation to filter by — an organisation-scoped read would
			// find nothing and silently reopen the same hole.
			$caseObject = $objectMapper->find(
				identifier: $caseId,
				_rbac: false,
				_multitenancy: false
			);
			if (is_object($caseObject) === true) {
				return $caseObject;
			}

			return null;
		} catch (\Throwable $e) {
			// A legal hold is an archiving-law control: failing to resolve the case
			// means the hold is NOT applied, so it must never be silent again.
			$this->logger->warning(
				'Dossiq legal-hold: could not resolve case object',
				[
					'app' => Application::APP_ID,
					'caseId' => $caseId,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end resolveCaseObject()

	/**
	 * Resolve an OpenRegister collaborator by FQN, or null when unavailable.
	 *
	 * @param string $fqn Fully-qualified class name.
	 *
	 * @return object|null
	 */
	private function resolveOr(string $fqn): ?object {
		if (class_exists($fqn) === false) {
			return null;
		}

		try {
			$service = $this->container->get($fqn);
			if (is_object($service) === true) {
				return $service;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveOr()

	/**
	 * Resolve the case UUID a proceeding object relates to.
	 *
	 * `objection`/`appealDecision` carry the case directly; `bezwaarDecision`
	 * links to its `bezwaar` (objection), whose `case` is resolved via the
	 * object mapper.
	 *
	 * @param string $schemaSlug The proceeding schema slug.
	 * @param array<string, mixed> $payload The proceeding object payload.
	 *
	 * @return string The case UUID, or '' when it cannot be resolved.
	 */
	private function resolveCaseId(string $schemaSlug, array $payload): string {
		if ($schemaSlug === 'bezwaarDecision') {
			$objectionId = (string)($payload['objectionProceeding'] ?? '');
			if ($objectionId === '') {
				return '';
			}

			return $this->caseIdOfObjection(objectionId: $objectionId);
		}

		return (string)($payload['case'] ?? '');
	}//end resolveCaseId()

	/**
	 * Resolve the case UUID an objection belongs to via the OR object mapper.
	 *
	 * @param string $objectionId The objection (bezwaar) UUID.
	 *
	 * @return string The linked case UUID, or '' when unresolvable.
	 */
	private function caseIdOfObjection(string $objectionId): string {
		$objectMapper = $this->resolveOr(fqn: self::OBJECT_MAPPER);
		if ($objectMapper === null) {
			return '';
		}

		try {
			// See resolveCaseObject(): findByUuid() does not exist on MagicMapper.
			$objection = $objectMapper->find(
				identifier: $objectionId,
				_rbac: false,
				_multitenancy: false
			);
			if ($objection === null || method_exists($objection, 'getObject') === false) {
				return '';
			}

			$data = $objection->getObject();
			if (is_array($data) === false) {
				return '';
			}

			return (string)($data['case'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq legal-hold: could not resolve objection for case linkage',
				[
					'app' => Application::APP_ID,
					'bezwaarId' => $objectionId,
					'error' => $e->getMessage(),
				]
			);
			return '';
		}//end try
	}//end caseIdOfObjection()

	/**
	 * Extract the OR object array from an event.
	 *
	 * @param Event $event Event instance.
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractObject(Event $event): ?array {
		$object = null;

		if (method_exists($event, 'getObject') === true) {
			$object = $event->getObject();
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end extractObject()

	/**
	 * Resolve the schema slug for an OR object payload.
	 *
	 * The payload carries the schema as an ID (`@self.schema` is
	 * `ObjectEntity::$schema`, written as `(string) $schemaId`), and `@self`
	 * has no `schemaSlug` key. Reading those keys directly — as this method
	 * used to — returned an id or an empty string, so the strict `in_array()`
	 * checks below never matched and no Awb legal hold has ever been placed.
	 * Resolution goes through the shared {@see ObjectSchemaSlugResolver}.
	 *
	 * @param array<string, mixed> $payload Object payload.
	 *
	 * @return string
	 */
	private function resolveSchemaSlug(array $payload): string {
		return $this->slugResolver->resolveFromPayload(payload: $payload);
	}//end resolveSchemaSlug()
}//end class
