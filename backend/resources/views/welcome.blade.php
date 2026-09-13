<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🏛️ SEC EDGAR Real-Time News Feed</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            margin-top: 0;
            color: #0f172a;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 4px;
            margin-bottom: 16px;
            background-color: #e0f2fe;
            color: #0369a1;
        }
        .filing-item {
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 0;
        }
        .filing-item:last-child {
            border-bottom: none;
        }
        .filing-item h3 {
            margin: 0 0 6px 0;
            font-size: 16px;
        }
        .filing-item a {
            color: #2563eb;
            text-decoration: none;
        }
        .filing-item a:hover {
            text-decoration: underline;
        }
        .filing-item small {
            color: #64748b;
        }
        .loading {
            color: #64748b;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏛️ SEC EDGAR Real-Time News Feed</h1>
        <div id="status" class="status-badge">Connecting to Live Feed...</div>
        <div id="feed-container">
            <div class="loading">Loading initial filings...</div>
        </div>
    </div>

    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script>
        const feedContainer = document.getElementById('feed-container');
        const statusBadge = document.getElementById('status');
        let knownIds = new Set();

        function renderFilings(filings, prepend = false) {
            if (!filings || filings.length === 0) {
                if (knownIds.size === 0) {
                    feedContainer.innerHTML = '<div class="loading">No filings found currently.</div>';
                }
                return;
            }

            const html = filings.map(item => {
                const filingId = item.id || item.accessionNumber || (item.link + item.updated);
                if (knownIds.has(filingId)) return '';
                knownIds.add(filingId);

                const dateStr = item.updated ? new Date(item.updated * 1000).toLocaleString() : 'N/A';
                return `
                    <div class="filing-item">
                        <h3><a href="${item.link || '#'}" target="_blank" rel="noopener noreferrer">${item.title || 'Untitled Filing'}</a></h3>
                        <small>Filed at: ${dateStr}</small>
                    </div>
                `;
            }).join('');

            if (html.trim() !== '') {
                if (prepend) {
                    feedContainer.insertAdjacentHTML('afterbegin', html);
                } else {
                    if (knownIds.size === filings.length) {
                        feedContainer.innerHTML = html;
                    } else {
                        feedContainer.insertAdjacentHTML('beforeend', html);
                    }
                }
            }
        }

        // Fetch initial feed from API
        fetch('/api/edgar-feed')
            .then(res => res.json())
            .then(data => {
                feedContainer.innerHTML = '';
                renderFilings(data, false);
                statusBadge.textContent = '● Live Feed Connected';
                statusBadge.style.backgroundColor = '#dcfce7';
                statusBadge.style.color = '#15803d';
            })
            .catch(err => {
                console.error('Failed to fetch initial feed:', err);
                statusBadge.textContent = '⚠️ Error loading feed';
                statusBadge.style.backgroundColor = '#fee2e2';
                statusBadge.style.color = '#b91c1c';
            });

        // Initialize WebSocket connection via Reverb/Pusher
        try {
            const reverbKey = "{{ config('reverb.apps.apps.0.key', 'capital_raise_reverb_key') }}";
            const reverbHost = "{{ config('reverb.apps.apps.0.options.host', 'localhost') }}";
            const reverbPort = {{ config('reverb.apps.apps.0.options.port', 8080) }};
            const reverbScheme = "{{ config('reverb.apps.apps.0.options.scheme', 'http') }}";
            const forceTLS = reverbScheme === 'https';

            const pusher = new Pusher(reverbKey, {
                wsHost: window.location.hostname || reverbHost,
                wsPort: reverbPort,
                wssPort: reverbPort,
                forceTLS: forceTLS,
                enabledTransports: ['ws', 'wss'],
                cluster: 'mt1'
            });

            const channel = pusher.subscribe('edgar-stream');
            channel.bind('.new-filings', function(data) {
                const filings = Array.isArray(data) ? data : (data?.filings || []);
                if (filings.length > 0) {
                    renderFilings(filings, true);
                }
            });
        } catch (e) {
            console.warn('WebSocket init warning:', e);
        }
    </script>
</body>
</html>
