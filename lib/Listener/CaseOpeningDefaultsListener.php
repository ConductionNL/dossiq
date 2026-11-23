<?php

/**
 * Dossiq case opening defaults listener.
 *
 * A case written through OpenRegister's API or a dialog (New case, Mail's
 * "New Case from this email") opens the way a case the intake writes does:
 * the opening fields it lacks (its case type's first status, default handler,
 * default team and confidentiality) come from its case type, through the same
 * {@see \OCA\Dossiq\Service\Intake\CaseOpeningDefaults} the intake's
 * SystemCaseStore applies. Without it such a case had no team and never
 * reached its team's queue.
 *
 * Runs on the pre-persist create event and only fills gaps: a field the
 * writer gave is kept.
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

use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\PreSaveCheck;
use OCA\Dossiq\Listener\Support\ReadsCaseWrites;
use OCA\Dossiq\Service\Intake\CaseOpeningDefaults;
use OCA\Dossiq\Service\Support\ReferenceId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fill a new case's opening fields from its case type.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseOpeningDefaultsListener implements IEventListener {
	use ReadsCaseWrites;

	/**
	 * The check named in the log when a payload cannot be read.
	 */
	private const PAYLOAD_CHECK = 'case opening defaults listener';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface  $settingsService Schema slug bridge.
	 * @param CaseTypeResolverInterface $resolver        The effective blueprint of a case type.
	 * @param LoggerInterface           $logger          Structured logger.
	 * @param CaseOpeningDefaults|null  $defaults        The opening defaults; a fresh one when null.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly CaseTypeResolverInterface $resolver,
		private readonly LoggerInterface $logger,
		private readonly ?CaseOpeningDefaults $defaults = null,
	) {
	}//end __construct()

	/**
	 * Fill in the opening fields a case about to be created lacks.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		(new PreSaveCheck(logger: $this->logger))->inspect(event: $event, inspect: $this->apply(...));
	}//end handle()

	/**
	 * Write the opening fields the case lacks, from its case type.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The case being written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$caseTypeId = ReferenceId::of(value: ($payload['caseType'] ?? null));
		if ($caseTypeId === '') {
			return;
		}

		try {
			$caseType = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: the case type of a new case could not be read for its opening defaults', ['caseType' => $caseTypeId, 'error' => $e->getMessage()]);
			return;
		}

		if ($caseType === []) {
			return;
		}

		$case    = array_merge($payload, $event->getModifiedData());
		$filled  = ($this->defaults ?? new CaseOpeningDefaults())->apply(case: $case, caseType: $caseType);
		$changes = array_diff_key($filled, $case);
		foreach ($filled as $field => $value) {
			if (array_key_exists($field, $case) === true && $case[$field] !== $value) {
				$changes[$field] = $value;
			}
		}

		if ($changes === []) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $changes));
	}//end apply()
}//end class
