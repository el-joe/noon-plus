# Live broadcasts: native WebRTC (D18)

Live broadcasts are admin-only (ADR 11). Since owner decision D18 (2026-11-19) they are **native WebRTC, one-to-many from the host's browser**; LiveKit is gone. There is no media server: the admin studio keeps one `RTCPeerConnection` per viewer (send-only), and each viewer (website or customer app) has one receive-only connection to the host. Recorded channels are unchanged (files from `public` storage, `playback.media_url`).

## How it fits together

```
 admin studio (browser)  ── offer / ICE ─► POST admin media/{id}/webrtc/signal ─► Reverb private-live.{slug}.peer.{peer} ─► viewer
 admin studio (browser)  ◄─ Reverb private-live.{slug}.host ◄─ POST api live-streams/{slug}/signal ◄── ready / answer / ICE / leave ── viewer
 viewer ── POST api live-streams/{slug}/join → peer token, channel, ICE servers, socket
 media:  host browser ══ SRTP (P2P, or via TURN) ══► each viewer
```

- **Signalling is server-relayed** (no Reverb client events): the API checks who may send what and broadcasts `LiveSignal` (`ShouldBroadcastNow`, event name `signal`) to the right private channel. Payloads carry only SDP / ICE and random peer ids — no personal data.
- **Viewer slots** live in the cache (Redis) per country + stream. `POST join` refuses a viewer beyond the cap with `409 stream_full`; a slot without a heartbeat for 90 s frees up; `leave` frees it at once.
- **Peer tokens**: `v1.{payload}.{HMAC}` signed with a key derived from `APP_KEY`, bound to country + stream + peer, valid 10 minutes, renewed by the presence heartbeat (every 30 s). Guests use them to call `signal` and to authorise their own channel through `POST /api/v1/{market}/broadcasting/auth` (`peer_token` field). Rotating `APP_KEY` only ends running viewer sessions.
- **Host channel** `private-live.{slug}.host`: authorised through the panel session route `/broadcasting/auth` for users with `live_media.manage` only.
- **End of show**: `End show` in the studio (or the panel's end action) moves the stream to `ended`, sends `ended` to every viewer, frees all slots and broadcasts `status.changed` on the public `live-stream.{slug}` channel.

## Requirements

1. **Reverb** running and reachable from browsers and the app ([reverb.md](reverb.md)); `BROADCAST_CONNECTION=reverb`.
2. **Panels built with Reverb on**: `VITE_REVERB_ENABLED=true` and `VITE_REVERB_*` in `backend/.env`, then `npm run build` in `backend/`. Without it the studio says so and Go live is disabled.
3. **HTTPS on the admin panel** (browsers only allow camera / microphone in a secure context). Locally use `localhost`, or a browser flag for `http://admin.nawy.test:8000` (the Playwright project `live-webrtc` uses `--unsafely-treat-insecure-origin-as-secure`).
4. The customer app connects to Reverb with the `socket` block of the join answer (`NAWY_REVERB_PUBLIC_HOST/PORT/SCHEME`, defaulting to `REVERB_*`); the website uses `NEXT_PUBLIC_REVERB_*` and falls back to the same block.

## ICE: STUN and TURN

| Variable | Meaning |
|---|---|
| `NAWY_WEBRTC_STUN_URLS` | comma-separated STUN URLs; default `stun:stun.l.google.com:19302`; empty = none |
| `NAWY_WEBRTC_TURN_URL` | comma-separated TURN URLs, e.g. `turn:turn.nawy.com:3478?transport=udp,turns:turn.nawy.com:5349` |
| `NAWY_WEBRTC_TURN_SECRET` | coturn `static-auth-secret`: the API issues time-limited credentials (username `{expiry}:{peer}`, credential `base64(HMAC-SHA1(secret, username))`) |
| `NAWY_WEBRTC_TURN_TTL` | lifetime of those credentials in seconds (default 3600) |
| `NAWY_WEBRTC_TURN_USERNAME` / `NAWY_WEBRTC_TURN_CREDENTIAL` | static TURN credentials (used only when no secret is set) |

Without TURN, viewers behind symmetric NAT or strict firewalls (some mobile carriers, corporate networks) cannot connect and see "We couldn't connect…" with a retry button. Production should run TURN.

### coturn setup (Ubuntu)

```bash
sudo apt install coturn
sudo sed -i 's/#TURNSERVER_ENABLED=1/TURNSERVER_ENABLED=1/' /etc/default/coturn
```

`/etc/turnserver.conf`:

```
listening-port=3478
tls-listening-port=5349
fingerprint
use-auth-secret
static-auth-secret=<long random secret, same as NAWY_WEBRTC_TURN_SECRET>
realm=turn.nawy.com
cert=/etc/letsencrypt/live/turn.nawy.com/fullchain.pem
pkey=/etc/letsencrypt/live/turn.nawy.com/privkey.pem
min-port=49160
max-port=49200
no-multicast-peers
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
total-quota=200
```

Open UDP/TCP 3478, TCP 5349 and UDP 49160–49200 on the firewall, `systemctl enable --now coturn`, then set `NAWY_WEBRTC_TURN_URL=turn:turn.nawy.com:3478?transport=udp,turns:turn.nawy.com:5349` and `NAWY_WEBRTC_TURN_SECRET`. Check with <https://webrtc.github.io/samples/src/content/peerconnection/trickle-ice/> using a username / credential from a `POST live-streams/{slug}/join` answer.

## Capacity

Every viewer is a separate upload from the host's browser. Quality presets in the studio: 360p ≈ 0.5 Mbps, 540p ≈ 1 Mbps (default), 720p ≈ 2 Mbps per viewer (+ ~64 kbps audio). 20 viewers at 540p need about 21 Mbps of upload and a strong machine. The cap is the country setting **`live.webrtc_max_viewers`** (Admin → Content → Live & videos → "Live viewers per broadcast", default 20, max 200); the studio shows connected viewers, each viewer's state and the estimated upload. For larger audiences a media server (SFU) would be needed again — a new owner decision.

## Rate limits

`POST join` 30/min per IP and market; `POST signal` 300/min per peer (from the token, else IP); studio signal 600/min and heartbeat 60/min per admin; `broadcasting/auth` 60/min.
