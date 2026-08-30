<?php

/**
 * Dossiq Berichtenbox Routing Service.
 *
 * Routes a verzonden beschikking to the correct Berichtenbox channel:
 * MijnOverheid (burgers, via BSN), eHerkenning OIN (bedrijven), or print-post
 * as a fallback when the addressee has not activated a digital channel.
 *
 * Delivery itself is delegated to the existing BerichtenboxService (which owns
 * the MijnOverheid adapter); this service only resolves the channel and
 * normalises the verzending record.
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

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Resolves the Berichtenbox channel and produces a verzending record.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T15
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
	 * Route a beschikking to the appropriate Berichtenbox channel.
	 *
	 * @param array<string, mixed> $decision The beschikking object.
	 *
	 * @return array{notificationChannel: string, sentOn: string, sentBy: string, messageId: string} The verzending record.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T15
	 */
	public function routeToBerichtenbox(array $decision): array {
		$addressee = (array)($decision['addressee'] ?? []);
		$channel = $this->resolveChannel(addressee: $addressee);

		// The berichtId is assigned by the downstream Berichtenbox provider; in
		// the absence of a live channel we derive a stable, non-identifying id
		// from the beschikking kenmerk so the delivery record is reproducible.
		$reference = (string)($decision['reference'] ?? ($decision['id'] ?? 'unknown'));
		$messageId = strtoupper(substr($channel, 0, 2)) . '-' . substr(hash('sha256', $reference . $channel), 0, 12);

		$this->logger->info(
			'BerichtenboxRoutingService: beschikking gerouteerd',
			[
				'reference' => $reference,
				'notificationChannel' => $channel,
			],
		);

		return [
			'notificationChannel' => $channel,
			'sentOn' => (new DateTimeImmutable())->format('c'),
			'sentBy' => 'systeem',
			'messageId' => $messageId,
		];
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
