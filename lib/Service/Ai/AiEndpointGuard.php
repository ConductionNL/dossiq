<?php

/**
 * Procest AI endpoint guard.
 *
 * The SSRF check applied to the configured AI model URL before any outbound
 * request is made: https-only plus a public-address requirement for cloud
 * models, http/https to loopback (or a docker service name that does not
 * resolve into the cloud metadata range) for local ones.
 *
 * Split out of {@see \OCA\Procest\Service\AiService}: this is a self-contained
 * security decision with its own CIDR deny-list and its own IPv4/IPv6 range
 * arithmetic, and it belongs next to that deny-list rather than inside a class
 * that also builds prompts and writes audit entries.
 *
 * @category Service
 * @package  OCA\Procest\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Ai;

use OCA\Procest\Support\SuppressesWarnings;
use Psr\Log\LoggerInterface;

/**
 * Validates that a configured AI model URL is safe to connect to.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */
class AiEndpointGuard {

	use SuppressesWarnings;

	/**
	 * RFC1918 + loopback + link-local CIDR blocks to deny (SSRF protection).
	 *
	 * @var string[]
	 */
	private const BLOCKED_CIDRS = [
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'::1/128',
		'fc00::/7',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger interface.
	 *
	 * @return void
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate that the configured AI model URL is safe to connect to (SSRF guard).
	 *
	 * For cloud models, requires https and a public hostname.
	 * For local models, allows http only to localhost / 127.0.0.1.
	 *
	 * @param string $url The base AI model URL.
	 * @param string $modelType The model type ('local' or 'cloud').
	 *
	 * @return bool True if the URL passes the SSRF check.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function isSafeUrl(string $url, string $modelType): bool {
		$parsed = parse_url($url);
		$scheme = strtolower($parsed['scheme'] ?? '');
		$host = strtolower($parsed['host'] ?? '');

		if ($host === '') {
			return false;
		}//end if

		if ($modelType === 'local') {
			return $this->isSafeLocalAiUrl(scheme: $scheme, host: $host);
		}//end if

		return $this->isSafeCloudAiUrl(scheme: $scheme, host: $host);
	}//end isSafeUrl()

	/**
	 * Validate a local-model URL (SSRF guard).
	 *
	 * Only http/https to localhost or 127.0.0.1; named docker service hostnames
	 * are allowed but must not resolve into the cloud metadata range.
	 *
	 * @param string $scheme The lower-cased URL scheme.
	 * @param string $host The lower-cased URL host.
	 *
	 * @return bool True if the URL passes the SSRF check.
	 */
	private function isSafeLocalAiUrl(string $scheme, string $host): bool {
		// Local models: only http/https to localhost or 127.0.0.1.
		if (in_array($scheme, ['http', 'https'], true) === false) {
			return false;
		}//end if

		if ($host !== 'localhost' && $host !== '127.0.0.1' && $host !== '::1') {
			// Allow named docker service hostnames (e.g. 'ollama') for local deployments
			// but still block known public metadata endpoints and RFC1918 IPs.
			$ipAddress = gethostbyname($host);
			if ($ipAddress !== $host
				&& $this->ipInCidr(ipAddress: $ipAddress, cidr: '169.254.0.0/16') === true
			) {
				$this->logger->warning(
					'AI SSRF: local model URL resolves to cloud metadata range',
					['host' => $host, 'ip' => $ipAddress]
				);
				return false;
			}//end if
		}//end if

		return true;
	}//end isSafeLocalAiUrl()

	/**
	 * Validate a cloud-model URL (SSRF guard).
	 *
	 * Https only, and the host must resolve to a public (non-RFC1918,
	 * non-loopback) address.
	 *
	 * @param string $scheme The lower-cased URL scheme.
	 * @param string $host The lower-cased URL host.
	 *
	 * @return bool True if the URL passes the SSRF check.
	 */
	private function isSafeCloudAiUrl(string $scheme, string $host): bool {
		// Cloud models: https only, must resolve to a public (non-RFC1918) address.
		if ($scheme !== 'https') {
			$this->logger->warning(
				'AI SSRF: cloud model URL must use https',
				['scheme' => $scheme]
			);
			return false;
		}//end if

		$records = $this->withoutWarnings(
			operation: static function () use ($host): mixed {
				return dns_get_record($host, (DNS_A | DNS_AAAA));
			}
		);
		if ($records === false || count($records) === 0) {
			$this->logger->warning(
				'AI SSRF: DNS resolution returned no records',
				['host' => $host, 'detail' => $this->lastSuppressedWarning()]
			);
			return false;
		}//end if

		foreach ($records as $record) {
			if ($this->isBlockedAddress(record: $record, host: $host) === true) {
				return false;
			}//end if
		}

		return true;
	}//end isSafeCloudAiUrl()

	/**
	 * Whether one DNS record resolves into a denied CIDR block.
	 *
	 * @param array<string, mixed> $record One dns_get_record() entry.
	 * @param string $host The host being validated (for logging).
	 *
	 * @return bool True when the address is denied.
	 */
	private function isBlockedAddress(array $record, string $host): bool {
		$ipAddress = ($record['ip'] ?? ($record['ipv6'] ?? null));
		if ($ipAddress === null) {
			return false;
		}//end if

		foreach (self::BLOCKED_CIDRS as $cidr) {
			if ($this->ipInCidr(ipAddress: $ipAddress, cidr: $cidr) === true) {
				$this->logger->warning(
					'AI SSRF: cloud model URL resolves to private/loopback address',
					['host' => $host, 'ip' => $ipAddress, 'cidr' => $cidr]
				);
				return true;
			}//end if
		}

		return false;
	}//end isBlockedAddress()

	/**
	 * Check if an IP address falls within a CIDR range (IPv4 and IPv6).
	 *
	 * @param string $ipAddress The IP address to test.
	 * @param string $cidr The CIDR block (e.g. '10.0.0.0/8').
	 *
	 * @return bool True if the IP is within the range.
	 */
	private function ipInCidr(string $ipAddress, string $cidr): bool {
		$isIpv6Cidr = str_contains($cidr, ':');
		$isIpv6Ip = str_contains($ipAddress, ':');

		if ($isIpv6Cidr === true && $isIpv6Ip === true) {
			return $this->isIpv6InCidr(ipAddress: $ipAddress, cidr: $cidr);
		}//end if

		if ($isIpv6Cidr === false && $isIpv6Ip === false) {
			return $this->isIpv4InCidr(ipAddress: $ipAddress, cidr: $cidr);
		}//end if

		return false;
	}//end ipInCidr()

	/**
	 * Check if an IPv6 address falls within an IPv6 CIDR range.
	 *
	 * @param string $ipAddress The IPv6 address to test.
	 * @param string $cidr The IPv6 CIDR block (e.g. 'fc00::/7').
	 *
	 * @return bool True if the IP is within the range.
	 */
	private function isIpv6InCidr(string $ipAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr);
		$prefixLen = (int)$prefix;
		$networkBin = inet_pton($network);
		$inputBin = inet_pton($ipAddress);
		if ($networkBin === false || $inputBin === false) {
			return false;
		}//end if

		$fullBytes = intdiv($prefixLen, 8);
		$remainBits = $prefixLen % 8;
		for ($i = 0; $i < $fullBytes; $i++) {
			if ($networkBin[$i] !== $inputBin[$i]) {
				return false;
			}//end if
		}

		if ($remainBits > 0 && $fullBytes < 16) {
			$mask = (0xFF << (8 - $remainBits)) & 0xFF;
			if ((ord($networkBin[$fullBytes]) & $mask) !== (ord($inputBin[$fullBytes]) & $mask)) {
				return false;
			}//end if
		}//end if

		return true;
	}//end isIpv6InCidr()

	/**
	 * Check if an IPv4 address falls within an IPv4 CIDR range.
	 *
	 * @param string $ipAddress The IPv4 address to test.
	 * @param string $cidr The IPv4 CIDR block (e.g. '10.0.0.0/8').
	 *
	 * @return bool True if the IP is within the range.
	 */
	private function isIpv4InCidr(string $ipAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr);
		$prefixLen = (int)$prefix;
		$networkLong = ip2long($network);
		$ipLong = ip2long($ipAddress);
		if ($networkLong === false || $ipLong === false) {
			return false;
		}//end if

		$mask = 0;
		if ($prefixLen !== 0) {
			$mask = ~0 << (32 - $prefixLen);
		}//end if

		return ($ipLong & $mask) === ($networkLong & $mask);
	}//end isIpv4InCidr()
}//end class
