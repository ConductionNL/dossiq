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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseTermsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTypeSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeTermStartInterface;
use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermResolutionInterface;
use OCA\Dossiq\Listener\Support\NewCaseTermBinding;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\Support\ReferencedUuid;
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
	 * Binds the statutory term and the other clocks.
	 *
	 * @var NewCaseTermBinding
	 */
	private readonly NewCaseTermBinding $terms;

	/**
	 * Constructor.
	 *
	 * @param TermijnServiceInterface $termService TermijnService.
	 * @param ObjectSchemaSlugResolverInterface $slugResolver Schema id-to-slug resolver.
	 * @param CaseTypeSlugResolverInterface $caseTypeSlugs Case-type uuid-to-slug resolver.
	 * @param LoggerInterface $logger Logger.
	 * @param CaseTermsServiceInterface|null $caseTerms The clocks beside the statutory one: the
	 *        planned end, the internal target, and the fixed closing date when the case
	 *        type declares one instead of a lead time. Optional so a container that
	 *        cannot build it leaves the statutory bind exactly as it was.
	 * @param TermResolutionInterface|null $resolution The case type's own first-response term,
	 *        which wins over the Awb default when one is declared. Optional for the
	 *        same reason as the parameter above it.
	 * @param IntakeTermStartInterface|null $intake When the request arrived and when its clock
	 *        starts, on the calendar the term counts on. The term starts there, not at
	 *        the moment the case row was written (REQ-OTE-02). Optional so a build
	 *        without it starts the term at creation exactly as before.
	 * @param ReceiptMoment $receipt Tells a receipt from a draft save: a draft binds no
	 *        term, and sending it does (decision 180).
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		TermijnServiceInterface $termService,
		private readonly ObjectSchemaSlugResolverInterface $slugResolver,
		private readonly CaseTypeSlugResolverInterface $caseTypeSlugs,
		private readonly LoggerInterface $logger,
		?CaseTermsServiceInterface $caseTerms = null,
		?TermResolutionInterface $resolution = null,
		?IntakeTermStartInterface $intake = null,
		private readonly ReceiptMoment $receipt = new ReceiptMoment(),
	) {
		$this->terms = new NewCaseTermBinding(
			termService: $termService,
			logger: $logger,
			caseTerms: $caseTerms,
			resolution: $resolution,
			intake: $intake,
		);
	}//end __construct()

	/**
	 * The uuid behind a reference, bare or extended.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The uuid, empty when there is none.
	 */
	private function referenced(mixed $value): string {
		return ReferencedUuid::inReference(value: $value);
	}//end referenced()

	/**
	 * Handle a case-created event.
	 *
	 * @param Event $event Event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 * @spec openspec/specs/termijn-binding/spec.md#requirement-the-statutory-term-counts-from-receipt-req-ote-02
	 * @spec openspec/specs/termijn-binding/spec.md
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function handle(Event $event): void {
		$payload = $this->createdCase(event: $event);
		if ($payload === null) {
			return;
		}

		$identity = $this->caseIdentity(payload: $payload);
		if ($identity === null) {
			return;
		}

		$this->terms->bind(identity: $identity, payload: $payload, context: $this->termContext(payload: $payload));
	}//end handle()





	/**
	 * The payload of a created (or sent) case, or null for anything else.
	 *
	 * @param Event $event Event.
	 *
	 * @return array<string, mixed>|null The case payload.
	 */
	private function createdCase(Event $event): ?array {
		// The create of a case that is not a draft, or the moment a draft is
		// sent. A draft binds no statutory term (decision 180).
		$payload = $this->receipt->receivedObject(event: $event, for: self::class);
		if ($payload === null) {
			return null;
		}

		if ($this->resolveSchemaSlug(payload: $payload) !== 'case') {
			return null;
		}

		return $payload;
	}//end createdCase()

	/**
	 * The case id, case type reference and case type slug of a new case.
	 *
	 * @param array<string, mixed> $payload The case payload.
	 *
	 * @return NewCaseIdentity|null Null when the case cannot bind a term.
	 */
	private function caseIdentity(array $payload): ?NewCaseIdentity {
		$caseId = (string)($payload['id'] ?? ($payload['uuid'] ?? ''));
		$caseTypeRef = (string)($payload['caseType'] ?? '');
		if ($caseId === '' || $caseTypeRef === '') {
			return null;
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

			return null;
		}

		return new NewCaseIdentity(caseId: $caseId, caseTypeRef: $caseTypeRef, caseType: $caseType);
	}//end caseIdentity()

	/**
	 * The resolution context of a new case's term.
	 *
	 * @param array<string, mixed> $payload The case payload.
	 *
	 * @return array<string, mixed> The organisation, service and priority.
	 */
	private function termContext(array $payload): array {
		// Which term this case actually gets. One case type can carry several:
		// one per participating organisation, per service and per priority,
		// which is what lets a gemeenschappelijke regeling run one case type
		// for five municipalities with five agreed norms. The resolution is
		// made here, where the case's own values are in hand, and is recorded
		// on the instance so a disputed date can be explained a year later.
		return [
			'organisation' => $this->referenced(value: ($payload['competentAuthority'] ?? null)),
			'service' => $this->referenced(value: ($payload['procedureType'] ?? null)),
			'priority' => $this->referenced(value: ($payload['priority'] ?? null)),
			'caseTypeId' => $this->referenced(value: ($payload['caseType'] ?? null)),
		];
	}//end termContext()


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
