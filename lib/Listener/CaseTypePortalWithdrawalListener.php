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
 * @spec openspec/changes/portal-citizen-writes-on-the-case/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\CaseType\PortalWithdrawalTarget;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

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
 * @spec openspec/changes/portal-citizen-writes-on-the-case/specs/portal-contribution/spec.md
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
	 * @param SettingsService        $settingsService Schema slug bridge.
	 * @param CaseTypeResolver       $resolver        The type's statuses, inherited ones included.
	 * @param CaseTypeStore          $store           The type's active workflow template.
	 * @param PortalWithdrawalTarget $target          Owns the rule.
	 * @param IL10N                  $l10n            Translation service.
	 * @param LoggerInterface        $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $resolver,
		private readonly CaseTypeStore $store,
		private readonly PortalWithdrawalTarget $target,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist case type save.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-citizen-writes-on-the-case/specs/portal-contribution/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getNewObject());
		}
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
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseTypeSchema(object: $payload) === false) {
			return;
		}

		$withdrawal = ($payload['portalWithdrawal'] ?? null);
		if (is_array($withdrawal) === false || $withdrawal === []) {
			return;
		}

		$self = (string)($entity->getUuid() ?? '');
		if ($self === '') {
			return;
		}

		$refusal = $this->target->refusal(
			withdrawal: $withdrawal,
			statuses: $this->statuses(caseTypeId: $self),
			moves: $this->moves(caseTypeId: $self)
		);
		if ($refusal === null) {
			return;
		}

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
		$this->logger->info(
			'Dossiq: refused a case type save whose portal withdrawal the workflow cannot write',
			['caseType' => $self, 'targetStatus' => ($withdrawal['targetStatus'] ?? null)]
		);
	}//end inspect()

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
		if ($refusal['reason'] === PortalWithdrawalTarget::NOT_OWN) {
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
		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $status) {
			$id = $this->store->rowId(row: $status);
			if ($id === '') {
				continue;
			}

			$title = trim((string)($status['name'] ?? ($status['title'] ?? '')));
			if ($title === '') {
				$title = $id;
			}

			$statuses[$id] = $title;
		}

		return $statuses;
	}//end statuses()

	/**
	 * The moves of the type's active template, whichever way they were stored.
	 *
	 * The authoring page writes `transitions` as a JSON string; reading that
	 * as no moves would wave every withdrawal through on the types that have
	 * the most moves.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return array<int, array<string, mixed>> The transitions.
	 */
	private function moves(string $caseTypeId): array {
		$raw = ($this->store->activeTemplate(caseTypeId: $caseTypeId)['transitions'] ?? []);
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_values(array_filter($raw, 'is_array'));
	}//end moves()

	/**
	 * Read an entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function payload(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: portal withdrawal check could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()

	/**
	 * Whether the supplied payload belongs to the `caseType` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return boolean True when this is a case type.
	 */
	private function isCaseTypeSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('case_type_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end isCaseTypeSchema()
}//end class
