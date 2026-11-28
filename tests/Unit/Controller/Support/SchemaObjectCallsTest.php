<?php

/**
 * Unit tests for SchemaObjectCalls.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\SchemaObjectCalls;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A create answers a schema refusal as 400 and a fault as 500; a patch answers
 * any failure as 500; a failed list is empty; a success goes through the answer.
 *
 * @covers \OCA\Dossiq\Controller\Support\SchemaObjectCalls
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Support\ObjectSearch
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SchemaObjectCallsTest extends TestCase {

	/**
	 * An object service that fails each call with the given error, or answers it.
	 *
	 * @param \Throwable|null $failure What every call throws; null answers.
	 *
	 * @return object
	 */
	private function objectService(?\Throwable $failure): object {
		return new class($failure) {
			/**
			 * Each call, by name.
			 *
			 * @var list<array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param \Throwable|null $failure What every call throws.
			 */
			public function __construct(private readonly ?\Throwable $failure) {
			}

			/**
			 * Save, or fail.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param ObjectSaveOptions $options Where to save it.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): array {
				$this->calls[] = ['saveObject', $object, $options->target->register, $options->target->schema, $options->uuid];
				return $this->answer(value: $object + ['id' => 'new-1']);
			}

			/**
			 * Patch, or fail.
			 *
			 * @param ObjectPatch $patch The patch.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(ObjectPatch $patch): array {
				$this->calls[] = ['patchObject', $patch->objectId, $patch->data];
				return $this->answer(value: $patch->data + ['id' => $patch->objectId]);
			}

			/**
			 * Search, or fail.
			 *
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->calls[] = ['searchObjectsBySlug', $register, $schema, $filters];
				return $this->answer(value: [['id' => 'a']]);
			}

			/**
			 * The value, or the failure.
			 *
			 * @param array<mixed> $value The answer.
			 *
			 * @return array<mixed>
			 */
			private function answer(array $value): array {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $value;
			}
		};
	}//end objectService()

	/**
	 * The response a success is turned into.
	 *
	 * @return \Closure
	 */
	private function answer(): \Closure {
		return static fn (mixed $object): JSONResponse => new JSONResponse(['answered' => $object]);
	}//end answer()

	/**
	 * A created object goes through the answer; it is saved as new in the given schema.
	 *
	 * @return void
	 */
	public function testACreatedObjectIsAnswered(): void {
		$service  = $this->objectService(failure: null);
		$response = (new SchemaObjectCalls(createFailure: 'Could not create', patchFailure: 'Could not update'))->created(
			store: [$service, 'dossiq', 'belplan'],
			record: ['name' => 'Hoofdnummer'],
			answer: $this->answer()
		);

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['answered' => ['name' => 'Hoofdnummer', 'id' => 'new-1']], actual: $response->getData());
		$this->assertSame(expected: [['saveObject', ['name' => 'Hoofdnummer'], 'dossiq', 'belplan', null]], actual: $service->calls);
	}//end testACreatedObjectIsAnswered()

	/**
	 * A schema refusal answers 400 with its message; any other failure 500 with the sentence.
	 *
	 * @return void
	 */
	public function testACreateAnswersARefusalAs400AndAFaultAs500(): void {
		$calls   = new SchemaObjectCalls(createFailure: 'Could not create', patchFailure: 'Could not update');
		$refused = $calls->created(
			store: [$this->objectService(failure: new OpenRegisterValidationException(message: "Property 'priority' is wrong")), 'r', 's'],
			record: [],
			answer: $this->answer()
		);
		$this->assertSame(expected: 400, actual: $refused->getStatus());
		$this->assertSame(expected: ['error' => "Property 'priority' is wrong"], actual: $refused->getData());

		$fault = $calls->created(
			store: [$this->objectService(failure: new RuntimeException(message: 'db down')), 'r', 's'],
			record: [],
			answer: $this->answer()
		);
		$this->assertSame(expected: 500, actual: $fault->getStatus());
		$this->assertSame(expected: ['error' => 'Could not create'], actual: $fault->getData());
	}//end testACreateAnswersARefusalAs400AndAFaultAs500()

	/**
	 * A patch writes only the changes and is answered; any failure, a refusal too, is a 500.
	 *
	 * @return void
	 */
	public function testAPatchIsAnsweredAndAnyFailureIs500(): void {
		$service = $this->objectService(failure: null);
		$calls   = new SchemaObjectCalls(createFailure: 'Could not create', patchFailure: 'Could not update');
		$patched = $calls->patched(
			store: [$service, 'dossiq', 'belplan'],
			id: 'b1',
			changes: ['priority' => 5],
			answer: $this->answer()
		);
		$this->assertSame(expected: ['answered' => ['priority' => 5, 'id' => 'b1']], actual: $patched->getData());
		$this->assertSame(expected: [['patchObject', 'b1', ['priority' => 5]]], actual: $service->calls);

		$failed = $calls->patched(
			store: [$this->objectService(failure: new OpenRegisterValidationException(message: 'bad')), 'r', 's'],
			id: 'b1',
			changes: [],
			answer: $this->answer()
		);
		$this->assertSame(expected: 500, actual: $failed->getStatus());
		$this->assertSame(expected: ['error' => 'Could not update'], actual: $failed->getData());
	}//end testAPatchIsAnsweredAndAnyFailureIs500()

	/**
	 * A list reads with the filters; a failed read is empty.
	 *
	 * @return void
	 */
	public function testAFailedListIsEmpty(): void {
		$service = $this->objectService(failure: null);
		$calls   = new SchemaObjectCalls(createFailure: 'Could not create', patchFailure: 'Could not update');

		$this->assertSame(expected: [['id' => 'a']], actual: $calls->listed(store: [$service, 'dossiq', 'belplan'], filters: ['_limit' => 200]));
		$this->assertSame(expected: [['searchObjectsBySlug', 'dossiq', 'belplan', ['_limit' => 200]]], actual: $service->calls);
		$this->assertSame(
			expected: [],
			actual: $calls->listed(store: [$this->objectService(failure: new RuntimeException(message: 'x')), 'r', 's'], filters: [])
		);
	}//end testAFailedListIsEmpty()
}//end class
