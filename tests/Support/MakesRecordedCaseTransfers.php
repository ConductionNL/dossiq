<?php

/**
 * Builds a CaseTransferService whose every collaborator call is recorded.
 *
 * Calls are recorded by parameter name, read from the real collaborator's
 * signature. Behaviour keys, besides those of RecordingTransferObjects:
 * `noObjectService`, `scope` ([register, schema]), `consent` (the verdict),
 * `precheck` (the idempotency answer), `mint` (a share id, or null),
 * `custodyRefuses` (true makes the custody move refuse).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use Closure;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTransferService;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\Dossiq\Service\Transfer\FederatedIdempotency;
use OCA\Dossiq\Service\Transfer\InternalHandover;
use OCA\Dossiq\Service\Transfer\TransferRegisterGateway;
use OCA\Dossiq\Service\Transfer\TransferShareBroker;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

trait MakesRecordedCaseTransfers {

	/**
	 * The calls made in the current case, in order.
	 *
	 * @var array<int, mixed>
	 */
	protected array $calls = [];

	/**
	 * The transfer store of the current case.
	 *
	 * @var RecordingTransferObjects|null
	 */
	protected ?RecordingTransferObjects $objects = null;

	/**
	 * A transfer service over recording doubles.
	 *
	 * @param array<string, mixed> $behaviour How the collaborators answer.
	 *
	 * @return CaseTransferService
	 */
	protected function recordedTransferService(array $behaviour): CaseTransferService {
		$this->calls = [];
		$this->objects = new RecordingTransferObjects(behaviour: $behaviour, log: $this->calls);
		$objects = $this->objects;
		$refuses = (($behaviour['custodyRefuses'] ?? false) === true);

		return new CaseTransferService(
			gateway: $this->recorder(class: TransferRegisterGateway::class, answers: [
				'objectService' => (($behaviour['noObjectService'] ?? false) === true) ? null : $objects,
				'transferScope' => ($behaviour['scope'] ?? [5, 7]),
			]),
			shareBroker: $this->recorder(class: TransferShareBroker::class, answers: [
				'mintTransferShare' => $this->mintedShare(shareId: (array_key_exists('mint', $behaviour) ? $behaviour['mint'] : 'share-1')),
				'resolveTransferShare' => (array_key_exists('resolve', $behaviour) ? $behaviour['resolve'] : ['sharedWith' => 'partner@remote.example', 'organisation' => null]),
			]),
			logger: $this->recordingLogger(),
			auditTrail: $this->recorder(class: TenantAuditTrailService::class, answers: ['emit' => ['ok' => true]]),
			internal: $this->recorder(class: InternalHandover::class, answers: [
				'initiate' => ['id' => 'handover-1'],
				'accept' => ['status' => 'accepted'],
				'refuse' => ['status' => 'refused'],
				'outstandingFor' => [['id' => 'handover-2']],
			]),
			custody: $this->recorder(class: CaseCustodyChain::class, answers: [
				'move' => static function () use ($refuses): array {
					if ($refuses === true) {
						throw new RefusedException('custody-closed', 'The case cannot move now.');
					}

					return ['moved' => true];
				},
			]),
			consent: $this->recorder(class: CaseTransferConsentGate::class, answers: [
				'assess' => ($behaviour['consent'] ?? ['allowed' => true, 'rule' => 'no-consent-needed', 'sentence' => '']),
			]),
			idempotency: $this->recorder(class: FederatedIdempotency::class, answers: [
				'precheck' => ($behaviour['precheck'] ?? ['key' => null, 'answer' => null]),
			]),
		);
	}//end recordedTransferService()

	/**
	 * A mock of one collaborator that records each listed method's calls by name.
	 *
	 * @param string               $class   The collaborator class.
	 * @param array<string, mixed> $answers Method => answer, or a Closure taking the named arguments.
	 *
	 * @return mixed
	 */
	private function recorder(string $class, array $answers): mixed {
		$mock = $this->createMock($class);
		$short = substr((string)strrchr($class, '\\'), 1);
		foreach ($answers as $method => $answer) {
			$names = array_map(
				static fn ($param): string => $param->getName(),
				(new ReflectionMethod($class, $method))->getParameters()
			);
			$mock->method($method)->willReturnCallback(
				function (...$args) use ($names, $short, $method, $answer): mixed {
					$named = [];
					$shown = [];
					foreach ($args as $index => $value) {
						$named[$names[$index]] = $value;
						$shown[$names[$index]] = is_object($value) ? ('<' . get_class($value) . '>') : $value;
					}

					$this->calls[] = [$short . '.' . $method => $shown];
					if ($answer instanceof Closure) {
						return $answer($named);
					}

					return $answer;
				}
			);
		}

		return $mock;
	}//end recorder()

	/**
	 * A minted share answering one id, or none.
	 *
	 * @param string|null $shareId The share id, or null for a failed mint.
	 *
	 * @return object|null
	 */
	private function mintedShare(?string $shareId): ?object {
		if ($shareId === null) {
			return null;
		}

		return new class ($shareId) {
			/**
			 * Constructor.
			 *
			 * @param string $shareId The share id.
			 */
			public function __construct(private string $shareId) {
			}//end __construct()

			/**
			 * The share id.
			 *
			 * @return string
			 */
			public function getId(): string {
				return $this->shareId;
			}//end getId()
		};
	}//end mintedShare()

	/**
	 * A logger that writes every line into the call log.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		$calls = &$this->calls;
		return new class ($calls) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $calls The shared call log.
			 */
			public function __construct(private array &$calls) {
			}//end __construct()

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->calls[] = ['log.' . (string)$level => ['message' => (string)$message, 'context' => $context]];
			}//end log()
		};
	}//end recordingLogger()
}//end trait
