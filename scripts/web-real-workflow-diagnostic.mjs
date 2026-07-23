import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const args = Object.fromEntries(process.argv.slice(2).map((arg) => {
    const [key, ...value] = arg.replace(/^--/, '').split('=');
    return [key, value.join('=') || 'true'];
}));
const baseUrl = String(args.base || process.env.JERVA_WEB_DIAGNOSTIC_URL || 'http://127.0.0.1:8000').replace(/\/+$/, '');
const runId = timestamp();
const password = `JervaDiagnostic-${runId}!`;
const user = {
    name: 'JERVA Web Diagnostic',
    email: `jerva-web-diagnostic-${runId}@example.test`,
    password,
};
const audioRoot = path.join(root, 'storage/app/private/diagnostics/web-real-workflow');
const reportRoot = path.join(root, 'storage/app/private/diagnostics/reports');
const uploadAudio = path.join(audioRoot, `${runId}-upload-61s.wav`);
const liveAudio = path.join(audioRoot, `${runId}-live-2s.wav`);
const cookieJar = new Map();

class HttpError extends Error {
    constructor(message, response) {
        super(message);
        this.response = response;
    }
}

const cookieHeader = () => [...cookieJar.entries()].map(([key, value]) => `${key}=${value}`).join('; ');

const storeCookies = (headers) => {
    const combined = headers.get('set-cookie') || '';
    combined.split(/,(?=[^;,]+=)/).forEach((cookie) => {
        const [pair] = cookie.trim().split(';');
        const index = pair.indexOf('=');

        if (index > 0) {
            cookieJar.set(pair.slice(0, index), pair.slice(index + 1));
        }
    });
};

const request = async (url, options = {}) => {
    const headers = new Headers(options.headers || {});

    if (cookieJar.size > 0) {
        headers.set('Cookie', cookieHeader());
    }

    let response;

    try {
        response = await fetch(`${baseUrl}${url}`, {
            redirect: 'manual',
            ...options,
            headers,
        });
    } catch (error) {
        throw new HttpError(`Request failed for ${options.method || 'GET'} ${url}: ${error.cause?.message || error.message}`, {
            cause: error.cause?.message || error.message,
        });
    }

    storeCookies(response.headers);

    return response;
};

const page = async (url) => {
    const response = await request(url, { headers: { Accept: 'text/html' } });
    const text = await response.text();

    if (!response.ok) {
        throw new HttpError(`${url} returned HTTP ${response.status}`, { status: response.status, text: text.slice(0, 1000) });
    }

    return text;
};

const csrfFrom = (html) => {
    const match = html.match(/name="csrf-token"\s+content="([^"]+)"/) || html.match(/name="_token"\s+value="([^"]+)"/);

    if (!match) {
        throw new Error('CSRF token was not found.');
    }

    return match[1];
};

const formPage = async (url, token, fields) => {
    const body = new URLSearchParams();
    body.set('_token', token);
    Object.entries(fields).forEach(([key, value]) => body.set(key, String(value)));

    const response = await request(url, {
        method: 'POST',
        headers: {
            Accept: 'text/html, application/xhtml+xml',
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': token,
        },
        body,
    });
    const text = await response.text();

    if (![200, 302, 303].includes(response.status)) {
        throw new HttpError(`${url} returned HTTP ${response.status}`, { status: response.status, text: text.slice(0, 1200) });
    }

    return { status: response.status, location: response.headers.get('location') || '', text };
};

const formJson = async (url, token, fields, files) => {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => body.append(key, String(value)));
    files.forEach(({ key, file, name, type }) => {
        body.append(key, new File([readFileSync(file)], name || path.basename(file), { type }));
    });

    const response = await request(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body,
    });
    const text = await response.text();
    const payload = parseJson(text);

    if (!response.ok) {
        throw new HttpError(messageFrom(payload, text, response.status), { status: response.status, payload, text: text.slice(0, 1200) });
    }

    return { status: response.status, payload };
};

