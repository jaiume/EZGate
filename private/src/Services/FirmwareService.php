<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\FirmwareVersionDAO;
use App\DAO\SiteDAO;
use App\Support\FirmwareAlreadyRunning;
use App\Support\FirmwareUsbImage;
use App\Support\FirmwareVersionReader;
use Psr\Http\Message\UploadedFileInterface;

class FirmwareService
{
    private const MAX_BYTES = 8388608;

    public function __construct(
        private FirmwareVersionDAO $firmwareVersionDAO,
        private BoardDAO $boardDAO,
        private SiteDAO $siteDAO
    ) {
    }

    public function listPage(): array
    {
        return [
            'success' => true,
            'message' => 'Firmware versions loaded.',
            'data' => [
                'versions' => $this->firmwareVersionDAO->findAllWithCounts(),
                'fleet_tree' => $this->fleetTree(),
            ],
            'error' => null,
        ];
    }

    public function listVersions(): array
    {
        return $this->firmwareVersionDAO->findAll();
    }

    public function pendingPayloadForBoard(array $board): ?array
    {
        $targetId = isset($board['target_firmware_version_id']) ? (int) $board['target_firmware_version_id'] : 0;
        if ($targetId <= 0) {
            return null;
        }

        $target = $this->firmwareVersionDAO->findById($targetId);
        if ($target === null) {
            return null;
        }

        $reported = trim((string) ($board['reported_firmware_version'] ?? ''));
        if (!FirmwareAlreadyRunning::shouldOffer($reported, (string) $target['version'])) {
            return null;
        }

        $hardwareId = (string) ($board['hardware_id'] ?? '');
        $base = rtrim(UtilityService::getBaseUrl(), '/');
        $url = $base . '/api/firmware/download?hardware_id=' . rawurlencode($hardwareId);

        return [
            'version' => $target['version'],
            'checksum' => $target['checksum'],
            'size' => (int) $target['file_size'],
            'url' => $url,
        ];
    }

