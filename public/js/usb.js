import { ESPLoader, Transport } from 'https://cdn.jsdelivr.net/npm/esptool-js@0.5.6/+esm';

const page = document.getElementById('usb-page');
if (!page) {
    throw new Error('USB page root is missing.');
}

const csrf = page.dataset.csrf || '';
const usbConfig = JSON.parse(page.dataset.usb || '{}');
const usbSites = JSON.parse(page.dataset.sites || '[]');
const statusEl = document.getElementById('usb-status');
const modeBadge = document.getElementById('usb-mode-badge');
const logEl = document.getElementById('usb-log');
const connectBtn = document.getElementById('usb-connect');
const cdcBtn = document.getElementById('usb-mode-cdc');
const downloadBtn = document.getElementById('usb-mode-download');
const disconnectBtn = document.getElementById('usb-disconnect');
const loadForm = document.getElementById('loadFirmwareForm');
const loadModalEl = document.getElementById('loadFirmwareModal');
const usbDock = document.getElementById('usb-dock');

const usbFilters = [
    { usbVendorId: 0x303a },
    { usbVendorId: 0x10c4 },
    { usbVendorId: 0x1a86 },
    { usbVendorId: 0x0403 },
];

let grantedPort = null;
let usbMode = null;
let usbBoardId = 0;
let usbSiteId = 0;
let usbSiteName = '';
let usbHardwareId = '';
let usbFriendlyName = '';
let usbBoardTypeName = '';
let pendingBoardType = null;
let lastDetectedChip = '';
let busy = false;
let cdc = null;
let cdcReady = false;
let cdcProbeTimer = null;
let identifyInFlight = '';
let lastCdcBootState = '';
let pendingUsbRecover = false;
let usbRecoverTimer = null;
let cdcReopenTimer = null;
let cdcReopenInFlight = false;
let cdcReopenAttempts = 0;

function logRaw(text) {
    if (!logEl) {
        return;
    }
    logEl.classList.remove('d-none');
    logEl.textContent += text;
    logEl.scrollTop = logEl.scrollHeight;
}

function log(line) {
    const text = String(line);
    logRaw(text.endsWith('\n') ? text : text + '\n');
}

function setStatus(html, kind) {
    if (!statusEl) {
        return;
    }
    statusEl.className = 'mb-0';
    if (kind === 'ok') {
        statusEl.classList.add('text-success');
    } else if (kind === 'err') {
        statusEl.classList.add('text-danger');
    } else {
        statusEl.classList.add('text-secondary');
    }
    statusEl.innerHTML = html;
}

function serialSupported() {
    return Boolean(navigator.serial);
}

function portOpen() {
    return Boolean(grantedPort);
}

function usbSessionActive() {
    return Boolean(grantedPort) || pendingUsbRecover;
}

function modeInfo() {
    if (!usbSessionActive()) {
        return { text: 'Disconnected', cls: 'text-bg-secondary' };
    }
    if (usbMode === 'cdc') {
        if (cdcReady) {
            return { text: 'CDC ready', cls: 'text-bg-success' };
        }
        return { text: 'CDC — waiting', cls: 'text-bg-warning text-dark' };
    }
    if (usbMode === 'download') {
        return { text: 'Download', cls: 'text-bg-primary' };
    }
    return { text: 'Port open', cls: 'text-bg-warning text-dark' };
}

function syncUsbButtons() {
    const connected = usbSessionActive();
    const hasPort = portOpen();
    if (modeBadge) {
        const info = modeInfo();
        modeBadge.className = 'badge ' + info.cls;
        modeBadge.textContent = info.text;
    }
    if (connectBtn) {
        connectBtn.classList.toggle('d-none', connected);
        connectBtn.disabled = busy || !serialSupported() || connected;
    }
    [cdcBtn, downloadBtn].forEach((btn) => {
        if (!btn) {
            return;
        }
        btn.classList.toggle('d-none', !connected);
        btn.disabled = busy || !hasPort;
    });
    if (disconnectBtn) {
        disconnectBtn.classList.toggle('d-none', !connected);
        disconnectBtn.disabled = busy;
    }
    if (cdcBtn) {
        cdcBtn.classList.toggle('btn-success', usbMode === 'cdc');
        cdcBtn.classList.toggle('btn-outline-secondary', usbMode !== 'cdc');
    }
    if (downloadBtn) {
        downloadBtn.classList.toggle('btn-primary', usbMode === 'download');
        downloadBtn.classList.toggle('btn-outline-secondary', usbMode !== 'download');
    }
    setActionButtonsEnabled(!busy && usbBoardId > 0);
}

function cdcCanCommand() {
    return usbMode === 'cdc' && cdcReady && Boolean(cdc);
}

