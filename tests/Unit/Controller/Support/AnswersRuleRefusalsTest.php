<?php

/**
 * Refusing rules answer their validation error with their status; accepting rules answer null.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\AnswersRuleRefusals;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\AnswersRuleRefusals
 */
class AnswersRuleRefusalsTest extends TestCase {

	public function testRuleRefusal(): void {
		$refused = ['valid' => false, 'status' => Http::STATUS_BAD_REQUEST, 'errors' => ['x']];
		$zgw = $this->createMock(ZgwServiceInterface::class);
		$zgw->method('buildValidationError')->with($refused)->willReturn(['detail' => 'invalid']);
		$host = new class ($zgw) {
			use AnswersRuleRefusals;

			public function __construct(private readonly ZgwServiceInterface $zgwService) {
			}

			public function refusalOf(array $ruleResult): ?JSONResponse {
				return $this->ruleRefusal(ruleResult: $ruleResult);
			}
		};

		$answer = $host->refusalOf(ruleResult: $refused);
		$this->assertInstanceOf(JSONResponse::class, $answer);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $answer->getStatus());
		$this->assertSame(['detail' => 'invalid'], $answer->getData());
		$this->assertNull($host->refusalOf(ruleResult: ['valid' => true]));
	}
}
