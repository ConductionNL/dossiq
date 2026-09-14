<?php

/**
 * Structural guard: one date write path.
 *
 * Nine controllers wrote a case date through nine private normalisers, each
 * with its own rule, and none of them read the administered time zone. This
 * test enumerates the writers and the normalisers so the count in the proposal
 * stops being a number somebody typed, and it fails when a tenth path appears.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
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
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Scans lib/ for a second date path.
 *
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */
class OneDateWritePathTest extends TestCase {
	/**
	 * The library root under test.
	 *
	 * @var string
	 */
	private const LIB_DIR = __DIR__ . '/../../../lib';

	/**
	 * The one class allowed to parse, format and zone a date.
	 *
	 * @var string
	 */
	private const NORMALISER = 'Service/CaseDateNormaliser.php';

	/**
	 * What an author should call instead, named in every failure message.
	 *
	 * @var string
	 */
	private const ADVICE = 'Inject OCA\Dossiq\Service\CaseDateNormaliser and call '
		. 'toCalendarDate(), toMoment() or parse() instead. It is the only class '
		. 'that may resolve a time zone. See openspec/changes/one-date-write-path.';

	/**
	 * The nine private normalisers this change retires, file to method.
	 *
	 * Each entry is a rule that disagreed with the other eight about what a
	 * date is. None of them may come back under its own name.
	 *
	 * @var array<string, string>
	 */
	private const RETIRED_NORMALISERS = [
		'Service/Doorlooptijd/CaseEnricher.php' => 'normaliseDate',
		'Controller/DwangsomPaymentCallbackController.php' => 'parseDate',
		'Service/WOODeadlineService.php' => 'parseIsoDate',
		'Service/WorkQueueService.php' => 'parseDateOnly',
		'Service/TermijnTimerService.php' => 'dateOrNull',
		'Service/ProcessMiningService.php' => 'parseDate',
		'Service/ProcessMining/DwellTimeAnalyzer.php' => 'parseDate',
		'Service/ProcessMining/ThroughputTrendCalculator.php' => 'parseDate',
		'Service/BesluitMigrationService.php' => 'asDateTime',
	];

	/**
	 * The nine write paths audited by this change, controller to the files
	 * that must reach the normaliser for that path.
	 *
	 * A path is satisfied when the controller or any service it writes
	 * through names CaseDateNormaliser. The enumeration is the instrument:
	 * a tenth controller writing a case date is a tenth entry, or a failure.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const WRITE_PATHS = [
		'TermijnController' => [
			'Controller/TermijnController.php',
			'Service/DeadlineExtensionService.php',
		],
		'ZrcController' => ['Controller/ZrcController.php'],
		'ComplaintController' => [
			'Controller/ComplaintController.php',
			'Service/ComplaintService.php',
		],
		'ConsultationController' => [
			'Controller/ConsultationController.php',
			'Service/ConsultationService.php',
		],
		'AdviceController' => [
			'Controller/AdviceController.php',
			'Service/AdviceService.php',
		],
		'WOOAssessmentController' => [
			'Controller/WOOAssessmentController.php',
			'Service/WOODeadlineService.php',
		],
		'ContactMomentController' => [
			'Controller/ContactMomentController.php',
			'Service/QuickActionService.php',
			'Service/Kcc/ContactMomentService.php',
		],
		'DwangsomController' => [
			'Controller/DwangsomController.php',
			'Service/DwangsomCalculationService.php',
		],
		'DwangsomPaymentCallbackController' => [
			'Controller/DwangsomPaymentCallbackController.php',
			'Service/DwangsomUitbetalingService.php',
		],
	];

	/**
	 * The five StUF files that hard-coded Europe/Amsterdam.
	 *
	 * A Belgian tenant is in ALLOWED_TIMEZONES, so the literal was wrong for
	 * a tenant we accept, not merely inelegant.
	 *
	 * @var array<int, string>
	 */
	private const STUF_FILES = [
		'Service/StufMessageBuilder.php',
		'Service/Stuf/StufMessageHandler.php',
		'Service/Stuf/StufCaseMappingStore.php',
		'Service/Stuf/ContactBetrokkeneMapper.php',
	];

