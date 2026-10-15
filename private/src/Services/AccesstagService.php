<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\VirtualDeviceDAO;
use App\Support\AccesstagList;
use App\Support\VirtualDeviceMode;

class AccesstagService
{
    public function __construct(
        private AuthService $authService,
        private VirtualDeviceDAO $virtualDeviceDAO,
        private BoardDAO $boardDAO,
        private EntryZenReadService $entryZenReadService
    ) {
    }

    public function replace(
        ?string $token,
        string $identifier,
        mixed $version,
        mixed $accesstags,
        bool $hasVersion = true,
        bool $hasAccesstags = true,
        mixed $readsEndpoint = null,
        mixed $readsToken = null,
        bool $hasEndpoint = false,
        bool $hasReadsToken = false
    ): array {
        if (!$this->authService->apiKeyMatches($token)) {
            return $this->fail('Invalid API key.', 'UNAUTHORIZED');
        }
        $identifier = trim($identifier);
        if ($identifier === '') {
            return $this->fail('Identifier is required.', 'VALIDATION_ERROR');
        }
        $device = $this->virtualDeviceDAO->findByPublicId($identifier);
        if ($device === null) {
            return $this->fail('Virtual device not found.', 'NOT_FOUND');
        }
        if (!VirtualDeviceMode::hasReader($device)) {
            return $this->fail('Virtual device has no Wiegand reader.', 'VALIDATION_ERROR');
        }

        $autonomous = $this->virtualDeviceDAO->relays((int) $device['id']) !== [];
        if (!$autonomous) {
            if ($hasVersion || $hasAccesstags) {
                return $this->fail('This reader does not use an accesstag list.', 'VALIDATION_ERROR');
            }
            $destination = $this->destinationPair($hasEndpoint, $hasReadsToken, $readsEndpoint, $readsToken);
            if (is_string($destination)) {
                return $this->fail($destination, 'VALIDATION_ERROR');
            }
            $this->storeDestination((int) $device['id'], (int) $device['board_id'], $destination);

            return [
                'success' => true,
                'data' => ['virtual_device' => (string) $device['public_id']],
                'error' => null,
            ];
        }

        if ($hasEndpoint || $hasReadsToken) {
            $destination = $this->destinationPair(true, true, $readsEndpoint, $readsToken);
            if (is_string($destination)) {
                return $this->fail($destination, 'VALIDATION_ERROR');
            }
        } else {
            $destination = null;
        }

        $versionText = trim((string) $version);
        if ($versionText === '') {
            return $this->fail('Version is required.', 'VALIDATION_ERROR');
        }
        if (!is_array($accesstags)) {
            return $this->fail('Accesstags must be an array.', 'VALIDATION_ERROR');
        }
        $numbers = [];
        foreach ($accesstags as $number) {
            if (!is_string($number) && !is_int($number)) {
                return $this->fail('Accesstags must be non-empty strings.', 'VALIDATION_ERROR');
            }
            $text = trim((string) $number);
            if ($text === '') {
                return $this->fail('Accesstags must be non-empty strings.', 'VALIDATION_ERROR');
            }
            $numbers[] = $text;
        }
        $numbers = AccesstagList::normalize($numbers);
        $pushed = $device['accesstag_list_pushed_version'];
        if ($pushed === null || (string) $pushed !== $versionText) {
            $this->virtualDeviceDAO->replaceNumbers((int) $device['id'], $numbers, false);
            $this->virtualDeviceDAO->setPushedVersion((int) $device['id'], $versionText);
        }
        if ($destination !== null) {
            $this->storeDestination((int) $device['id'], (int) $device['board_id'], $destination);
        }

        return $this->ok((string) $device['public_id'], $versionText);
    }

