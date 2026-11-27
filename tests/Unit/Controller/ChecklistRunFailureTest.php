<?php

/**
 * ChecklistRunFailure Unit Tests
 *
 * The answer to a checklist run the store did not take: OpenRegister's two
 * validation refusals are a 400 logged at info, this app's own refusal a 422
 * logged not at all, and anything else a 500 logged as an error.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use LogicException;
use OCA\Dossiq\Controller\ChecklistRunFailure;
use OCA\OpenRegister\Exception\CustomValidationException as OpenRegisterCustomValidationException;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for ChecklistRunFailure.
 *
 * @covers \OCA\Dossiq\Controller\ChecklistRunFailure
 */
final class ChecklistRunFailureTest extends TestCase {

	/**
	 * Both OpenRegister refusals are a 400 with OpenRegister's sentence, logged at info with the app name.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterRefusalIsTheCallersFault(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->exactly(count: 2))->method('info')->with(
			$this->stringStartsWith(prefix: 'Rejected checklist run for case case-7: '),
			['app' => 'dossiq']
		);
		$logger->expects($this->never())->method('error');
		$failure = new ChecklistRunFailure(logger: $logger, appName: 'dossiq');

		$plain = $failure->answer(caseId: 'case-7', exception: new OpenRegisterValidationException(message: "Property 'checklist' is wrong"));
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $plain->getStatus());
		$this->assertSame(expected: ['message' => "Property 'checklist' is wrong"], actual: $plain->getData());

		$custom = $failure->answer(
			caseId: 'case-7',
			exception: new OpenRegisterCustomValidationException(message: 'Referenced object does not exist', errors: ['case' => 'not found'])
		);
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $custom->getStatus());
	}//end testAnOpenRegisterRefusalIsTheCallersFault()

	/**
	 * This app's own refusal is a 422 with its sentence, and is not logged.
	 *
	 * @return void
	 */
	public function testTheAppsOwnRefusalIsA422(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());
		$failure = new ChecklistRunFailure(logger: $logger, appName: 'dossiq');

		$response = $failure->answer(caseId: 'case-7', exception: new RuntimeException(message: 'A required photo is missing'));

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'A required photo is missing'], actual: $response->getData());
	}//end testTheAppsOwnRefusalIsA422()

	/**
	 * Anything else is a 500 that says submission failed, logged as an error.
	 *
	 * @return void
	 */
	public function testAnythingElseIsAServerFault(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Failed to submit a checklist run for case case-7: the store is gone',
			['app' => 'dossiq']
		);
		$failure = new ChecklistRunFailure(logger: $logger, appName: 'dossiq');

		$response = $failure->answer(caseId: 'case-7', exception: new LogicException(message: 'the store is gone'));

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Submission failed: the store is gone'], actual: $response->getData());
	}//end testAnythingElseIsAServerFault()
}//end class
