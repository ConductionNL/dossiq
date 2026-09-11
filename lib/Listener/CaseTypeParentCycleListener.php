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

use OCA\Dossiq\Service\CaseTypeResolver;
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
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $resolver,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
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
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getNewObject());
		}
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
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseTypeSchema(object: $payload) === false) {
			return;
		}

		$parent = $this->referenceId(value: ($payload['parentCaseType'] ?? null));
		if ($parent === '') {
			return;
		}

		$self = (string)($entity->getUuid() ?? '');
		if ($self === '') {
			$self = $this->referenceId(value: ($payload['@self'] ?? ($payload['id'] ?? null)));
		}

		$names = $this->resolver->cycleFor(
			caseTypeId: $self,
			parentCaseTypeId: $parent,
			selfTitle: (string)($payload['title'] ?? '')
		);
		if ($names === []) {
			return;
		}

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
		$this->logger->info(
			'Dossiq: refused a case type save whose parent chain returns to itself',
			['caseType' => $self, 'parentCaseType' => $parent]
		);
	}//end inspect()

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
				'Dossiq: case type cycle check could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end referenceId()

	/**
	 * Whether the supplied payload belongs to the `caseType` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a case type.
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