    public function pull(array $body): array
    {
        $hardwareId = trim((string) ($body['hardware_id'] ?? ''));
        if ($hardwareId === '') {
            return $this->fail('Board identifier is required.', 'VALIDATION_ERROR');
        }
        $board = $this->boardDAO->findByHardwareId($hardwareId);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }
        $publicId = trim((string) ($body['virtual_device'] ?? ''));
        $device = $publicId === '' ? null : $this->virtualDeviceDAO->findByPublicId($publicId);
        if ($device === null || (int) $device['board_id'] !== (int) $board['id']) {
            return $this->fail('Virtual device not found.', 'NOT_FOUND');
        }
        if (!VirtualDeviceMode::hasReader($device)) {
            return $this->fail('Virtual device has no Wiegand reader.', 'VALIDATION_ERROR');
        }
        if ($this->virtualDeviceDAO->relays((int) $device['id']) === []) {
            return $this->fail('This reader does not use an accesstag list.', 'VALIDATION_ERROR');
        }
        if (!array_key_exists('from_version', $body) || !array_key_exists('from_hash', $body)) {
            return $this->fail('from_version and from_hash are required.', 'VALIDATION_ERROR');
        }
        if (!array_key_exists('offset', $body) || !array_key_exists('limit', $body)) {
            return $this->fail('offset and limit are required.', 'VALIDATION_ERROR');
        }
        $offset = $body['offset'];
        $limit = $body['limit'];
        if (!is_int($offset) && !(is_string($offset) && ctype_digit($offset))) {
            return $this->fail('offset must be zero or greater.', 'VALIDATION_ERROR');
        }
        if (!is_int($limit) && !(is_string($limit) && ctype_digit($limit))) {
            return $this->fail('limit must be from 1 to 200.', 'VALIDATION_ERROR');
        }
        $offset = (int) $offset;
        $limit = (int) $limit;
        if ($offset < 0) {
            return $this->fail('offset must be zero or greater.', 'VALIDATION_ERROR');
        }
        if ($limit < 1 || $limit > AccesstagList::MAX_LIMIT) {
            return $this->fail('limit must be from 1 to 200.', 'VALIDATION_ERROR');
        }

        $fromVersion = $body['from_version'];
        $fromVersion = $fromVersion === null ? null : trim((string) $fromVersion);
        if ($fromVersion === '') {
            $fromVersion = null;
        }
        $fromHash = $body['from_hash'];
        $fromHash = $fromHash === null ? null : strtolower(trim((string) $fromHash));
        if ($fromHash === '') {
            $fromHash = null;
        }
        if ($fromVersion === null && $fromHash !== null) {
            return $this->fail('from_hash must be null when from_version is null.', 'VALIDATION_ERROR');
        }
        $full = !empty($body['full']);

        $pushedNumbers = $this->virtualDeviceDAO->numbers((int) $device['id'], false);
        $baselineNumbers = $this->virtualDeviceDAO->numbers((int) $device['id'], true);
        $pushedVersion = $device['accesstag_list_pushed_version'] !== null
            ? (string) $device['accesstag_list_pushed_version']
            : null;
        $confirmedVersion = $device['accesstag_list_version'] !== null
            ? (string) $device['accesstag_list_version']
            : null;
        $pushedHash = AccesstagList::hash($pushedNumbers);
        $baselineHash = AccesstagList::hash($baselineNumbers);

        if ($pushedVersion !== null
            && $fromVersion === $pushedVersion
            && AccesstagList::hashesMatch($fromHash, $pushedHash)
        ) {
            return $this->page($device, $fromVersion, $pushedVersion, $pushedHash, false, $offset, $limit, []);
        }

        $replace = AccesstagList::shouldReplace($fromVersion, $fromHash, $confirmedVersion, $baselineHash, $full);
        $changes = AccesstagList::changes($baselineNumbers, $pushedNumbers, $replace);

