<?php

/**
 * The route is the ground for making a document final, never the act.
 *
 * 🔴 THE REFUSAL IS DRIVEN FIRST, in every case below. A guard that stops
 * working passes a test that only ever asks it to allow something, and the
 * allowing half is what every document on every instance already does.
 *
 * 🔴 THE THREE PERMISSIVE ANSWERS ARE KEPT APART. A document nobody routed, an
 * instance with no decidiq, and a route that has CLEARED all end in the same
 * `final`, and only the third is an approval. Folding them together would make
 * a guard that could not tell a document nobody reviewed from one three people
 * agreed to, which is the claim this feature exists to be able to make.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentApprovalClearance;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class InformatieobjectApprovalGuardTest extends TestCase {

	/** The document under test. */
	private const DOCUMENT_ID = 'io-1';

	/**
	 * The changes the store was asked to write.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * A lifecycle wired to a store holding one draft document.
	 *
	 * @param DocumentApprovalClearance|null $approvals The clearance reader.
	 *
	 * @return InformatieobjectStatusLifecycle The service.
	 */
	private function lifecycle(?DocumentApprovalClearance $approvals): InformatieobjectStatusLifecycle {
		$document = [
			'id' => self::DOCUMENT_ID,
			'@self' => ['uuid' => self::DOCUMENT_ID],
			'status' => 'draft',
			'titel' => 'Concept brief',
		];

		// `find()` is typed to an entity, and the app reads it back through
		// `jsonSerialize()`. Returning a bare array here would be a double
		// shaped unlike the real store, which is the difference between a test
		// that passes and one that means anything.
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($document);
		$entity->method('getUuid')->willReturn(self::DOCUMENT_ID);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($entity);
		$objects->method('searchObjects')->willReturn([$document]);
		$objects->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, ...$rest): ObjectEntityInterface {
				$this->written[] = $data;
				$stored = $this->createMock(ObjectEntityInterface::class);
				$stored->method('getUuid')->willReturn($objectId);

				return $stored;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest): ObjectEntityInterface {
				$this->written[] = $object;
				$stored = $this->createMock(ObjectEntityInterface::class);
				$stored->method('getUuid')->willReturn(self::DOCUMENT_ID);

				return $stored;
			}
		);

		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'register' ? 'dossiq' : $key)
		);

		return new InformatieobjectStatusLifecycle(
			$settings,
			new NullLogger(),
			$approvals
		);
	}//end lifecycle()

	/**
	 * A clearance reader answering one fixed shape.
	 *
	 * `onlyMethods` and not `addMethods`: a double that invented a reader the
	 * real class lacks would pass here and fatal in production.
	 *
	 * @param array<string, mixed> $answer What the reader says.
	 *
	 * @return DocumentApprovalClearance The double.
	 */
	private function clearance(array $answer): DocumentApprovalClearance {
		$reader = $this->getMockBuilder(DocumentApprovalClearance::class)
			->disableOriginalConstructor()
			->onlyMethods(['forDocument', 'describe'])
			->getMock();
		$reader->method('forDocument')->willReturn($answer);
		$reader->method('describe')->willReturnCallback(
			static function (array $clearance): string {
				$first = (($clearance['waitingOn'] ?? [])[0] ?? []);

				return trim(
					(string)($first['routeName'] ?? '') . ', step "' . (string)($first['stageName'] ?? '') . '"'
				);
			}
		);

		return $reader;
	}//end clearance()

	/**
	 * An open route refuses the lock, and the refusal names the route and step.
	 *
	 * @return void
	 */
	public function testAnOpenRouteRefusesTheLock(): void {
		$lifecycle = $this->lifecycle(
			$this->clearance(
				[
					'routed' => true,
					'cleared' => false,
					'waitingOn' => [
						[
							'route' => 'ar-1',
							'routeName' => 'Concept brief review',
							'stage' => 1,
							'stageName' => 'Juridisch',
							'actor' => 'jurist',
						],
					],
				]
			)
		);

		try {
			$lifecycle->transition(self::DOCUMENT_ID, 'final');
			$this->fail('a document in an open route was made final');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('Concept brief review', $e->getMessage());
			$this->assertStringContainsString('Juridisch', $e->getMessage());
		}

		$this->assertSame(
			[],
			$this->written,
			'the document was written even though the transition was refused, so it is final and locked anyway'
		);
	}//end testAnOpenRouteRefusesTheLock()

	/**
	 * A route that completed approved lets the handler make the transition.
	 *
	 * @return void
	 */
	public function testAnApprovedRouteAllowsTheLock(): void {
		$lifecycle = $this->lifecycle(
			$this->clearance(['routed' => true, 'cleared' => true, 'waitingOn' => []])
		);

		$result = $lifecycle->transition(self::DOCUMENT_ID, 'final');

		$this->assertSame('final', $result['status']);
		$this->assertArrayHasKey('lockedOn', $result, 'making a document final must stamp lockedOn');
		$this->assertNotSame([], $this->written);
	}//end testAnApprovedRouteAllowsTheLock()

	/**
	 * A document nobody routed is untouched, which is where every document on
	 * every instance was before this change.
	 *
	 * @return void
	 */
	public function testADocumentWithNoRouteIsUnchanged(): void {
		$lifecycle = $this->lifecycle(
			$this->clearance(DocumentApprovalClearance::NOT_ROUTED)
		);

		$result = $lifecycle->transition(self::DOCUMENT_ID, 'final');

		$this->assertSame('final', $result['status']);
	}//end testADocumentWithNoRouteIsUnchanged()

	/**
	 * An instance with no clearance reader at all behaves as it always did.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoReaderIsUnchanged(): void {
		$result = $this->lifecycle(null)->transition(self::DOCUMENT_ID, 'final');

		$this->assertSame('final', $result['status']);
	}//end testAnInstanceWithNoReaderIsUnchanged()

	/**
	 * The guard is about `final` alone: it is the transition that LOCKS.
	 *
	 * A guard that also caught `archived` would refuse to archive a document
	 * whose route was abandoned years ago, which is a records-management act
	 * that has nothing to do with a review.
	 *
	 * @return void
	 */
	public function testTheGuardIsAboutTheLockAndNotEveryTransition(): void {
		$reader = $this->clearance(
			['routed' => true, 'cleared' => false, 'waitingOn' => [['routeName' => 'R', 'stageName' => 'S']]]
		);
		$reader->expects($this->never())->method('forDocument');

		// `draft -> archived` is refused by the forward-only rule, NOT by the
		// approval guard, which is exactly the point: the guard is never asked.
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Invalid status transition/');

		$this->lifecycle($reader)->transition(self::DOCUMENT_ID, 'archived');
	}//end testTheGuardIsAboutTheLockAndNotEveryTransition()

	/**
	 * The statuses are the stored English ones, not the ZGW Dutch labels.
	 *
	 * The change's own spec says `concept`, `definitief` and `gearchiveerd`,
	 * which is what a ZGW reader calls them and NOT what this register stores.
	 * A guard written against the Dutch spelling would never fire, because no
	 * document's status is ever `definitief`.
	 *
	 * @return void
	 */
	public function testTheStoredStatusesAreTheEnglishOnes(): void {
		$this->assertSame(
			['draft', 'final', 'archived'],
			InformatieobjectStatusLifecycle::VALID_STATUSES
		);
	}//end testTheStoredStatusesAreTheEnglishOnes()
}//end class
