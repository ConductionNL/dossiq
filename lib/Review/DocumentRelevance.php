<?php

/**
 * Dossiq document review: relevance of each collected document, apart from the verdict.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the `documentReview` of each document on a case.
 *
 * Whether a document is about the request at all is recorded here, apart
 * from the disclosure verdict on `wooDocumentAssessment`. A document without
 * a review reads as `unmarked`, so a document nobody looked at is never
 * taken for one that was set aside.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
 */
class DocumentRelevance {

	use SearchesObjects;

	public const UNMARKED = 'unmarked';

	public const IN_SCOPE = 'in-scope';

	public const OUT_OF_SCOPE = 'out-of-scope';

	public const RELEVANCES = [self::UNMARKED, self::IN_SCOPE, self::OUT_OF_SCOPE];

	/**
	 * The app config key of the review schema.
	 */
	public const SCHEMA_KEY = 'document_review_schema';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the review schema can be read. Without it, relevance is not recorded at all.
	 *
	 * @return bool True when OpenRegister and the review schema are configured.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function isAvailable(): bool {
		return $this->settingsService->getObjectService() !== null
			&& $this->settingsService->getConfigValue('register') !== ''
			&& $this->settingsService->getConfigValue(self::SCHEMA_KEY) !== '';
	}//end isAvailable()

	/**
	 * Every review of a case, keyed by document.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, array<string, mixed>> The reviews by documentRef.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function forCase(string $caseId): array {
		if ($this->isAvailable() === false || $caseId === '') {
			return [];
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $this->settingsService->getObjectService(),
			register: $this->settingsService->getConfigValue('register'),
			schema: $this->settingsService->getConfigValue(self::SCHEMA_KEY),
			filters: ['case' => $caseId, '_limit' => 1000],
		);
		$reviews = [];
		foreach ($rows as $row) {
			$ref = (string)($row['documentRef'] ?? '');
			if ($ref !== '') {
				$reviews[$ref] = $row;
			}
		}

		return $reviews;
	}//end forCase()

	/**
	 * The relevance of a document as its review holds it, `unmarked` without one.
	 *
	 * @param array<string, array<string, mixed>> $reviews The case's reviews, by document.
	 * @param string $documentRef The document.
	 *
	 * @return string One of self::RELEVANCES.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public static function relevanceOf(array $reviews, string $documentRef): string {
		$relevance = (string)(($reviews[$documentRef] ?? [])['relevance'] ?? self::UNMARKED);
		if (in_array($relevance, self::RELEVANCES, true) === false) {
			return self::UNMARKED;
		}

		return $relevance;
	}//end relevanceOf()

	/**
	 * Count the documents of a case per relevance. A document without a review counts as unmarked.
	 *
	 * @param list<string> $documentRefs The case's documents.
	 * @param array<string, array<string, mixed>> $reviews The case's reviews, by document.
	 *
	 * @return array{unmarked: int, inScope: int, outOfScope: int} The counts.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function count(array $documentRefs, array $reviews): array {
		$counts = ['unmarked' => 0, 'inScope' => 0, 'outOfScope' => 0];
		$keys = [self::UNMARKED => 'unmarked', self::IN_SCOPE => 'inScope', self::OUT_OF_SCOPE => 'outOfScope'];
		foreach ($documentRefs as $ref) {
			$counts[$keys[self::relevanceOf(reviews: $reviews, documentRef: $ref)]]++;
		}

		return $counts;
	}//end count()

	/**
	 * The documents that still hold up the decision.
	 *
	 * An out-of-scope document needs no verdict, an in-scope one needs a
	 * verdict, and an unmarked one is outstanding whatever it carries: nobody
	 * has said yet whether it is about the request. Without the review schema
	 * every document needs a verdict, as before, which is the stricter reading.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<int, int|string> $documentIds The case's documents.
	 * @param array<string, bool> $assessed The assessed documents as keys.
	 *
	 * @return list<string> The outstanding documents.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function outstanding(string $caseId, array $documentIds, array $assessed): array {
		$available = $this->isAvailable();
		$reviews = [];
		if ($available === true) {
			$reviews = $this->forCase(caseId: $caseId);
		}

		$outstanding = [];
		foreach ($documentIds as $docId) {
			$docId = (string)$docId;
			$relevance = self::IN_SCOPE;
			if ($available === true) {
				$relevance = self::relevanceOf(reviews: $reviews, documentRef: $docId);
			}

			$needsVerdict = ($relevance === self::IN_SCOPE && isset($assessed[$docId]) === false);
			if ($relevance === self::UNMARKED || $needsVerdict === true) {
				$outstanding[] = $docId;
			}
		}

		return $outstanding;
	}//end outstanding()

	/**
	 * A reviewer marks a document's relevance. Changing a rule's marking records the rule as overturned.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document.
	 * @param string $relevance `in-scope`, `out-of-scope` or `unmarked`.
	 * @param string $userId The reviewer.
	 *
	 * @return array<string, mixed> The saved review.
	 *
	 * @throws RefusedException When the relevance is unknown or the review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function mark(string $caseId, string $documentRef, string $relevance, string $userId): array {
		if (in_array($relevance, self::RELEVANCES, true) === false) {
			throw new RefusedException(
				rule: 'review-relevance-unknown',
				sentence: 'Mark the document in scope, out of scope or unmarked.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$review = ($this->forCase(caseId: $caseId)[$documentRef] ?? ['case' => $caseId, 'documentRef' => $documentRef, 'relevance' => self::UNMARKED]);
		$wasRule = (($review['relevanceSource'] ?? '') === 'rule' && (string)($review['rule'] ?? '') !== '');
		if ($wasRule === true && ($review['relevance'] ?? '') !== $relevance) {
			$review['overturnedRule'] = (string)$review['rule'];
		}

		$review['relevance'] = $relevance;
		$review['relevanceSource'] = 'reviewer';
		$review['markedBy'] = $userId;
		$review['markedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		return $this->save(review: $review);
	}//end mark()

	/**
	 * The pages seen on these reviews, and the required pages still unseen.
	 *
	 * @return PagesSeen The pages seen, over these reviews.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function pages(): PagesSeen {
		return new PagesSeen(reviews: $this, depth: new ReviewDepth());
	}//end pages()

	/**
	 * Save a review, creating or replacing it.
	 *
	 * @param array<string, mixed> $review The review.
	 *
	 * @return array<string, mixed> The saved review.
	 *
	 * @throws RefusedException When the review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function save(array $review): array {
		if ($this->isAvailable() === false) {
			throw $this->unstored(previous: null);
		}

		$uuid = ($review['id'] ?? ($review['uuid'] ?? null));
		if ($uuid !== null) {
			$uuid = (string)$uuid;
		}

		unset($review['@self'], $review['id'], $review['uuid']);
		try {
			$saved = $this->settingsService->getObjectService()->saveObject(
				object: $review,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue(self::SCHEMA_KEY),
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a Document review could not be written',
				['app' => Application::APP_ID, 'document' => ($review['documentRef'] ?? ''), 'exception' => $e->getMessage()]
			);
			throw $this->unstored(previous: $e);
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return (array)$saved;
	}//end save()

	/**
	 * The refusal when a review cannot be stored.
	 *
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException The refusal.
	 */
	private function unstored(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'review-unavailable',
			sentence: 'The review cannot be stored, so nothing was marked.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unstored()
}//end class