const getJson = async (url) => {
    const response = await request(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const text = await response.text();
    const payload = parseJson(text);

    if (!response.ok) {
        throw new HttpError(messageFrom(payload, text, response.status), { status: response.status, payload, text: text.slice(0, 1200) });
    }

    return { status: response.status, payload };
};

const parseJson = (text) => {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
};

const messageFrom = (payload, text, status) => payload?.message || text.slice(0, 300) || `HTTP ${status}`;

async function main() {
    mkdirSync(audioRoot, { recursive: true });
    mkdirSync(reportRoot, { recursive: true });
    writeFileSync(uploadAudio, wavContent(61));
    writeFileSync(liveAudio, wavContent(2));

    console.log('web diagnostic step: create verified pro user');
    ensureDiagnosticUser();

    console.log('web diagnostic step: login page');
    const loginHtml = await page('/login');
    const loginToken = csrfFrom(loginHtml);

    console.log('web diagnostic step: login');
    await formPage('/login', loginToken, {
        email: user.email,
        password: user.password,
        remember: '0',
    });

    console.log('web diagnostic step: workspace page');
    const workspaceHtml = await page('/workspace');
    const token = csrfFrom(workspaceHtml);

    console.log('web diagnostic step: create project');
    const createProject = await formPage('/workspace', token, {
        title: `Web Real Workflow ${runId}`,
    });
    const projectId = projectIdFromLocation(createProject.location);

    console.log('web diagnostic step: upload audio with server chunking');
    const upload = await formJson(`/workspace/${projectId}/upload`, token, {
        duration_seconds: 61,
        server_chunk: 1,
    }, [{
        key: 'audio',
        file: uploadAudio,
        name: path.basename(uploadAudio),
        type: 'audio/wav',
    }]);
    const uploadTranscriptId = upload.payload?.transcript?.id;

    console.log('web diagnostic step: live audio chunk');
    const live = await formJson(`/workspace/${projectId}/chunk`, token, {
        duration_seconds: 2,
        clip_index: 1,
        clip_start_ms: 0,
        clip_end_ms: 2000,
    }, [{
        key: 'audio',
        file: liveAudio,
        name: path.basename(liveAudio),
        type: 'audio/wav',
    }]);
    const liveTranscriptId = live.payload?.transcript?.id;

    console.log('web diagnostic step: poll processing status');
    const finalStatus = await pollStatus(projectId, [uploadTranscriptId, liveTranscriptId].filter(Boolean));
    const output = report({ projectId, upload, live, finalStatus });
    const reportPath = path.join(reportRoot, `${runId}-web-real-workflow.md`);
    writeFileSync(reportPath, output, 'utf8');
    console.log(reportPath);
}

function ensureDiagnosticUser() {
    execFileSync('php', ['scripts/web-diagnostic-user.php', user.email, user.password], { cwd: root, stdio: 'pipe' });
}

function projectIdFromLocation(location) {
    const match = String(location).match(/\/workspace\/(\d+)/);

    if (!match) {
        throw new Error(`Project id was not found in redirect location [${location}].`);
    }

    return Number(match[1]);
}

async function pollStatus(projectId, transcriptIds) {
    const deadline = Date.now() + 360_000;
    let latest = null;

    while (Date.now() < deadline) {
        const status = await getJson(`/workspace/${projectId}/status`);
        latest = status.payload;
        const transcripts = status.payload?.project?.transcripts || [];
        const selected = transcripts.filter((transcript) => transcriptIds.includes(transcript.id));

        if (
            selected.length === transcriptIds.length &&
            selected.every((transcript) => ['completed', 'failed', 'cancelled'].includes(transcript.status))
        ) {
            return status.payload;
        }

        await new Promise((resolve) => setTimeout(resolve, 3000));
    }

    return latest;
}

function report({ projectId, upload, live, finalStatus }) {
    const transcripts = finalStatus?.project?.transcripts || [];
    const lines = [
        '# JERVA Web Real Workflow Diagnostic',
        '',
        `- Generated: ${new Date().toISOString()}`,
        `- Base URL: ${baseUrl}`,
        `- Project ID: ${projectId}`,
        `- User: ${user.email}`,
        '',
        '## Upload Path',
        '',
        `- Endpoint: POST /workspace/${projectId}/upload`,
        `- Status: ${upload.status}`,
        `- Transcript ID: ${upload.payload?.transcript?.id ?? ''}`,
        `- Initial transcript status: ${upload.payload?.transcript?.status ?? ''}`,
        '',
        '## Live Path',
        '',
        `- Endpoint: POST /workspace/${projectId}/chunk`,
        `- Status: ${live.status}`,
        `- Transcript ID: ${live.payload?.transcript?.id ?? ''}`,
        `- Initial transcript status: ${live.payload?.transcript?.status ?? ''}`,
        '',
        '## Final Transcript Status',
        '',
    ];

    transcripts.forEach((transcript) => {
        lines.push(
            `### Transcript ${transcript.id}`,
            '',
            `- Source: ${transcript.source}`,
            `- Status: ${transcript.status}`,
            `- Duration seconds: ${transcript.duration_seconds}`,
            `- Sections: ${Array.isArray(transcript.sections) ? transcript.sections.length : 0}`,
            `- Text preview: ${String(transcript.raw_text || '').slice(0, 300)}`,
            `- Last log: ${lastLogMessage(transcript)}`,
            '',
        );
    });

    return lines.join('\n');
}

function lastLogMessage(transcript) {
    const log = Array.isArray(transcript.processing_log) ? transcript.processing_log : [];
    const last = [...log].reverse().find((entry) => entry?.message);

    return last?.message || '';
}

function wavContent(seconds) {
    const sampleRate = 16000;
    const channels = 1;
    const bitsPerSample = 16;
    const sampleCount = sampleRate * seconds;
    const data = Buffer.alloc(sampleCount * 2);

    for (let index = 0; index < sampleCount; index++) {
        const sample = Math.round(Math.sin(2 * Math.PI * 440 * (index / sampleRate)) * 8000);
        data.writeInt16LE(sample, index * 2);
    }

    const header = Buffer.alloc(44);
    const byteRate = sampleRate * channels * (bitsPerSample / 8);
    const blockAlign = channels * (bitsPerSample / 8);
    header.write('RIFF', 0);
    header.writeUInt32LE(36 + data.length, 4);
    header.write('WAVE', 8);
    header.write('fmt ', 12);
    header.writeUInt32LE(16, 16);
    header.writeUInt16LE(1, 20);
    header.writeUInt16LE(channels, 22);
    header.writeUInt32LE(sampleRate, 24);
    header.writeUInt32LE(byteRate, 28);
    header.writeUInt16LE(blockAlign, 32);
    header.writeUInt16LE(bitsPerSample, 34);
    header.write('data', 36);
    header.writeUInt32LE(data.length, 40);

    return Buffer.concat([header, data]);
}

function timestamp() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
}

main().catch((error) => {
    if (error instanceof HttpError) {
        console.error(error.message);
        console.error(JSON.stringify(error.response, null, 2));
    } else {
        console.error(error.stack || error.message);
    }
    process.exitCode = 1;
});
