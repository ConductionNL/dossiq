<?php

/**
 * The request body readers of the termijn, accruing amount and case terms controllers.
 *
 * Each reads `php://input`, decodes it as JSON and answers the array it holds,
 * or an empty array for anything else, exactly as `ReadsJsonRequests` does.
 * The termijn controller's bad request answer matches the trait's too. These
 * pin every one of those answers, against the trait, by serving `php://input`
 * from a stream wrapper.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\AccruingAmountController;
use OCA\Dossiq\Controller\CaseTermsController;
use OCA\Dossiq\Controller\MandaatMatrixController;
use OCA\Dossiq\Controller\TermijnController;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Serves `php://input` from a string; every other php:// stream is refused.
 */
final class JsonBodyInputStream {

	/**
	 * What php://input holds.
	 *
	 * @var string
	 */
	public static string $input = '';

	/**
	 * The stream context, set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * How much has been read.
	 *
	 * @var int
	 */
	private int $offset = 0;

	/**
	 * Open the stream.
	 *
	 * @param string $path The url.
	 *
	 * @return bool Whether it is php://input.
	 */
	public function stream_open(string $path): bool {
		$this->offset = 0;
		return $path === 'php://input';
	}

	/**
	 * Read from it.
	 *
	 * @param int $count How much.
	 *
	 * @return string The bytes.
	 */
	public function stream_read(int $count): string {
		$chunk = substr(self::$input, $this->offset, $count);
		$this->offset += strlen($chunk);
		return $chunk;
	}

	/**
	 * Whether it is all read.
	 *
	 * @return bool At the end.
	 */
	public function stream_eof(): bool {
		return $this->offset >= strlen(self::$input);
	}

	/**
	 * Its stat.
	 *
	 * @return array<int|string, int> Nothing worth knowing.
	 */
	public function stream_stat(): array {
		return [];
	}
}//end class

class JsonBodyCopiesTest extends TestCase {

	/**
	 * Serve php://input from the wrapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', JsonBodyInputStream::class);
	}//end setUp()

	/**
	 * Put the real php:// streams back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		stream_wrapper_restore('php');
	}//end tearDown()

	/**
	 * The trait's user and every copy.
	 *
	 * @return array<string, array{0: class-string}> Per controller: its class.
	 */
	public static function readers(): array {
		return [
			'trait (mandaat matrix)' => [MandaatMatrixController::class],
			'termijn'                => [TermijnController::class],
			'accruing amount'        => [AccruingAmountController::class],
			'case terms'             => [CaseTermsController::class],
		];
	}//end readers()

	/**
	 * What a private method of a constructor-less controller answers.
	 *
	 * @param class-string $class     The controller.
	 * @param string       $method    The method.
	 * @param array<mixed> $arguments Its arguments.
	 *
	 * @return mixed The answer.
	 */
	private function call(string $class, string $method, array $arguments = []): mixed {
		$controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		return (new ReflectionMethod($controller, $method))->invokeArgs($controller, $arguments);
	}//end call()

	/**
	 * A JSON object or list is answered; anything else is an empty array.
	 *
	 * @param class-string $class The controller.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testTheBodyIsTheArrayItHolds(string $class): void {
		$bodies = [
			'{"zaakId":"c-1","dagen":5}' => ['zaakId' => 'c-1', 'dagen' => 5],
			'[1,"two"]'                  => [1, 'two'],
			'{}'                         => [],
			'"text"'                     => [],
			'42'                         => [],
			'null'                       => [],
			'not json'                   => [],
			''                           => [],
		];
		foreach ($bodies as $input => $expected) {
			JsonBodyInputStream::$input = (string)$input;
			self::assertSame($expected, $this->call(class: $class, method: 'jsonBody'), 'body ' . $input);
		}
	}//end testTheBodyIsTheArrayItHolds()

	/**
	 * The termijn controller's bad request is the trait's: 400 with the message.
	 *
	 * @return void
	 */
	public function testABadRequestIsTheTraitsBadRequest(): void {
		foreach ([MandaatMatrixController::class, TermijnController::class] as $class) {
			$answer = $this->call(class: $class, method: 'badRequest', arguments: ['zaakId and zaaktype are required']);
			self::assertInstanceOf(JSONResponse::class, $answer);
			self::assertSame([400, ['message' => 'zaakId and zaaktype are required']], [$answer->getStatus(), $answer->getData()]);
		}
	}//end testABadRequestIsTheTraitsBadRequest()
}//end class
