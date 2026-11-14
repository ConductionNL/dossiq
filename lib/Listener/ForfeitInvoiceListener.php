<?php

/**
 * Dossiq ForfeitInvoiceListener
 *
 * Hands an enforcement act to ForfeitInvoiceRaiser at the moment it becomes
 * forfeited (`verbeurd`), once: a save that keeps it forfeited raises nothing.
 * When no invoice could be raised the act stays forfeited and the failure is
 * logged; the save that forfeited it is not undone.
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
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Exception\ForfeitInvoiceNotRaisedException;
use OCA\Dossiq\Kernel\Contracts\ForfeitInvoiceRaiserInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Raises the invoice when an enforcement act becomes forfeited.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */
class ForfeitInvoiceListener implements IEventListener {
	/**
	 * The status a forfeited act carries.
	 *
	 * @var string
	 */
	private const FORFEITED = 'verbeurd';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Names the enforcement act schema.
	 * @param ForfeitInvoiceRaiserInterface $raiser Raises the invoice.
	 * @param LoggerInterface $logger Records an invoice that was not raised.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly ForfeitInvoiceRaiserInterface $raiser,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise once, when the act's status moves to forfeited.
	 *
	 * @param Event $event The OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$entity = $event->getNewObject();
		if ($this->isActSchema(actual: (string)$entity->getSchema()) === false) {
			return;
		}

		$after = (array)$entity->getObject();
		$before = (array)($event->getOldObject()?->getObject() ?? []);
		if ($this->becameForfeited(after: $after, before: $before) === false) {
			return;
		}

		$this->raise(after: $after);
	}//end handle()

	/**
	 * Whether the object's schema is the configured handhavingsactie schema.
	 *
	 * @param string $actual The object's schema.
	 *
	 * @return bool True for an act.
	 */
	private function isActSchema(string $actual): bool {
		$schema = (string)$this->settingsService->getConfigValue('handhavingsactie_schema');
		if ($schema === '' || ($actual !== $schema && str_ends_with($actual, '/' . $schema) === false)) {
			return false;
		}

		return true;
	}//end isActSchema()

	/**
	 * Whether this update moved the act's status to forfeited.
	 *
	 * @param array<string, mixed> $after  The act after the update.
	 * @param array<string, mixed> $before The act before the update.
	 *
	 * @return bool True when it became forfeited now.
	 */
	private function becameForfeited(array $after, array $before): bool {
		if (($after['status'] ?? '') !== self::FORFEITED || ($before['status'] ?? '') === self::FORFEITED) {
			return false;
		}

		return true;
	}//end becameForfeited()

	/**
	 * Raise the invoice, logging when none was raised.
	 *
	 * @param array<string, mixed> $after The forfeited act.
	 *
	 * @return void
	 */
	private function raise(array $after): void {
		try {
			$this->raiser->raise(action: $after);
		} catch (ForfeitInvoiceNotRaisedException $e) {
			$this->logger->warning(
				'Dossiq: a forfeited penalty raised no invoice',
				['case' => $e->getCaseId(), 'reason' => $e->getReasonCode(), 'error' => $e->getPrevious()?->getMessage()]
			);
		}
	}//end raise()
}//end class
