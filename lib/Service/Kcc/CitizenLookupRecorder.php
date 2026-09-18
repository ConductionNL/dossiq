<?php

/**
 * Records every attempt to resolve a citizen identifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * One `sociaalDomeinAuditLog` row per citizen lookup, refusals included.
 *
 * 🔴 THE REFUSALS ARE THE POINT, NOT THE SUCCESSES. A reveal answers an
 * inzageverzoek under AVG art. 15: who looked at me, when. A REFUSAL answers a
 * different and more urgent question: an account refused four hundred times in
 * an afternoon is not a handler who mistyped a BSN. Before this class, both
 * looked the same afterwards, which is to say like nothing at all: the schema
 * was declared in `register.d/50-sociaal-domein.json` and written by no code
 * anywhere in the app.
 *
 * 🔴 IT SWALLOWS, AND THAT IS DELIBERATE, WHICH IS WHY IT IS WRITTEN DOWN. A
 * recorder that threw would turn an OpenRegister outage into a contact-centre
 * outage. That is the fail-open shape every security review flags, and it is
 * right here for one reason: the act being recorded HAS ALREADY BEEN
 * AUTHORISED by `CitizenLookupGuard`. The record is evidence, not a gate.
 * Anywhere the record were itself the gate, it would have to fail closed, and
 * this class must not be reused there without that changing.
 *
 * The failure is logged at error level rather than dropped, so an instance
 * whose audit has silently stopped is visible in the Nextcloud log instead of
 * being discovered during the audit it exists for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
 */
class CitizenLookupRecorder {

	use SearchesObjects;

	/**
	 * The schema the rows are written to.
	 */
	public const SCHEMA = 'sociaalDomeinAuditLog';

	/**
	 * The result of a lookup that was answered.
	 */
	public const RESULT_ALLOWED = 'succes';

	/**
	 * The result of a lookup the guard refused. One of the schema's own enum
	 * values; a value the enum does not hold is refused by OpenRegister and
	 * this class swallows the refusal, so the row would vanish.
	 */
	public const RESULT_REFUSED = 'geweigerd-none-toegang';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access and the configured register.
	 * @param IRequest        $request         The request, for the caller's address.
	 * @param LoggerInterface $logger          Says when the audit itself failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one lookup.
	 *
	 * @param string             $employeeId The account that looked.
	 * @param string             $subjectId  The citizen reference it looked with.
	 * @param bool               $allowed    Whether the guard permitted it.
	 * @param array<int, string> $fields     The sensitive fields answered, [] when none.
	 * @param string             $ground     Why the caller was allowed, or why not.
	 *
	 * @return bool True when the row was written. Never throws.
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function record(
		string $employeeId,
		string $subjectId,
		bool $allowed,
		array $fields = [],
		string $ground = '',
	): bool {
		try {
			[$objectService, $register] = $this->target();

			$this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: self::SCHEMA,
				object: $this->row(
					employeeId: $employeeId,
					subjectId: $subjectId,
					allowed: $allowed,
					fields: $fields,
					ground: $ground,
				),
			);

			return true;
		} catch (Throwable $e) {
			// \Throwable, not \Exception: an OpenRegister refusal can surface as
			// a PHP Error, and an audit that cannot be written must not take the
			// contact centre down with it. See the class comment for why this
			// fail-open is the right one here and nowhere else.
			$this->logger->error(
				'Dossiq: a citizen lookup could not be recorded. The lookup itself was not affected, '
				. 'and it is now unaccountable.',
				[
					'employee' => $employeeId,
					'allowed' => $allowed,
					'exception' => $e->getMessage(),
				]
			);

			return false;
		}//end try
	}//end record()

	/**
	 * The row one lookup writes.
	 *
	 * `caseId` is deliberately absent: a lookup is about a citizen and may
	 * answer no case at all, which is why the schema stopped requiring it at
	 * 1.1.0. `subjectId` is what an inzageverzoek searches on.
	 *
	 * @param string             $employeeId The account that looked.
	 * @param string             $subjectId  The citizen reference.
	 * @param bool               $allowed    Whether it was permitted.
	 * @param array<int, string> $fields     The sensitive fields answered.
	 * @param string             $ground     The ground.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(
		string $employeeId,
		string $subjectId,
		bool $allowed,
		array $fields,
		string $ground,
	): array {
		return [
			'employeeId' => $employeeId,
			'subjectId' => $subjectId,
			'action' => 'read',
			'moment' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
			'ipAddress' => $this->request->getRemoteAddress(),
			'geraadpleegdeVelden' => array_values($fields),
			'authorisationGround' => $ground,
			'result' => ($allowed === true ? self::RESULT_ALLOWED : self::RESULT_REFUSED),
		];
	}//end row()

	/**
	 * The object service and the register.
	 *
	 * @return array{0: object, 1: string} The two.
	 *
	 * @throws RuntimeException When OpenRegister or the register is absent.
	 */
	private function target(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end target()
}//end class
