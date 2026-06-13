<?php

declare(strict_types=1);

namespace Amtgard\IdpSlimExample\Views;

use Amtgard\IdpSlimExample\Config\ExampleDefaults;

final class DashboardHtml
{
    /**
     * @param array<string, array{method: string, path: string}> $libraryCoverage
     */
    public static function render(
        bool $authenticated,
        ?string $email,
        array $libraryCoverage,
        string $loginUrl,
        string $logoutUrl,
        bool $clientIamConfigured,
    ): string {
        $authBadge = $authenticated
            ? '<span class="badge badge-ok">Signed in</span>'
            : '<span class="badge badge-warn">Not signed in</span>';
        $emailLine = $authenticated && $email !== null
            ? '<p class="meta">Session email: <code>' . self::escape($email) . '</code></p>'
            : '';

        $coverageRows = '';
        foreach ($libraryCoverage as $label => $route) {
            $coverageRows .= sprintf(
                '<tr><td>%s</td><td><code>%s %s</code></td></tr>',
                self::escape($label),
                self::escape($route['method']),
                self::escape($route['path']),
            );
        }

        $authCheckRequirement = self::escape(ExampleDefaults::policyRequirement());
        $authCheckPolicyJson = self::escape(ExampleDefaults::policyOrnsJson());
        $clientIamResource = self::escape(ExampleDefaults::clientIamResource());
        $clientIamSegmentsJson = self::escape('{}');

        $clientIamSection = $clientIamConfigured
            ? <<<HTML
            <details class="panel" open>
                <summary>Client IAM</summary>
                <div class="panel-body">
                    <p class="hint">Requires <code>IDP_CLIENT_SECRET</code>. Segments and resource defaults load from service format on page load.</p>
                    <div class="actions">
                        <button type="button" class="btn" data-call="GET" data-path="/api/client-iam/service-format">Get service format</button>
                    </div>
                    <form id="compose-claim-form" class="form-grid">
                        <label>Resource
                            <input type="text" name="resource" value="{$clientIamResource}" required>
                        </label>
                        <label>Segments (JSON object — keys from <code>service_format</code>)
                            <textarea name="segments" rows="4">{$clientIamSegmentsJson}</textarea>
                        </label>
                        <button type="submit" class="btn btn-primary">Compose claim</button>
                    </form>
                </div>
            </details>
        HTML
            : <<<'HTML'
            <details class="panel panel-muted">
                <summary>Client IAM</summary>
                <div class="panel-body">
                    <p class="hint">Not configured. Set <code>IDP_CLIENT_SECRET</code> and <code>IDP_IAM_SERVICE</code> in <code>.env</code> to enable.</p>
                </div>
            </details>
        HTML;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IDP Slim Example</title>
    <style>
        :root {
            --bg: #0f1419;
            --surface: #1a2332;
            --border: #2d3a4f;
            --text: #e7ecf3;
            --muted: #8b9cb3;
            --accent: #3d8bfd;
            --ok: #3dd68c;
            --warn: #f5a524;
            --danger: #f31260;
            --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            display: flex;
            flex-direction: column;
        }
        .wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            max-width: 100%;
            margin: 0 auto;
            padding: 1rem 1.25rem;
            width: 100%;
        }
        header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-shrink: 0;
        }
        h1 { margin: 0; font-size: 1.5rem; }
        .badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .badge-ok { background: rgba(61, 214, 140, 0.15); color: var(--ok); }
        .badge-warn { background: rgba(245, 165, 36, 0.15); color: var(--warn); }
        .meta { color: var(--muted); margin: 0.25rem 0 0; }
        .layout {
            flex: 1;
            display: flex;
            gap: 1rem;
            min-height: 0;
        }
        @media (max-width: 799px) {
            .layout { flex-direction: column; }
            .controls-panel { max-height: 45vh; width: 100%; }
            .output-panel { min-height: 45vh; }
        }
        .controls-panel {
            width: 22rem;
            flex-shrink: 0;
            min-height: 0;
            align-self: stretch;
            overflow-x: hidden;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            padding-right: 0.25rem;
        }
        .output-panel {
            flex: 1;
            min-width: 0;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            flex-shrink: 0;
        }
        .panel-muted { opacity: 0.85; }
        .panel > summary {
            list-style: none;
            cursor: pointer;
            padding: 0.75rem 1rem;
            font-size: 1.05rem;
            font-weight: 600;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .panel > summary::-webkit-details-marker { display: none; }
        .panel > summary::before {
            content: '▸';
            color: var(--muted);
            font-size: 0.85rem;
            transition: transform 0.15s ease;
        }
        .panel[open] > summary::before { transform: rotate(90deg); }
        .panel > summary:hover { background: rgba(255, 255, 255, 0.03); }
        .panel-body {
            padding: 0 1rem 1rem;
            border-top: 1px solid var(--border);
        }
        .output-card {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }
        .output-head {
            flex-shrink: 0;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border);
        }
        .output-head h2 {
            margin: 0 0 0.25rem;
            font-size: 1.05rem;
        }
        .hint { color: var(--muted); font-size: 0.9rem; margin: 0 0 0.75rem; }
        .output-head .hint { margin: 0; }
        .header-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-left: auto;
            margin-bottom: 0;
        }
        .panel-body .actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .panel-body .actions:last-child { margin-bottom: 0; }
        .btn {
            border: 1px solid var(--border);
            background: #243044;
            color: var(--text);
            padding: 0.45rem 0.75rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .btn:hover { border-color: var(--accent); }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-link {
            display: inline-block;
            text-decoration: none;
            color: inherit;
        }
        .form-grid { display: grid; gap: 0.75rem; }
        label { display: grid; gap: 0.35rem; font-size: 0.9rem; }
        input, textarea {
            font: inherit;
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 6px;
            padding: 0.5rem 0.6rem;
        }
        textarea { font-family: var(--mono); font-size: 0.85rem; resize: vertical; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: left; padding: 0.4rem 0; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-weight: 500; }
        code { font-family: var(--mono); font-size: 0.85em; }
        #trace-log {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            margin: 0;
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .trace-entry {
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .trace-time {
            padding: 0.45rem 0.75rem;
            font-size: 0.8rem;
            color: var(--muted);
            background: rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
        }
        .trace-head {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
            padding: 0.6rem 0.75rem;
            background: #243044;
            font-size: 0.85rem;
        }
        .trace-status-ok { color: var(--ok); }
        .trace-status-err { color: var(--danger); }
        .trace-body {
            margin: 0;
            padding: 0.75rem;
            font-family: var(--mono);
            font-size: 0.8rem;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .empty-trace { color: var(--muted); font-style: italic; margin: 0; }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <div>
                <h1>IDP Slim Example</h1>
                {$emailLine}
            </div>
            {$authBadge}
            <div class="header-actions">
                <a class="btn btn-link btn-primary" href="{$loginUrl}">Login</a>
                <a class="btn btn-link" href="{$logoutUrl}">Logout</a>
            </div>
        </header>

        <div class="layout">
            <aside class="controls-panel">
                <details class="panel" open>
                    <summary>Session</summary>
                    <div class="panel-body">
                        <div class="actions">
                            <button type="button" class="btn" data-call="GET" data-path="/me">GET /me</button>
                        </div>
                    </div>
                </details>

                <details class="panel" open>
                    <summary>Resources (Bearer)</summary>
                    <div class="panel-body">
                        <div class="actions">
                            <button type="button" class="btn" data-call="GET" data-path="/resources/userinfo">Userinfo</button>
                            <button type="button" class="btn" data-call="GET" data-path="/resources/validate">Validate</button>
                            <button type="button" class="btn" data-call="GET" data-path="/resources/jwt">JWT</button>
                            <button type="button" class="btn" data-call="POST" data-path="/refresh">Refresh tokens</button>
                        </div>
                    </div>
                </details>

                <details class="panel">
                    <summary>Authorization check</summary>
                    <div class="panel-body">
                        <form id="auth-check-form" class="form-grid">
                            <label>Requirement ORN
                                <input type="text" name="requirement" value="{$authCheckRequirement}" required>
                            </label>
                            <label>Policy (JSON array of ORNs)
                                <textarea name="policy" rows="3">{$authCheckPolicyJson}</textarea>
                            </label>
                            <button type="submit" class="btn btn-primary">POST /api/check-authorization</button>
                        </form>
                    </div>
                </details>

                {$clientIamSection}

                <details class="panel">
                    <summary>Library coverage</summary>
                    <div class="panel-body">
                        <table>
                            <thead><tr><th>Method</th><th>Route</th></tr></thead>
                            <tbody>{$coverageRows}</tbody>
                        </table>
                    </div>
                </details>
            </aside>

            <section class="output-panel">
                <div class="output-card">
                    <div class="output-head">
                        <h2>Request trace</h2>
                        <p class="hint">Responses appear here (newest first).</p>
                    </div>
                    <div id="trace-log"><p class="empty-trace">No requests yet.</p></div>
                </div>
            </section>
        </div>
    </div>

    <script>
        const traceLog = document.getElementById('trace-log');

        function formatBody(body) {
            if (body === '') return '(empty)';
            try {
                return JSON.stringify(JSON.parse(body), null, 2);
            } catch {
                return body;
            }
        }

        function formatTimestamp(date) {
            return date.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
            });
        }

        function prependTrace(method, path, status, body, requestBody) {
            const entry = document.createElement('div');
            entry.className = 'trace-entry';
            const statusClass = status >= 200 && status < 300 ? 'trace-status-ok' : 'trace-status-err';
            const timestamp = formatTimestamp(new Date());
            const reqLine = requestBody !== undefined
                ? '<div class="trace-time">' + timestamp + '</div>'
                  + '<div class="trace-head"><strong>' + method + ' ' + path + '</strong><span class="' + statusClass + '">' + status + '</span></div>'
                  + '<pre class="trace-body">Request:\\n' + formatBody(requestBody) + '\\n\\nResponse:\\n' + formatBody(body) + '</pre>'
                : '<div class="trace-time">' + timestamp + '</div>'
                  + '<div class="trace-head"><strong>' + method + ' ' + path + '</strong><span class="' + statusClass + '">' + status + '</span></div>'
                  + '<pre class="trace-body">' + formatBody(body) + '</pre>';
            entry.innerHTML = reqLine;
            if (traceLog.querySelector('.empty-trace')) {
                traceLog.innerHTML = '';
            }
            traceLog.prepend(entry);
        }

        async function callApi(method, path, jsonBody) {
            const options = { method, credentials: 'same-origin' };
            if (jsonBody !== undefined) {
                options.headers = { 'Content-Type': 'application/json' };
                options.body = JSON.stringify(jsonBody);
            }
            const response = await fetch(path, options);
            const text = await response.text();
            prependTrace(method, path, response.status, text, jsonBody !== undefined ? JSON.stringify(jsonBody) : undefined);
        }

        document.querySelectorAll('[data-call]').forEach((button) => {
            button.addEventListener('click', () => {
                callApi(button.dataset.call, button.dataset.path);
            });
        });

        document.getElementById('auth-check-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            let policy;
            try {
                policy = JSON.parse(form.policy.value);
            } catch {
                prependTrace('POST', '/api/check-authorization', 0, 'Invalid policy JSON');
                return;
            }
            await callApi('POST', '/api/check-authorization', {
                requirement: form.requirement.value,
                policy,
            });
        });

        const composeForm = document.getElementById('compose-claim-form');
        if (composeForm) {
            composeForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const form = event.target;
                let segments;
                try {
                    segments = JSON.parse(form.segments.value);
                } catch {
                    prependTrace('POST', '/api/client-iam/compose-claim', 0, 'Invalid segments JSON');
                    return;
                }
                if (!segments || typeof segments !== 'object' || Array.isArray(segments)) {
                    prependTrace('POST', '/api/client-iam/compose-claim', 0, 'Segments must be a JSON object');
                    return;
                }
                await callApi('POST', '/api/client-iam/compose-claim', {
                    resource: form.resource.value,
                    segments,
                });
            });

            async function applyClientIamDefaults() {
                try {
                    const response = await fetch('/api/client-iam/service-format', { credentials: 'same-origin' });
                    const text = await response.text();
                    if (!response.ok) {
                        return;
                    }
                    const data = JSON.parse(text);
                    if (!data.compose_defaults) {
                        return;
                    }
                    composeForm.resource.value = data.compose_defaults.resource;
                    composeForm.segments.value = JSON.stringify(data.compose_defaults.segments, null, 2);
                } catch {
                    // Keep server-rendered placeholders when service format is unavailable.
                }
            }

            applyClientIamDefaults();
        }
    </script>
</body>
</html>
HTML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
