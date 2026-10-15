<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DAO\AccessReadDAO;
use App\DAO\BoardDAO;
use App\DAO\ManagedPdo;
use App\Services\AccesstagService;
use App\Services\ConfigService;
use App\Services\HeartbeatService;
use App\Services\ReadService;
use App\Services\StatusService;
use App\Services\TriggerService;
use App\Services\VirtualDeviceService;
use App\Support\AccesstagList;
use App\Support\PulseDuration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class VirtualDeviceContractTest extends TestCase
{
    private ManagedPdo $db;
    private int $siteId = 0;
    private int $boardId = 0;
    private string $readerPublic = '';
    private string $emptyPublic = '';
    private int $readerDeviceId = 0;

    protected function setUp(): void
    {
        if (!defined('BASE_DIR')) {
            define('BASE_DIR', dirname(__DIR__, 2));
        }
        $container = require BASE_DIR . '/config/container.php';
        $this->db = $container->get(ManagedPdo::class);
        $site = $this->db->prepare('INSERT INTO sites (name, pulse_duration_ms) VALUES (?, ?)');
        $site->execute(['TESTVD site', 750]);
        $this->siteId = (int) $this->db->lastInsertId();
        $type = $this->db->query("SELECT id FROM board_types WHERE name = 'Kincony KC868-A8'")->fetch();
        $board = $this->db->prepare(
            'INSERT INTO boards (hardware_id, friendly_name, site_id, board_type_id) VALUES (?, ?, ?, ?)'
        );
        $board->execute(['TESTVD00000001', 'TESTVD board', $this->siteId, (int) $type['id']]);
        $this->boardId = (int) $this->db->lastInsertId();

        $devices = $container->get(\App\DAO\VirtualDeviceDAO::class);
        $this->readerPublic = 'vd_test0001';
        $this->emptyPublic = 'vd_test0002';
        $this->readerDeviceId = $devices->create($this->boardId, $this->readerPublic, 'Gate', 1);
        $devices->replaceRelays($this->readerDeviceId, [
            ['relay' => 1, 'pulse' => 80],
            ['relay' => 2, 'pulse' => null],
        ]);
        $emptyId = $devices->create($this->boardId, $this->emptyPublic, 'No outputs', null);
        $devices->replaceRelays($emptyId, []);
        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->db->prepare('DELETE FROM boards WHERE hardware_id = ?')->execute(['TESTVD00000001']);
        $this->db->prepare('DELETE FROM sites WHERE id = ?')->execute([$this->siteId]);
    }

    public function testTriggerIsPerVirtualDeviceAndRejectsHardwareAndEmptyRelays(): void
    {
        $container = require BASE_DIR . '/config/container.php';
        $trigger = $container->get(TriggerService::class);
        $key = (string) ConfigService::get('api.api_key');

        $missing = $trigger->trigger($key, '');
        $this->assertFalse($missing['success']);
        $this->assertSame('VALIDATION_ERROR', $missing['error']['code']);

        $hardware = $trigger->trigger($key, 'TESTVD00000001');
        $this->assertFalse($hardware['success']);
        $this->assertSame('NOT_FOUND', $hardware['error']['code']);
        $this->assertSame('Virtual device not found.', $hardware['message']);

        $empty = $trigger->trigger($key, $this->emptyPublic);
        $this->assertFalse($empty['success']);
        $this->assertSame('Virtual device has no relays.', $empty['message']);

        $first = $trigger->trigger($key, $this->readerPublic);
        $this->assertTrue($first['success']);
        $this->assertSame($this->readerPublic, $first['data']['virtual_device']);

        $rows = $this->db->prepare(
            'SELECT virtual_device_id, status FROM open_commands WHERE board_id = ? AND status = ?'
        );
        $rows->execute([$this->boardId, 'pending']);
        $pending = $rows->fetchAll();
        $this->assertCount(1, $pending);
        $this->assertSame($this->readerDeviceId, (int) $pending[0]['virtual_device_id']);
    }

    public function testRelayAndReaderUniquenessAndPerRelayPulse(): void
    {
        $container = require BASE_DIR . '/config/container.php';
        $service = $container->get(VirtualDeviceService::class);
        $takenRelay = $service->save(null, $this->boardId, 'Other', [1], null, [1 => 100]);
        $this->assertFalse($takenRelay['success']);
        $this->assertStringContainsString('already assigned', $takenRelay['message']);

        $takenReader = $service->save(null, $this->boardId, 'Other', [3], 1, [3 => null]);
        $this->assertFalse($takenReader['success']);
        $this->assertStringContainsString('already assigned', $takenReader['message']);

        $this->assertSame(80, PulseDuration::resolve(80, 750));
        $this->assertSame(750, PulseDuration::resolve(null, 750));
        $this->assertSame(PulseDuration::appDefault(), PulseDuration::resolve(null, null));

        $heartbeat = $container->get(HeartbeatService::class);
        $method = new ReflectionMethod(HeartbeatService::class, 'virtualDevicePayloads');
        $payloads = $method->invoke($heartbeat, $this->boardId, true, [], []);
        $byId = [];
        foreach ($payloads as $payload) {
            $byId[$payload['id']] = $payload;
        }
        $relays = $byId[$this->readerPublic]['config']['relays'];
        $this->assertSame(80, $relays[0]['pulse_duration_ms']);
        $this->assertSame(750, $relays[1]['pulse_duration_ms']);
    }

    public function testListPushStatusAndChunkedDiff(): void
    {
        $container = require BASE_DIR . '/config/container.php';
        $lists = $container->get(AccesstagService::class);
        $status = $container->get(StatusService::class);
        $key = (string) ConfigService::get('api.api_key');

        $relay = $status->status($key, $this->readerPublic);
        $this->assertTrue($relay['success']);
        $this->assertSame(['virtual_device', 'status'], array_keys($relay['data']));
        $this->assertSame('Virtual device has no relays.', $status->status($key, $this->emptyPublic)['message']);

        $before = $status->readerStatus($key, $this->readerPublic);
        $this->assertTrue($before['success']);
        $this->assertSame('autonomous', $before['data']['operation']);
        $this->assertNull($before['data']['accesstag_list_version']);
        $this->assertArrayNotHasKey('status', $before['data']);

        $pushed = $lists->replace($key, $this->readerPublic, '1', ['1001', '1002']);
        $this->assertTrue($pushed['success']);
        $again = $lists->replace($key, $this->readerPublic, '1', ['9999']);
        $this->assertTrue($again['success']);
        $this->assertSame(['1001', '1002'], $container->get(\App\DAO\VirtualDeviceDAO::class)->numbers($this->readerDeviceId, false));

        $still = $status->readerStatus($key, $this->readerPublic);
        $this->assertNull($still['data']['accesstag_list_version']);

        $noReader = $lists->replace($key, $this->emptyPublic, '1', []);
        $this->assertSame('Virtual device has no Wiegand reader.', $noReader['message']);

        $full = $lists->pull([
            'hardware_id' => 'TESTVD00000001',
            'virtual_device' => $this->readerPublic,
            'from_version' => null,
            'from_hash' => null,
            'offset' => 0,
            'limit' => 1,
            'full' => true,
        ]);
        $this->assertTrue($full['data']['replace']);
        $this->assertSame(2, $full['data']['total']);
        $this->assertSame('1', $full['data']['version']);
        $this->assertSame(AccesstagList::hash(['1001', '1002']), $full['data']['list_hash']);
        $this->assertCount(1, $full['data']['changes']);
        $this->assertSame('add', $full['data']['changes'][0]['op']);

        $next = $lists->pull([
            'hardware_id' => 'TESTVD00000001',
            'virtual_device' => $this->readerPublic,
            'from_version' => null,
            'from_hash' => null,
            'offset' => 1,
            'limit' => 1,
            'full' => true,
        ]);
        $this->assertSame('1002', $next['data']['changes'][0]['accesstag']);

        $device = $container->get(\App\DAO\VirtualDeviceDAO::class)->findByPublicId($this->readerPublic);
        $this->assertTrue($lists->confirmReport($device, '1', $full['data']['list_hash']));
        $confirmed = $status->readerStatus($key, $this->readerPublic);
        $this->assertSame('1', $confirmed['data']['accesstag_list_version']);
        $this->assertArrayNotHasKey('accesstag_list_version', $status->status($key, $this->readerPublic)['data']);

        $lists->replace($key, $this->readerPublic, '2', ['1002', '1003']);
        $lists->replace($key, $this->readerPublic, '3', ['1002', '1004']);
        $device = $container->get(\App\DAO\VirtualDeviceDAO::class)->findByPublicId($this->readerPublic);
        $this->assertFalse($lists->confirmReport($device, '3', AccesstagList::hash(['1001'])));
        $held = $status->readerStatus($key, $this->readerPublic);
        $this->assertSame('1', $held['data']['accesstag_list_version']);

        $diff = $lists->pull([
            'hardware_id' => 'TESTVD00000001',
            'virtual_device' => $this->readerPublic,
            'from_version' => '1',
            'from_hash' => AccesstagList::hash(['1001', '1002']),
            'offset' => 0,
            'limit' => 40,
        ]);
        $this->assertFalse($diff['data']['replace']);
        $this->assertSame([
            ['op' => 'remove', 'accesstag' => '1001'],
            ['op' => 'add', 'accesstag' => '1004'],
        ], $diff['data']['changes']);
        $this->assertSame('3', $diff['data']['version']);

        $mismatch = $lists->pull([
            'hardware_id' => 'TESTVD00000001',
            'virtual_device' => $this->readerPublic,
            'from_version' => '1',
            'from_hash' => str_repeat('b', 64),
            'offset' => 0,
            'limit' => 40,
        ]);
        $this->assertTrue($mismatch['data']['replace']);
        $this->assertSame('add', $mismatch['data']['changes'][0]['op']);
    }

    public function testReadsAcceptThePayloadAndKeepAFailedEntryZenPush(): void
    {
        $container = require BASE_DIR . '/config/container.php';
        $reads = $container->get(ReadService::class);
        $result = $reads->ingest([
            'hardware_id' => 'TESTVD00000001',
            'reads' => [[
                'read_at' => '2026-10-07T18:00:00Z',
                'virtual_device' => $this->readerPublic,
                'accesstag_number' => '1001',
                'in_list' => true,
                'board_event_id' => 7,
            ], [
                'read_at' => '2026-10-07T18:00:01Z',
                'virtual_device' => 'vd_missing',
                'accesstag_number' => '1001',
                'in_list' => false,
                'board_event_id' => 8,
            ]],
        ]);
        $this->assertSame([7], $result['data']['accepted']);
        $this->assertSame([8], $result['data']['rejected']);

        $dup = $reads->ingest([
            'hardware_id' => 'TESTVD00000001',
            'reads' => [[
                'read_at' => '2026-10-07T18:00:00Z',
                'virtual_device' => $this->readerPublic,
                'accesstag_number' => '1001',
                'in_list' => true,
                'board_event_id' => 7,
            ]],
        ]);
        $this->assertSame([7], $dup['data']['accepted']);
        $count = $this->db->prepare(
            'SELECT COUNT(*) AS n FROM access_reads ar
             JOIN virtual_devices vd ON vd.id = ar.virtual_device_id
             WHERE vd.board_id = ? AND ar.board_event_id = 7'
        );
        $count->execute([$this->boardId]);
        $this->assertSame(1, (int) $count->fetch()['n']);

        $changed = $reads->ingest([
            'hardware_id' => 'TESTVD00000001',
            'reads' => [[
                'read_at' => '2026-10-07T18:00:02Z',
                'virtual_device' => $this->readerPublic,
                'accesstag_number' => '1009',
                'in_list' => false,
                'board_event_id' => 7,
            ]],
        ]);
        $this->assertSame([7], $changed['data']['accepted']);
        $count->execute([$this->boardId]);
        $this->assertSame(2, (int) $count->fetch()['n']);

        $pending = $container->get(AccessReadDAO::class)->pendingForBoard($this->boardId);
        $this->assertCount(2, $pending);
        $this->assertFalse($container->get(\App\Services\EntryZenReadService::class)->push($pending));
        $this->assertCount(2, $container->get(AccessReadDAO::class)->pendingForBoard($this->boardId));

        $missingBoard = $reads->ingest(['hardware_id' => 'TESTVDNONE', 'reads' => []]);
        $this->assertSame('NOT_FOUND', $missingBoard['error']['code']);
    }

    public function testReaderOnlyDestinationAndOpenerReads(): void
    {
        $container = require BASE_DIR . '/config/container.php';
        $devices = $container->get(\App\DAO\VirtualDeviceDAO::class);
        $lists = $container->get(AccesstagService::class);
        $status = $container->get(StatusService::class);
        $reads = $container->get(ReadService::class);
        $key = (string) ConfigService::get('api.api_key');

        $public = 'vd_test0003';
        $id = $devices->create($this->boardId, $public, 'Reader', 2);

        $partial = $lists->replace(
            $key,
            $public,
            null,
            null,
            false,
            false,
            'https://entryzen.example/reads',
            null,
            true,
            false
        );
        $this->assertSame('Reads endpoint and token must both be set.', $partial['message']);

        $withList = $lists->replace(
            $key,
            $public,
            '1',
            ['1001'],
            true,
            true,
            'https://entryzen.example/reads',
            'secret',
            true,
            true
        );
        $this->assertSame('This reader does not use an accesstag list.', $withList['message']);

        $badUrl = $lists->replace(
            $key,
            $public,
            null,
            null,
            false,
            false,
            'ftp://entryzen.example/reads',
            'secret',
            true,
            true
        );
        $this->assertSame('Reads endpoint must be an http or https URL.', $badUrl['message']);

        $saved = $lists->replace(
            $key,
            $public,
            null,
            null,
            false,
            false,
            'https://entryzen.example/reads',
            'secret',
            true,
            true
        );
        $this->assertTrue($saved['success']);
        $this->assertSame(['virtual_device' => $public], $saved['data']);
        $destination = $devices->readsDestination($id);
        $this->assertSame('https://entryzen.example/reads', $destination['reads_endpoint']);
        $this->assertSame('secret', $destination['reads_token']);

        $reader = $status->readerStatus($key, $public);
        $this->assertSame('reader_only', $reader['data']['operation']);
        $this->assertArrayNotHasKey('accesstag_list_version', $reader['data']);
        $this->assertSame('Virtual device has no Wiegand reader.', $status->readerStatus($key, $this->emptyPublic)['message']);

        $pull = $lists->pull([
            'hardware_id' => 'TESTVD00000001',
            'virtual_device' => $public,
            'from_version' => null,
            'from_hash' => null,
            'offset' => 0,
            'limit' => 1,
        ]);
        $this->assertSame('This reader does not use an accesstag list.', $pull['message']);

        $ingested = $reads->ingest([
            'hardware_id' => 'TESTVD00000001',
            'reads' => [[
                'read_at' => '2026-10-07T18:00:00Z',
                'virtual_device' => $public,
                'accesstag_number' => '1001',
                'in_list' => true,
                'board_event_id' => 11,
            ], [
                'read_at' => '2026-10-07T18:00:00Z',
                'virtual_device' => $this->emptyPublic,
                'accesstag_number' => '1001',
                'in_list' => false,
                'board_event_id' => 12,
            ]],
        ]);
        $this->assertSame([11], $ingested['data']['accepted']);
        $this->assertSame([12], $ingested['data']['rejected']);
        $stored = $this->db->prepare('SELECT in_list FROM access_reads WHERE virtual_device_id = ? AND board_event_id = 11');
        $stored->execute([$id]);
        $this->assertSame(0, (int) $stored->fetch()['in_list']);

        $pending = $container->get(AccessReadDAO::class)->pendingForBoard($this->boardId);
        $mine = array_values(array_filter(
            $pending,
            static fn (array $item): bool => (int) $item['virtual_device_id'] === $id
        ));
        $this->assertCount(1, $mine);
        $devices->setReadsDestination($id, 'http://127.0.0.1:9/reads', 'secret');
        $this->assertFalse($container->get(\App\Services\EntryZenReadService::class)->push($mine));
        $left = $this->db->prepare('SELECT COUNT(*) AS n FROM access_reads WHERE virtual_device_id = ?');
        $left->execute([$id]);
        $this->assertSame(1, (int) $left->fetch()['n']);

        $lists->replace($key, $this->readerPublic, '1', ['1001', '1002']);
        $same = $lists->replace(
            $key,
            $this->readerPublic,
            '1',
            ['9999'],
            true,
            true,
            'https://entryzen.example/other',
            'next',
            true,
            true
        );
        $this->assertTrue($same['success']);
        $this->assertSame('1', $same['data']['version']);
        $this->assertSame(['1001', '1002'], $devices->numbers($this->readerDeviceId, false));
        $gate = $devices->readsDestination($this->readerDeviceId);
        $this->assertSame('https://entryzen.example/other', $gate['reads_endpoint']);
        $this->assertSame('next', $gate['reads_token']);

        $devices->replaceRelays($this->readerDeviceId, []);
        $flipped = $status->readerStatus($key, $this->readerPublic);
        $this->assertSame('reader_only', $flipped['data']['operation']);
        $this->assertArrayNotHasKey('accesstag_list_version', $flipped['data']);
        $this->assertSame(['1001', '1002'], $devices->numbers($this->readerDeviceId, false));
        $blocked = $lists->pull([
            'hardware_id' => 'TESTVD00000001',
            'virtual_device' => $this->readerPublic,
            'from_version' => '1',
            'from_hash' => AccesstagList::hash(['1001', '1002']),
            'offset' => 0,
            'limit' => 40,
        ]);
        $this->assertSame('This reader does not use an accesstag list.', $blocked['message']);

        $heartbeat = $container->get(HeartbeatService::class);
        $method = new ReflectionMethod(HeartbeatService::class, 'virtualDevicePayloads');
        $payloads = $method->invoke($heartbeat, $this->boardId, true, [], []);
        $byId = [];
        foreach ($payloads as $payload) {
            $byId[$payload['id']] = $payload;
        }
        $this->assertArrayNotHasKey('accesstag_list_version', $byId[$public]);
        $this->assertArrayNotHasKey('accesstag_list_version', $byId[$this->readerPublic]);
    }
}
