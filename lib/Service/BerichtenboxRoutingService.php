<?php

/**
 * Dossiq Berichtenbox Routing Service.
 *
 * Routes a verzonden beschikking to the correct Berichtenbox channel:
 * MijnOverheid (burgers, via BSN), eHerkenning OIN (bedrijven), or print-post
 * as a fallback when the addressee has not activated a digital channel.
 *
 * It ONLY picks the channel. It used to also answer a `messageId` hashed from
 * the beschikking reference, a `sentOn` of now and a `sentBy` of `systeem`,
 * while calling no transport at all, and every caller stored that as proof of
 * dispatch. A requester notice goes out through
 * {@see \OCA\Dossiq\Service\Notification\RequesterNoticeSender}, which
 * calls a real transport and answers the transport's own message id; nothing
 * here may read as a send (REQ-WRN-001).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T15
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;

/**
 * Resolves the Berichtenbox channel. Sends nothing, and says nothing that reads as a send.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T15
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */
class BerichtenboxRoutingService {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The Berichtenbox channel a beschikking's addressee is reachable on.
	 *
	 * @param array<string, mixed> $decision The beschikking object.
	 *
	 * @return array{notificationChannel: string} The channel, and nothing that reads as a send.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T15
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function routeToBerichtenbox(array $decision): array {
		$addressee = (array)($decision['addressee'] ?? []);
		$channel = $this->resolveChannel(addressee: $addressee);

		$this->logger->info(
			'BerichtenboxRoutingService: channel chosen for a beschikking, nothing sent',
			[
				'reference' => (string)($decision['reference'] ?? ($decision['id'] ?? '')),
				'notificationChannel' => $channel,
			],
		);

		return ['notificationChannel' => $channel];
	}//end routeToBerichtenbox()

	/**
	 * Resolve the Berichtenbox channel for an addressee.
	 *
	 * @param array<string, mixed> $addressee The addressee block.
	 *
	 * @return string The channel slug.
	 */
	private function resolveChannel(array $addressee): string {
		$type = (string)($addressee['type'] ?? '');
		$bevestigd = ($addressee['messageBoxConfirmed'] ?? false) === true;

		if ($bevestigd === false) {
			return 'print-post';
		}

		if ($type === 'burger' && ($addressee['bsn'] ?? '') !== '') {
			return 'berichtenbox-mijnoverheid';
		}

		if ($type === 'bedrijf' && ($addressee['oin'] ?? '') !== '') {
			return 'berichtenbox-eherkenning';
		}

		return 'print-post';
	}//end resolveChannel()
}//end class
