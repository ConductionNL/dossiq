<?php

/**
 * Dossiq Dependent Term Listener.
 *
 * When a term event that MOVES a date is recorded, the cases waiting on that
 * case are offered the same move. The trigger is the event row rather than the
 * instance write, because the event is where the number of days lives and it is
 * written once per move: watching the instance would fire on every rewrite of
 * the same dates and offer the same move twice.
 *
 * The reaction is asynchronous by intent (ADR-078): the caseworker who
 * extended a term waits on their own extension, not on a fan-out over every
 * case that waits on theirs. Failures are swallowed and logged, because an
 * offer that could not be written must not undo an extension that was.
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
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\DependentTermOfferInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Listener\Support\EventObjectPayload;
use OCA\Dossiq\Listener\Support\SchemaMembership;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Offers a moved term to the cases waiting on it.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
 */
class DependentTermListener implements IEventListener {
	use SearchesObjects;

	/**
	 * The schema whose creates this listener reads.
	 */
	public const WATCHED_SCHEMA_CONFIG_KEY = 'termijn_gebeurtenis_schema';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface    $settingsService Schema ids and the object service.
	 * @param TermijnServiceInterface     $terms           Reads the instance the event names.
	 * @param DependentTermOfferInterface $offers          Writes the offer.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly TermijnServiceInterface $terms,
		private readonly DependentTermOfferInterface $offers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the create of a term event.
	 *
	 * @param Event $event The dispatched OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function handle(Event $event): void {
		try {
			$object = EventObjectPayload::firstOf(event: $event, methods: ['getObject', 'getNewObject']);
			if ($object === null || $this->isTermEvent(object: $object) === false) {
				return;
			}

			$this->offerMovedTerm(object: $object);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a moved term could not be offered to its dependents: ' . $e->getMessage()
			);
		}//end try
	}//end handle()

	/**
	 * Offer a term event that moved the term to the dependents of its case.
	 *
	 * @param array<string, mixed> $object The term event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	private function offerMovedTerm(array $object): void {
		$days = $this->movedDays(object: $object);
		if ($days <= 0) {
			return;
		}

		$caseId = $this->caseBehind(object: $object);
		if ($caseId === '') {
			return;
		}

		$this->offers->offer(
			sourceCaseId: $caseId,
			sourceTitle: $this->titleOf(caseId: $caseId),
			daysImpact: $days
		);
	}//end offerMovedTerm()

	/**
	 * The days a moving term event moved the term by, 0 for any other event.
	 *
	 * @param array<string, mixed> $object The term event.
	 *
	 * @return int The days.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	private function movedDays(array $object): int {
		if (in_array((string)($object['type'] ?? ''), DependentTermOfferInterface::MOVING_EVENTS, true) === false) {
			return 0;
		}

		return (int)($object['daysImpact'] ?? 0);
	}//end movedDays()

	/**
	 * The case the event's instance is bound to.
	 *
	 * @param array<string, mixed> $object The term event.
	 *
	 * @return string The case uuid, empty when it cannot be read.
	 */
	private function caseBehind(array $object): string {
		$instanceId = (string)($object['deadlineInstance'] ?? '');
		if ($instanceId === '') {
			return '';
		}

		$instance = $this->terms->getTermijnInstance(termInstanceId: $instanceId);
		if ($instance === null) {
			return '';
		}

		$case = ($instance['case'] ?? '');
		if (is_array($case) === true) {
			return (string)($case['id'] ?? '');
		}

		return (string)$case;
	}//end caseBehind()

	/**
	 * What the case that moved is called, for the sentence on the task.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string The title, empty when it cannot be read.
	 */
	private function titleOf(string $caseId): string {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return '';
		}

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			return '';
		}

		$title = (string)($case['title'] ?? '');
		if ($title !== '') {
			return $title;
		}

		return (string)($case['identifier'] ?? '');
	}//end titleOf()

	/**
	 * Whether this object belongs to the term event schema.
	 *
	 * @param array<string, mixed> $object The object payload.
	 *
	 * @return bool True when it does.
	 */
	private function isTermEvent(array $object): bool {
		return SchemaMembership::belongsTo(
			object: $object,
			expected: (string)$this->settingsService->getConfigValue(self::WATCHED_SCHEMA_CONFIG_KEY)
		);
	}//end isTermEvent()
}//end class