        return $this->page($device, $fromVersion, $pushedVersion, $pushedHash, $replace, $offset, $limit, $changes);
    }

    /**
     * @param array<string, mixed> $device
     */
    public function confirmReport(array $device, mixed $version, mixed $hash): bool
    {
        if ($this->virtualDeviceDAO->relays((int) $device['id']) === []) {
            return false;
        }
        $versionText = $version === null ? null : trim((string) $version);
        $hashText = $hash === null ? null : strtolower(trim((string) $hash));
        if ($versionText === null || $versionText === '' || $hashText === null || $hashText === '') {
            return false;
        }
        $pushedVersion = $device['accesstag_list_pushed_version'] !== null
            ? (string) $device['accesstag_list_pushed_version']
            : null;
        if ($pushedVersion === null || $versionText !== $pushedVersion) {
            return false;
        }
        $pushedHash = AccesstagList::hash($this->virtualDeviceDAO->numbers((int) $device['id'], false));
        if (!AccesstagList::hashesMatch($hashText, $pushedHash)) {
            return false;
        }
        if ((string) ($device['accesstag_list_version'] ?? '') === $versionText) {
            return true;
        }
        $this->virtualDeviceDAO->confirmList((int) $device['id'], $versionText);
        return true;
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $reported
     * @return array<string, mixed>
     */
    public function heartbeatFields(array $device, array $reported): array
    {
        if (!VirtualDeviceMode::hasReader($device) || $this->virtualDeviceDAO->relays((int) $device['id']) === []) {
            return [];
        }
        $pushedVersion = $device['accesstag_list_pushed_version'] !== null
            ? (string) $device['accesstag_list_pushed_version']
            : null;
        $pushedNumbers = $this->virtualDeviceDAO->numbers((int) $device['id'], false);
        $pushedHash = $pushedVersion === null ? null : AccesstagList::hash($pushedNumbers);
        $fields = [
            'accesstag_list_version' => $pushedVersion,
            'accesstag_list_hash' => $pushedHash,
        ];
        $reportedVersion = array_key_exists('accesstag_list_version', $reported)
            ? $reported['accesstag_list_version']
            : null;
        $reportedVersion = $reportedVersion === null ? null : trim((string) $reportedVersion);
        if ($reportedVersion === '') {
            $reportedVersion = null;
        }
        $reportedHash = array_key_exists('accesstag_list_hash', $reported)
            ? $reported['accesstag_list_hash']
            : null;
        $reportedHash = $reportedHash === null ? null : strtolower(trim((string) $reportedHash));
        if ($reportedHash === '') {
            $reportedHash = null;
        }
        if ($reportedVersion !== null && !$this->hashMatchesStored($device, $reportedVersion, $reportedHash, $pushedHash)) {
            $fields['accesstag_list_replace'] = true;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $device
     */
    private function hashMatchesStored(array $device, string $reportedVersion, ?string $reportedHash, ?string $pushedHash): bool
    {
        $pushedVersion = $device['accesstag_list_pushed_version'] !== null
            ? (string) $device['accesstag_list_pushed_version']
            : null;
        $confirmed = $device['accesstag_list_version'] !== null
            ? (string) $device['accesstag_list_version']
            : null;
        if ($pushedVersion !== null && $reportedVersion === $pushedVersion) {
            return AccesstagList::hashesMatch($reportedHash, $pushedHash);
        }
        if ($confirmed !== null && $reportedVersion === $confirmed) {
            $baselineHash = AccesstagList::hash($this->virtualDeviceDAO->numbers((int) $device['id'], true));
            return AccesstagList::hashesMatch($reportedHash, $baselineHash);
        }

        return false;
    }

    /**
     * @param list<array{op: string, accesstag: string}> $changes
     */
    private function page(
        array $device,
        ?string $fromVersion,
        ?string $version,
        string $listHash,
        bool $replace,
        int $offset,
        int $limit,
        array $changes
    ): array {
        return [
            'success' => true,
            'data' => [
                'virtual_device' => (string) $device['public_id'],
                'from_version' => $fromVersion,
                'version' => $version,
                'list_hash' => $listHash,
                'replace' => $replace,
                'offset' => $offset,
                'total' => count($changes),
                'changes' => array_slice($changes, $offset, $limit),
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{endpoint: string, token: string}|string
     */
    private function destinationPair(bool $hasEndpoint, bool $hasToken, mixed $endpoint, mixed $token): array|string
    {
        if (!$hasEndpoint || !$hasToken || (!is_string($endpoint) && !is_int($endpoint)) || (!is_string($token) && !is_int($token))) {
            return 'Reads endpoint and token must both be set.';
        }
        $endpointText = trim((string) $endpoint);
        $tokenText = trim((string) $token);
        if ($endpointText === '' || $tokenText === '') {
            return 'Reads endpoint and token must both be set.';
        }
        $parts = parse_url($endpointText);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (strlen($endpointText) > 512 || strlen($tokenText) > 255 || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Reads endpoint must be an http or https URL.';
        }

        return ['endpoint' => $endpointText, 'token' => $tokenText];
    }

    /**
     * @param array{endpoint: string, token: string} $destination
     */
    private function storeDestination(int $deviceId, int $boardId, array $destination): void
    {
        $changed = $this->virtualDeviceDAO->setReadsDestination(
            $deviceId,
            $destination['endpoint'],
            $destination['token']
        );
        if ($changed) {
            $this->entryZenReadService->pushForBoard($boardId);
        }
    }

    private function ok(string $publicId, string $version): array
    {
        return [
            'success' => true,
            'data' => [
                'virtual_device' => $publicId,
                'version' => $version,
            ],
            'error' => null,
        ];
    }

    private function fail(string $message, string $code): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => ['code' => $code, 'details' => []],
        ];
    }
}
