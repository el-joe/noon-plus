/**
 * D18: the admin live studio (Admin → Content → Live & videos → a live
 * broadcast). Native WebRTC, one-to-many from this browser: the host
 * publishes camera + mic (or a screen share) and keeps one
 * RTCPeerConnection per viewer (send-only). No media server.
 *
 * Signalling is relayed by the API over Reverb:
 * - viewers' messages (`ready`, `answer`, `candidate`, `leave`) arrive on the
 *   private channel `live.{slug}.host` (event `signal`, with the `peer` id);
 * - offers / ICE candidates for a viewer are POSTed to
 *   `media/{stream}/webrtc/signal` and relayed to that viewer's channel;
 * - `media/{stream}/webrtc/online` is the heartbeat (every 15 s) and, with
 *   `restart`, tells connected viewers to start over (studio (re)opened or
 *   the socket reconnected).
 *
 * The page element `[data-live-studio]` holds the config as JSON
 * (`ice_servers`, routes, `max_viewers`, labels).
 */

const PRESETS = {
    low: { width: 640, height: 360, frameRate: 24, bitrate: 500_000 },
    medium: { width: 960, height: 540, frameRate: 30, bitrate: 1_000_000 },
    high: { width: 1280, height: 720, frameRate: 30, bitrate: 2_000_000 },
};
const AUDIO_BITRATE = 64_000;
const HEARTBEAT_MS = 15_000;

export async function initLiveStudio() {
    const root = document.querySelector('[data-live-studio]');

    if (!root) {
        return;
    }

    const config = JSON.parse(root.querySelector('script[type="application/json"]')?.textContent ?? '{}');
    const studio = new LiveStudio(root, config);
    await studio.start();
    window.__liveStudio = studio;
}

class LiveStudio {
    constructor(root, config) {
        this.root = root;
        this.config = config;
        this.labels = config.labels ?? {};
        this.status = config.status;
        this.audioOnly = config.media_mode === 'audio';
        this.preset = 'medium';
        this.peers = new Map();
        this.camera = null;
        this.screen = null;
        this.muted = false;
        this.echo = null;
        this.heartbeat = null;
        this.el = (name) => root.querySelector(`[data-studio="${name}"]`);
    }

    async start() {
        this.el('preset').value = this.preset;
        this.bind();
        this.render();

        // Camera, mic and WebRTC need a secure context (HTTPS, or localhost in development).
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || typeof RTCPeerConnection === 'undefined') {
            this.say(this.labels.insecure, true);
            this.el('golive')?.setAttribute('disabled', 'disabled');
            this.el('camera-start')?.setAttribute('disabled', 'disabled');

            return;
        }

        const { createEcho } = await import('./echo');
        this.echo = createEcho();

        if (!this.echo) {
            this.say(this.labels.noRealtime, true);
            this.el('golive')?.setAttribute('disabled', 'disabled');

            return;
        }

        this.echo.private(this.config.channel)
            .subscribed(() => {
                if (this.status === 'live') {
                    void this.online(true);
                }
            })
            .error(() => this.say(this.labels.socketError, true))
            .listen('.signal', (message) => void this.onSignal(message));

        // A reconnect re-subscribes the channel (`subscribed` again → restart above).
        this.echo.connector.pusher.connection.bind('state_change', ({ current }) => {
            if (current === 'unavailable' || current === 'failed') {
                this.say(this.labels.socketError, true);
            }
        });

