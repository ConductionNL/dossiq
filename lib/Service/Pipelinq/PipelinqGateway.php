<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pipelinq;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place in dossiq that names a pipelinq class.
 *
 * Pipelinq owns the party, the contact moment, the correspondence language,
 * the satisfaction loop and the programme above the cases. Dossiq declares and
 * renders them. Six services are consumed, and resolving each of them inline
 * would put six copies of the same class_exists / method_exists / log-once
 * dance in six files: the day pipelinq renames one, five of those copies would
 * still look healthy.
 *
 * 🔴 A DUCK-TYPED CALL, NOT REST. pipelinq ships HTTP routes as well, and they
 * exist for a caller that is not in the fleet. A fleet app calling them would
 * be sending itself a web request, with a session and a CSRF token, to reach a
 * class already loaded in the same process. ADR-041 and gate-27 say an in-fleet
 * command travels as a typed call, and dossiq already does this twice:
 * PartyIndicatorReader against OpenRegister's guard, CommitteeDelegationService
 * against decidiq's event. This is the third, not a new pattern.
 *
 * 🔴 AN ABSENT PIPELINQ IS NOT AN EMPTY ONE. Every reader built on this gateway
 * reports whether pipelinq answered SEPARATELY from what it answered, because
 * "pipelinq says this case has no contact moments" and "pipelinq is not
 * installed" are different sentences and a handler needs the second one. A
 * panel that cannot tell them apart renders an empty list forever on an
 * instance that has never been asked, which is exactly the failure
 * `projects-leaf.spec.ts` exists to catch on the planninq leaf.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */
class PipelinqGateway {

	/**
	 * pipelinq's contact moments leaf: list and append on a host object.
	 */
	public const CONTACT_MOMENTS = 'OCA\\Pipelinq\\Integration\\ContactMomentLeafProvider';

	/**
	 * The two filing acts: onto a further case, and off one.
	 */
	public const CONTACT_MOMENT_FILING = 'OCA\\Pipelinq\\Service\\ContactMomentFilingService';

	/**
	 * A party's typed fields and its standing indicators.
	 */
	public const PARTY_PANEL = 'OCA\\Pipelinq\\Integration\\PartyLeafProvider';

	/**
	 * Whether an indicator blocks an act on a party.
	 */
	public const PARTY_INDICATORS = 'OCA\\Pipelinq\\Service\\PartyIndicatorService';

	/**
	 * The party kind vocabulary and the acceptance per record type.
	 */
	public const PARTY_KINDS = 'OCA\\Pipelinq\\Service\\PartyKindRegistryService';

	/**
	 * The language to write to a party in, and the rule that produced it.
	 */
	public const CORRESPONDENCE_LANGUAGE = 'OCA\\Pipelinq\\Service\\CorrespondenceLanguageService';

	/**
	 * The satisfaction dispatch rules.
	 */
	public const SURVEY_DISPATCH = 'OCA\\Pipelinq\\Service\\SurveyDispatchService';

	/**
	 * The programme above the cases.
	 */
	public const PROGRAMMES = 'OCA\\Pipelinq\\Service\\ProgrammePortfolioService';

	/**
	 * pipelinq's case-type contribution provider.
	 *
	 * Named HERE and nowhere else, like every other pipelinq class this app
	 * knows about. A FQCN spelled out in a comment somewhere is the thing that
	 * survives a rename and then tells the next reader a lie, and the lookup it
	 * describes is duck-typed, so the day the name moves nothing errors — the
	 * contribution simply stops arriving.
	 *
	 * @var string
	 */
	public const CASE_TYPE_CONTRIBUTIONS = 'OCA\\Pipelinq\\Dossiq\\CaseTypeContributionProvider';

	/**
	 * The app id, for the one log line an absent pipelinq produces.
	 */
	public const APP_ID = 'pipelinq';

	/**
	 * Which capabilities have already been reported missing this request, so
	 * an absent pipelinq costs one log line rather than one per read.
	 *
	 * @var array<string, bool>
	 */
	private array $reported = [];

