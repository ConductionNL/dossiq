<?php

/**
 * A JSON request body is read into an array, and only the named fields are taken from it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\JsonRequestBody;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\Support\JsonRequestBody
 */
class JsonRequestBodyTest extends TestCase {

	/**
	 * A request whose public getContent() answers the given content.
	 *
	 * @param string|null $content The raw body, or null to make the read fail.
	 *
	 * @return IRequest
	 */
	private function request(?string $content): IRequest {
		$request = $this->getMockBuilder(IRequest::class)->addMethods(['getContent'])->getMockForAbstractClass();
		if ($content === null) {
			$request->method('getContent')->willThrowException(new RuntimeException('closed'));

			return $request;
		}

		$request->method('getContent')->willReturn($content);

		return $request;
	}//end request()

	/**
	 * A JSON object is decoded; anything else reads as an empty body.
	 *
	 * @return void
	 */
	public function testAJsonObjectIsDecoded(): void {
		$body = new JsonRequestBody();

		self::assertSame(['caseId' => 'case-1'], $body->read(request: $this->request(content: '{"caseId":"case-1"}')));
		self::assertSame([], $body->read(request: $this->request(content: '"text"')));
		self::assertSame([], $body->read(request: $this->request(content: '')));
		self::assertSame([], $body->read(request: $this->request(content: null)));
	}//end testAJsonObjectIsDecoded()

	/**
	 * Only the named fields that were sent are taken, a null among them included.
	 *
	 * @return void
	 */
	public function testOnlyTheNamedFieldsThatWereSentAreTaken(): void {
		$taken = (new JsonRequestBody())->only(
			body: ['rationale' => 'Because', 'decision' => null, 'status' => 'signed'],
			fields: ['rationale', 'decision', 'addressee']
		);

		self::assertSame(['rationale' => 'Because', 'decision' => null], $taken);
	}//end testOnlyTheNamedFieldsThatWereSentAreTaken()

	/**
	 * A field reads as trimmed text, and a blank or absent one as null.
	 *
	 * @return void
	 */
	public function testABlankFieldReadsAsNull(): void {
		$body = new JsonRequestBody();

		self::assertSame('tpl-1', $body->textOrNull(body: ['templateId' => ' tpl-1 '], field: 'templateId'));
		self::assertNull($body->textOrNull(body: ['templateId' => '  '], field: 'templateId'));
		self::assertNull($body->textOrNull(body: [], field: 'templateId'));
	}//end testABlankFieldReadsAsNull()
}//end class
