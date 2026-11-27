<?php

/**
 * BrcDecisionDeletion Unit Tests
 *
 * Deleting a besluit (brc-009): a find the store cannot answer is the store
 * failure response; a besluit not there is 404; destroy rules that refuse
 * answer their validation error and delete nothing; otherwise its OIOs are
 * sync-deleted by its URL and then the besluit itself.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Kernel\Contracts\BrcCrossRegisterSyncInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwBusinessRulesServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Service\Zgw\BrcDecisionDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for BrcDecisionDeletion.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BrcDecisionDeletion
 * @uses   \OCA\Dossiq\Support\NormalisesObjectRows
 */
final class BrcDecisionDeletionTest extends TestCase {

	/**
	 * The besluiten mapping.
	 *
	 * @var array<string, string>
	 */
	private const MAPPING = ['sourceRegister' => '7', 'sourceSchema' => '71'];

	/**
	 * An object store answering one find, and recording finds and deletes.
	 *
	 * @param mixed $found What find answers, or a Throwable it throws.
	 *
	 * @return object
	 */
	private function store(mixed $found): object {
		return new class($found) {
			/**
			 * The calls, in order.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $found What find answers, or a Throwable it throws.
			 */
			public function __construct(private mixed $found) {
			}

			/**
			 * Record the find and answer or throw.
			 *
			 * @param string            $id      The id.
			 * @param ObjectFindOptions $options Where to look.
			 *
			 * @return mixed
			 */
			public function find(string $id, ObjectFindOptions $options): mixed {
				$this->calls[] = ['find', $id, $options->register, $options->schema];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}

			/**
			 * Record the delete.
			 *
			 * @param ObjectDeletion $deletion What to delete.
			 *
			 * @return bool
			 */
			public function deleteObject(ObjectDeletion $deletion): bool {
				$this->calls[] = ['delete', $deletion->uuid];
				return true;
			}
		};
	}//end store()

	/**
	 * The ZGW service, with the destroy rules answering the given result.
	 *
	 * @param array<string, mixed> $ruleResult What the rules answer.
	 * @param array<int, mixed>    $validated  The rule calls, filled in.
	 *
	 * @return ZgwServiceInterface
	 */
	private function zgw(array $ruleResult, array &$validated): ZgwServiceInterface {
		$rules = $this->createMock(originalClassName: ZgwBusinessRulesServiceInterface::class);
		$rules->method('validate')->willReturnCallback(
			static function (mixed ...$args) use ($ruleResult, &$validated): array {
				$validated[] = $args;
				return $ruleResult;
			}
		);

		$zgw = $this->createMock(originalClassName: ZgwServiceInterface::class);
		$zgw->method('getBusinessRulesService')->willReturn($rules);
		$zgw->method('buildValidationError')->willReturnCallback(static fn (array $result): array => ['invalid' => $result['detail']]);
		$zgw->method('buildBaseUrl')->willReturn('https://nc.example/zgw/besluiten/v1/besluiten');
		$zgw->method('notFoundResponse')->willReturn(new JSONResponse(['detail' => 'not found'], 404));
		$zgw->method('storeFailureResponse')->willReturnCallback(
			static fn (\Throwable $error, string $operation, string $object): JSONResponse => new JSONResponse(
				[$operation, $object, $error->getMessage()],
				503
			)
		);
		return $zgw;
	}//end zgw()

	/**
	 * A besluit the rules allow is found in its mapping, its OIOs go by its URL, then it goes.
	 *
	 * @return void
	 */
	public function testAnAllowedBesluitTakesItsOiosAlong(): void {
		$validated = [];
		$store = $this->store(found: ['id' => 'b-1', 'title' => 'B-1']);
		$sync = $this->createMock(originalClassName: BrcCrossRegisterSyncInterface::class);
		$sync->expects($this->once())->method('deleteOiosForDecision')->with('https://nc.example/zgw/besluiten/v1/besluiten/b-1');
		$deletion = new BrcDecisionDeletion(zgwService: $this->zgw(ruleResult: ['valid' => true], validated: $validated), sync: $sync);

		$request = $this->createMock(originalClassName: IRequest::class);

		$refusal = $deletion->delete(objectService: $store, mappingConfig: self::MAPPING, uuid: 'b-1', request: $request);

		$this->assertNull(actual: $refusal);
		$this->assertSame(expected: [['find', 'b-1', '7', '71'], ['delete', 'b-1']], actual: $store->calls);
		$this->assertSame(
			expected: ['besluiten', 'besluiten', 'destroy', [], ['id' => 'b-1', 'title' => 'B-1']],
			actual: array_slice($validated[0], 0, 5)
		);
	}//end testAnAllowedBesluitTakesItsOiosAlong()

	/**
	 * Destroy rules that refuse answer their validation error, and nothing is deleted.
	 *
	 * @return void
	 */
	public function testARefusedDeleteDeletesNothing(): void {
		$validated = [];
		$store = $this->store(found: ['id' => 'b-1']);
		$sync = $this->createMock(originalClassName: BrcCrossRegisterSyncInterface::class);
		$sync->expects($this->never())->method('deleteOiosForDecision');
		$zgw = $this->zgw(ruleResult: ['valid' => false, 'status' => 400, 'detail' => 'nee'], validated: $validated);
		$deletion = new BrcDecisionDeletion(zgwService: $zgw, sync: $sync);
		$request = $this->createMock(originalClassName: IRequest::class);

		$refusal = $deletion->delete(objectService: $store, mappingConfig: self::MAPPING, uuid: 'b-1', request: $request);

		$this->assertSame(expected: [400, ['invalid' => 'nee']], actual: [$refusal?->getStatus(), $refusal?->getData()]);
		$this->assertSame(expected: [['find', 'b-1', '7', '71']], actual: $store->calls);
	}//end testARefusedDeleteDeletesNothing()

	/**
	 * A besluit that is not there is 404; a find the store cannot answer is the store failure.
	 *
	 * @return void
	 */
	public function testAMissingOrUnreadableBesluitIsNotDeleted(): void {
		$validated = [];
		$zgw = $this->zgw(ruleResult: ['valid' => true], validated: $validated);
		$sync = $this->createMock(originalClassName: BrcCrossRegisterSyncInterface::class);
		$deletion = new BrcDecisionDeletion(zgwService: $zgw, sync: $sync);
		$request = $this->createMock(originalClassName: IRequest::class);

		$missing = $deletion->delete(objectService: $this->store(found: null), mappingConfig: self::MAPPING, uuid: 'b-1', request: $request);
		$unreadable = $deletion->delete(
			objectService: $this->store(found: new RuntimeException('store down')),
			mappingConfig: self::MAPPING,
			uuid: 'b-1',
			request: $request
		);

		$this->assertSame(expected: 404, actual: $missing?->getStatus());
		$this->assertSame(
			expected: [503, ['BRC delete besluit', 'besluiten b-1', 'store down']],
			actual: [$unreadable?->getStatus(), $unreadable?->getData()]
		);
		$this->assertSame(expected: [], actual: $validated);
	}//end testAMissingOrUnreadableBesluitIsNotDeleted()
}//end class
