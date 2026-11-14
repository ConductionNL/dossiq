<?php

/**
 * HalResponseBody unit tests (brk-woz-register-adapters).
 *
 * Covers the three reads the BRK and WOZ adapters share: the HAL
 * `_embedded` list of a search, the wrapped-or-bare object of a lookup,
 * and the status handling around that lookup (404 is nothing, other
 * non-2xx statuses come back as the status).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External;

use OCA\Dossiq\Service\External\HalResponseBody;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\External\HalResponseBody
 */
class HalResponseBodyTest extends TestCase {

	/**
	 * The reader under test.
	 *
	 * @var HalResponseBody
	 */
	private HalResponseBody $body;

	/**
	 * Build the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->body = new HalResponseBody();
	}//end setUp()

	/**
	 * A HAL search answer yields the list under the requested key.
	 *
	 * @return void
	 */
	public function testEmbeddedListReturnsTheListUnderTheKey(): void {
		$json = json_encode(
			[
				'_embedded' => [
					'percelen' => [['id' => 'a'], ['id' => 'b']],
					'other' => [['id' => 'z']],
				],
			]
		);

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $this->body->embeddedList(body: $json, key: 'percelen'));
	}//end testEmbeddedListReturnsTheListUnderTheKey()

	/**
	 * A key missing under `_embedded` yields an empty list.
	 *
	 * @return void
	 */
	public function testEmbeddedListWithoutTheKeyIsEmpty(): void {
		$json = json_encode(['_embedded' => ['other' => [['id' => 'z']]]]);

		$this->assertSame([], $this->body->embeddedList(body: $json, key: 'percelen'));
	}//end testEmbeddedListWithoutTheKeyIsEmpty()

	/**
	 * A body without `_embedded` yields an empty list.
	 *
	 * @return void
	 */
	public function testEmbeddedListWithoutEmbeddedIsEmpty(): void {
		$this->assertSame([], $this->body->embeddedList(body: '{"total":0}', key: 'percelen'));
	}//end testEmbeddedListWithoutEmbeddedIsEmpty()

	/**
	 * A body that is not JSON, or not a JSON object, yields an empty list.
	 *
	 * @return void
	 */
	public function testEmbeddedListOfANonObjectBodyIsEmpty(): void {
		$this->assertSame([], $this->body->embeddedList(body: 'not json', key: 'percelen'));
		$this->assertSame([], $this->body->embeddedList(body: '"text"', key: 'percelen'));
	}//end testEmbeddedListOfANonObjectBodyIsEmpty()

	/**
	 * An `_embedded` that is a scalar yields an empty list.
	 *
	 * @return void
	 */
	public function testEmbeddedListWithAScalarEmbeddedIsEmpty(): void {
		$this->assertSame([], $this->body->embeddedList(body: '{"_embedded":"nope"}', key: 'percelen'));
	}//end testEmbeddedListWithAScalarEmbeddedIsEmpty()

	/**
	 * A single scalar under the key is cast into a one-item list.
	 *
	 * @return void
	 */
	public function testEmbeddedListCastsAScalarEntryToAList(): void {
		$this->assertSame(['x'], $this->body->embeddedList(body: '{"_embedded":{"percelen":"x"}}', key: 'percelen'));
	}//end testEmbeddedListCastsAScalarEntryToAList()

	/**
	 * An object wrapped under the key is unwrapped.
	 *
	 * @return void
	 */
	public function testSingleObjectUnwrapsTheKey(): void {
		$json = json_encode(['perceel' => ['id' => 'a'], 'links' => []]);

		$this->assertSame(['id' => 'a'], $this->body->singleObject(body: $json, key: 'perceel'));
	}//end testSingleObjectUnwrapsTheKey()

	/**
	 * A bare object (not wrapped under the key) is returned whole.
	 *
	 * @return void
	 */
	public function testSingleObjectReturnsABareObjectWhole(): void {
		$json = json_encode(['id' => 'a', 'sectie' => 'B']);

		$this->assertSame(['id' => 'a', 'sectie' => 'B'], $this->body->singleObject(body: $json, key: 'perceel'));
	}//end testSingleObjectReturnsABareObjectWhole()

	/**
	 * A non-JSON body, or a scalar under the key, yields an empty array.
	 *
	 * @return void
	 */
	public function testSingleObjectOfANonObjectIsEmpty(): void {
		$this->assertSame([], $this->body->singleObject(body: 'not json', key: 'perceel'));
		$this->assertSame([], $this->body->singleObject(body: '{"perceel":"x"}', key: 'perceel'));
	}//end testSingleObjectOfANonObjectIsEmpty()

	/**
	 * A 404 answer is nothing, without the body being read.
	 *
	 * @return void
	 */
	public function testObjectOfA404IsEmpty(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(404);
		$response->expects($this->never())->method('getBody');

		$this->assertSame([], $this->body->objectOf(response: $response, key: 'perceel'));
	}//end testObjectOfA404IsEmpty()

	/**
	 * A failed status outside 2xx comes back as that status.
	 *
	 * @return void
	 */
	public function testObjectOfAFailedStatusReturnsTheStatus(): void {
		foreach ([500, 403, 199, 300] as $status) {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->expects($this->never())->method('getBody');

			$this->assertSame($status, $this->body->objectOf(response: $response, key: 'perceel'));
		}
	}//end testObjectOfAFailedStatusReturnsTheStatus()

	/**
	 * A 2xx answer yields the object, unwrapped from its key.
	 *
	 * @return void
	 */
	public function testObjectOfASuccessReturnsTheObject(): void {
		foreach ([200, 299] as $status) {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn('{"perceel":{"id":"a"}}');

			$this->assertSame(['id' => 'a'], $this->body->objectOf(response: $response, key: 'perceel'));
		}
	}//end testObjectOfASuccessReturnsTheObject()

	/**
	 * A 2xx answer without an object is nothing.
	 *
	 * @return void
	 */
	public function testObjectOfASuccessWithoutAnObjectIsEmpty(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('');

		$this->assertSame([], $this->body->objectOf(response: $response, key: 'perceel'));
	}//end testObjectOfASuccessWithoutAnObjectIsEmpty()
}//end class