	/**
	 * @param ContainerInterface $container The server container, which is what
	 *   holds another app's services. Resolving through it rather than `new`
	 *   keeps pipelinq's own constructor dependencies pipelinq's problem.
	 * @param LoggerInterface $logger Says once which capability is unavailable.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance has pipelinq at all.
	 *
	 * Asked by a surface deciding between "nothing to show" and "pipelinq is
	 * not installed". The contact moments leaf is the probe because every
	 * capability here postdates it: an instance carrying it carries the rest.
	 *
	 * @return bool True when pipelinq's services can be resolved.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
	 */
	public function isAvailable(): bool {
		return ($this->service(class: self::CONTACT_MOMENTS, methods: ['list']) !== null);
	}//end isAvailable()

	/**
	 * One pipelinq service, or null when this instance cannot serve it.
	 *
	 * The method list is not decoration. A pipelinq older than the capability
	 * resolves the class and lacks the method, and returning that object would
	 * fatal on the first call instead of degrading. Refusing it here turns a
	 * fatal into a fallback.
	 *
	 * @param string $class One of this class's constants.
	 * @param array<int, string> $methods The methods the caller is about to use.
	 *
	 * @return object|null The service, or null.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
	 */
	public function service(string $class, array $methods = []): ?object {
		try {
			// The container is asked FIRST and its failure is the answer. A
			// `class_exists` early return reads better and is wrong here: the
			// server container is what actually holds another app's services,
			// it autoloads on the way, and short-circuiting on the class name
			// makes this seam untestable without shipping pipelinq's classes
			// into dossiq's test tree. The reason below still says which of
			// the two happened.
			$service = $this->container->get($class);
		} catch (Throwable $e) {
			$reason = $e->getMessage();
			if (class_exists($class) === false) {
				$reason = 'pipelinq is not installed on this instance';
			}

			$this->reportOnce(
				class: $class,
				reason: $reason,
			);

			return null;
		}

		if (is_object($service) === false) {
			$this->reportOnce(class: $class, reason: 'the container answered something that is not an object');

			return null;
		}

		foreach ($methods as $method) {
			// Duck-typed, never instanceof: an interface would make pipelinq a
			// hard dependency of dossiq, which is the coupling this whole seam
			// exists to avoid (hydra ADR-046).
			if (is_callable([$service, $method]) === false) {
				$this->reportOnce(
					class: $class,
					reason: "this pipelinq has no {$method}(), so it predates the capability"
				);

				return null;
			}
		}

		return $service;
	}//end service()

	/**
	 * Call one method on one pipelinq service, or answer the fallback.
	 *
	 * Wraps the call as well as the resolve, because a pipelinq that throws
	 * must not take a dossiq page down with it. What comes back is pipelinq's
	 * answer or the caller's fallback, and `answered` says which.
	 *
	 * @param string $class One of this class's constants.
	 * @param string $method The method to call.
	 * @param array<string, mixed> $arguments Named arguments for it.
	 * @param mixed $fallback What to answer when pipelinq cannot.
	 *
	 * @return array{answered: bool, value: mixed, reason: string} The answer.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
	 */
	public function ask(string $class, string $method, array $arguments = [], mixed $fallback = null): array {
		$service = $this->service(class: $class, methods: [$method]);
		if ($service === null) {
			return [
				'answered' => false,
				'value' => $fallback,
				'reason' => 'pipelinq does not serve this capability on this instance',
			];
		}

		try {
			$value = $service->{$method}(...$arguments);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq pipelinq: the call failed, so the fallback answers and nothing here is refused: '
				. $e->getMessage(),
				['class' => $class, 'method' => $method]
			);

			return ['answered' => false, 'value' => $fallback, 'reason' => $e->getMessage()];
		}

		return ['answered' => true, 'value' => $value, 'reason' => ''];
	}//end ask()

	/**
	 * Say once per request which capability is unavailable.
	 *
	 * Once, because an instance without pipelinq reads these surfaces on every
	 * case page, and a log line per read is a log nobody can use.
	 *
	 * @param string $class The pipelinq class.
	 * @param string $reason Why it could not be served.
	 *
	 * @return void
	 */
	private function reportOnce(string $class, string $reason): void {
		if (isset($this->reported[$class]) === true) {
			return;
		}

		$this->reported[$class] = true;

		$this->logger->debug(
			'Dossiq pipelinq: a capability is unavailable, so its surface answers dossiq\'s own fallback '
			. 'and says pipelinq did not answer: ' . $reason,
			['class' => $class]
		);
	}//end reportOnce()
}//end class
