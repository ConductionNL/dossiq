<?php

/**
 * A service class nothing calls is a capability that does not exist.
 *
 * dossiq#2945 shipped `CaseSplitPolicy`, `CaseSplitPlan` and `IncidentRecord`.
 * All three were correct, all three had their own suites, all three were green,
 * and nothing anywhere called any of them: no controller, no listener, no job,
 * no component. The change was merged, the tasks were ticked, and the
 * capability was dark. dossiq#2957 supplied the callers a fortnight later.
 *
 * The same sweep found `AreaRouting` in exactly that state from dossiq#2936,
 * and `CaseAreaResolver` behind it.
 * `QueueItemLifecycle` was on that list too, and left it when
 * `PersonalQueueService` started asking it.
 *
 * ⚠️ TWO OF THESE WERE FIRST READ AS DUPLICATES AND NEITHER WAS. `DecisionService`
 * was noted as superseded by a `BezwaarDecisionService` THAT DOES NOT EXIST, and
 * `PdokBagService` as superseded by `BagApiAdapter`, which its own interface
 * calls deliberately distinct. Both notes were written from the name alone. A
 * class that looks like a duplicate is the one case where the sweep's answer has
 * to be checked against the code before anybody deletes anything.
 *
 * 🔴 A UNIT TEST OF THE CLASS CANNOT SEE THIS. A pure class answers the same
 * whether anybody asks it or not, so its own suite is green throughout, which
 * is why the shape survived four merges. Only a sweep over the whole tree can
 * tell a capability from a correct class nobody uses.
 *
 * WHAT IS SWEPT, AND WHAT IS NOT. Controllers resolve from a route name,
 * listeners and jobs from a registration, repair steps from `info.xml`: each is
 * reachable without its class name ever appearing beside a caller, so a
 * name-based sweep would report every one of them. The sweep is therefore over
 * the DECIDING layer only, the directories where a class is reached by being
 * constructed and asked. A controller that no route names is
 * `RouteReachabilityTest`'s job and it already does it.
 *
 * THE ALLOWLIST ONLY SHRINKS. An entry whose class has since acquired a caller
 * fails here, the same way the no-surface allowlist does, so the number cannot
 * quietly stay where it is.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @coversNothing
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every deciding class is asked by something.
 */
class NoDarkCapabilityTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The directories whose classes are reached by being constructed and asked.
	 *
	 * Deliberately NOT `lib/Controller`, `lib/Listener`, `lib/BackgroundJob`,
	 * `lib/Command`, `lib/Migration`, `lib/Repair`, `lib/Settings` or
	 * `lib/Dashboard`: each of those is reached by a registration rather than
	 * by a call, so a name-based sweep reports all of them and means nothing.
	 *
	 * @var array<int, string>
	 */
	private const SWEPT = [
		'lib/Service',
		'lib/Portal',
		'lib/Contribution',
	];

	/**
	 * Where a caller could be.
	 *
	 * @var array<int, string>
	 */
	private const CALLERS = ['lib', 'src', 'appinfo', 'templates'];

	/**
	 * Classes with no caller today, and why each is still here.
	 *
	 * Every entry is a capability that was shipped and never wired. The reason
	 * says what it would take, so the next person picks one up rather than
	 * rediscovering it.
	 *
	 * @var array<string, string>
	 */
	private const DARK_TODAY = [
		// THE LAST ONE, AND THE ONLY ONE THAT IS BLOCKED RATHER THAN UNDECIDED.
		// Everything else on this list has been wired or retired; see the
		// docblock above for where each went.
		//
		// WHAT WOULD UNBLOCK IT, NAMED: the change `dossiq/case-location-surface`,
		// which is the one that should put a BAG address id on the case. Without
		// that field the listener has nothing to pass, and guessing an address
		// from a case's free-text location would route a case to the wrong wijk
		// silently, which is worse than not routing it. Nothing else is missing:
		// the resolver itself is correct and tested, `AreaRouting` is wired and
		// consulted by `RoleResolverService`, and since the PDOK BAG adapter
		// landed an instance can resolve an address id for free, with no
		// Kadaster key. One field is the whole gap.
		'CaseAreaResolver' => 'REQ-RTP-04 write half (dossiq#2936): resolves a BAG address id to a wijk, and no case field carries an address id, so the listener that should call it has nothing to pass. BLOCKED on the change `dossiq/case-location-surface`, which should add that field; it is the only thing missing.',

		// THREE PIPELINQ CONSUMERS, each blocked on a SURFACE the change that
		// introduced them never specified. Their sibling `PartyRefusalReader`
		// is wired, into `FileRequestService`, because dossiq already had the
		// question it answers. These three answer questions nothing in dossiq
		// asks yet, and inventing a caller to satisfy the guard would ship the
		// surface's decisions inside a service nobody designed.
		'PartyKindConsumer' => 'REQ-PLQ-04: answers which party kinds a CASE TYPE accepts, preferring pipelinq over dossiq\'s three. dossiq decides kinds once, schema-wide, in `CaseRoleVocabulary::sync()`, which has no case type to ask about. BLOCKED on a per-case-type party picker; until one exists there is no call site whose question this is.',
		'CorrespondenceLanguageConsumer' => 'REQ-PLQ-06: the language to write to a party in, with the reason it was chosen. Nothing in dossiq chooses a correspondence language today — the letter paths take the instance language. BLOCKED on the correspondence surface that would show the tag and its reason beside the recipient.',
		'ProgrammeConsumer' => 'REQ-PLQ-08: hangs a case under a programme and renders the progress with its mode. dossiq has no programme surface at all: no tab, no route, no field on the case. BLOCKED on that surface; the consumer is complete and tested against pipelinq\'s contract.',
	];

	/**
	 * Nothing deciding is unreachable and unexplained.
	 *
	 * @return void
	 */
	public function testEveryDecidingClassIsCalledOrExplained(): void {
		$dark = $this->darkClasses();
		$unexplained = array_values(array_diff($dark, array_keys(self::DARK_TODAY)));

		$this->assertSame(
			[],
			$unexplained,
			"These classes are shipped and nothing calls them, so the capability does not exist:\n  "
				. implode("\n  ", $unexplained)
				. "\nGive each one a caller, or add it to DARK_TODAY with what wiring it would take. "
				. 'A merged change whose classes nobody asks is the shape dossiq#2945 shipped and #2957 repaired.'
		);
	}

	/**
	 * The list only shrinks.
	 *
	 * @return void
	 */
	public function testTheListHoldsNothingThatNowHasACaller(): void {
		$dark = $this->darkClasses();
		$stale = array_values(array_diff(array_keys(self::DARK_TODAY), $dark));

		$this->assertSame(
			[],
			$stale,
			"These are on the dark list and now have a caller; take them off:\n  " . implode("\n  ", $stale)
		);
	}

	/**
	 * The sweep read something, so an empty answer is an answer.
	 *
	 * Without this a broken path would report no dark classes and pass, which
	 * is the failure mode of every sweep that greps.
	 *
	 * @return void
	 */
	public function testTheSweepActuallyRead(): void {
		$this->assertGreaterThan(
			200,
			count($this->declaredClasses()),
			'The sweep found almost no classes, so it cannot have checked any.'
		);
	}

	/**
	 * The declared classes with no reference outside their own file.
	 *
	 * @return array<int, string> The class names.
	 */
	private function darkClasses(): array {
		$dark = [];
		foreach ($this->declaredClasses() as $name => $path) {
			if ($this->hasCaller(name: $name, ownPath: $path) === false) {
				$dark[] = $name;
			}
		}

		sort($dark);

		return $dark;
	}

	/**
	 * Every class declared in the swept directories, by name.
	 *
	 * @return array<string, string> Name to path.
	 */
	private function declaredClasses(): array {
		$classes = [];
		foreach (self::SWEPT as $dir) {
			$base = self::ROOT . '/' . $dir;
			if (is_dir($base) === false) {
				continue;
			}

			$walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
			foreach ($walker as $file) {
				if ($file->isFile() === false || $file->getExtension() !== 'php') {
					continue;
				}

				$source = (string)file_get_contents($file->getPathname());
				if (preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $m) === 1) {
					$classes[$m[1]] = $file->getPathname();
				}
			}
		}

		return $classes;
	}

	/**
	 * Every file a caller could be in, read once.
	 *
	 * READ ONCE, not once per class. Walking the tree per class turned a
	 * three-second sweep into thirty, and a slow guard is a guard somebody
	 * eventually moves out of the unit suite.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $haystack = null;

	/**
	 * Whether anything outside the class's own file names it.
	 *
	 * A whole-word match over the shipped tree, which is deliberately generous:
	 * a constructor type hint, a `::class`, a static call and a manifest string
	 * all count. The question is not how it is reached but whether ANYTHING
	 * reaches it, and a generous match keeps the false positives down to the
	 * conventions listed on {@see self::SWEPT}.
	 *
	 * @param string $name    The class name.
	 * @param string $ownPath Its own file.
	 *
	 * @return bool TRUE when something else names it.
	 */
	private function hasCaller(string $name, string $ownPath): bool {
		$own = (string)realpath($ownPath);
		$pattern = '/\b' . preg_quote($name, '/') . '\b/';

		foreach ($this->haystack() as $path => $source) {
			if ($path === $own) {
				continue;
			}

			if (preg_match($pattern, $source) === 1) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One PHP file with its comments removed.
	 *
	 * 🔴 A CLASS NAMED ONLY IN PROSE IS NOT CALLED, and this is the line that
	 * says so. Without it, the docblock sentence explaining why a class exists
	 * counts as its caller, which is precisely the shape being guarded against:
	 * every one of dossiq#2945's three classes was written about at length and
	 * constructed by nobody. Proved by removing this file's own caller for
	 * `AreaRouting` and watching the sweep stay green on the leftover comment.
	 *
	 * @param string $source The file.
	 * @param bool   $isPhp  Whether it can be tokenised.
	 *
	 * @return string The source a name may be looked for in.
	 */
	private function withoutComments(string $source, bool $isPhp): string {
		if ($isPhp === false) {
			return $source;
		}

		$kept = '';
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === true) {
				if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
					continue;
				}

				$kept .= $token[1];
				continue;
			}

			$kept .= $token;
		}

		return $kept;
	}

	/**
	 * Every file a caller could be in, keyed by its real path.
	 *
	 * @return array<string, string> Path to contents.
	 */
	private function haystack(): array {
		if (self::$haystack !== null) {
			return self::$haystack;
		}

		$files = [];
		foreach (self::CALLERS as $dir) {
			$base = self::ROOT . '/' . $dir;
			if (is_dir($base) === false) {
				continue;
			}

			$walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
			foreach ($walker as $file) {
				if ($file->isFile() === false) {
					continue;
				}

				if (in_array($file->getExtension(), ['php', 'js', 'vue', 'ts', 'json', 'xml'], true) === false) {
					continue;
				}

				$files[(string)realpath($file->getPathname())] = $this->withoutComments(
					source: (string)file_get_contents($file->getPathname()),
					isPhp: ($file->getExtension() === 'php'),
				);
			}
		}

		self::$haystack = $files;

		return $files;
	}
}
