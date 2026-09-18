<?php

/**
 * Asking shillinq what this case owes, and taking no for an answer.
 *
 * 🔑 THE READ GOES THROUGH SHILLINQ'S OWN LEAF (ADR-022, ADR-011). The payment
 * requests raised on a case are shillinq objects, and the state a handler
 * reads is derived by shillinq from the provider state and the counter
 * settlements together. So this class calls
 * `PaymentRequestLeafProvider::list()` and maps its answer, rather than
 * querying shillinq's register behind its back and re-deriving the maths. A
 * second derivation is a second answer, and money with two answers is the
 * failure this whole change is about.
 *
 * 🔴 THE LOOKUP IS RUNTIME AND DUCK-TYPED, LIKE EVERY OTHER CROSS-APP SEAM
 * HERE (ADR-083). shillinq is an optional runtime dependency: naming its class
 * in a constructor signature would make dossiq uninstallable without it. Every
 * way the seam can fail — the app absent, the class renamed, the call throwing
 * — lands on ONE answer, `stale`, and never on "nothing is owed". Those two
 * are opposite facts and they must never be the same bytes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a case's payment state from shillinq, or says it could not.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class CasePaymentReader {
	/**
	 * The app that holds the money.
	 *
	 * @var string
	 */
	public const MONEY_APP = 'shillinq';

	/**
	 * The register and schema shillinq's leaf identifies a dossiq case by.
	 *
	 * The leaf keys its requests on `{register, schema, id}`, so these two
	 * literals are how a payment request finds its way back to a case. They
	 * are dossiq's own register and schema slug, frozen the way every stored
	 * literal is frozen: changing them orphans every request already raised.
	 *
	 * @var string
	 */
	public const CASE_REGISTER = 'dossiq';
	public const CASE_SCHEMA = 'case';

	/**
	 * Every spelling of shillinq's payment leaf, newest first.
	 *
	 * ONE SPELLING, because shillinq has only ever published one. The list is
	 * a list so a genuine second spelling has somewhere to go, not because a
	 * second one is expected: a `class_exists()` on a name the other app never
	 * published is dead code pretending to be a fallback.
	 *
	 * @var array<int, string>
	 */
	private const LEAF_CLASSES = [
		'\\OCA\\Shillinq\\Integration\\PaymentRequestLeafProvider',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Nextcloud's app manager, for the installed check.
	 * @param ContainerInterface $container The server container, for the runtime lookup.
	 * @param ITimeFactory $time The clock, so a test can say when "now" is.
	 * @param CasePaymentState $states The state vocabulary.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly ITimeFactory $time,
		private readonly CasePaymentState $states,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The payment state of one case, and when it was read.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{paymentState: string, paymentStateCheckedAt: string} The projection.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-the-payment-state-is-on-the-case-and-read-from-shillinq-req-fee-02
	 */
	public function stateOf(string $caseId): array {
		$now = $this->time->getDateTime()->format('c');

		if ($caseId === '') {
			return $this->states->projection(state: CasePaymentState::STALE, checkedAt: $now);
		}

		$leaf = $this->leaf();
		if ($leaf === null) {
			// shillinq is not installed, or does not publish the leaf this app
			// knows how to read. NOT "nothing is owed": a gemeente that
			// disabled the money app has not waived its leges.
			return $this->states->projection(state: CasePaymentState::STALE, checkedAt: $now);
		}

		try {
			$answer = $leaf->list(self::CASE_REGISTER, self::CASE_SCHEMA, $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the payment state of a case could not be read from shillinq',
				['exception' => $e, 'caseId' => $caseId],
			);

			return $this->states->projection(state: CasePaymentState::STALE, checkedAt: $now);
		}

		$items = ($answer['items'] ?? null);
		if (is_array($items) === false) {
			// An envelope without items is a shape this app does not know how
			// to read, which is a failure to answer rather than an answer of
			// none.
			return $this->states->projection(state: CasePaymentState::STALE, checkedAt: $now);
		}

		return $this->states->projection(
			state: $this->states->fromRequests(requests: $items),
			checkedAt: $now,
		);
	}//end stateOf()

	/**
	 * shillinq's payment leaf, or null when it cannot be had.
	 *
	 * @return object|null The leaf provider.
	 */
	private function leaf(): ?object {
		if ($this->appManager->isInstalled(self::MONEY_APP) === false) {
			return null;
		}

		foreach (self::LEAF_CLASSES as $class) {
			if (class_exists($class) === false) {
				continue;
			}

			try {
				$leaf = $this->container->get(ltrim($class, '\\'));
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: shillinq is installed but its payment leaf could not be resolved',
					['exception' => $e, 'class' => $class],
				);

				continue;
			}

			if (is_object($leaf) === true && method_exists($leaf, 'list') === true) {
				return $leaf;
			}
		}

		return null;
	}//end leaf()
}//end class
