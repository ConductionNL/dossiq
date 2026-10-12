<?php

/**
 * Unit tests for ZgwRuleResults.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The valid and the refused result of a rule.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ZgwRuleResultsTest extends TestCase {
	/**
	 * What the builder logged at error: message and context.
	 *
	 * @var array<int, array>
	 */
	private array $errors = [];

	/**
	 * The builder over a logger that records its errors.
	 *
	 * @return ZgwRuleResults
	 */
	private function results(): ZgwRuleResults {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new ZgwRuleResults(logger: $logger);
	}//end results()

	/**
	 * A check that could not run refuses with a 503, names the rule, blames no field, and is logged
	 * with the rule and the object.
	 *
	 * Control: an ordinary refusal logs nothing and keeps its own status.
	 *
	 * @return void
	 */
	public function testACheckThatCouldNotRunRefusesAndIsLogged(): void {
		$results = $this->results();

		$results->error(status: 400, detail: 'Nee.');
		$this->assertSame([], $this->errors);

		$this->assertSame(
			[
				'valid' => false,
				'status' => 503,
				'detail' => 'De controle van regel drc-004 kon niet worden uitgevoerd. Er is niets gewijzigd. Probeer het later opnieuw.',
				'invalidParams' => [],
				'enrichedBody' => [],
				'code' => 'check-not-completed',
			],
			$results->notVerified(rule: 'drc-004', object: 'case c-1 with document d-1', cause: 'down')
		);
		$this->assertSame(
			[[
				'ZGW rule {rule} could not be checked for {object}, so the request is refused: {cause}',
				['rule' => 'drc-004', 'object' => 'case c-1 with document d-1', 'cause' => 'down'],
			]],
			$this->errors
		);
	}//end testACheckThatCouldNotRunRefusesAndIsLogged()

	/**
	 * A pass carries the body and a 200.
	 *
	 * @return void
	 */
	public function testAPassCarriesTheBody(): void {
		$this->assertSame(
			['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => ['a' => 1]],
			$this->results()->isValid(body: ['a' => 1])
		);
	}//end testAPassCarriesTheBody()

	/**
	 * A refusal carries its status, detail and fields, and a code only when one is given.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsFields(): void {
		$results = $this->results();
		$field   = $results->fieldError(fieldName: 'zaaktype', code: 'bad-url', reason: 'Ongeldig.');

		$this->assertSame(['name' => 'zaaktype', 'code' => 'bad-url', 'reason' => 'Ongeldig.'], $field);
		$this->assertSame(
			['valid' => false, 'status' => 400, 'detail' => 'Nee.', 'invalidParams' => [$field], 'enrichedBody' => []],
			$results->error(status: 400, detail: 'Nee.', invalidParams: [$field])
		);
		$this->assertSame(
			['valid' => false, 'status' => 403, 'detail' => 'Nee.', 'invalidParams' => [], 'enrichedBody' => [], 'code' => 'permission_denied'],
			$results->error(status: 403, detail: 'Nee.', code: 'permission_denied')
		);
	}//end testARefusalCarriesItsFields()

	/**
	 * A field that may not change is refused by name.
	 *
	 * @return void
	 */
	public function testAnImmutableFieldIsRefusedByName(): void {
		$detail = 'Het veld identificatie mag niet gewijzigd worden.';

		$this->assertSame(
			[
				'valid' => false,
				'status' => 400,
				'detail' => $detail,
				'invalidParams' => [['name' => 'identificatie', 'code' => 'wijzigen-niet-toegelaten', 'reason' => $detail]],
				'enrichedBody' => [],
			],
			$this->results()->fieldImmutableError(fieldName: 'identificatie')
		);
	}//end testAnImmutableFieldIsRefusedByName()
}//end class