function setActionButtonsEnabled(enabled) {
    const noFirmware = !document.getElementById('load_firmware_id');
    document.querySelectorAll('.usb-board-action').forEach((btn) => {
        const action = btn.dataset.action;
        const needsCdcReady = action === 'clear' || action === 'config' || action === 'keys';
        const needsSite = action === 'config' && usbSites.length === 0;
        btn.disabled = !enabled
            || (action === 'load' && noFirmware)
            || needsSite
            || (needsCdcReady && !cdcCanCommand());
    });
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[ch]));
}

function rememberBoard(board) {
    usbBoardId = Number(board.id || 0);
    usbSiteId = Number(board.site_id || 0);
    usbSiteName = String(board.site_name || '');
    usbHardwareId = String(board.hardware_id || '');
    usbFriendlyName = String(board.friendly_name || '');
    usbBoardTypeName = String(board.board_type_name || '');
    filterFirmwareOptions(Number(board.board_type_id || 0));
}

function filterFirmwareOptions(boardTypeId) {
    const select = document.getElementById('load_firmware_id');
    if (!select || boardTypeId <= 0) {
        return;
    }
    let first = null;
    Array.from(select.options).forEach((option) => {
        const match = Number(option.dataset.boardType || 0) === boardTypeId;
        option.hidden = !match;
        option.disabled = !match;
        if (match && first === null) {
            first = option;
        }
    });
    if (first) {
        select.value = first.value;
    }
}

function cdcWaitingMessage() {
    const state = cdcBootState(cdc ? cdc.buf : '');
    if (state === 'booting') {
        return 'Firmware is booting on this COM port. Waiting for an <code>EZGUSB ok</code> handshake.';
    }
    if (state === 'download') {
        return 'This COM port is in the bootloader. Press RST and leave CDC selected.';
    }
    return 'Waiting for firmware to answer <code>EZGUSB</code> on this COM port. Press RST if it is still in the bootloader.';
}

function actionButtonsHtml() {
    if (usbMode === 'download') {
        return `
            <p class="small text-secondary mb-2">Download mode can flash firmware. Switch to CDC after RST for config and keys.</p>
            <div class="d-flex flex-nowrap gap-2">
                <button class="btn btn-sm btn-outline-primary usb-board-action" type="button" data-action="load"${noFirmwareAttr()}>Load firmware</button>
            </div>`;
    }
    if (usbMode === 'cdc') {
        const waiting = !cdcCanCommand();
        const disabled = waiting ? ' disabled title="Waiting for the board to answer CDC commands"' : '';
        const siteOptions = usbSites.map((site) => {
            const selected = Number(site.id) === usbSiteId ? ' selected' : '';
            return '<option value="' + Number(site.id) + '"' + selected + '>' + escapeHtml(site.name) + '</option>';
        }).join('');
        const noSites = usbSites.length === 0;
        const pushDisabled = waiting || noSites
            ? ' disabled title="' + (noSites ? 'Add a site first' : 'Waiting for the board to answer CDC commands') + '"'
            : '';
        const help = waiting
            ? cdcWaitingMessage()
            : 'Choose a site, then assign this board and write that site\'s Wi-Fi and LoRa config over USB.';
        return `
            <p class="small text-secondary mb-2">${help}</p>
            <div class="mb-2" style="max-width: 24rem;">
                <label class="form-label mb-1" for="usb-site-id">Site</label>
                <select class="form-select form-select-sm" id="usb-site-id"${noSites ? ' disabled' : ''}>
                    <option value="">Choose a site</option>
                    ${siteOptions}
                </select>
            </div>
            <div class="d-flex flex-nowrap gap-2">
                <button class="btn btn-sm btn-outline-danger usb-board-action" type="button" data-action="clear"${disabled}>Clear config</button>
                <button class="btn btn-sm btn-primary usb-board-action" type="button" data-action="config"${pushDisabled}>Assign and push config</button>
                <button class="btn btn-sm btn-outline-primary usb-board-action" type="button" data-action="keys"${disabled}>Load LoRa keys</button>
            </div>`;
    }
    return '<p class="small text-secondary mb-0">Choose CDC or Download.</p>';
}