	/**
	 * Private date parsers outside the nine write paths, each with the reason
	 * it stays and the change that retires it.
	 *
	 * Inherited debt, per CLAUDE.md: reported, not swept. An empty reason
	 * fails the test, because the entry IS the debt record.
	 *
	 * @var array<string, string>
	 */
	private const PARSER_ALLOWLIST = [
		'Repair/ArmTermijnEngineTimers.php::dateOrNull' => 'one-shot repair step arming engine timers; it reads stored values rather than writing a case date, and it retires with the repair step itself',
		'Service/Beschikking/OpenRegisterArchivalAdapter.php::computeDestructionDate' => 'archival retention arithmetic, not a case date write; belongs to the archival capability and its own change',
		'Service/Bezwaar/BeroepService.php::shiftDate' => 'beroep term arithmetic on an already normalised value; moves with openspec/changes/terms-on-the-engine-calendar',
		'Service/CaseLifecycleService.php::requireLaterDate' => 'a comparison guard, not a writer: it refuses an earlier date and stores nothing itself',
	];

	/**
	 * A private method whose name reads like a date helper.
	 *
	 * @var string
	 */
	private const DATE_NAME_PATTERN = '/(date|time|moment|timestamp|iso)/i';

	/**
	 * A body that parses a date out of a variable.
	 *
	 * Narrow on purpose: constructing a fixed instant is not a parse, and a
	 * format of an already parsed value is not a second rule.
	 *
	 * @var string
	 */
	private const PARSE_BODY_PATTERN = '/new\s+\\\\?DateTime(Immutable)?\s*\(\s*(datetime:\s*)?\$|strtotime\s*\(\s*\$/i';

	/**
	 * The nine private normalisers are gone, by name, from their own files.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function testTheNinePrivateNormalisersAreRetired(): void {
		$survivors = [];
		foreach (self::RETIRED_NORMALISERS as $file => $method) {
			$path = self::LIB_DIR . '/' . $file;
			if (file_exists($path) === false) {
				continue;
			}

			$source = (string)file_get_contents($path);
			if (preg_match('/private\s+function\s+' . preg_quote($method, '/') . '\s*\(/', $source) === 1) {
				$survivors[] = $file . '::' . $method . '()';
			}
		}

		self::assertSame(
			[],
			$survivors,
			"These private date normalisers still exist:\n - " . implode("\n - ", $survivors)
			. "\n" . self::ADVICE
		);
	}//end testTheNinePrivateNormalisersAreRetired()

	/**
	 * No private method in lib/ parses a date, outside the allowlist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function testNoPrivateMethodParsesADate(): void {
		$violations = [];
		foreach ($this->libraryFiles() as $relative => $source) {
			if ($relative === self::NORMALISER) {
				continue;
			}

			foreach ($this->privateDateParsers($source) as $method) {
				$key = $relative . '::' . $method;
				if (array_key_exists($key, self::PARSER_ALLOWLIST) === true) {
					continue;
				}

				$violations[] = $key . '()';
			}
		}

		sort($violations);
		self::assertSame(
			[],
			$violations,
			"A private date parser is a second rule for what a date is:\n - "
			. implode("\n - ", $violations) . "\n" . self::ADVICE
		);
	}//end testNoPrivateMethodParsesADate()

	/**
	 * Every allowlist entry carries a reason and still exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function testEveryAllowlistEntryCarriesAReason(): void {
		foreach (self::PARSER_ALLOWLIST as $key => $reason) {
			self::assertNotSame('', trim($reason), 'Allowlist entry ' . $key . ' needs a reason.');
			[$file] = explode('::', $key);
			self::assertFileExists(
				self::LIB_DIR . '/' . $file,
				'Allowlisted file ' . $file . ' is gone; drop the entry.'
			);
		}
	}//end testEveryAllowlistEntryCarriesAReason()

	/**
	 * No class in lib/ names an IANA time zone as a literal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function testNoClassNamesATimeZoneLiteral(): void {
		$violations = [];
		foreach ($this->libraryFiles() as $relative => $source) {
			if ($relative === self::NORMALISER) {
				continue;
			}

			if (preg_match_all('/new\s+\\\\?DateTimeZone\s*\(/i', $source, $matches) > 0) {
				$violations[] = $relative . ' constructs a DateTimeZone';
			}

			if (preg_match_all("/'(Europe\/[A-Za-z_]+)'/", $source, $zones) > 0) {
				foreach (array_unique($zones[1]) as $zone) {
					$violations[] = $relative . " names '" . $zone . "'";
				}
			}
		}

		sort($violations);
		self::assertSame(
			[],
			$violations,
			"A hard-coded zone is a zone the administrator never chose:\n - "
			. implode("\n - ", $violations) . "\n" . self::ADVICE
		);
	}//end testNoClassNamesATimeZoneLiteral()

	/**
	 * Each of the nine write paths reaches the normaliser.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function testEveryWritePathReachesTheNormaliser(): void {
		$dark = [];
		foreach (self::WRITE_PATHS as $path => $files) {
			$reached = false;
			foreach ($files as $file) {
				$full = self::LIB_DIR . '/' . $file;
				self::assertFileExists($full, 'Write path ' . $path . ' names a file that is gone: ' . $file);
				if (str_contains((string)file_get_contents($full), 'CaseDateNormaliser') === true) {
					$reached = true;
					break;
				}
			}

			if ($reached === false) {
				$dark[] = $path . ' (' . implode(', ', $files) . ')';
			}
		}

		self::assertSame(
			[],
			$dark,
			"These write paths still set a case date without the normaliser:\n - "
			. implode("\n - ", $dark) . "\n" . self::ADVICE
		);
	}//end testEveryWritePathReachesTheNormaliser()

	/**
	 * The five StUF files read the tenant zone through the normaliser.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function testStufMessagesReadTheTenantZone(): void {
		foreach (self::STUF_FILES as $file) {
			$full = self::LIB_DIR . '/' . $file;
			self::assertFileExists($full, 'StUF file is gone: ' . $file);
			self::assertTrue(
				str_contains((string)file_get_contents($full), 'CaseDateNormaliser'),
				$file . ' still states its own zone. A Belgian tenant then sends Dutch timestamps. ' . self::ADVICE
			);
		}
	}//end testStufMessagesReadTheTenantZone()

	/**
	 * The normaliser itself exists, is public and does the work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function testTheNormaliserItselfIsAllowed(): void {
		$path = self::LIB_DIR . '/' . self::NORMALISER;
		self::assertFileExists($path, 'CaseDateNormaliser is the deliverable of one-date-write-path.');

		$source = (string)file_get_contents($path);
		foreach (['toCalendarDate', 'toMoment', 'parse'] as $method) {
			self::assertMatchesRegularExpression(
				'/public\s+function\s+' . $method . '\s*\(/',
				$source,
				'CaseDateNormaliser::' . $method . '() is part of the published surface.'
			);
		}

		self::assertMatchesRegularExpression(
			'/new\s+DateTimeZone\s*\(/',
			$source,
			'CaseDateNormaliser is the one class that resolves a zone.'
		);
	}//end testTheNormaliserItselfIsAllowed()

	/**
	 * Read every PHP file under lib/, keyed by its path relative to lib/.
	 *
	 * @return array<string, string>
	 */
	private function libraryFiles(): array {
		$root = (string)realpath(self::LIB_DIR);
		$files = [];
		$walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($walker as $entry) {
			if ($entry->isFile() === false || $entry->getExtension() !== 'php') {
				continue;
			}

			$relative = ltrim(str_replace($root, '', (string)$entry->getPathname()), '/');
			$files[$relative] = (string)file_get_contents((string)$entry->getPathname());
		}

		ksort($files);
		return $files;
	}//end libraryFiles()