        if (this.status === 'live') {
            // Studio reopened while live: resume the camera without another click where the browser allows it.
            await this.startCamera().catch(() => this.say(this.labels.cameraNeeded, true));
            this.startHeartbeat();
        }
    }

    bind() {
        this.el('camera-start')?.addEventListener('click', () => void this.startCamera().catch((error) => this.say(`${this.labels.cameraError} ${error?.message ?? ''}`, true)));
        this.el('golive')?.addEventListener('click', () => void this.goLive());
        this.el('end')?.addEventListener('click', () => void this.end());
        this.el('mute')?.addEventListener('click', () => this.toggleMute());
        this.el('screen')?.addEventListener('click', () => void this.toggleScreen());
        this.el('preset')?.addEventListener('change', (event) => void this.setPreset(event.target.value));
        this.el('video-device')?.addEventListener('change', () => void this.startCamera());
        this.el('audio-device')?.addEventListener('change', () => void this.startCamera());
        window.addEventListener('pagehide', () => this.closeAll());
    }

    /** Camera + mic (re)start with the chosen devices and preset; live peers get the new tracks. */
    async startCamera() {
        const preset = PRESETS[this.preset];
        const videoId = this.el('video-device')?.value;
        const audioId = this.el('audio-device')?.value;
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: audioId ? { deviceId: { exact: audioId } } : true,
            video: this.audioOnly ? false : {
                ...(videoId ? { deviceId: { exact: videoId } } : {}),
                width: { ideal: preset.width },
                height: { ideal: preset.height },
                frameRate: { ideal: preset.frameRate },
            },
        });
        this.camera?.getTracks().forEach((track) => track.stop());
        this.camera = stream;
        stream.getAudioTracks().forEach((track) => {
            track.enabled = !this.muted;
        });
        await this.listDevices();
        this.showPreview();
        this.replaceTracks();
        this.say(this.status === 'live' ? this.labels.live : this.labels.ready);
        this.render();
    }

    async listDevices() {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const fill = (select, kind, current) => {
            if (!select) {
                return;
            }

            const chosen = select.value || current;
            select.replaceChildren(...devices.filter((device) => device.kind === kind).map((device, index) => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.textContent = device.label || `${kind} ${index + 1}`;
                option.selected = device.deviceId === chosen;

                return option;
            }));
        };
        fill(this.el('video-device'), 'videoinput', this.camera?.getVideoTracks()[0]?.getSettings().deviceId);
        fill(this.el('audio-device'), 'audioinput', this.camera?.getAudioTracks()[0]?.getSettings().deviceId);
    }

    /** The video track viewers get: the screen share when on, else the camera. */
    videoTrack() {
        return this.screen?.getVideoTracks()[0] ?? this.camera?.getVideoTracks()[0] ?? null;
    }

    audioTrack() {
        return this.camera?.getAudioTracks()[0] ?? null;
    }

    showPreview() {
        const video = this.el('preview');

        if (!video) {
            return;
        }

        const preview = new MediaStream([this.videoTrack()].filter(Boolean));
        video.srcObject = preview;
        void video.play().catch(() => {});
    }

    replaceTracks() {
        for (const { pc } of this.peers.values()) {
            for (const sender of pc.getSenders()) {
                const track = sender.track?.kind === 'audio' || (!sender.track && this.audioOnly) ? this.audioTrack() : this.videoTrack();

                if (track && sender.track !== track) {
                    void sender.replaceTrack(track);
                }
            }

            void this.applyBitrate(pc);
        }
    }

    async toggleScreen() {
        if (this.screen) {
            this.screen.getTracks().forEach((track) => track.stop());
            this.screen = null;
        } else {
            try {
                this.screen = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { ideal: 15 } }, audio: false });
                this.screen.getVideoTracks()[0]?.addEventListener('ended', () => {
                    this.screen = null;
                    this.showPreview();
                    this.replaceTracks();
                    this.render();
                });
            } catch {
                return;
            }
        }

        this.showPreview();
        this.replaceTracks();
        this.render();
    }

    toggleMute() {
        this.muted = !this.muted;
        this.camera?.getAudioTracks().forEach((track) => {
            track.enabled = !this.muted;
        });
        this.render();
    }

    async setPreset(name) {
        this.preset = PRESETS[name] ? name : 'medium';
        const preset = PRESETS[this.preset];
        await this.camera?.getVideoTracks()[0]?.applyConstraints({ width: { ideal: preset.width }, height: { ideal: preset.height }, frameRate: { ideal: preset.frameRate } }).catch(() => {});

        for (const { pc } of this.peers.values()) {
            await this.applyBitrate(pc);
        }

        this.render();
    }

    async applyBitrate(pc) {
        for (const sender of pc.getSenders()) {
            if (sender.track?.kind !== 'video') {
                continue;
            }

            const parameters = sender.getParameters();
            parameters.encodings = parameters.encodings?.length ? parameters.encodings : [{}];
            parameters.encodings[0].maxBitrate = PRESETS[this.preset].bitrate;
            await sender.setParameters(parameters).catch(() => {});
        }
    }

    async goLive() {
        if (!this.camera) {
            try {
                await this.startCamera();
            } catch (error) {
                this.say(`${this.labels.cameraError} ${error?.message ?? ''}`, true);

                return;
            }
        }

        this.el('golive')?.setAttribute('disabled', 'disabled');
        const answer = await this.post(this.config.routes.live, {});

        if (!answer.ok) {
            this.el('golive')?.removeAttribute('disabled');
            this.say(answer.body?.message ?? this.labels.failed, true);

            return;
        }

        this.status = 'live';
        this.say(this.labels.live);
        this.render();
        await this.online(true);
        this.startHeartbeat();
    }

    async end() {
        if (!window.confirm(this.labels.confirmEnd)) {
            return;
        }

        const answer = await this.post(this.config.routes.end, {});

        if (!answer.ok) {
            this.say(answer.body?.message ?? this.labels.failed, true);

            return;
        }

        this.status = 'ended';
        this.closeAll();
        this.say(this.labels.ended);
        window.location.reload();
    }

    closeAll() {
        window.clearInterval(this.heartbeat);

        for (const peer of this.peers.keys()) {
            this.closePeer(peer);
        }

        if (this.status === 'ended') {
            this.camera?.getTracks().forEach((track) => track.stop());
            this.screen?.getTracks().forEach((track) => track.stop());
        }
    }

    startHeartbeat() {
        window.clearInterval(this.heartbeat);
        this.heartbeat = window.setInterval(() => void this.online(false), HEARTBEAT_MS);
    }

    /** Heartbeat; the answer lists the viewer slots, peers that left are closed. */
    async online(restart) {
        const answer = await this.post(this.config.routes.online, { restart });

        if (!answer.ok) {
            return;
        }

        const slots = new Set((answer.body.peers ?? []).map((peer) => peer.id));

        if (restart) {
            for (const peer of this.peers.keys()) {
                this.closePeer(peer);
            }
        }

        for (const peer of this.peers.keys()) {
            if (!slots.has(peer)) {
                this.closePeer(peer);
            }
        }

        this.slots = answer.body.viewers ?? slots.size;
        this.render();
    }

    async onSignal(message) {
        const { peer, type } = message ?? {};

        if (!peer || this.status !== 'live') {
            return;
        }

        if (type === 'ready') {
            await this.offer(peer);
        } else if (type === 'answer') {
            const entry = this.peers.get(peer);

            if (!entry) {
                return;
            }

            await entry.pc.setRemoteDescription({ type: 'answer', sdp: message.sdp?.endsWith('\n') ? message.sdp : `${message.sdp}\r\n` });
            entry.remote = true;

            for (const candidate of entry.pending.splice(0)) {
                await entry.pc.addIceCandidate(candidate).catch(() => {});
            }
        } else if (type === 'candidate') {
            const entry = this.peers.get(peer);

            if (!entry || !message.candidate?.candidate) {
                return;
            }

            if (entry.remote) {
                await entry.pc.addIceCandidate(message.candidate).catch(() => {});
            } else {
                entry.pending.push(message.candidate);
            }
        } else if (type === 'leave') {
            this.closePeer(peer);
        }

        this.render();
    }

    /** A viewer is ready: a fresh send-only connection and our offer. */
    async offer(peer) {
        if (!this.camera) {
            return;
        }

        this.closePeer(peer);
        const pc = new RTCPeerConnection({ iceServers: this.config.ice_servers ?? [] });
        const entry = { pc, state: 'connecting', pending: [], remote: false, since: Date.now() };
        this.peers.set(peer, entry);

        const outgoing = new MediaStream([this.videoTrack(), this.audioTrack()].filter(Boolean));
        outgoing.getTracks().forEach((track) => pc.addTransceiver(track, { direction: 'sendonly', streams: [outgoing] }));

        pc.addEventListener('icecandidate', ({ candidate }) => {
            if (candidate) {
                void this.post(this.config.routes.signal, { peer, type: 'candidate', candidate: candidate.toJSON() });
            }
        });
        pc.addEventListener('connectionstatechange', () => {
            entry.state = pc.connectionState;

            if (pc.connectionState === 'connected') {
                void this.applyBitrate(pc);
            }

            if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
                this.closePeer(peer);
            }

            this.render();
        });

        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        const answer = await this.post(this.config.routes.signal, { peer, type: 'offer', sdp: offer.sdp });

        if (!answer.ok) {
            this.closePeer(peer);
        }

        this.render();
    }

    closePeer(peer) {
        const entry = this.peers.get(peer);

        if (entry) {
            entry.pc.close();
            this.peers.delete(peer);
        }
    }

    async post(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            return { ok: response.ok, body: await response.json().catch(() => ({})) };
        } catch {
            return { ok: false, body: {} };
        }
    }

    toggle(name, visible) {
        const element = this.el(name);

        if (element) {
            element.hidden = !visible;
        }
    }

    say(text, warning = false) {
        const line = this.el('status');

        if (line && text) {
            line.textContent = text;
            line.classList.toggle('text-danger', warning);
        }
    }

    render() {
        const connected = [...this.peers.values()].filter((entry) => entry.state === 'connected').length;
        const live = this.status === 'live';
        const kbps = Math.round((connected * ((this.audioOnly ? 0 : PRESETS[this.preset].bitrate) + AUDIO_BITRATE)) / 1000);

        this.toggle('golive', !live && this.status !== 'ended');
        this.toggle('end', live);
        this.toggle('camera-start', !this.camera);
        this.toggle('screen', !this.audioOnly && Boolean(navigator.mediaDevices?.getDisplayMedia));

        const mute = this.el('mute');

        if (mute) {
            mute.textContent = this.muted ? this.labels.unmute : this.labels.mute;
            mute.setAttribute('aria-pressed', String(this.muted));
        }

        const screen = this.el('screen');

        if (screen) {
            screen.textContent = this.screen ? this.labels.stopShare : this.labels.share;
        }

        const count = this.el('count');

        if (count) {
            count.textContent = `${connected} / ${this.config.max_viewers}`;
        }

        const upload = this.el('upload');

        if (upload) {
            upload.textContent = kbps >= 1000 ? `${(kbps / 1000).toFixed(1)} Mbps` : `${kbps} kbps`;
        }

        const list = this.el('viewers');

        if (list) {
            const rows = [...this.peers.entries()].map(([peer, entry]) => {
                const item = document.createElement('li');
                item.className = 'flex justify-between gap-2 font-mono';
                item.dataset.peerState = entry.state;
                item.textContent = `${peer.slice(0, 8)} · ${this.labels.states?.[entry.state] ?? entry.state}`;

                return item;
            });

            if (rows.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'text-fg-secondary';
                empty.textContent = this.labels.noViewers;
                rows.push(empty);
            }

            list.replaceChildren(...rows);
        }

        this.root.dataset.studioState = live ? 'live' : this.status;
        this.root.dataset.connectedViewers = String(connected);
    }
}
