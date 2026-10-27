<?php

/**
 * Dossiq one statutory term per case listener.
 *
 * A case runs one statutory term at a time. A statutory term written for a
 * case (by an example set, an import, the API or the term engine itself) takes
 * over from the one that ran before it: that one is withdrawn. Without this a
 * case created by an example set ran two terms side by side, the one the term
 * engine starts when a case is filed and the one the set declares, and the
 * queue, the term reports and the term counts disagreed on which one counted.
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
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Withdraws the statutory term a newly written one takes over from.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see TermKind} is a vocabulary: the four
 *                                       kinds and the reading of an absent one.
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class OneStatutoryTermPerCaseListener implements IEventListener {

	/**
	 * The statuses of a term that is still running.
	 *
	 * @var array<int, string>
	 */
	private const RUNNING = ['lopend', 'paused', 'verlengd', 'exceeded'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Names the term instance schema.
	 * @param TermijnService  $terms           Reads the case's terms and writes the withdrawal.
	 * @param LoggerInterface $logger          Logs a withdrawal that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $terms,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Withdraw the case's earlier running statutory term when a statutory term is created for it.
	 *
	 * Never blocks the write that triggered it: a failure is logged.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$entity   = $event->getObject();
		$expected = (string)$this->settingsService->getConfigValue(key: 'termijn_instance_schema');
		if ($expected === '' || (string)$entity->getSchema() !== $expected) {
			return;
		}

		$written = (array)($entity->getObject() ?? []);
		$caseId  = $this->referenced(value: ($written['case'] ?? ''));
		if ($caseId === '' || TermKind::ofInstance(instance: $written) !== TermKind::STATUTORY) {
			return;
		}

		try {
			foreach ($this->terms->instancesForCase(caseId: $caseId) as $other) {
				$id = (string)($other['id'] ?? '');
				if ($this->takenOver(other: $other, id: $id, writtenId: (string)$entity->getUuid()) === true) {
					$this->terms->updateTermijnInstance(termInstanceId: $id, patch: ['status' => 'withdrawn']);
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'Dossiq: a new statutory term could not withdraw the one running before it',
				context: ['case' => $caseId, 'exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Whether another term of the case is the running statutory term the new one takes over from.
	 *
	 * @param array<string, mixed> $other     The other term.
	 * @param string               $id        Its id.
	 * @param string               $writtenId The new term's id.
	 *
	 * @return bool
	 */
	private function takenOver(array $other, string $id, string $writtenId): bool {
		return $id !== ''
			&& $id !== $writtenId
			&& TermKind::ofInstance(instance: $other) === TermKind::STATUTORY
			&& in_array((string)($other['status'] ?? ''), self::RUNNING, true) === true;
	}//end takenOver()

	/**
	 * The uuid behind a reference, bare or extended.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The uuid, empty when there is none.
	 */
	private function referenced(mixed $value): string {
		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ''));
		}

		if (is_string($value) === true) {
			return trim($value);
		}

		return '';
	}//end referenced()
}//end class