	/**
	 * Names of the private methods in one file that parse a date.
	 *
	 * @param string $source The PHP source to scan.
	 *
	 * @return array<int, string>
	 */
	private function privateDateParsers(string $source): array {
		$found = [];
		if (preg_match_all('/private\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
			return $found;
		}

		foreach ($matches[1] as $index => $capture) {
			$name = (string)$capture[0];
			if (preg_match(self::DATE_NAME_PATTERN, $name) !== 1) {
				continue;
			}

			$body = $this->methodBody($source, (int)$matches[0][$index][1]);
			if (preg_match(self::PARSE_BODY_PATTERN, $body) === 1) {
				$found[] = $name;
			}
		}

		return $found;
	}//end privateDateParsers()

	/**
	 * The braced body of the method starting at an offset.
	 *
	 * @param string $source The PHP source.
	 * @param int $offset Offset of the `private function` keyword.
	 *
	 * @return string
	 */
	private function methodBody(string $source, int $offset): string {
		$open = strpos($source, '{', $offset);
		if ($open === false) {
			return '';
		}

		$depth = 0;
		$length = strlen($source);
		for ($index = $open; $index < $length; $index++) {
			if ($source[$index] === '{') {
				$depth++;
			}

			if ($source[$index] === '}') {
				$depth--;
				if ($depth === 0) {
					return substr($source, $open, (($index - $open) + 1));
				}
			}
		}

		return substr($source, $open);
	}//end methodBody()
}//end class