function renderDock() {
    if (!usbDock) {
        return;
    }
    if (!portOpen()) {
        usbDock.innerHTML = '';
        syncUsbButtons();
        return;
    }
    if (!usbBoardId) {
        if (pendingBoardType) {
            usbDock.innerHTML = `
                <div class="border-top px-3 py-3">
                    <p class="small mb-2"><code>${escapeHtml(pendingBoardType.hardwareId)}</code> is not in inventory.</p>
                    <button class="btn btn-sm btn-primary" type="button" id="usb-choose-type">Choose board type</button>
                </div>`;
            const choose = document.getElementById('usb-choose-type');
            if (choose) {
                choose.addEventListener('click', openBoardTypeModal);
            }
            syncUsbButtons();
            return;
        }
        const waiting = usbMode === 'cdc'
            ? cdcWaitingMessage()
            : usbMode === 'download'
                ? 'Identifying in Download…'
                : 'Port is open. Choose CDC (firmware serial) or Download (flash).';
        usbDock.innerHTML = `
            <div class="border-top px-3 py-3">
                <p class="small text-secondary mb-0">${waiting}</p>
            </div>`;
        syncUsbButtons();
        return;
    }
    const label = usbFriendlyName || usbHardwareId;
    const siteLabel = usbSiteId > 0 ? (usbSiteName || ('Site ' + usbSiteId)) : 'Unassigned';
    const typeBadge = usbBoardTypeName
        ? `<span class="badge text-bg-light border">${escapeHtml(usbBoardTypeName)}</span>`
        : '';
    usbDock.innerHTML = `
        <div class="border-top px-3 py-3" id="usb-board-card" data-board-id="${usbBoardId}">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <strong>${escapeHtml(label)}</strong>
                <code>${escapeHtml(usbHardwareId)}</code>
                ${typeBadge}
                <span class="badge ${usbSiteId > 0 ? 'text-bg-info' : 'text-bg-secondary'}">${escapeHtml(siteLabel)}</span>
            </div>
            ${actionButtonsHtml()}
        </div>`;
    syncUsbButtons();
}

function noFirmwareAttr() {
    return document.getElementById('load_firmware_id') ? '' : ' disabled title="Add firmware first"';
}

function clearUsbBoard() {
    usbBoardId = 0;
    usbSiteId = 0;
    usbSiteName = '';
    usbHardwareId = '';
    usbFriendlyName = '';
    usbBoardTypeName = '';
    pendingBoardType = null;
    identifyInFlight = '';
    cdcReady = false;
    renderDock();
}

function ui8ToBstr(bytes) {
    const chunk = 0x8000;
    let binary = '';
    for (let i = 0; i < bytes.length; i += chunk) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
    }
    return binary;
}

function terminal() {
    return {
        clean() {},
        writeLine(data) {
            log(String(data));
        },
        write(data) {
            logRaw(String(data));
        },
    };
}

async function withLoader(port, work) {
    const transport = new Transport(port, true);
    const loader = new ESPLoader({
        transport,
        baudrate: 115200,
        terminal: terminal(),
    });
    try {
        const chip = await loader.main();
        log('Connected to ' + chip);
        lastDetectedChip = String(chip || '');
        const mac = await loader.chip.readMac(loader);
        log('MAC ' + mac);
        await work(loader, mac, chip);
        await loader.after('no_reset');
        return mac;
    } finally {
        try {
            await transport.disconnect();
        } catch (_err) {
            // Port may already be closed.
        }
    }
}

