<?php

/**
 * Dossiq Portal Submission Ledger
 *
 * Makes a portal submission once. Portaliq relays the key a form makes when it
 * opens (`Idempotency-Key`) with every send of the same answers. The first send
 * with a key claims it; a second send with the same key, from the same
 * resident to the same action, gets the first one's answer back, or hears that
 * the first is still being worked on. Live round 3 (14 Oct) showed why: a slow
 * answer read as a failure, the resident sent again, and got a second case.
 *
 * Generic: any portal action endpoint names its own scope and hands in the
 * subject from the verified assertion, never from the body.
 *
 * Kept in the distributed cache. Without a cache that can claim a key at
 * once (an `IMemcache`), the claim falls back to read-then-write, which still
 * stops a resend that comes after the first send was answered.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * Claims, remembers and releases submission keys.
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
 */
class PortalSubmissionLedger {

	/**
	 * The header the key arrives in.
	 */
	public const HEADER = 'Idempotency-Key';

	/**
	 * What a key may look like; anything else is treated as no key.
	 */
	private const KEY = '/^[A-Za-z0-9_-]{16,128}$/';

	/**
	 * How long a claim holds while the first send is worked on, in seconds.
	 */
	private const PROCESSING_TTL = 300;

	/**
	 * How long the first send's answer is kept for a resend, in seconds.
	 */
	private const ANSWER_TTL = 86400;

	/**
	 * The marker of a claim that has no answer yet.
	 */
	private const PROCESSING = 'processing';

	/**
	 * The cache the ledger is kept in.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Gives the distributed cache.
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createDistributed('dossiq_portal_submission');
	}//end __construct()

	/**
	 * The key a request carries, or '' when it carries none or one that is not a plain token.
	 *
	 * @param string $header The header's value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 */
	public function keyOf(string $header): string {
		$key = trim($header);
		if (preg_match(self::KEY, $key) !== 1) {
			return '';
		}

		return $key;
	}//end keyOf()

	/**
	 * Claim a key for a first send.
	 *
	 * @param string $scope      The action, e.g. `woo-verzoek`.
	 * @param string $subjectRef The resident, from the verified assertion.
	 * @param string $key        The submission key.
	 *
	 * @return array{state: string, answer?: array<string, mixed>} `claimed` for a first send,
	 *                                                              `processing` while the first is busy,
	 *                                                              `answered` with the first one's answer.
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 */
	public function claim(string $scope, string $subjectRef, string $key): array {
		$entry = $this->entry(scope: $scope, subjectRef: $subjectRef, key: $key);
		if ($this->claimEntry(entry: $entry) === true) {
			return ['state' => 'claimed'];
		}

		$held = $this->cache->get($entry);
		if (is_string($held) === true && $held !== self::PROCESSING) {
			$answer = json_decode($held, true);
			if (is_array($answer) === true) {
				return ['state' => 'answered', 'answer' => $answer];
			}
		}

		if ($held === null) {
			// The claim expired between the two reads: this send is the first now.
			$this->cache->set($entry, self::PROCESSING, self::PROCESSING_TTL);
			return ['state' => 'claimed'];
		}

		return ['state' => 'processing'];
	}//end claim()

	/**
	 * Claim an entry: at once on an `IMemcache`, else read-then-write.
	 *
	 * @param string $entry The cache entry.
	 *
	 * @return bool Whether this send claimed it.
	 */
	private function claimEntry(string $entry): bool {
		if ($this->cache instanceof IMemcache) {
			return $this->cache->add($entry, self::PROCESSING, self::PROCESSING_TTL) === true;
		}

		if ($this->cache->get($entry) !== null) {
			return false;
		}

		$this->cache->set($entry, self::PROCESSING, self::PROCESSING_TTL);
		return true;
	}//end claimEntry()

	/**
	 * Keep the first send's answer for a resend with the same key.
	 *
	 * @param string               $scope      The action.
	 * @param string               $subjectRef The resident.
	 * @param string               $key        The submission key.
	 * @param array<string, mixed> $answer     What the first send answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 */
	public function remember(string $scope, string $subjectRef, string $key, array $answer): void {
		$this->cache->set(
			$this->entry(scope: $scope, subjectRef: $subjectRef, key: $key),
			(string)json_encode($answer),
			self::ANSWER_TTL
		);
	}//end remember()

	/**
	 * Let the key go after a send that made nothing, so a corrected resend is a first send.
	 *
	 * @param string $scope      The action.
	 * @param string $subjectRef The resident.
	 * @param string $key        The submission key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 */
	public function release(string $scope, string $subjectRef, string $key): void {
		$this->cache->remove($this->entry(scope: $scope, subjectRef: $subjectRef, key: $key));
	}//end release()

	/**
	 * The cache entry of one key: the resident and the action are in it, so a
	 * key from someone else, or for another action, never matches.
	 *
	 * @param string $scope      The action.
	 * @param string $subjectRef The resident.
	 * @param string $key        The submission key.
	 *
	 * @return string
	 */
	private function entry(string $scope, string $subjectRef, string $key): string {
		return hash('sha256', $scope . "\n" . $subjectRef . "\n" . $key);
	}//end entry()
}//end class
