<?php

/**
 * Writing document reviews: marking unmarked documents in scope, and storing a review or refusing to.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Review\DocumentRelevance
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/a-verdict-marks-its-document-in-scope/specs/document-review/spec.md#requirement-a-verdict-on-an-unmarked-document-marks-it-in-scope-req-wrt-006
 */
class DocumentRelevanceWritesTest extends TestCase {

	/**
	 * The object store double: the rows it answers, the saves it received, and what a save answers.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A store over these rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->store  = new class {
			public array $rows = [];
			public array $searches = [];
			public array $saves = [];
			public mixed $answer = null;
			public ?\Throwable $failure = null;

			public function searchObjectsBySlug(string $register, string $schema, array $filters, mixed ...$scope): array {
				$this->searches[] = [$register, $schema, $filters, $scope];
				return $this->rows;
			}

			public function saveObject(array $object, string $register, string $schema, ?string $uuid): mixed {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return ($this->answer ?? $object);
			}
		};
	}//end setUp()

	/**
	 * The reviews over the store, with the register and review schema configured or not.
	 *
	 * @param bool $available Whether the store and both settings are there.
	 *
	 * @return DocumentRelevance
	 */
	private function reviews(bool $available=true): DocumentRelevance {
		$config   = ['register' => 'woo', DocumentRelevance::SCHEMA_KEY => 'document-review'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($available === true ? $this->store : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default=''): string => ($config[$key] ?? $default));

		return new DocumentRelevance(settingsService: $settings, logger: $this->logger);
	}//end reviews()

	/**
	 * Only unmarked documents of the case are marked in scope; marked ones keep their marking.
	 *
	 * @return void
	 */
	public function testAVerdictMarksOnlyTheUnmarkedDocuments(): void {
		$this->store->rows = [
			['id' => 'r-a', 'case' => 'c-1', 'documentRef' => 'doc-a', 'relevance' => 'in-scope'],
			['id' => 'r-b', 'case' => ['id' => 'c-1'], 'documentRef' => 'doc-b', 'relevance' => 'out-of-scope'],
			['id' => 'r-c', 'case' => 'c-1', 'documentRef' => 'doc-c', 'relevance' => 'unmarked', '@self' => ['x' => 1]],
			['id' => 'r-x', 'case' => 'other', 'documentRef' => 'doc-x', 'relevance' => 'out-of-scope'],
		];

		$marked = $this->reviews()->markInScopeWhereUnmarked(caseId: 'c-1', documentRefs: ['doc-a', 'doc-b', 'doc-c', 'doc-d', 'doc-d', '', 'doc-x'], userId: 'beoordelaar');

		$this->assertSame(3, $marked, 'doc-c, doc-d and doc-x (whose review belongs to another case)');
		$this->assertSame(['woo', 'document-review', ['case' => 'c-1', '_limit' => 1000], ['_rbac' => false, '_multitenancy' => false]], $this->store->searches[0]);
		$this->assertSame(['r-c', null, null], array_column($this->store->saves, 'uuid'));

		$existing = $this->store->saves[0]['object'];
		$this->assertSame(['case', 'documentRef', 'relevance', 'relevanceSource', 'markedBy', 'markedAt'], array_keys($existing), 'id and @self are not written back');
		$this->assertSame('in-scope', $existing['relevance']);
		$this->assertSame('reviewer', $existing['relevanceSource']);
		$this->assertSame('beoordelaar', $existing['markedBy']);
		$this->assertNotFalse(DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $existing['markedAt']));

