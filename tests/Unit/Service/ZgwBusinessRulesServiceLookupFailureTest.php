<?php

/**
 * A ZGW write whose rule could not read the store
 *
 * A rule lookup that could not run throws, and the entry point of the rules
 * refuses the request for it with the same answer as decision 224's guards.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Zgw\LookupNotCompleted;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What a ZGW write answers when a rule's read fails, from the entry point every write passes.
 *
 * @covers \OCA\Dossiq\Service\ZgwBusinessRulesService
 * @uses \OCA\Dossiq\Service\Zgw\LookupNotCompleted
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
 */
final class ZgwBusinessRulesServiceLookupFailureTest extends TestCase {
	/**
	 * The errors logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $errors = [];

	/**
	 * The entry point with a ZTC guard and a dispatcher that answer as given.
	 *
	 * @param \Closure $dispatch What the per-register rules answer, or throw.
	 * @param \Closure|null $conceptGuard What the ZTC concept guard answers, or throws.
	 *
	 * @return ZgwBusinessRulesService
	 */
	private function rules(\Closure $dispatch, ?\Closure $conceptGuard = null): ZgwBusinessRulesService {
		$ztc = $this->createMock(ZgwZtcRulesService::class);
		$ztc->method('checkConceptProtection')->willReturnCallback($conceptGuard ?? static fn (): ?array => null);
		$ztc->method('defaultConcept')->willReturnArgument(0);

		$dispatcher = $this->createMock(ZgwRulesDispatcher::class);
		$dispatcher->method('dispatch')->willReturnCallback($dispatch);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new ZgwBusinessRulesService(ztcRules: $ztc, dispatcher: $dispatcher, results: new ZgwRuleResults(logger: $logger));
	}//end rules()

	/**
	 * A lookup that throws, as a rule's read of a type that could not be reached.
	 *
	 * @return never
	 */
	private static function down(): never {
		throw new LookupNotCompleted(lookup: 'reference name "Brief"', cause: new RuntimeException('connection refused'));
	}//end down()

	/**
	 * A per-register rule whose read failed refuses the write with 503, logged with what it read.
	 *
	 * @return void
	 */
	public function testARuleWhoseReadFailedRefusesTheWrite(): void {
		$result = $this->rules(dispatch: static fn (): array => self::down())->validate(
			zgwApi: 'catalogi',
			resource: 'zaaktype-informatieobjecttypen',
			action: 'create',
			body: ['informatieobjecttype' => 'Brief']
		);

		$this->assertFalse($result['valid']);
		$this->assertSame(ZgwRuleResults::NOT_VERIFIED_STATUS, $result['status']);
		$this->assertSame(ZgwRuleResults::NOT_VERIFIED_CODE, $result['code']);
		$this->assertSame([], $result['invalidParams'], 'nothing in the request is wrong');
		$this->assertStringContainsString('catalogi/zaaktype-informatieobjecttypen create', $result['detail']);
		$this->assertCount(1, $this->errors);
		$this->assertSame(
			['rule' => 'catalogi/zaaktype-informatieobjecttypen create', 'object' => 'reference name "Brief"', 'cause' => 'connection refused'],
			$this->errors[0][1]
		);
	}//end testARuleWhoseReadFailedRefusesTheWrite()

	/**
	 * A read that fails in one of the entry point's own guards refuses the same way.
	 *
	 * @return void
	 */
	public function testAGuardWhoseReadFailedRefusesTheWriteToo(): void {
		$dispatched = false;
		$result     = $this->rules(
			dispatch: static function () use (&$dispatched): array {
				$dispatched = true;
				return ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => []];
			},
			conceptGuard: static fn (): ?array => self::down()
		)->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'create', body: []);

		$this->assertSame(503, $result['status']);
		$this->assertFalse($dispatched, 'no rule runs after a refusal');
	}//end testAGuardWhoseReadFailedRefusesTheWriteToo()

	/**
	 * Control: rules that read and answered decide as before, and nothing is logged.
	 *
	 * @return void
	 */
	public function testRulesThatCouldReadDecideAsBefore(): void {
		$passed  = $this->rules(dispatch: static fn (string $zgwApi, string $resource, string $action, array $body): array => ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body])
			->validate(zgwApi: 'zaken', resource: 'zaken', action: 'create', body: ['identificatie' => 'Z-1']);
		$refused = $this->rules(dispatch: static fn (): array => ['valid' => false, 'status' => 400, 'detail' => 'De combinatie is niet uniek.', 'invalidParams' => [], 'enrichedBody' => []])
			->validate(zgwApi: 'zaken', resource: 'zaken', action: 'create', body: ['identificatie' => 'Z-1']);

		$this->assertTrue($passed['valid']);
		$this->assertSame(['identificatie' => 'Z-1'], $passed['enrichedBody']);
		$this->assertSame(400, $refused['status']);
		$this->assertSame([], $this->errors);
	}//end testRulesThatCouldReadDecideAsBefore()
}//end class
