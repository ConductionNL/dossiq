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
use OCA\Dossiq\Service\Money\ForfeitInvoiceRaiser;
use OCA\Dossiq\Service\SettingsService;
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
	 * @param SettingsService $settingsService Names the enforcement act schema.
	 * @param ForfeitInvoiceRaiser $raiser Raises the invoice.
	 * @param LoggerInterface $logger Records an invoice that was not raised.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ForfeitInvoiceRaiser $raiser,
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
		$schema = (string)$this->settingsService->getConfigValue('handhavingsactie_schema');
		$actual = (string)$entity->getSchema();
		if ($schema === '' || ($actual !== $schema && str_ends_with($actual, '/' . $schema) === false)) {
			return;
		}

		$after = (array)$entity->getObject();
		$before = (array)($event->getOldObject()?->getObject() ?? []);
		if (($after['status'] ?? '') !== self::FORFEITED || ($before['status'] ?? '') === self::FORFEITED) {
			return;
		}

		try {
			$this->raiser->raise(action: $after);
		} catch (ForfeitInvoiceNotRaisedException $e) {
			$this->logger->warning(
				'Dossiq: a forfeited penalty raised no invoice',
				['case' => $e->getCaseId(), 'reason' => $e->getReasonCode(), 'error' => $e->getPrevious()?->getMessage()]
			);
		}
	}//end handle()
}//end class