    public function createFromUpload(?UploadedFileInterface $file): array
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->fail('A firmware binary is required.', 'VALIDATION_ERROR');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->fail('The firmware file could not be uploaded.', 'VALIDATION_ERROR');
        }

        $size = $file->getSize() ?? 0;
        if ($size > self::MAX_BYTES) {
            return $this->fail('Firmware files must be 8 MB or smaller.', 'VALIDATION_ERROR');
        }

        $original = $file->getClientFilename() ?? 'firmware.bin';
        $original = basename(str_replace("\0", '', $original));
        if ($original === '' || $original === '.' || $original === '..') {
            $original = 'firmware.bin';
        }

        $stored = 'fw_' . bin2hex(random_bytes(16)) . '.bin';
        $directory = $this->storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->fail('Unable to store firmware on the server.', 'INTERNAL_ERROR');
        }

        $path = $directory . '/' . $stored;
        try {
            $file->moveTo($path);
        } catch (\Throwable) {
            return $this->fail('Unable to store firmware on the server.', 'INTERNAL_ERROR');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            @unlink($path);
            return $this->fail('The firmware file is empty.', 'VALIDATION_ERROR');
        }
        if ($size > self::MAX_BYTES) {
            @unlink($path);
            return $this->fail('Firmware files must be 8 MB or smaller.', 'VALIDATION_ERROR');
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            @unlink($path);
            return $this->fail('The firmware file is empty.', 'VALIDATION_ERROR');
        }
        if (!FirmwareVersionReader::isEspAppImage($bytes)) {
            @unlink($path);
            return $this->fail('That file is not a valid ESP application image.', 'VALIDATION_ERROR');
        }

        $version = FirmwareVersionReader::fromBytes($bytes);
        if ($version === null) {
            @unlink($path);
            return $this->fail('Could not read a version from that firmware file.', 'VALIDATION_ERROR');
        }

        if ($this->firmwareVersionDAO->findByVersion($version) !== null) {
            @unlink($path);
            return $this->fail('Version ' . $version . ' is already in the catalog.', 'VALIDATION_ERROR');
        }

        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum) || $checksum === '') {
            @unlink($path);
            return $this->fail('Unable to checksum the firmware file.', 'INTERNAL_ERROR');
        }

        try {
            $id = $this->firmwareVersionDAO->create(
                $version,
                null,
                $checksum,
                $original,
                $stored,
                $size
            );
        } catch (\Throwable) {
            @unlink($path);
            return $this->fail('Unable to save the firmware version.', 'INTERNAL_ERROR');
        }

        return [
            'success' => true,
            'message' => 'Firmware version ' . $version . ' added.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function push(int $firmwareId, string $scope, ?int $boardId, ?int $siteId): array
    {
        $version = $this->firmwareVersionDAO->findById($firmwareId);
        if ($version === null) {
            return $this->fail('Firmware version not found.', 'NOT_FOUND');
        }

        $label = (string) $version['version'];
        $scope = trim($scope);
        if ($scope === 'board') {
            if ($boardId === null || $boardId <= 0) {
                return $this->fail('Choose a board to push to.', 'VALIDATION_ERROR');
            }
            $board = $this->boardDAO->findById($boardId);
            if ($board === null) {
                return $this->fail('Board not found.', 'NOT_FOUND');
            }
            return $this->offerToBoards([$board], $firmwareId, $label);
        }

        if ($scope === 'site') {
            if ($siteId === null || $siteId <= 0) {
                return $this->fail('Choose a site to push to.', 'VALIDATION_ERROR');
            }
            $site = $this->siteDAO->findById($siteId);
            if ($site === null) {
                return $this->fail('Site not found.', 'NOT_FOUND');
            }
            $boards = [];
            foreach ($this->boardDAO->findAllWithSite() as $board) {
                if ((int) ($board['site_id'] ?? 0) === $siteId) {
                    $boards[] = $board;
                }
            }
            if ($boards === []) {
                return $this->fail('No boards at ' . $site['name'] . '.', 'VALIDATION_ERROR');
            }
            return $this->offerToBoards($boards, $firmwareId, $label);
        }

        if ($scope === 'fleet') {
            return $this->offerToBoards($this->boardDAO->findAllWithSite(), $firmwareId, $label);
        }

        return $this->fail('Choose whether to push to a board, a site, or the fleet.', 'VALIDATION_ERROR');
    }

    public function hasUsbFactory(array $version): bool
    {
        $stored = (string) ($version['stored_filename'] ?? '');
        if ($stored === '') {
            return false;
        }

        return FirmwareUsbImage::hasFactory($this->storageDirectory(), $stored);
    }

    /**
     * @return array{success: bool, message: string, data: ?array, error: ?array}
     */
    public function usbManifestForId(int $id): array
    {
        $version = $this->firmwareVersionDAO->findById($id);
        if ($version === null) {
            return $this->fail('Firmware version not found.', 'NOT_FOUND');
        }
        if (!$this->hasUsbFactory($version)) {
            return $this->fail('This version has no USB factory image.', 'NOT_FOUND');
        }

        $segments = [];
        foreach (FirmwareUsbImage::PARTS as $name => $part) {
            $segments[] = [
                'name' => $name,
                'address' => $part['address'],
                'url' => '/firmware/' . $id . '/usb/' . $name,
            ];
        }
        $segments[] = [
            'name' => 'app',
            'address' => FirmwareUsbImage::APP_ADDRESS,
            'url' => '/firmware/' . $id . '/file',
        ];

        return [
            'success' => true,
            'message' => 'USB factory image ready.',
            'data' => [
                'version' => $version['version'],
                'flashSize' => '8MB',
                'flashMode' => 'qio',
                'flashFreq' => '80m',
                'segments' => $segments,
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{success: bool, message: string, data: ?array, error: ?array}
     */
    public function usbFileForId(int $id, string $part): array
    {
        $version = $this->firmwareVersionDAO->findById($id);
        if ($version === null) {
            return $this->fail('Firmware version not found.', 'NOT_FOUND');
        }

        $filename = FirmwareUsbImage::sidecarFilename((string) $version['stored_filename'], $part);
        if ($filename === null) {
            return $this->fail('Unknown USB image part.', 'NOT_FOUND');
        }

        $path = $this->storageDirectory() . '/' . $filename;
        if (!is_file($path)) {
            return $this->fail('USB image part is missing.', 'NOT_FOUND');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            return $this->fail('USB image part is missing.', 'NOT_FOUND');
        }

        return [
            'success' => true,
            'message' => 'USB image part ready.',
            'data' => [
                'path' => $path,
                'filename' => $filename,
                'checksum' => hash_file('sha256', $path) ?: '',
                'version' => $version['version'],
                'size' => $size,
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{success: bool, message: string, data: ?array, error: ?array}
     */
    public function deleteVersion(int $id): array
    {
        $version = $this->firmwareVersionDAO->findById($id);
        if ($version === null) {
            return $this->fail('Firmware version not found.', 'NOT_FOUND');
        }

        $this->unlinkVersionFiles($version);
        $this->firmwareVersionDAO->delete($id);

        return $this->ok('Firmware version ' . $version['version'] . ' deleted.');
    }

    /**
     * @return array{success: bool, message: string, data: ?array, error: ?array}
     */
    public function publishFromBuild(
        string $appPath,
        string $bootloaderPath,
        string $partitionsPath,
        string $bootApp0Path
    ): array {
        foreach (['app' => $appPath, 'bootloader' => $bootloaderPath, 'partitions' => $partitionsPath, 'boot_app0' => $bootApp0Path] as $label => $path) {
            if (!is_file($path) || filesize($path) <= 0) {
                return $this->fail('Missing ' . $label . ' image at ' . $path, 'VALIDATION_ERROR');
            }
        }

        $bytes = file_get_contents($appPath);
        if (!is_string($bytes) || $bytes === '') {
            return $this->fail('The application image is empty.', 'VALIDATION_ERROR');
        }
        if (!FirmwareVersionReader::isEspAppImage($bytes)) {
            return $this->fail('That file is not a valid ESP application image.', 'VALIDATION_ERROR');
        }

        $versionLabel = FirmwareVersionReader::fromBytes($bytes);
        if ($versionLabel === null) {
            return $this->fail('Could not read a version from that firmware file.', 'VALIDATION_ERROR');
        }

        $directory = $this->storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->fail('Unable to store firmware on the server.', 'INTERNAL_ERROR');
        }

        $existing = $this->firmwareVersionDAO->findByVersion($versionLabel);
        $stored = $existing['stored_filename'] ?? ('fw_' . bin2hex(random_bytes(16)) . '.bin');
        $destApp = $directory . '/' . $stored;
        if (!copy($appPath, $destApp)) {
            return $this->fail('Unable to store the application image.', 'INTERNAL_ERROR');
        }

        $sidecars = [
            'bootloader' => $bootloaderPath,
            'partitions' => $partitionsPath,
            'boot_app0' => $bootApp0Path,
        ];
        foreach ($sidecars as $part => $source) {
            $name = FirmwareUsbImage::sidecarFilename($stored, $part);
            if ($name === null || !copy($source, $directory . '/' . $name)) {
                return $this->fail('Unable to store the ' . $part . ' image.', 'INTERNAL_ERROR');
            }
        }

        $size = filesize($destApp);
        $checksum = hash_file('sha256', $destApp);
        if ($size === false || $size <= 0 || !is_string($checksum) || $checksum === '') {
            return $this->fail('Unable to checksum the firmware file.', 'INTERNAL_ERROR');
        }

        $original = 'ezgate-' . $versionLabel . '.bin';
        if ($existing !== null) {
            $this->firmwareVersionDAO->updateFile(
                (int) $existing['id'],
                $checksum,
                $original,
                $stored,
                $size
            );
            $id = (int) $existing['id'];
            $message = 'Firmware version ' . $versionLabel . ' updated with USB factory extras.';
        } else {
            $id = $this->firmwareVersionDAO->create(
                $versionLabel,
                null,
                $checksum,
                $original,
                $stored,
                $size
            );
            $message = 'Firmware version ' . $versionLabel . ' published with USB factory extras.';
        }

        return [
            'success' => true,
            'message' => $message,
            'data' => ['id' => $id, 'version' => $versionLabel, 'size' => $size],
            'error' => null,
        ];
    }

    public function pushSelected(int $firmwareId, bool $fleet, array $siteIds, array $boardIds, bool $unassigned): array
    {
        $version = $this->firmwareVersionDAO->findById($firmwareId);
        if ($version === null) {
            return $this->fail('Firmware version not found.', 'NOT_FOUND');
        }

        $label = (string) $version['version'];
        $all = $this->boardDAO->findAllWithSite();
        if ($fleet) {
            return $this->offerToBoards($all, $firmwareId, $label);
        }

        $siteSet = [];
        foreach ($siteIds as $siteId) {
            $id = (int) $siteId;
            if ($id > 0) {
                $siteSet[$id] = true;
            }
        }
        $boardSet = [];
        foreach ($boardIds as $boardId) {
            $id = (int) $boardId;
            if ($id > 0) {
                $boardSet[$id] = true;
            }
        }

        $selected = [];
        foreach ($all as $board) {
            $id = (int) $board['id'];
            $siteId = $board['site_id'] === null || $board['site_id'] === '' ? null : (int) $board['site_id'];
            if (isset($boardSet[$id])
                || ($siteId !== null && isset($siteSet[$siteId]))
                || ($unassigned && $siteId === null)
            ) {
                $selected[] = $board;
            }
        }

        if ($selected === []) {
            return $this->fail('Select at least one board or site.', 'VALIDATION_ERROR');
        }

        return $this->offerToBoards($selected, $firmwareId, $label);
    }

    private function offerToBoards(array $boards, int $firmwareId, string $versionLabel): array
    {
        if ($boards === []) {
            return $this->fail('No boards in that selection.', 'VALIDATION_ERROR');
        }

        $eligibleIds = [];
        $eligibleBoards = [];
        $already = [];
        foreach ($boards as $board) {
            $id = (int) ($board['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (FirmwareAlreadyRunning::matches($board['reported_firmware_version'] ?? null, $versionLabel)) {
                $already[$id] = $board;
                continue;
            }
            $eligibleIds[$id] = $id;
            $eligibleBoards[$id] = $board;
        }

        if ($eligibleIds === []) {
            if (count($already) === 1) {
                $board = reset($already);
                $name = trim((string) ($board['friendly_name'] ?? ''));
                if ($name === '') {
                    $name = (string) ($board['hardware_id'] ?? 'This board');
                }
                return $this->fail(
                    $name . ' is already running version ' . $versionLabel
                    . '. Pushing it again would put the same firmware in both slots.',
                    'VALIDATION_ERROR'
                );
            }
            return $this->fail(
                'The selected boards are already running version ' . $versionLabel . '.',
                'VALIDATION_ERROR'
            );
        }

        $this->boardDAO->setTargetFirmwareForIds(array_values($eligibleIds), $firmwareId, $versionLabel);
        $offered = count($eligibleIds);
        $skipped = count($already);

        if ($offered === 1) {
            $board = $eligibleBoards[array_key_first($eligibleBoards)];
            $name = trim((string) ($board['friendly_name'] ?? ''));
            if ($name === '') {
                $name = (string) ($board['hardware_id'] ?? 'the board');
            }
            $message = 'Version ' . $versionLabel . ' will be offered to ' . $name . ' on the next heartbeat.';
        } else {
            $message = 'Version ' . $versionLabel . ' will be offered to ' . $offered . ' boards.';
        }
        if ($skipped > 0) {
            $message .= ' ' . $skipped . ' already running it ' . ($skipped === 1 ? 'was' : 'were') . ' skipped.';
        }

        return $this->ok($message);
    }

    private function fleetTree(): array
    {
        $tree = [];
        foreach ($this->siteDAO->findAll() as $site) {
            $id = (int) $site['id'];
            $tree[$id] = [
                'id' => $id,
                'name' => $site['name'],
                'boards' => [],
            ];
        }

        $unassigned = [
            'id' => null,
            'name' => 'Unassigned',
            'boards' => [],
        ];

        foreach ($this->boardDAO->findAllWithSite() as $board) {
            $siteId = $board['site_id'] === null || $board['site_id'] === '' ? null : (int) $board['site_id'];
            if ($siteId !== null && isset($tree[$siteId])) {
                $tree[$siteId]['boards'][] = $board;
            } else {
                $unassigned['boards'][] = $board;
            }
        }

        $fleetTree = array_values($tree);
        if ($unassigned['boards'] !== []) {
            $fleetTree[] = $unassigned;
        }

        return $fleetTree;
    }

    /**
     * @return array{success: bool, message: string, data: ?array, error: ?array, path?: string, filename?: string, checksum?: string, version?: string, size?: int}
     */
    public function fileForBoardDownload(string $hardwareId): array
    {
        $hardwareId = trim($hardwareId);
        if ($hardwareId === '') {
            return $this->fail('Board identifier is required.', 'VALIDATION_ERROR');
        }

        $board = $this->boardDAO->findByHardwareId($hardwareId);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $targetId = isset($board['target_firmware_version_id']) ? (int) $board['target_firmware_version_id'] : 0;
        if ($targetId <= 0) {
            return $this->fail('No firmware is queued for this board.', 'NOT_FOUND');
        }

        $version = $this->firmwareVersionDAO->findById($targetId);
        if ($version === null) {
            return $this->fail('Firmware version not found.', 'NOT_FOUND');
        }

        $path = $this->storageDirectory() . '/' . $version['stored_filename'];
        if (!is_file($path)) {
            return $this->fail('Firmware file is missing.', 'NOT_FOUND');
        }

        return [
            'success' => true,
            'message' => 'Firmware file ready.',
            'data' => [
                'path' => $path,
                'filename' => $version['original_filename'],
                'checksum' => $version['checksum'],
                'version' => $version['version'],
                'size' => (int) $version['file_size'],
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{success: bool, message: string, data: ?array, error: ?array}
     */
    public function fileForId(int $id): array
    {
        $version = $this->firmwareVersionDAO->findById($id);
        if ($version === null) {
            return $this->fail('Firmware version not found.', 'NOT_FOUND');
        }

        $path = $this->storageDirectory() . '/' . $version['stored_filename'];
        if (!is_file($path)) {
            return $this->fail('Firmware file is missing.', 'NOT_FOUND');
        }

        return [
            'success' => true,
            'message' => 'Firmware file ready.',
            'data' => [
                'path' => $path,
                'filename' => $version['original_filename'],
                'checksum' => $version['checksum'],
                'version' => $version['version'],
                'size' => (int) $version['file_size'],
            ],
            'error' => null,
        ];
    }

    private function storageDirectory(): string
    {
        return BASE_DIR . '/var/firmware';
    }

    /**
     * @param array<string, mixed> $version
     */
    private function unlinkVersionFiles(array $version): void
    {
        $directory = $this->storageDirectory();
        $stored = (string) ($version['stored_filename'] ?? '');
        if ($stored === '') {
            return;
        }

        $app = $directory . '/' . $stored;
        if (is_file($app)) {
            @unlink($app);
        }
        foreach (array_keys(FirmwareUsbImage::PARTS) as $part) {
            $name = FirmwareUsbImage::sidecarFilename($stored, $part);
            if ($name === null) {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function ok(string $message): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => null,
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
