<?php

/**
 * Builds a CaseSharingService whose every collaborator call is recorded.
 *
 * Calls are recorded by parameter name, read from the real collaborator's
 * signature; objects are recorded by class name. Behaviour keys, besides
 * those of RecordingShareObjects: `noObjectService`, `config` (setting =>
 * value), `consent` (the verdict), `caseLink` (the minted case link),
 * `fileLinks` (file id => link), `stored` (the recorded share),
 * `notRevoked` (what revokeMinted() could not pull), `linksRefused` (what
 * revokeLinksOf() could not pull).
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
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\FederatedCaseShareService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

trait MakesRecordedCaseShares {

	/**
	 * The calls made in the current case, in order.
	 *
	 * @var array<int, mixed>
	 */
	protected array $calls = [];

	/**
	 * The share store of the current case.
	 *
	 * @var RecordingShareObjects|null
	 */
	protected ?RecordingShareObjects $objects = null;

	/**
	 * A sharing service over recording doubles; its access check says yes for alice only.
	 *
	 * @param array<string, mixed> $behaviour How the collaborators answer.
	 *
	 * @return CaseSharingService
	 */
	protected function recordedSharingService(array $behaviour): CaseSharingService {
		$this->calls = [];
		$this->objects = new RecordingShareObjects(behaviour: $behaviour, log: $this->calls);
		$config = array_merge(['register' => '5', 'case_share_schema' => '9'], ($behaviour['config'] ?? []));
		$fileLinks = ($behaviour['fileLinks'] ?? []);

		return new CaseSharingService(
			settingsService: $this->shareRecorder(class: SettingsService::class, answers: [
				'getConfigValue' => static fn (array $args): string => ($config[$args['key']] ?? $args['default']),
			]),
			gateway: $this->shareRecorder(class: OpenRegisterSharingGateway::class, answers: [
				'objectService' => (($behaviour['noObjectService'] ?? false) === true) ? null : $this->objects,
				'toArray' => static fn (array $args): array => is_object($args['value']) ? $args['value']->jsonSerialize() : (array)$args['value'],
			]),
			accessPolicy: $this->shareRecorder(class: CaseAccessPolicy::class, answers: [
				'canUserAccessCase' => static fn (array $args): bool => $args['userId'] === 'alice',
			]),
			accessLinks: $this->shareRecorder(class: CaseAccessLinkService::class, answers: [
				'mintCaseLink' => ($behaviour['caseLink'] ?? ['id' => 11, 'uuid' => 'link-11', 'url' => 'https://cloud.example/l/abc', 'expiresAt' => '2026-12-01']),
				'mintFileLink' => static fn (array $args): array => ($fileLinks[$args['fileId']] ?? ['id' => 20, 'uuid' => 'file-' . $args['fileId'], 'url' => 'https://cloud.example/f/' . $args['fileId']]),
			]),
			linkShares: $this->shareRecorder(class: CaseLinkShares::class, answers: [
				'store' => ($behaviour['stored'] ?? ['id' => 'share-1', 'shareType' => 'link']),
				'revokeMinted' => ($behaviour['notRevoked'] ?? []),
				'revokeLinksOf' => ($behaviour['linksRefused'] ?? []),
			]),
			federatedShares: $this->shareRecorder(class: FederatedCaseShareService::class, answers: [
				'createFederatedShare' => ['id' => 'fed-1'],
				'revokeFederatedShare' => ['status' => 'revoked'],
				'getCaseIdForFederatedShare' => 'case-1',
			]),
			consent: $this->shareRecorder(class: CaseTransferConsentGate::class, answers: [
				'assess' => ($behaviour['consent'] ?? ['allowed' => true, 'rule' => 'consent-found', 'sentence' => '', 'consent' => ['id' => ' consent-1 '], 'scope' => ['title'], 'until' => '2027-01-01']),
			]),
			logger: $this->shareLogger(),
		);
	}//end recordedSharingService()

	/**
	 * A mock of one collaborator that records each listed method's calls by name.
	 *
	 * @param string               $class   The collaborator class.
	 * @param array<string, mixed> $answers Method => answer, or a Closure taking the named arguments.
	 *
	 * @return mixed
	 */
	private function shareRecorder(string $class, array $answers): mixed {
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
	}//end shareRecorder()

	/**
	 * A logger that writes every line into the call log.
	 *
	 * @return AbstractLogger
	 */
	private function shareLogger(): AbstractLogger {
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
	}//end shareLogger()
}//end trait