		$this->assertSame(['case' => 'c-1', 'documentRef' => 'doc-d'], array_slice($this->store->saves[1]['object'], 0, 2));
		$this->assertSame('doc-x', $this->store->saves[2]['object']['documentRef']);
		$this->assertSame('woo', $this->store->saves[1]['register']);
		$this->assertSame('document-review', $this->store->saves[1]['schema']);
	}//end testAVerdictMarksOnlyTheUnmarkedDocuments()

	/**
	 * Without the store, a case or documents nothing is read or marked.
	 *
	 * @return void
	 */
	public function testNothingIsMarkedWithoutAStoreACaseOrDocuments(): void {
		$this->assertSame(0, $this->reviews(available: false)->markInScopeWhereUnmarked(caseId: 'c-1', documentRefs: ['doc-a'], userId: 'u'));
		$this->assertSame(0, $this->reviews()->markInScopeWhereUnmarked(caseId: '', documentRefs: ['doc-a'], userId: 'u'));
		$this->assertSame(0, $this->reviews()->markInScopeWhereUnmarked(caseId: 'c-1', documentRefs: [], userId: 'u'));
		$this->assertSame([], $this->store->searches);
		$this->assertSame([], $this->store->saves);
	}//end testNothingIsMarkedWithoutAStoreACaseOrDocuments()

	/**
	 * A save replaces by id, else by uuid, and answers the serialised object.
	 *
	 * @return void
	 */
	public function testASaveReplacesByIdAndAnswersTheSerialisedObject(): void {
		$this->store->answer = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'r-9', 'relevance' => 'in-scope'];
			}
		};

		$saved = $this->reviews()->save(review: ['uuid' => 'r-9', 'documentRef' => 'doc-a', 'relevance' => 'in-scope', '@self' => []]);

		$this->assertSame(['id' => 'r-9', 'relevance' => 'in-scope'], $saved);
		$this->assertSame('r-9', $this->store->saves[0]['uuid']);
		$this->assertSame(['documentRef' => 'doc-a', 'relevance' => 'in-scope'], $this->store->saves[0]['object']);

		$this->store->answer = ['id' => 'r-10'];
		$this->assertSame(['id' => 'r-10'], $this->reviews()->save(review: ['id' => 10, 'uuid' => 'ignored']));
		$this->assertSame('10', $this->store->saves[1]['uuid'], 'the id wins over the uuid, as a string');
	}//end testASaveReplacesByIdAndAnswersTheSerialisedObject()

	/**
	 * Without the store a save is refused as indeterminate.
	 *
	 * @return void
	 */
	public function testASaveWithoutTheStoreIsRefused(): void {
		try {
			$this->reviews(available: false)->save(review: ['documentRef' => 'doc-a']);
			$this->fail('no refusal');
		} catch (RefusedException $e) {
			$this->assertSame('review-unavailable', $e->getRule());
			$this->assertSame('The review cannot be stored, so nothing was marked.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			$this->assertNull($e->getPrevious());
		}
	}//end testASaveWithoutTheStoreIsRefused()

	/**
	 * A store that fails is logged and the save refused, with the failure as the cause.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsLoggedAndRefused(): void {
		$failure = new RuntimeException('disk full');
		$this->store->failure = $failure;
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: a Document review could not be written',
			['app' => 'dossiq', 'document' => 'doc-a', 'exception' => 'disk full']
		);

		try {
			$this->reviews()->save(review: ['documentRef' => 'doc-a']);
			$this->fail('no refusal');
		} catch (RefusedException $e) {
			$this->assertSame('review-unavailable', $e->getRule());
			$this->assertSame($failure, $e->getPrevious());
		}
	}//end testAFailingStoreIsLoggedAndRefused()

	/**
	 * A relevance outside the known ones reads as unmarked.
	 *
	 * @return void
	 */
	public function testAnUnknownRelevanceReadsAsUnmarked(): void {
		$this->assertSame('unmarked', DocumentRelevance::relevanceOf(reviews: ['doc-a' => ['relevance' => 'maybe']], documentRef: 'doc-a'));
		$this->assertSame('out-of-scope', DocumentRelevance::relevanceOf(reviews: ['doc-a' => ['relevance' => 'out-of-scope']], documentRef: 'doc-a'));
	}//end testAnUnknownRelevanceReadsAsUnmarked()
}//end class
