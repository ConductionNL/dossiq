<?php

/**
 * Dossiq Term Status Clock Listener.
 *
 * Keeps a term's engine timer matching the status the case is actually in.
 * It reconciles rather than reacting to a transition: the question is only
 * ever "is this case in a status its term runs in, and is the timer stopped",
 * and answering that from the case as it stands needs no old value and cannot
 * drift when a move arrives twice or not at all.
 *
 * Failures are swallowed and logged, because a clock that could not be
 * reconciled must not fail the save that changed the status.
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
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\TermStatusClock;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciles the term clock with the case's status.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-term-declares-the-statuses-its-clock-runs-in-with-thresholds-as-days-or-shares-req-tcf-03
 */
class TermStatusClockListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema ids.
	 * @param TermStatusClock $clock           Suspends and resumes the timer.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermStatusClock $clock,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Reconcile the clock of the saved case.
	 *
	 * @param Event $event The dispatched OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-term-declares-the-statuses-its-clock-runs-in-with-thresholds-as-days-or-shares-req-tcf-03
	 */
	public function handle(Event $event): void {
		try {
			$case = $this->payloadOf(event: $event);
			if ($case === null || $this->isCaseSchema(object: $case) === false) {
				return;
			}

			$caseId = (string)(($case['id'] ?? '') ?: ($case['@self']['id'] ?? ''));
			if ($caseId === '') {
				return;
			}

			$this->clock->reconcile(
				caseId: $caseId,
				statusId: $this->referenced(value: ($case['status'] ?? null)),
				caseType: $this->referenced(value: ($case['caseType'] ?? null))
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a term clock could not be reconciled with the case status: ' . $e->getMessage()
			);
		}//end try
	}//end handle()

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
	 * Whether this object belongs to the `case` schema.
	 *
	 * @param array<string, mixed> $object The object payload.
	 *
	 * @return bool True when it does.
	 */
	private function isCaseSchema(array $object): bool {
		$configured = (string)$this->settingsService->getConfigValue('case_schema');
		if ($configured === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $configured
			|| str_ends_with($candidate, '/' . $configured)
		);
	}//end isCaseSchema()

	/**
	 * The saved object, whatever the event calls its getter.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The payload.
	 */
	private function payloadOf(Event $event): ?array {
		foreach (['getNewObject', 'getObject'] as $method) {
			if (method_exists($event, $method) === false) {
				continue;
			}

			$value = $event->{$method}();
			if (is_array($value) === true) {
				return $value;
			}

			if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
				$serialized = $value->jsonSerialize();
				if (is_array($serialized) === true) {
					return $serialized;
				}
			}
		}

		return null;
	}//end payloadOf()
}//end class
