<?php

/**
 * Every endpoint that takes a citizen identifier is rate limited.
 *
 * 🔴 THIS TEST EXISTS BECAUSE THE ATTRIBUTE IS INVISIBLE WHEN IT IS MISSING.
 * A controller method with no `UserRateLimit` behaves exactly like one with a
 * generous limit: it answers. Nothing warns, nothing logs, and no test that
 * calls the method notices, because the limit is enforced by Nextcloud's
 * middleware and not by the method. So the only way to know the limit is there
 * is to look at the attribute, which is what this does.
 *
 * It is a SWEEP, not three assertions. A fourth endpoint that takes a
 * `burgerId` is exactly the thing that will be added without a limit, and a
 * test naming three methods would stay green through it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ContactMomentController;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The per-account limit on a citizen lookup.
 *
 * @coversNothing
 */
class CitizenLookupRateLimitTest extends TestCase {

	/**
	 * The period the limit is expressed over, in seconds.
	 */
	private const PERIOD = 3600;

	/**
	 * The most lookups one account may make in that period.
	 */
	private const CEILING = 60;

	/**
	 * The methods that resolve a citizen identifier.
	 *
	 * Named rather than derived, and then CHECKED against a derivation below,
	 * so the pair catches both a limit that was dropped and an endpoint that
	 * was added.
	 *
	 * @var array<int, string>
	 */
	private const LOOKUPS = [
		'index',
		'voorblad',
		'create',
		// FOUND BY THE SWEEP BELOW, NOT BY READING THE CHANGE. The proposal
		// named three endpoints. These two also take a caller-supplied
		// `burgerId` and also ask the guard, so they are lookups too and were
		// unlimited. This is the whole reason the second test derives the list
		// instead of trusting this one.
		'nieuweZaak',
		'klachtRegistreren',
	];

	/**
	 * Every lookup method carries the attribute, within the ceiling.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	public function testEveryLookupMethodIsRateLimited(): void {
		$reflection = new ReflectionClass(ContactMomentController::class);

		foreach (self::LOOKUPS as $name) {
			$attributes = $reflection->getMethod($name)->getAttributes(UserRateLimit::class);
			$this->assertCount(1, $attributes, $name . ' must carry exactly one UserRateLimit');

			$limit = $attributes[0]->newInstance();
			$this->assertSame(self::PERIOD, $limit->getPeriod(), $name . ' period');
			$this->assertLessThanOrEqual(self::CEILING, $limit->getLimit(), $name . ' limit');
			// And not so low it breaks the desk, which is how a limit gets
			// removed altogether. See ContactMomentController::LOOKUP_LIMIT_PER_HOUR.
			$this->assertGreaterThanOrEqual(20, $limit->getLimit(), $name . ' limit');
		}
	}//end testEveryLookupMethodIsRateLimited()

	/**
	 * No other public method of the controller resolves a citizen identifier
	 * without carrying the limit.
	 *
	 * The derivation the list above is checked against: any public method whose
	 * body mentions the citizen-lookup guard is a lookup, and a lookup without
	 * a limit is the endpoint somebody added later.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	public function testNoLookupEndpointEscapedTheList(): void {
		$reflection = new ReflectionClass(ContactMomentController::class);
		$source = file((string)$reflection->getFileName());

		$guarded = [];
		foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== ContactMomentController::class) {
				continue;
			}

			$start = (int)$method->getStartLine();
			$end = (int)$method->getEndLine();
			$body = implode('', array_slice($source, $start - 1, ($end - $start) + 1));
			// THE MARKER IS THE CALL, AND THE CALL MOVED. The guard and the
			// recorder became one collaborator, `GuardedCitizenLookup`, so the
			// question a lookup asks is now `$this->lookups->isAllowed()`. The
			// old marker matched nothing and the sweep derived an EMPTY list,
			// which is a sweep that can never find the endpoint it exists to
			// find. A sweep that matches nothing is not a sweep that passed.
			if (str_contains($body, 'lookups->isAllowed') === true) {
				$guarded[] = $method->getName();
			}
		}

		sort($guarded);
		$expected = self::LOOKUPS;
		sort($expected);

		$this->assertSame(
			$expected,
			$guarded,
			'a method asks the citizen-lookup guard and is not in LOOKUPS, so it is an endpoint that '
			. 'resolves a citizen identifier with no rate limit on it'
		);
	}//end testNoLookupEndpointEscapedTheList()
}//end class
