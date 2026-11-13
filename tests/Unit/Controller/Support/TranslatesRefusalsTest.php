<?php

/**
 * Translates Refusals Test
 *
 * Pins how a controller turns a refusal into its JSON answer: the status the
 * refusal carries, the rule as `error`, the rule's code, and the sentence in
 * the caller's language. A sentence the translator cannot format, or a
 * request without any translator, still goes out as written. A refusal the
 * service could not decide is logged as a warning, a plain refusal as info.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the refusal translation trait.
 */
#[CoversClass(TranslatesRefusals::class)]
#[UsesClass(RefusedException::class)]
final class TranslatesRefusalsTest extends TestCase {

	/**
	 * The logger the controller writes to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A controller using the trait, with the given translator of its own.
	 *
	 * @param IL10N|null $l10n The controller's translator, or null for none.
	 *
	 * @return object The controller.
	 */
	private function controller(?IL10N $l10n): object {
		return new class($this->logger, $l10n) {
			use TranslatesRefusals;

			/**
			 * Constructor.
			 *
			 * @param LoggerInterface $logger The logger.
			 * @param IL10N|null      $l10n   The translator.
			 */
			public function __construct(
				public LoggerInterface $logger,
				private ?IL10N $l10n,
			) {
			}

			/**
			 * The controller's own translator.
			 *
			 * @return IL10N|null The translator.
			 */
			protected function refusalL10n(): ?IL10N {
				return $this->l10n;
			}

			/**
			 * Answer a refusal.
			 *
			 * @param string           $op The operation.
			 * @param RefusedException $e  The refusal.
			 *
			 * @return JSONResponse The answer.
			 */
			public function answer(string $op, RefusedException $e): JSONResponse {
				return $this->refused(op: $op, e: $e);
			}
		};
	}//end controller()

	/**
	 * A translator that answers with the given callback.
	 *
	 * @param callable $t The t() behaviour.
	 *
	 * @return IL10N&MockObject The translator.
	 */
	private function translator(callable $t): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback($t);
		return $l10n;
	}//end translator()

	/**
	 * A refusal goes out with its status, rule, code and the translated sentence, logged as info.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsStatusRuleCodeAndTranslatedSentence(): void {
		$l10n = $this->translator(
			static fn (string $text): string => $text === 'The case is closed.' ? 'De zaak is gesloten.' : $text
		);
		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains(': close refused'),
			['rule' => 'case-closed', 'status' => 409]
		);
		$this->logger->expects($this->never())->method('warning');

		$response = $this->controller(l10n: $l10n)->answer(
			op: 'close',
			e: new RefusedException(rule: 'case-closed', sentence: 'The case is closed.')
		);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(
			['message' => 'De zaak is gesloten.', 'error' => 'case-closed', 'code' => 'case_closed'],
			$response->getData()
		);
	}//end testARefusalCarriesItsStatusRuleCodeAndTranslatedSentence()

	/**
	 * An undecidable refusal is logged as a warning and keeps its 503.
	 *
	 * @return void
	 */
	public function testAnIndeterminateRefusalIsLoggedAsAWarning(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains(': split could not be answered'),
			['rule' => 'rights-unknown', 'status' => 503]
		);
		$this->logger->expects($this->never())->method('info');

		$response = $this->controller(l10n: $this->translator(static fn (string $text): string => $text))->answer(
			op: 'split',
			e: RefusedException::indeterminate(rule: 'rights-unknown', sentence: 'Rights could not be read.')
		);

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('Rights could not be read.', $response->getData()['message']);
	}//end testAnIndeterminateRefusalIsLoggedAsAWarning()

	/**
	 * A sentence the translator cannot format goes out as written, not as a 500.
	 *
	 * @return void
	 */
	public function testASentenceTheTranslatorCannotFormatGoesOutAsWritten(): void {
		$l10n = $this->translator(
			static function (string $text): string {
				throw new RuntimeException('Too few arguments for "%"');
			}
		);

		$response = $this->controller(l10n: $l10n)->answer(
			op: 'close',
			e: new RefusedException(rule: 'over-budget', sentence: 'Over 100% of the budget.', status: 422)
		);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('Over 100% of the budget.', $response->getData()['message']);
	}//end testASentenceTheTranslatorCannotFormatGoesOutAsWritten()

	/**
	 * An empty sentence is not handed to the translator.
	 *
	 * @return void
	 */
	public function testAnEmptySentenceIsNotTranslated(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->never())->method('t');

		$response = $this->controller(l10n: $l10n)->answer(
			op: 'close',
			e: new RefusedException(rule: 'forbidden', sentence: '', status: 403)
		);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('', $response->getData()['message']);
	}//end testAnEmptySentenceIsNotTranslated()

	/**
	 * Without a translator of its own and no app translator to be had, the sentence goes out as written.
	 *
	 * @return void
	 */
	public function testWithoutAnyTranslatorTheSentenceGoesOutAsWritten(): void {
		$response = $this->controller(l10n: null)->answer(
			op: 'close',
			e: new RefusedException(rule: 'case-closed', sentence: 'The case is closed.')
		);

		$this->assertSame('The case is closed.', $response->getData()['message']);
		$this->assertSame('case_closed', $response->getData()['code']);
	}//end testWithoutAnyTranslatorTheSentenceGoesOutAsWritten()
}//end class
