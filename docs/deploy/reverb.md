# Reverb (websockets)

Reverb broadcasts dispatch and live updates (e.g. `country.{code}.ops`, ADR 15) and carries the signalling of live broadcasts (native WebRTC, D18 — [webrtc.md](webrtc.md)).

- Process: `php artisan reverb:start --host=0.0.0.0 --port=8080` under the process manager; restart on deploy (`php artisan reverb:restart`).
- Proxy: nginx terminates TLS for `ws.nawy.com` and forwards `Upgrade`/`Connection` headers to `127.0.0.1:8080`. Clients use `REVERB_HOST=ws.nawy.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https`.
- Several Reverb nodes need `REVERB_SCALING_ENABLED=true` (Redis pub/sub).
- Raise the open-file limit (`ulimit -n 10000`) for the Reverb process.
- Channel authorisation is in `backend/routes/channels.php`; private channels require a signed-in user with the right membership.
- D16 clients: the panels (`backend/resources/js/echo.js`, `VITE_REVERB_ENABLED=true` + `VITE_REVERB_*`) join `country.{code}.ops` on the dispatch boards through the session route `/broadcasting/auth`; the website (`website/lib/realtime.ts`, `NEXT_PUBLIC_REVERB_*`) joins the public `live-stream.{slug}` and the private `App.Models.User.{id}` through `POST /api/v1/{market}/broadcasting/auth` (Sanctum), which the website's same-origin proxy calls with the httpOnly token.
- D18 live signalling: `private-live.{slug}.host` (admin studio, session auth, `live_media.manage`) and `private-live.{slug}.peer.{peer}` (one viewer, authorised by its signed peer token through `POST /api/v1/{market}/broadcasting/auth` — guests included). Signals are `ShouldBroadcastNow` (not queued) because they are latency bound; no client events are used. Media never passes through Reverb.
- Broadcasts go on the `broadcasts` queue (Horizon). A broadcast that cannot be sent is reported and never fails the request (`App\Shared\Broadcasting\Realtime`); clients without a socket keep polling.
