<?php

/**
 * How a digest reaches a person: by being written down.
 *
 * ADR-031, and the reason it is a record rather than a `notify()` call. The
 * canonical dialect routes a notification from a declared trigger on a schema,
 * so writing one `workDigest` object is the whole dispatch: the register
 * decides the channel, the recipient and the wording, and dossiq never calls
 * the notification manager. That also makes "a digest was sent" and "a digest
 * was not sent" both readable afterwards, which a fire-and-forget call is not.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Writes the digest record the register turns into a message.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class DigestDispatcher {
	use SearchesObjects;

	/**
	 * The schema a digest is written to.
	 *
	 * @var string
	 */
	public const SCHEMA = 'workDigest';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Send one composed digest.
	 *
	 * @param array<string, mixed> $digest The composed digest.
	 *
	 * @return string The record's id.
	 *
	 * @throws RuntimeException When the digest cannot be written.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function send(array $digest): string {
		$objects = $this->settings->getObjectService();
		$register = trim((string)$this->settings->getConfigValue('register'));
		if ($objects === null || $register === '') {
			throw new RuntimeException('Dossiq cannot write a digest without a configured register.');
		}

		$saved = $this->saveObjectAsArray(
			objectService: $objects,
			register: $register,
			schema: self::SCHEMA,
			object: [
				'person' => (string)$digest['person'],
				'day' => (string)$digest['day'],
				'waiting' => (int)$digest['waiting'],
				'late' => (int)$digest['late'],
				'summary' => $this->summaryOf(digest: $digest),
			]
		);

		if ($saved === null) {
			throw new RuntimeException('The digest was not written.');
		}

		$self = ($saved['@self'] ?? []);
		if (is_array($self) === true && trim((string)($self['id'] ?? '')) !== '') {
			return (string)$self['id'];
		}

		return (string)($saved['id'] ?? '');
	}//end send()

	/**
	 * The one line the message quotes.
	 *
	 * Counts, not prose: the digest's job is to get somebody to open the
	 * queue, and a message that reproduces the queue is a message they read
	 * instead of opening it.
	 *
	 * @param array<string, mixed> $digest The composed digest.
	 *
	 * @return string The summary line.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function summaryOf(array $digest): string {
		$titles = [];
		foreach (($digest['named'] ?? []) as $item) {
			$titles[] = (string)$item['title'];
		}

		return implode(', ', $titles);
	}//end summaryOf()
}//end class
