<?php

/**
 * Structural guard: dossiq does not grow a second evaluator of who may open a case.
 *
 * 🔴 WHY A STRUCTURAL TEST AND NOT A CODE REVIEW.
 *
 * D22 put the grant, its provenance, the deny and the inheritance in
 * OpenRegister, because access is a property of the object and the object
 * lives there. D-1 draws the consequence for this repository: "a method in
 * dossiq that decides who may see a case is a finding". That is a rule about
 * code that does not exist yet, and a rule about absent code is exactly the
 * kind nobody remembers. It cannot be enforced by a unit test on a class,
 * because the class whose existence it forbids has no test.
 *
 * So this test enumerates the evaluators that exist TODAY, with a reason each,
 * and fails when a fourth appears. The number in the proposal stops being a
 * number somebody typed, and the day a well-meaning change adds
 * `CaseAccessService::canRead()` the suite says so with the reason written
 * here rather than at review time.
 *
 * WHAT THE ALLOWLIST IS AND IS NOT. It is a record of inherited debt, not an
 * endorsement. Two of the three decide on a DOCUMENT and a SUBSTITUTION ROW
 * rather than on a case, which is outside what openregister#3726 answers. The
 * third does decide on a case, and it is named as the one to retire; retiring
 * it is its own change, because an MCP tool provider that stops checking is a
 * worse outcome than one that checks twice.
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
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans lib/ for a second answer to "who may open this case".
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
class NoSecondPermissionEvaluatorTest extends TestCase {

	/**
	 * The library root under test.
	 *
	 * @var string
	 */
	private const LIB_DIR = __DIR__ . '/../../../lib';

	/**
	 * The method-name vocabulary of an effective-permission evaluator.
	 *
	 * Deliberately names the READ side of the question. dossiq's transition
	 * guards and its mandate matrix decide whether a MOVE is allowed and who
	 * may sign a decision, and D-2 keeps both: they answer a question
	 * OpenRegister is not asked. What OpenRegister owns is whether a caller may
	 * open, list or write the object at all.
	 *
	 * @var string
	 */
	private const EVALUATOR_PATTERN = '/function\s+('
		. 'hasPermission|effectivePermissions?|effectiveGrants|resolveEffectivePermissions?'
		. '|canRead|canOpen|canView|canReadCase|mayRead|mayOpen|mayView'
		. '|isReadableBy|readableBy|computePermissions|grantsFor|permissionsFor'
		. '|isAuthorizedToRead|resolveGrants?'
		. ')\s*\(/';

	/**
	 * What an author should do instead, named in the failure message.
	 *
	 * @var string
	 */
	private const ADVICE = 'Ask OpenRegister instead. '
		. 'OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway reads its effective '
		. 'grants and their provenance, and it is the only class that may. A second '
		. 'evaluator is a second answer, and the first time the two disagree the '
		. 'disagreement is a disclosure. See openspec/changes/case-grants-name-their-source.';

	/**
	 * The evaluators that predate this change, file to method, with the reason each stays.
	 *
	 * @var array<string, string>
	 */
	private const INHERITED = [
		// Decides on a DOCUMENT's classification against the caller's clearance,
		// which is the vertrouwelijkheidaanduiding ladder rather than an RBAC
		// grant. It rides openregister's `sensitive-field-reveal-audit` and
		// dossiq's `sensitive-fields-declared`, both out of scope here.
		'Service/InformatieobjectAccessGuard.php' => 'canRead',
		// Decides on a SUBSTITUTION ROW, whose holders are named on the row
		// itself (absentee, substitute, creator). No object grant expresses it.
		'Service/Substitution/SubstitutionAccessGuard.php' => 'mayView',
		// 🔴 THIS ONE DOES DECIDE ON A CASE, and it is the one to retire. The
		// MCP tool provider asks it before answering an assistant. Retiring it
		// means the provider reads the gateway instead, which is its own change
		// because an MCP surface that stops checking is worse than one that
		// checks twice.
		'Mcp/Tool/DossiqCaseAuthorizer.php' => 'canReadCase',
		'Mcp/DossiqToolProvider.php' => 'mayRead',
	];

	/**
	 * No evaluator exists in lib/ that the allowlist does not already name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testDossiqGrowsNoSecondPermissionEvaluator(): void {
		$found = [];
		foreach ($this->phpFiles() as $relative => $source) {
			if (preg_match_all(self::EVALUATOR_PATTERN, $source, $matches) === 0) {
				continue;
			}

			foreach ($matches[1] as $method) {
				if ((self::INHERITED[$relative] ?? null) === $method) {
					continue;
				}

				$found[] = $relative . '::' . $method . '()';
			}
		}

		self::assertSame(
			[],
			$found,
			"dossiq grew an effective-permission evaluator of its own:\n  "
			. implode("\n  ", $found) . "\n\n" . self::ADVICE
		);
	}//end testDossiqGrowsNoSecondPermissionEvaluator()

	/**
	 * Every allowlisted evaluator still exists, so the list cannot rot into a comment.
	 *
	 * An allowlist entry for a method that has been deleted is a rule that
	 * silently stops guarding a file, and it reads as coverage. Asserting the
	 * entries are live is what keeps the list a record rather than a wish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testEveryAllowlistedEvaluatorStillExists(): void {
		foreach (self::INHERITED as $relative => $method) {
			$path = self::LIB_DIR . '/' . $relative;
			self::assertFileExists(
				$path,
				sprintf('The allowlist names %s, which is gone. Delete the entry.', $relative)
			);
			self::assertMatchesRegularExpression(
				'/function\s+' . preg_quote($method, '/') . '\s*\(/',
				(string)file_get_contents($path),
				sprintf(
					'%s no longer defines %s(). Delete the allowlist entry rather than leaving it to rot.',
					$relative,
					$method
				)
			);
		}
	}//end testEveryAllowlistedEvaluatorStillExists()

	/**
	 * The gateway is the only class that reads OpenRegister's provenance.
	 *
	 * One reader is what makes "dossiq stores no copy" checkable: a second
	 * caller of `provenanceFor()` would be a second place a copy could be kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testOnlyTheGatewayReadsOpenRegistersProvenance(): void {
		$callers = [];
		foreach ($this->phpFiles() as $relative => $source) {
			// 🔑 ANCHORED. `provenanceFor` is a substring of the gateway's own
			// public `provenanceForCase()`, so a plain `str_contains` would
			// name every caller of the gateway as a second reader of
			// OpenRegister. The word boundary matches the OpenRegister method
			// and not dossiq's wrapper around it.
			if (preg_match('/\bprovenanceFor\b/', $source) === 0) {
				continue;
			}

			$callers[] = $relative;
		}

		self::assertSame(
			['Service/Access/OpenRegisterGrantsGateway.php'],
			$callers,
			'OpenRegister\'s provenance is read in one place, so there is one place a copy could be kept. '
			. self::ADVICE
		);
	}//end testOnlyTheGatewayReadsOpenRegistersProvenance()

	/**
	 * Every PHP file under lib/, keyed by its path relative to lib/.
	 *
	 * @return array<string, string> Relative path to file contents.
	 */
	private function phpFiles(): array {
		$root = (string)realpath(self::LIB_DIR);
		$files = [];

		// Iterated by PATH rather than by SplFileInfo: the object form needs an
		// inline docblock to type it, and an inline docblock is the one
		// Squiz.Commenting sniff this repository enforces on tests.
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach (array_keys(iterator_to_array($iterator)) as $path) {
			$path = (string)$path;
			if (is_file($path) === false || pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
				continue;
			}

			$relative = substr($path, (strlen($root) + 1));
			$files[$relative] = (string)file_get_contents($path);
		}

		ksort($files);

		return $files;
	}//end phpFiles()
}//end class
