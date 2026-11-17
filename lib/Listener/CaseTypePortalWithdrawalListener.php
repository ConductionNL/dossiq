<?php

/**
 * Dossiq case type portal withdrawal listener.
 *
 * REQ-PORTAL-014, design D4 of portal-citizen-writes-on-the-case: a case type
 * MUST NOT be saved with a `portalWithdrawal` whose `targetStatus` the
 * workflow cannot write from each of its open statuses. portaliq offers the
 * withdrawal to a resident on the strength of that block alone, so a target
 * the workflow refuses is a button that fails in front of the resident.
 *
 * A case type is written straight to OpenRegister's object API by the case
 * type page, so this guards the store itself, on OpenRegister's pre-persist,
 * stoppable events, the way {@see CaseTypeParentCycleListener} does. A
 * stopped event reaches the client as a 422 carrying the sentence, which
 * names the status.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\PortalWithdrawalTargetInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\PreSaveCheck;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Refuse a case type save whose portal withdrawal lands where the workflow cannot go.
 *
 * 🔴 A TYPE WHOSE STATUSES CANNOT BE READ IS NOT JUDGED. A case type created
 * in the same import as its statuses has none stored yet when its own create
 * event fires, and refusing it then would make the seeded Woo request type
 * unimportable. Such a save passes, and the next edit is judged.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class CaseTypePortalWithdrawalListener implements IEventListener {

	/**
	 * The error code a refused save carries back to the client.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'caseType.portalWithdrawalUnreachable';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface        $settingsService Schema slug bridge.
	 * @param CaseTypeLookup $caseTypes The case type resolver and store, and the portal withdrawal target.
	 * @param IL10N                  $l10n            Translation service.
	 * @param LoggerInterface        $logger          Structured logger.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly CaseTypeLookup $caseTypes,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist case type save.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function handle(Event $event): void {
		(new PreSaveCheck(logger: $this->logger))->inspect(event: $event, inspect: $this->inspect(...));
	}//end handle()

	/**
	 * Refuse the save when the incoming withdrawal cannot be written.
	 *
	 * The INCOMING block is judged against the STORED statuses and workflow,
	 * which is exactly what a resident's withdrawal would meet after the save.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The stoppable event.
	 * @param ObjectEntity                            $entity The entity being written.
	 *
	 * @return void
	 */
	private function inspect(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$payload = $this->caseTypePayload(entity: $entity);
		$withdrawal = $this->withdrawalOf(payload: $payload);
		$self = (string)($entity->getUuid() ?? '');
		if ($payload === null || $withdrawal === [] || $self === '') {
			return;
		}

		$refusal = $this->caseTypes->target->refusal(
			withdrawal: $withdrawal,
			statuses: $this->statuses(caseTypeId: $self),
			moves: $this->moves(caseTypeId: $self, payload: $payload)
		);
		if ($refusal === null) {
			return;
		}

		$this->refuse(event: $event, refusal: $refusal);
		$this->logger->info(
			'Dossiq: refused a case type save whose portal withdrawal the workflow cannot write',
			['caseType' => $self, 'targetStatus' => ($withdrawal['targetStatus'] ?? null)]
		);
	}//end inspect()

	/**
	 * The payload of a case type save, or null for anything else.
	 *
	 * @param ObjectEntity $entity The object being saved.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function caseTypePayload(ObjectEntity $entity): ?array {
		$payload = (new PreSaveCheck(logger: $this->logger))->read(entity: $entity, check: 'portal withdrawal check');
		if ($payload === null || (new CaseTypeSchema(settingsService: $this->settingsService))->contains(object: $payload) === false) {
			return null;
		}

		return $payload;
	}//end caseTypePayload()

	/**
	 * The portal withdrawal the case type configures, or an empty array when it configures none.
	 *
	 * @param array<string, mixed>|null $payload The case type payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function withdrawalOf(?array $payload): array {
		$withdrawal = ($payload['portalWithdrawal'] ?? null);
		if (is_array($withdrawal) === false) {
			return [];
		}

		return $withdrawal;
	}//end withdrawalOf()

	/**
	 * Stop the save and say why the workflow cannot write the withdrawal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The save.
	 * @param array<string, mixed>                    $refusal The refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $refusal): void {
		$event->setErrors(
			[
				'message' => $this->sentence(refusal: $refusal),
				// An array, like the parent cycle check's: the shared form
				// dialog joins every STRING value of `errors` into the message
				// a person reads, and a string code would be printed after it.
				'codes' => [self::ERROR_CODE],
			]
		);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * The sentence a refusal reads as, naming the status at fault.
	 *
	 * Literal `t()` calls, so the catalogue extraction finds both sentences.
	 *
	 * @param array{reason: string, parameters: array<int, string>} $refusal The refusal.
	 *
	 * @return string The translated sentence.
	 */
	private function sentence(array $refusal): string {
		if ($refusal['reason'] === PortalWithdrawalTargetInterface::NOT_OWN) {
			return $this->l10n->t(
				'A withdrawal lands on %s, which is not a status of this case type. Pick one of its own statuses.',
				$refusal['parameters']
			);
		}

		return $this->l10n->t(
			'A case in %1$s cannot move to %2$s, so a withdrawal there would fail. Add that move, or pick another status.',
			$refusal['parameters']
		);
	}//end sentence()

	/**
	 * The type's statuses, id to title.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return array<string, string> The statuses.
	 */
	private function statuses(string $caseTypeId): array {
		$statuses = [];
		foreach ($this->caseTypes->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $status) {
			$id = $this->caseTypes->store->rowId(row: $status);
			if ($id === '') {
				continue;
			}

			$title = $this->text->textOf(value: ($status['name'] ?? ($status['title'] ?? null)), language: $this->l10n->getLanguageCode());
			if ($title === '') {
				$title = $id;
			}

			$statuses[$id] = $title;
		}

		return $statuses;
	}//end statuses()

	/**
	 * The moves of the flow the saved case type follows, whichever way they were stored.
	 *
	 * The flow is the one the incoming payload names in `workflowDefinition`,
	 * else the type's active template ({@see \OCA\Dossiq\Service\CaseTypeStore::activeTemplate()}).
	 * Judging an update by the stored active template refused the shipped Woo
	 * case type on every upgraded instance, because that instance still held
	 * an older basis flow without the withdrawal move.
	 *
	 * The authoring page writes `transitions` as a JSON string; reading that
	 * as no moves would wave every withdrawal through on the types that have
	 * the most moves.
	 *
	 * @param string $caseTypeId The case type.
	 * @param array<string, mixed> $payload The saved case type; its `workflowDefinition` names the flow.
	 *
	 * @return array<int, array<string, mixed>> The transitions.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function moves(string $caseTypeId, array $payload): array {
		$named = ($payload['workflowDefinition'] ?? null);
		if (is_string($named) === false && is_array($named) === false) {
			$named = null;
		}

		$raw = ($this->caseTypes->store->activeTemplate(caseTypeId: $caseTypeId, named: $named)['transitions'] ?? []);
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_values(array_filter($raw, 'is_array'));
	}//end moves()

}//end class
