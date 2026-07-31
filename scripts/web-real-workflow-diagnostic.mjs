import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
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
    name: 'JERVA Transcriber Diagnostic',
    email: `jerva-web-diagnostic-${runId}@example.test`,
    password,
};
const audioRoot = path.join(root, 'storage/app/private/diagnostics/web-real-workflow');
const reportRoot = path.join(root, 'storage/app/private/diagnostics/reports');
const uploadAudio = path.join(audioRoot, `${runId}-upload-near-minute.wav`);
const liveAudio = path.join(audioRoot, `${runId}-live-2s.wav`);
const topupAmountCents = Number(args.topupCents || process.env.JERVA_WEB_DIAGNOSTIC_TOPUP_CENTS || 1000);
const topupReference = `JERVA-DIAG-${runId}`;
const topupSessionId = `cs_diag_${runId.replace(/[^a-zA-Z0-9]/g, '_')}`;
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

const postJson = async (url, payload, headers = {}) => {
    const response = await request(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...headers,
        },
        body: JSON.stringify(payload),
    });
    const text = await response.text();
    const parsed = parseJson(text);

    if (!response.ok) {
        throw new HttpError(messageFrom(parsed, text, response.status), { status: response.status, payload: parsed, text: text.slice(0, 1200) });
    }

    return { status: response.status, payload: parsed };
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
    writeFileSync(uploadAudio, wavContent(60.5));
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

    console.log('web diagnostic step: tracked wallet top-up');
    const topup = createTrackedTopup();

    console.log('web diagnostic step: paymongo webhook confirms top-up');
    const topupWebhook = await postSignedPayMongoWebhook(paymongoPaidTopupPayload(topup));

    console.log('web diagnostic step: workspace status shows credited wallet');
    const topupStatus = await getJson(`/workspace/${projectId}/status`);
    const walletBalanceCents = Number(topupStatus.payload?.entitlements?.usage?.wallet_balance_cents ?? 0);

    if (walletBalanceCents < topupAmountCents) {
        throw new Error(`Top-up was not visible in workspace status. Expected at least ${topupAmountCents} cents, got ${walletBalanceCents}.`);
    }

    console.log('web diagnostic step: upload audio with server chunking');
    const upload = await formJson(`/workspace/${projectId}/upload`, token, {
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
    const output = report({ projectId, topup, topupWebhook, topupStatus, upload, live, finalStatus });
    const reportPath = path.join(reportRoot, `${runId}-web-real-workflow.md`);
    writeFileSync(reportPath, output, 'utf8');
    console.log(reportPath);
}

function ensureDiagnosticUser() {
    execFileSync('php', ['scripts/web-diagnostic-user.php', user.email, user.password], { cwd: root, stdio: 'pipe' });
}

function createTrackedTopup() {
    const output = execFileSync('php', [
        'scripts/web-diagnostic-topup.php',
        user.email,
        String(topupAmountCents),
        topupReference,
        topupSessionId,
    ], { cwd: root, stdio: 'pipe' });

    return JSON.parse(String(output));
}

async function postSignedPayMongoWebhook(payload) {
    const body = JSON.stringify(payload);
    const timestamp = Math.floor(Date.now() / 1000);
    const secret = payMongoWebhookSecret();
    const signature = hmacSha256(`${timestamp}.${body}`, secret);

    return postJson('/paymongo/webhook', payload, {
        'PayMongo-Signature': `t=${timestamp},te=${signature}`,
    });
}

function payMongoWebhookSecret() {
    const secret = envValue('PAYMONGO_WEBHOOK_SECRET');

    if (!secret) {
        throw new Error('PAYMONGO_WEBHOOK_SECRET is required for the top-up webhook diagnostic.');
    }

    return secret;
}

function envValue(key) {
    if (process.env[key]) {
        return process.env[key];
    }

    try {
        const env = readFileSync(path.join(root, '.env'), 'utf8');
        const match = env.match(new RegExp(`^${key}=(.*)$`, 'm'));

        return match ? match[1].trim().replace(/^["']|["']$/g, '') : '';
    } catch {
        return '';
    }
}

function hmacSha256(value, secret) {
    return createHmac('sha256', secret).update(value).digest('hex');
}

function paymongoPaidTopupPayload(topup) {
    return {
        data: {
            attributes: {
                type: 'checkout_session.payment.paid',
                data: {
                    id: topup.checkout_session_id,
                    type: 'checkout_session',
                    attributes: {
                        reference_number: topup.reference,
                        metadata: {
                            plan: 'wallet_topup',
                            billing_transaction_id: String(topup.id),
                            wallet_topup_amount: String(topup.amount),
                            wallet_topup_currency: 'USD',
                        },
                        payments: [
                            {
                                id: `pay_diag_${runId.replace(/[^a-zA-Z0-9]/g, '_')}`,
                                attributes: {
                                    status: 'paid',
                                },
                            },
                        ],
                    },
                },
            },
        },
    };
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

function report({ projectId, topup, topupWebhook, topupStatus, upload, live, finalStatus }) {
    const transcripts = finalStatus?.project?.transcripts || [];
    const lines = [
        '# JERVA Transcriber Real Workflow Diagnostic',
        '',
        `- Generated: ${new Date().toISOString()}`,
        `- Base URL: ${baseUrl}`,
        `- Project ID: ${projectId}`,
        `- User: ${user.email}`,
        '',
        '## Wallet Top-Up Path',
        '',
        `- Endpoint: POST /paymongo/webhook`,
        `- Webhook status: ${topupWebhook.status}`,
        `- Reference: ${topup.reference}`,
        `- Checkout session: ${topup.checkout_session_id}`,
        `- Top-up amount cents: ${topup.amount}`,
        `- Workspace wallet balance cents: ${topupStatus.payload?.entitlements?.usage?.wallet_balance_cents ?? ''}`,
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
