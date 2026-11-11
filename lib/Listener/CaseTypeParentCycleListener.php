<?php

/**
 * Dossiq case type parent cycle listener.
 *
 * REQ-CT-20: a chain of parents that returns to itself SHALL be refused on
 * save. The refusal existed, in `CaseTypeResolver::assertNoCycle()`, but its
 * only caller was the publish path. A person who set a parent in the case
 * type's Edit dialog saved through OpenRegister's generic object API, which
 * never reaches that path, so the loop was stored and nothing said so. The
 * blueprint reader then stopped the chain where it looped, which kept the
 * page from hanging and hid the loop at the same time.
 *
 * This listener guards the store itself, on OpenRegister's PRE-persist,
 * stoppable events, the same way the location BAG check and the immutability
 * guards do. A stopped event reaches the client as a 422 carrying the message
 * below, which names the loop link by link.
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Listener\Support\PreSaveCheck;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\ReferenceId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Refuse a case type save whose parent descends from the type itself.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeParentCycleListener implements IEventListener {
	/**
	 * The error code a refused save carries back to the client.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'caseType.parentCycle';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService Schema slug bridge.
	 * @param CaseTypeResolver $resolver        Owns the cycle rule and reads the chain.
	 * @param IL10N            $l10n            Translation service.
	 * @param LoggerInterface  $logger          Structured logger.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $resolver,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist case type save and refuse a parent that loops.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function handle(Event $event): void {
		(new PreSaveCheck(logger: $this->logger))->inspect(event: $event, inspect: $this->inspect(...));
	}//end handle()

	/**
	 * Refuse the save when the incoming parent closes a loop.
	 *
	 * The INCOMING parent is the one checked, against the STORED chain above
	 * it: that is exactly the chain the save would create. Setting Bezwaar's
	 * parent to Bezwaar (verkort), whose stored parent is Bezwaar, walks
	 * verkort -> Bezwaar and meets the type being saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The stoppable event.
	 * @param ObjectEntity                            $entity The entity being written.
	 *
	 * @return void
	 */
	private function inspect(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$payload = $this->caseTypePayload(entity: $entity);
		$parent = $this->referenceId(value: ($payload['parentCaseType'] ?? null));
		if ($payload === null || $parent === '') {
			return;
		}

		$self = $this->selfId(entity: $entity, payload: $payload);
		$names = $this->resolver->cycleFor(
			caseTypeId: $self,
			parentCaseTypeId: $parent,
			selfTitle: $this->text->textOf(value: ($payload['title'] ?? null))
		);
		if ($names === []) {
			return;
		}

		$this->refuse(event: $event, names: $names);
		$this->logger->info(
			'Dossiq: refused a case type save whose parent chain returns to itself',
			['caseType' => $self, 'parentCaseType' => $parent]
		);
	}//end inspect()

	/**
	 * The payload of a case type save, or null for anything else.
	 *
	 * @param ObjectEntity $entity The object being saved.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function caseTypePayload(ObjectEntity $entity): ?array {
		$payload = (new PreSaveCheck(logger: $this->logger))->read(entity: $entity, check: 'case type cycle check');
		if ($payload === null || (new CaseTypeSchema(settingsService: $this->settingsService))->contains(object: $payload) === false) {
			return null;
		}

		return $payload;
	}//end caseTypePayload()

	/**
	 * The id of the case type being saved: its uuid, or the id its payload carries.
	 *
	 * @param ObjectEntity         $entity  The object being saved.
	 * @param array<string, mixed> $payload Its payload.
	 *
	 * @return string Empty for a new case type that names no id.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function selfId(ObjectEntity $entity, array $payload): string {
		$self = (string)($entity->getUuid() ?? '');
		if ($self === '') {
			$self = $this->referenceId(value: ($payload['@self'] ?? ($payload['id'] ?? null)));
		}

		return $self;
	}//end selfId()

	/**
	 * Stop the save and say which case types inherit from each other.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The save.
	 * @param array<string>                           $names The case types in the loop.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $names): void {
		$event->setErrors(
			[
				'message' => $this->l10n->t(
					'A case type cannot inherit from itself: %s',
					[implode(' -> ', $names)]
				),
				// An ARRAY, like the location BAG check's `codes`, not a
				// bare string: the shared form dialog joins every string
				// value of `errors` into the message a person reads, and a
				// string code would be printed after the sentence.
				'codes' => [self::ERROR_CODE],
				'cycle' => $names,
			]
		);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function referenceId(mixed $value): string {
		return ReferenceId::of(value: $value);
	}//end referenceId()
}//end class
