<?php

/**
 * Dossiq Termijn Case Created Listener.
 *
 * Observes OpenRegister ObjectCreatedEvent on the dossiq case schema and
 * binds an AWB termijn (TermijnInstance) to the case using the active
 * TermijnDefinitie for the case zaaktype. Defers all work to
 * {@see TermijnService} (ADR-022). A missing definition is logged at debug
 * level but never blocks case creation.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Term\TermResolution;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Binds a TermijnInstance to a freshly-created dossiq case.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class DeadlineCaseCreatedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService TermijnService.
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 * @param CaseTypeSlugResolver $caseTypeSlugs Case-type uuid-to-slug resolver.
	 * @param LoggerInterface $logger Logger.
	 * @param CaseTermsService|null $caseTerms The clocks beside the statutory one: the
	 *        planned end, the internal target, and the fixed closing date when the case
	 *        type declares one instead of a lead time. Optional so a container that
	 *        cannot build it leaves the statutory bind exactly as it was.
	 * @param TermResolution|null $resolution The case type's own first-response term,
	 *        which wins over the Awb default when one is declared. Optional for the
	 *        same reason as the parameter above it.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly CaseTypeSlugResolver $caseTypeSlugs,
		private readonly LoggerInterface $logger,
		private readonly ?CaseTermsService $caseTerms = null,
		private readonly ?TermResolution $resolution = null,
	) {
	}//end __construct()

	/**
	 * The uuid behind a reference, bare or extended.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The uuid, empty when there is none.
	 */
	private function referenced(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ''));
		}

		return '';
	}//end referenced()

	/**
	 * Handle a case-created event.
	 *
	 * @param Event $event Event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		if ($this->resolveSchemaSlug(payload: $payload) !== 'case') {
			return;
		}

		$caseId = (string)($payload['id'] ?? ($payload['uuid'] ?? ''));
		$caseTypeRef = (string)($payload['caseType'] ?? '');
		if ($caseId === '' || $caseTypeRef === '') {
			return;
		}

		// 🔴 A `case` carries its case type as a UUID; a deadlineDefinition
		// binds by SLUG. Handing the uuid straight to TermijnService matched
		// no shipped case type, so no term was ever bound and no FlowTimer
		// ever armed — and the refusal was invisible at the default loglevel.
		$caseType = $this->caseTypeSlugs->toSlug(reference: $caseTypeRef);
		if ($caseType === '') {
			$this->logger->warning(
				'Dossiq termijn: the case type behind a new case could not be resolved to a slug, '
				. 'so no statutory term was started',
				['case' => $caseId, 'caseType' => $caseTypeRef]
			);

			return;
		}

		// Which term this case actually gets. One case type can carry several:
		// one per participating organisation, per service and per priority,
		// which is what lets a gemeenschappelijke regeling run one case type
		// for five municipalities with five agreed norms. The resolution is
		// made here, where the case's own values are in hand, and is recorded
		// on the instance so a disputed date can be explained a year later.
		$resolution = $this->resolution?->resolve(
			caseType: $caseType,
			context: [
				'organisation' => $this->referenced(value: ($payload['competentAuthority'] ?? null)),
				'service' => $this->referenced(value: ($payload['procedureType'] ?? null)),
				'priority' => $this->referenced(value: ($payload['priority'] ?? null)),
			]
		);

		try {
			$this->termService->createTermijnInstance($caseId, $caseType, null, $resolution);
			$this->bindTheOtherClocks(caseId: $caseId, caseTypeRef: $caseTypeRef, payload: $payload);
		} catch (NoTermijnDefinitieException $e) {
			// NOT debug. A case that matched no definition at all has no
			// statutory clock running, which is exactly the state that hid a
			// fleet-wide key mismatch behind a quiet log line. It is a valid
			// configuration for a case type with no beslistermijn, so it is a
			// warning rather than an error — but it is visible.
			$this->logger->warning(
				'Dossiq termijn: no active TermijnDefinitie for case type "' . $caseType . '", '
				. 'so case ' . $caseId . ' runs without a statutory term',
				['case' => $caseId, 'caseType' => $caseType, 'caseTypeId' => $caseTypeRef]
			);

			// A subsidy round declares a closing DATE and no lead time, so it
			// has no TermijnDefinitie and a refusal here is the ordinary path
			// rather than the broken one. The other clocks still bind, and the
			// fixed date still becomes the statutory term.
			$this->bindTheOtherClocks(caseId: $caseId, caseTypeRef: $caseTypeRef, payload: $payload);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq termijn: could not bind a term to case ' . $caseId . ': ' . $e->getMessage(),
				['case' => $caseId, 'caseType' => $caseType, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Bind the planned end, the internal target and the fixed closing date.
	 *
	 * Separate from the statutory bind above, and tolerant of its own failure:
	 * a service norm that could not be bound must never stop a case being
	 * created, and the warning is what says the case is carrying fewer clocks
	 * than its type declares.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeRef The case type as the case carries it.
	 * @param array<string, mixed> $payload The created case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	private function bindTheOtherClocks(string $caseId, string $caseTypeRef, array $payload): void {
		if ($this->caseTerms === null) {
			return;
		}

		try {
			$this->caseTerms->bindForCase(
				caseId: $caseId,
				caseTypeId: $caseTypeRef,
				plannedStart: trim((string)($payload['plannedStartDate'] ?? '')),
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq termijn: the planned end and the internal target could not be bound to case ' . $caseId,
				['case' => $caseId, 'caseType' => $caseTypeRef, 'error' => $e->getMessage()]
			);
		}
	}//end bindTheOtherClocks()

	/**
	 * Extract OR object array from an event.
	 *
	 * @param Event $event Event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractObject(Event $event): ?array {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
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
	 * Resolve the schema slug.
	 *
	 * The payload carries the schema as an ID (`@self.schema` is
	 * `ObjectEntity::$schema`, written as `(string) $schemaId`), and `@self`
	 * has no `schemaSlug` key. Reading those keys directly — as this method
	 * used to — returned an id or an empty string, so the `!== 'case'` guard in
	 * {@see self::handle()} always short-circuited and no AWB TermijnInstance
	 * has ever been bound to a case. Resolution goes through the shared
	 * {@see ObjectSchemaSlugResolver}.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return string
	 */
	private function resolveSchemaSlug(array $payload): string {
		return $this->slugResolver->resolveFromPayload(payload: $payload);
	}//end resolveSchemaSlug()
}//end class