async function postFields(url, fields) {
    const body = new FormData();
    body.append('_csrf', csrf);
    Object.entries(fields).forEach(([key, value]) => {
        body.append(key, String(value));
    });
    const response = await fetch(url, {
        method: 'POST',
        body,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    return response.json();
}

async function postJson(url, fields) {
    const payload = await postFields(url, fields);
    if (!payload.success) {
        throw new Error(payload.message || 'Request failed.');
    }
    return payload;
}

async function fetchJson(url) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const json = await response.json();
    if (!json.success) {
        throw new Error(json.message || 'Request failed.');
    }
    return json;
}

function cdcText(buf) {
    return String(buf || '').replace(/\r/g, '');
}

function cdcBootState(buf) {
    const n = cdcText(buf);
    if (/EZGUSB ok/i.test(n)) {
        return 'ready';
    }
    if (/DOWNLOAD_BOOT|waiting for download/i.test(n)) {
        return 'download';
    }
    if (/ESP-ROM:|SPI_FAST_FLASH_BOOT|entry 0x/.test(n)) {
        return 'booting';
    }
    return 'unknown';
}

function parseHandshake(buf) {
    const lines = cdcText(buf).split('\n');
    for (let i = lines.length - 1; i >= 0; i--) {
        const line = lines[i].trim();
        const match = line.match(/^EZGUSB ok\s+(\S+)\s+([0-9A-Fa-f]{12})(?:\s+(.+))?$/i);
        if (!match) {
            continue;
        }
        return {
            version: match[1],
            hardwareId: match[2].toUpperCase(),
            boardType: (match[3] || '').trim(),
        };
    }
    return null;
}

function parseBoardId(buf) {
    const handshake = parseHandshake(buf);
    return handshake ? handshake.hardwareId : '';
}

function trimCdcBuf() {
    if (cdc && cdc.buf.length > 8192) {
        cdc.buf = cdc.buf.slice(-4096);
    }
}

function resolveCdcWaiters() {
    if (!cdc) {
        return;
    }
    cdc.waiters = cdc.waiters.filter((waiter) => {
        if (cdc.buf.indexOf(waiter.needle) >= 0) {
            waiter.resolve(true);
            return false;
        }
        return true;
    });
}

function cdcReadyReason(buf) {
    return /EZGUSB ok/i.test(cdcText(buf)) ? 'EZGUSB handshake' : '';
}

function stopCdcProbe() {
    if (cdcProbeTimer) {
        clearInterval(cdcProbeTimer);
        cdcProbeTimer = null;
    }
}

async function probeCdcOnce() {
    if (!cdc || cdcReady || usbMode !== 'cdc') {
        return;
    }
    if (cdcBootState(cdc.buf) === 'download') {
        return;
    }
    try {
        await cdc.writer.write(cdc.encoder.encode('EZGUSB\n'));
    } catch (_err) {}
}

function startCdcProbe() {
    stopCdcProbe();
    probeCdcOnce();
    cdcProbeTimer = setInterval(() => {
        if (cdcReady || usbMode !== 'cdc' || !cdc) {
            stopCdcProbe();
            return;
        }
        probeCdcOnce();
    }, 1500);
}

function markCdcReady(reason) {
    if (cdcReady) {
        return;
    }
    cdcReady = true;
    stopCdcProbe();
    log('CDC ready via ' + reason);
    renderDock();
    const label = usbFriendlyName || usbHardwareId;
    if (usbBoardId && label) {
        setStatus('CDC ready: firmware is answering serial commands — <strong>' + escapeHtml(label) + '</strong>', 'ok');
    } else {
        setStatus('CDC ready: firmware is answering serial commands. Waiting for the board id…', 'ok');
    }
}

function considerCdcReady(buf) {
    if (cdcReady || usbMode !== 'cdc') {
        return;
    }
    const reason = cdcReadyReason(buf);
    if (reason) {
        markCdcReady(reason);
    }
}

async function maybeIdentifyFromCdc(buf) {
    const handshake = parseHandshake(buf);
    if (!handshake) {
        return;
    }
    const hardwareId = handshake.hardwareId;
    const boardType = handshake.boardType;
    if (!hardwareId || hardwareId === identifyInFlight) {
        return;
    }
    if (usbHardwareId && hardwareId === usbHardwareId && (!boardType || usbBoardTypeName === boardType)) {
        return;
    }
    identifyInFlight = hardwareId;
    try {
        await identifyFromHardwareId(hardwareId, '', boardType);
    } catch (err) {
        log(err.message || String(err));
    }
}

function clearUsbRecoverTimer() {
    if (usbRecoverTimer) {
        clearTimeout(usbRecoverTimer);
        usbRecoverTimer = null;
    }
}

function clearCdcReopenTimer() {
    if (cdcReopenTimer) {
        clearTimeout(cdcReopenTimer);
        cdcReopenTimer = null;
    }
}

function cancelUsbRecover() {
    pendingUsbRecover = false;
    clearUsbRecoverTimer();
    clearCdcReopenTimer();
}

function isEzgateUsbPort(port) {
    if (!port || typeof port.getInfo !== 'function') {
        return false;
    }
    try {
        const info = port.getInfo();
        return usbFilters.some((filter) => filter.usbVendorId === info.usbVendorId);
    } catch (_err) {
        return false;
    }
}

function beginUsbRecover(reason) {
    pendingUsbRecover = true;
    cdcReady = false;
    lastCdcBootState = '';
    grantedPort = null;
    clearCdcReopenTimer();
    clearUsbRecoverTimer();
    usbRecoverTimer = setTimeout(() => {
        usbRecoverTimer = null;
        if (grantedPort || !pendingUsbRecover) {
            return;
        }
        pendingUsbRecover = false;
        usbMode = null;
        clearUsbBoard();
        setStatus('USB disconnected.', 'err');
        syncUsbButtons();
        renderDock();
    }, 8000);
    setStatus(reason || 'USB reset. Waiting for the COM port to return…');
    syncUsbButtons();
    renderDock();
}

async function applyCdcOpenStatus() {
    const handshake = parseHandshake(cdc ? cdc.buf : '');
    if (handshake) {
        await identifyFromHardwareId(handshake.hardwareId, '', handshake.boardType);
        return;
    }
    if (cdcReady) {
        return;
    }
    const state = cdcBootState(cdc ? cdc.buf : '');
    if (state === 'booting') {
        setStatus('CDC: firmware is booting. Waiting for EZGUSB handshake…');
    } else if (state === 'download') {
        setStatus('CDC: this port is in the bootloader. Press RST and leave CDC selected.');
    } else {
        setStatus('CDC: waiting for EZGUSB handshake.');
    }
}

async function resumeCdcListen() {
    if (cdcReopenInFlight || busy || usbMode !== 'cdc' || !grantedPort) {
        syncUsbButtons();
        renderDock();
        return;
    }
    cdcReopenInFlight = true;
    cdcReady = false;
    lastCdcBootState = '';
    setStatus('CDC: USB re-enumerated. Waiting for EZGUSB handshake…');
    syncUsbButtons();
    renderDock();
    try {
        await startCdc();
        cdcReopenAttempts = 0;
        await applyCdcOpenStatus();
    } catch (err) {
        setStatus(err.message || String(err), 'err');
        log(err.message || String(err));
        cdcReopenAttempts += 1;
        if (cdcReopenAttempts < 4 && !pendingUsbRecover && grantedPort && usbMode === 'cdc') {
            scheduleCdcReopen();
        }
    } finally {
        cdcReopenInFlight = false;
        if (cdcReopenAttempts < 4 && usbMode === 'cdc' && grantedPort && !cdc && !busy && !pendingUsbRecover) {
            scheduleCdcReopen();
        }
        syncUsbButtons();
        renderDock();
    }
}

function scheduleCdcReopen() {
    if (usbMode !== 'cdc') {
        return;
    }
    clearCdcReopenTimer();
    cdcReopenTimer = setTimeout(() => {
        cdcReopenTimer = null;
        if (usbMode !== 'cdc' || busy || cdc || !grantedPort) {
            return;
        }
        resumeCdcListen();
    }, 400);
}

async function resumeAfterUsbPort(port) {
    if (!port) {
        return;
    }
    grantedPort = port;
    pendingUsbRecover = false;
    cdcReopenAttempts = 0;
    clearUsbRecoverTimer();
    if (usbMode === 'cdc') {
        await resumeCdcListen();
        return;
    }
    syncUsbButtons();
    renderDock();
    if (usbMode === 'download') {
        setStatus('COM port returned. Download still selected.');
        return;
    }
    setStatus('COM port returned. Choose CDC or Download.');
}

async function stopCdc() {
    stopCdcProbe();
    cdcReady = false;
    lastCdcBootState = '';
    const session = cdc;
    cdc = null;
    if (!session) {
        return;
    }
    session.waiters.forEach((waiter) => waiter.resolve(false));
    try {
        await session.reader.cancel();
    } catch (_err) {}
    try {
        session.reader.releaseLock();
    } catch (_err) {}
    try {
        session.writer.releaseLock();
    } catch (_err) {}
    try {
        await session.port.close();
    } catch (_err) {}
}

async function listenCdc(session) {
    while (cdc === session) {
        try {
            const { value, done } = await session.reader.read();
            if (done) {
                break;
            }
            if (!value) {
                continue;
            }
            const chunk = session.decoder.decode(value, { stream: true });
            session.buf += chunk;
            logRaw(chunk);
            trimCdcBuf();
            resolveCdcWaiters();
            considerCdcReady(session.buf);
            maybeIdentifyFromCdc(session.buf);
            if (usbMode === 'cdc' && !cdcReady) {
                const state = cdcBootState(session.buf);
                if (state !== lastCdcBootState) {
                    lastCdcBootState = state;
                    renderDock();
                    if (state === 'booting') {
                        setStatus('CDC: firmware is booting. Waiting for EZGUSB handshake…');
                    } else if (state === 'download') {
                        setStatus('CDC: this port is in the bootloader. Press RST and leave CDC selected.');
                    }
                }
            }
        } catch (_err) {
            break;
        }
    }
    if (cdc === session && usbMode === 'cdc') {
        cdcReady = false;
        if (pendingUsbRecover) {
            renderDock();
            return;
        }
        setStatus('CDC: serial dropped during USB reset. Reopening…');
        scheduleCdcReopen();
        renderDock();
    }
}

async function startCdc() {
    await stopCdc();
    const port = grantedPort;
    if (!port) {
        throw new Error('Connect a USB board first.');
    }
    if (port.readable || port.writable) {
        try {
            await port.close();
        } catch (_err) {}
    }
    await port.open({ baudRate: 115200 });
    try {
        await port.setSignals({ dataTerminalReady: true, requestToSend: true });
    } catch (_err) {}
    const session = {
        port,
        reader: port.readable.getReader(),
        writer: port.writable.getWriter(),
        decoder: new TextDecoder(),
        encoder: new TextEncoder(),
        buf: '',
        waiters: [],
    };
    cdc = session;
    listenCdc(session);
    startCdcProbe();
}

function waitCdcNeedle(needle, deadlineMs) {
    if (!cdc) {
        return Promise.resolve(false);
    }
    if (cdc.buf.indexOf(needle) >= 0) {
        return Promise.resolve(true);
    }
    return new Promise((resolve) => {
        const waiter = { needle, resolve };
        cdc.waiters.push(waiter);
        setTimeout(() => {
            if (!cdc) {
                resolve(false);
                return;
            }
            cdc.waiters = cdc.waiters.filter((item) => item !== waiter);
            resolve(cdc.buf.indexOf(needle) >= 0);
        }, deadlineMs);
    });
}

async function sendUsbLine(line, okNeedle) {
    if (!cdc) {
        throw new Error('CDC is not open. Click CDC while firmware is running or booting.');
    }
    const start = cdc.buf.length;
    await cdc.writer.write(cdc.encoder.encode(line + '\n'));
    const deadline = Date.now() + 8000;
    while (Date.now() < deadline) {
        if (cdc.buf.indexOf(okNeedle, start) >= 0) {
            return;
        }
        const remaining = deadline - Date.now();
        if (remaining <= 0) {
            break;
        }
        await waitCdcNeedle(okNeedle, Math.min(400, remaining));
    }
    if (cdc.buf.indexOf(okNeedle, start) < 0) {
        throw new Error('Board did not confirm ' + okNeedle + '. Stay in CDC and press RST if firmware is not running.');
    }
}

async function applyIdentifiedBoard(result) {
    rememberBoard(result.data.board);
    renderDock();
    const typeBit = usbBoardTypeName ? ' · ' + escapeHtml(usbBoardTypeName) : '';
    const siteBit = usbSiteId > 0
        ? ' on site ' + escapeHtml(usbSiteName || String(usbSiteId))
        : ' (unassigned)';
    if (usbMode === 'cdc' && !cdcReady) {
        setStatus('CDC: matched <strong>' + escapeHtml(usbFriendlyName || usbHardwareId) + '</strong>' + typeBit + siteBit + '. Waiting for firmware to answer commands…');
        return;
    }
    const modeBit = usbMode === 'cdc' ? 'CDC ready' : usbMode === 'download' ? 'Download' : 'USB';
    setStatus(
        modeBit + ': <strong>' + escapeHtml(usbFriendlyName || usbHardwareId) + '</strong>' + typeBit + siteBit,
        'ok'
    );
}

async function identifyFromHardwareId(hardwareId, boardType, suggestedType) {
    const fields = { hardware_id: hardwareId };
    if (boardType) {
        fields.board_type = boardType;
    }
    const result = await postFields('/usb/identify', fields);
    if (result.error && result.error.code === 'BOARD_TYPE_REQUIRED') {
        pendingBoardType = {
            hardwareId: (result.data && result.data.hardware_id) || hardwareId,
            suggested: suggestedType || '',
        };
        openBoardTypeModal();
        renderDock();
        setStatus('Choose a board type for <strong>' + escapeHtml(pendingBoardType.hardwareId) + '</strong>. It is not in inventory yet.');
        return;
    }
    if (!result.success) {
        throw new Error(result.message || 'Request failed.');
    }
    pendingBoardType = null;
    await applyIdentifiedBoard(result);
}

async function requestPort() {
    grantedPort = await navigator.serial.requestPort({ filters: usbFilters });
    return grantedPort;
}

async function releaseUsbPorts() {
    cancelUsbRecover();
    await stopCdc();
    const ports = [];
    if (grantedPort) {
        ports.push(grantedPort);
    }
    grantedPort = null;
    usbMode = null;
    clearUsbBoard();
    try {
        const extra = await navigator.serial.getPorts();
        extra.forEach((port) => {
            if (!ports.includes(port)) {
                ports.push(port);
            }
        });
    } catch (_err) {}
    for (const port of ports) {
        try {
            if (port.readable || port.writable) {
                await port.close();
            }
        } catch (_err) {}
        try {
            if (typeof port.forget === 'function') {
                await port.forget();
            }
        } catch (_err) {}
    }
}

async function disconnectUsb() {
    if (busy) {
        return;
    }
    await releaseUsbPorts();
    setStatus('USB disconnected.');
    syncUsbButtons();
}

async function connectPort() {
    if (!serialSupported()) {
        setStatus('USB detection needs Chrome or Edge on this computer.', 'err');
        return;
    }
    if (busy || portOpen()) {
        return;
    }
    busy = true;
    syncUsbButtons();
    try {
        grantedPort = await requestPort();
        usbMode = null;
        setStatus('COM port open. Choose CDC if firmware is running or booting, or Download to flash.');
        renderDock();
    } catch (err) {
        if (err && err.name === 'NotFoundError') {
            setStatus('No USB board selected.', 'err');
        } else {
            setStatus(err.message || String(err), 'err');
            log(err.message || String(err));
        }
        grantedPort = null;
        usbMode = null;
        clearUsbBoard();
    } finally {
        busy = false;
        syncUsbButtons();
    }
}

async function enterCdc() {
    if (!portOpen() || busy) {
        return;
    }
    busy = true;
    usbMode = 'cdc';
    cdcReady = false;
    syncUsbButtons();
    renderDock();
    setStatus('CDC: checking that firmware is answering serial commands…');
    try {
        await startCdc();
        await applyCdcOpenStatus();
    } catch (err) {
        setStatus(err.message || String(err), 'err');
        log(err.message || String(err));
    } finally {
        busy = false;
        syncUsbButtons();
        renderDock();
    }
}

async function enterDownload() {
    if (!portOpen() || busy) {
        return;
    }
    busy = true;
    usbMode = 'download';
    syncUsbButtons();
    renderDock();
    setStatus('Download: identifying…');
    try {
        await stopCdc();
        cdcReady = false;
        const mac = await withLoader(grantedPort, async () => {});
        await identifyFromHardwareId(mac);
    } catch (err) {
        setStatus(err.message || String(err), 'err');
        log(err.message || String(err));
    } finally {
        busy = false;
        syncUsbButtons();
        renderDock();
    }
}

async function flashFirmware(loader, firmwareId) {
    const manifestRes = await fetch('/firmware/' + firmwareId + '/usb-manifest', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const manifestJson = await manifestRes.json();
    if (!manifestJson.success) {
        throw new Error(manifestJson.message || 'No USB factory image for that version.');
    }
    const manifest = manifestJson.data;
    const fileArray = [];
    for (const segment of manifest.segments) {
        setStatus('Downloading ' + segment.name + '…');
        const response = await fetch(segment.url, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error('Could not download ' + segment.name + '.');
        }
        const bytes = new Uint8Array(await response.arrayBuffer());
        log('Segment ' + segment.name + ' ' + bytes.length + ' bytes at 0x' + Number(segment.address).toString(16));
        fileArray.push({ data: ui8ToBstr(bytes), address: Number(segment.address) });
    }
    setStatus('Writing factory image…');
    await loader.writeFlash({
        fileArray,
        flashSize: manifest.flashSize || usbConfig.flashSize || '8MB',
        flashMode: manifest.flashMode || usbConfig.flashMode || 'qio',
        flashFreq: manifest.flashFreq || usbConfig.flashFreq || '80m',
        eraseAll: manifest.flashSize === '4MB',
        compress: true,
        reportProgress(_fileIndex, written, total) {
            setStatus('Writing firmware… ' + Math.round((written / total) * 100) + '%');
        },
    });
}

async function runBusy(label, work) {
    if (busy) {
        return;
    }
    busy = true;
    syncUsbButtons();
    setStatus(label);
    try {
        await work();
    } catch (err) {
        setStatus(err.message || String(err), 'err');
        log(err.message || String(err));
    } finally {
        busy = false;
        syncUsbButtons();
    }
}

async function requireCdcReady() {
    if (usbMode !== 'cdc') {
        setStatus('This command needs CDC.', 'err');
        return false;
    }
    if (!cdcCanCommand()) {
        setStatus('CDC is not ready. Wait until the badge says CDC ready, or press RST and click CDC again.', 'err');
        return false;
    }
    return true;
}

async function clearConfig() {
    if (!await requireCdcReady()) {
        return;
    }
    if (!confirm('Clear stored Wi‑Fi and site config on this board? LoRa keys stay. The portal assignment is unchanged.')) {
        return;
    }
    await runBusy('Clearing board config over USB…', async () => {
        await sendUsbLine('EZGCLR', 'EZGCLR ok');
        log('Board config cleared');
        setStatus('CDC: config cleared. LoRa keys were left in place. Press RST if you want setup.', 'ok');
    });
}

async function loadFirmware(boardId, firmwareId) {
    if (usbMode !== 'download') {
        setStatus('Load firmware needs Download.', 'err');
        return;
    }
    await runBusy('Loading firmware over USB…', async () => {
        await withLoader(grantedPort, async (loader) => {
            await flashFirmware(loader, firmwareId);
        });
        const result = await postJson('/boards/' + boardId + '/usb-load', {
            firmware_id: firmwareId,
            return_to: '/usb',
        });
        log(result.message || 'Firmware written');
        usbMode = 'download';
        setStatus(
            (result.message || 'Firmware written') +
            ' Still in Download. Press RST, then click CDC for config or keys.',
            'ok'
        );
        renderDock();
    });
}

async function loadConfig(boardId) {
    if (!await requireCdcReady()) {
        return;
    }
    const select = document.getElementById('usb-site-id');
    const siteId = Number(select && select.value ? select.value : 0);
    if (siteId < 1) {
        setStatus('Choose a site before pushing config.', 'err');
        return;
    }
    const siteName = select && select.selectedOptions[0] ? select.selectedOptions[0].text : '';
    await runBusy('Assigning the site and writing configuration…', async () => {
        if (siteId !== usbSiteId) {
            const moved = await postJson('/boards/' + boardId + '/move', { site_id: String(siteId) });
            usbSiteId = siteId;
            usbSiteName = siteName;
            log(moved.message || 'Assigned to site');
        }
        const cfgJson = await fetchJson('/boards/' + boardId + '/usb-config');
        const siteConfig = cfgJson.data.config;
        if (!siteConfig) {
            throw new Error('This board is not on a site.');
        }
        await sendUsbLine('EZGCFG ' + JSON.stringify(siteConfig), 'EZGCFG ok');
        log('Site configuration stored');
        setStatus('CDC: assigned to <strong>' + escapeHtml(usbSiteName || siteName) + '</strong> and configuration written.', 'ok');
        renderDock();
    });
}

async function loadLoraKeys(boardId) {
    if (!await requireCdcReady()) {
        return;
    }
    await runBusy('Loading LoRa keys over USB…', async () => {
        const credJson = await fetchJson('/boards/' + boardId + '/lora-credential');
        const cred = credJson.data.credential;
        await sendUsbLine('EZGKEY ' + cred, 'EZGKEY ok');
        log('LoRa credential stored');
        setStatus('CDC: LoRa keys written.', 'ok');
    });
}

function openBoardTypeModal() {
    const modalEl = document.getElementById('boardTypeModal');
    const idEl = document.getElementById('boardTypeHardwareId');
    const select = document.getElementById('board_type_choice');
    if (!modalEl || !pendingBoardType || !window.bootstrap) {
        return;
    }
    if (idEl) {
        idEl.textContent = pendingBoardType.hardwareId;
    }
    if (select) {
        const suggested = pendingBoardType.suggested || '';
        const match = Array.from(select.options).some((option) => option.value === suggested);
        select.value = match ? suggested : '';
    }
    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

const boardTypeForm = document.getElementById('boardTypeForm');
if (boardTypeForm) {
    boardTypeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!pendingBoardType || busy) {
            return;
        }
        const select = document.getElementById('board_type_choice');
        const boardType = select ? select.value : '';
        if (!boardType) {
            return;
        }
        busy = true;
        syncUsbButtons();
        try {
            await identifyFromHardwareId(pendingBoardType.hardwareId, boardType);
            const modalEl = document.getElementById('boardTypeModal');
            if (window.bootstrap && modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
        } catch (err) {
            setStatus(err.message || String(err), 'err');
            log(err.message || String(err));
        } finally {
            busy = false;
            syncUsbButtons();
            renderDock();
        }
    });
}

function openLoadModal(boardId) {
    document.getElementById('load_board_id').value = String(boardId);
    if (window.bootstrap && loadModalEl) {
        window.bootstrap.Modal.getOrCreateInstance(loadModalEl).show();
    }
}

if (!serialSupported()) {
    setStatus('USB detection needs Chrome or Edge on this computer.', 'err');
    syncUsbButtons();
} else {
    navigator.serial.addEventListener('connect', (event) => {
        const port = event.target;
        if (busy) {
            return;
        }
        if (grantedPort && grantedPort !== port) {
            return;
        }
        if (pendingUsbRecover || (usbMode && !grantedPort)) {
            if (pendingUsbRecover || isEzgateUsbPort(port)) {
                resumeAfterUsbPort(port);
                return;
            }
        }
        if (!usbSessionActive()) {
            setStatus('USB cable attached. Click Connect.');
        }
    });
    navigator.serial.addEventListener('disconnect', (event) => {
        if (event.target !== grantedPort) {
            return;
        }
        if (busy) {
            return;
        }
        void stopCdc();
        beginUsbRecover(usbMode === 'cdc'
            ? 'CDC: board USB reset during boot. Waiting for the COM port…'
            : 'USB reset. Waiting for the COM port to return…');
    });
}

connectBtn.addEventListener('click', () => connectPort());
if (cdcBtn) {
    cdcBtn.addEventListener('click', () => enterCdc());
}
if (downloadBtn) {
    downloadBtn.addEventListener('click', () => enterDownload());
}
if (disconnectBtn) {
    disconnectBtn.addEventListener('click', () => disconnectUsb());
}

if (usbDock) {
    usbDock.addEventListener('click', (event) => {
        const btn = event.target.closest('.usb-board-action');
        if (!btn || !usbBoardId) {
            return;
        }
        const action = btn.dataset.action;
        if (action === 'clear') {
            clearConfig();
            return;
        }
        if (action === 'load') {
            openLoadModal(usbBoardId);
            return;
        }
        if (action === 'config') {
            loadConfig(usbBoardId);
            return;
        }
        if (action === 'keys') {
            loadLoraKeys(usbBoardId);
        }
    });
}

if (loadForm) {
    loadForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const boardId = Number(document.getElementById('load_board_id').value || 0);
        const firmwareId = Number(document.getElementById('load_firmware_id').value || 0);
        if (!boardId || !firmwareId) {
            return;
        }
        if (window.bootstrap && loadModalEl) {
            window.bootstrap.Modal.getOrCreateInstance(loadModalEl).hide();
        }
        loadFirmware(boardId, firmwareId);
    });
}

syncUsbButtons();
