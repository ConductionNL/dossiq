<?php

/**
 * The converted sites throw instead of answering an empty value.
 *
 * These are the service-level halves of the four conversions. Each one used
 * to catch \Throwable and answer `[]` or `null`, and every caller then read
 * that emptiness as a decision about the user. The mutation that proves each
 * assertion is the old code: put the `return []` back and the test goes red.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 *
 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\MandaatVerifier;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A store that could not be read is a refusal, not an empty result.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\MandaatVerifier
 * @covers \OCA\Dossiq\Service\MandaatCheckService
 */
class RefusalReplacesTheEmptyAnswerTest extends TestCase {
	/**
	 * A settings service whose object service throws on every search.
	 *
	 * @param bool $throws Whether the search throws or answers rows.
	 *
	 * @return SettingsService The configured mock.
	 */
	private function settings(bool $throws): SettingsService {
		$objectService = new class($throws) {
			/**
			 * Whether the search throws.
			 *
			 * @var bool
			 */
			private bool $throws;

			/**
			 * Constructor.
			 *
			 * @param bool $throws Whether the search throws.
			 */
			public function __construct(bool $throws) {
				$this->throws = $throws;
			}

			/**
			 * Slug-aware search, as SearchesObjects calls it.
			 *
			 * @param string               $register Register slug.
			 * @param string               $schema   Schema slug.
			 * @param array<string, mixed> $filters  Query filters.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($this->throws === true) {
					throw new RuntimeException('the register is not answering');
				}

				return [];
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return [
					'register' => 'dossiq',
					'mandaat_regeling_schema' => 'mandaatRegeling',
					'mandaat_schema' => 'mandaat',
				][$key] ?? $default;
			}
		);

		return $settings;
	}//end settings()

	/**
	 * An unreadable mandate scheme refuses, and says which rule could not run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnreadableMandateSchemeRefusesInsteadOfAnsweringEmpty(): void {
		$verifier = new MandaatVerifier(
			settingsService: $this->settings(throws: true),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		try {
			$verifier->resolveMandaatRegeling(caseType: 'wmo');
			self::fail(
				message: 'resolveMandaatRegeling answered instead of refusing. An empty regeling reaches '
				. 'the approver as "insufficient mandaat", which names the person for a register '
				. 'that could not be read.'
			);
		} catch (RefusedException $e) {
			self::assertSame(expected: 'mandaat-regeling-unreadable', actual: $e->getRule());
			self::assertSame(expected: 503, actual: $e->getStatus());
			self::assertStringContainsString(needle: 'could not be read', haystack: $e->getSentence());
		}
	}//end testAnUnreadableMandateSchemeRefusesInsteadOfAnsweringEmpty()

	/**
	 * A mandate scheme that reads answers an array, and refuses nothing.
	 *
	 * The pair: without it, a method that threw unconditionally would pass the
	 * test above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAReadableMandateSchemeStillAnswers(): void {
		$verifier = new MandaatVerifier(
			settingsService: $this->settings(throws: false),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		self::assertSame(expected: [], actual: $verifier->resolveMandaatRegeling(caseType: 'wmo'));
	}//end testAReadableMandateSchemeStillAnswers()

	/**
	 * An unreadable mandate register refuses rather than reporting "niet bevoegd".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnreadableMandateRegisterRefusesInsteadOfAnsweringEmpty(): void {
		$check = new MandaatCheckService(
			settingsService: $this->settings(throws: true),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			conflictService: null,
		);

		try {
			$check->getApplicableMandaten(decisionType: 'subsidie', caseType: 'wmo');
			self::fail(
				message: 'getApplicableMandaten answered an empty list. isAuthorized() then reports '
				. 'REDEN_NIET_BEVOEGD, which is a statement about the user rather than about '
				. 'the register.'
			);
		} catch (RefusedException $e) {
			self::assertSame(expected: 'mandaat-register-unreadable', actual: $e->getRule());
			self::assertSame(expected: 503, actual: $e->getStatus());
		}
	}//end testAnUnreadableMandateRegisterRefusesInsteadOfAnsweringEmpty()

	/**
	 * A mandate register that reads answers a list, and refuses nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAReadableMandateRegisterStillAnswers(): void {
		$check = new MandaatCheckService(
			settingsService: $this->settings(throws: false),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			conflictService: null,
		);

		self::assertSame(
			expected: [],
			actual: $check->getApplicableMandaten(decisionType: 'subsidie', caseType: 'wmo')
		);
	}//end testAReadableMandateRegisterStillAnswers()
}//end class
