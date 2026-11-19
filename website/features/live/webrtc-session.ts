import type { LiveJoin, ViewerSignal } from "@/lib/api/live";

/**
 * D18: the WebRTC viewer session (framework-free so it can be unit tested).
 *
 * One-to-many P2P from the host's browser: this viewer takes a slot
 * (`POST join` → peer token + private channel + ICE servers), subscribes to
 * its own channel `private-live.{slug}.peer.{peer}` (Pusher event `signal`),
 * says `ready`, answers the host's offer (receive only) and trickles ICE
 * candidates through `POST signal`. The heartbeat (`presence`) keeps the slot
 * and renews the token. `online` from the host (studio reopened) restarts the
 * connection; `ended` closes it.
 */

export type ViewerState = "connecting" | "waiting" | "playing" | "full" | "notLive" | "ended" | "failed";

export type HostMessage =
  | { type: "offer"; sdp: string }
  | { type: "candidate"; candidate: RTCIceCandidateInit }
  | { type: "online" }
  | { type: "ended" };

export type SignalSubscription = { close(): void };

/** The bits of RTCPeerConnection the session uses (a fake in tests). */
export type PeerLike = Pick<
  RTCPeerConnection,
  "setRemoteDescription" | "createAnswer" | "setLocalDescription" | "addIceCandidate" | "close" | "addTransceiver" | "addEventListener" | "connectionState" | "iceConnectionState"
>;

export type ViewerDeps = {
  join(resume?: { peer_id: string; peer_token: string }): Promise<LiveJoin>;
  signal(token: string, message: ViewerSignal): Promise<unknown>;
  presence(token: string): Promise<{ peer_token?: string }>;
  /** Subscribe to the peer channel; resolves once subscribed. `token()` gives the current peer token for (re)authorisation. */
  subscribe(join: LiveJoin, token: () => string, onMessage: (message: HostMessage) => void, onDrop: () => void): Promise<SignalSubscription>;
  createPeer(config: RTCConfiguration): PeerLike;
  onState(state: ViewerState): void;
  onStream(stream: MediaStream | null): void;
};

/** API error codes that end the attempt with a specific state. */
export function stateForError(error: unknown): ViewerState {
  const code = (error as { code?: string } | null)?.code;
  if (code === "stream_full") return "full";
  if (code === "stream_not_live") return "notLive";
  return "failed";
}

export const READY_RETRY_MS = 8_000;
export const DISCONNECT_GRACE_MS = 10_000;

export class LiveViewerSession {
  state: ViewerState = "connecting";
  private join: LiveJoin | null = null;
  private token = "";
  private peer: PeerLike | null = null;
  private remoteSet = false;
  private pending: RTCIceCandidateInit[] = [];
  private subscription: SignalSubscription | null = null;
  private readyTimer: ReturnType<typeof setInterval> | null = null;
  private heartbeat: ReturnType<typeof setInterval> | null = null;
  private dropTimer: ReturnType<typeof setTimeout> | null = null;
  private stopped = false;

  constructor(private deps: ViewerDeps) {}

  /** Join (or rejoin the same slot after a failure) and wait for the host's offer. */
  async start(): Promise<void> {
    this.stopped = false;
    this.set("connecting");
    try {
      const resume = this.join ? { peer_id: this.join.peer_id, peer_token: this.token } : undefined;
      let join: LiveJoin;
      try {
        join = await this.deps.join(resume);
      } catch (error) {
        // A stale slot: take a fresh one.
        if (!resume || stateForError(error) !== "failed") throw error;
        join = await this.deps.join();
      }
      if (this.stopped) return;
      this.join = join;
      this.token = join.peer_token;
      this.subscription?.close();
      this.subscription = await this.deps.subscribe(join, () => this.token, (message) => void this.onMessage(message), () => this.fail());
      if (this.stopped) return;
      this.startHeartbeat(join.heartbeat_seconds);
      await this.ready();
      if (!join.host_online && this.state === "connecting") this.set("waiting");
    } catch (error) {
      if (!this.stopped) this.stop(stateForError(error));
    }
  }

  /** Close everything; `leave` frees the slot at once. */
  stop(state: ViewerState = "ended", leave = false): void {
    this.stopped = true;
    if (leave && this.token) void this.deps.signal(this.token, { type: "leave" }).catch(() => {});
    this.clearTimers();
    this.closePeer();
    this.subscription?.close();
    this.subscription = null;
    this.set(state);
  }

  async onMessage(message: HostMessage): Promise<void> {
    if (this.stopped) return;
    if (message.type === "ended") {
      this.stop("ended");
      return;
    }
    if (message.type === "online") {
      this.closePeer();
      this.set("connecting");
      await this.ready();
      return;
    }
    if (message.type === "offer") {
      await this.answer(message.sdp);
      return;
    }
    if (message.type === "candidate" && message.candidate?.candidate) {
      if (this.peer && this.remoteSet) await this.peer.addIceCandidate(message.candidate).catch(() => {});
      else this.pending.push(message.candidate);
    }
  }

  private async ready(): Promise<void> {
    await this.deps.signal(this.token, { type: "ready" });
    if (this.readyTimer) clearInterval(this.readyTimer);
    // Until an offer arrives (host not publishing yet), keep saying ready.
    this.readyTimer = setInterval(() => {
      if (this.peer || this.stopped) return;
      if (this.state === "connecting") this.set("waiting");
      void this.deps.signal(this.token, { type: "ready" }).catch((error) => this.handleSignalError(error));
    }, READY_RETRY_MS);
  }

  private async answer(sdp: string): Promise<void> {
    // Host candidates may overtake the offer (separate relayed requests): keep them.
    const early = this.peer ? [] : this.pending;
    this.closePeer();
    this.pending = early;
    const peer = this.deps.createPeer({ iceServers: this.join?.ice_servers ?? [] });
    this.peer = peer;
    const stream = new MediaStream();
    peer.addEventListener("track", (event) => {
      const track = (event as RTCTrackEvent).track;
      if (!stream.getTracks().includes(track)) stream.addTrack(track);
      this.deps.onStream(stream);
      if (track.kind === "video" || this.state !== "playing") this.set("playing");
    });
    peer.addEventListener("icecandidate", (event) => {
      const candidate = (event as RTCPeerConnectionIceEvent).candidate;
      if (!candidate || !candidate.candidate) return;
      void this.deps.signal(this.token, { type: "candidate", candidate: { candidate: candidate.candidate, sdpMid: candidate.sdpMid ?? null, sdpMLineIndex: candidate.sdpMLineIndex ?? null } });
    });
    peer.addEventListener("connectionstatechange", () => this.onConnection(peer));
    peer.addEventListener("iceconnectionstatechange", () => this.onConnection(peer));

    try {
      // A description must end with CRLF; a proxy or input trimming may have dropped it.
      await peer.setRemoteDescription({ type: "offer", sdp: sdp.endsWith("\n") ? sdp : `${sdp}\r\n` });
      this.remoteSet = true;
      for (const candidate of this.pending.splice(0)) await peer.addIceCandidate(candidate).catch(() => {});
      const answer = await peer.createAnswer();
      await peer.setLocalDescription(answer);
      await this.deps.signal(this.token, { type: "answer", sdp: answer.sdp ?? "" });
    } catch (error) {
      this.handleSignalError(error);
    }
  }

  private onConnection(peer: PeerLike): void {
    if (peer !== this.peer || this.stopped) return;
    const state = peer.connectionState ?? peer.iceConnectionState;
    if (state === "failed") {
      this.fail();
    } else if (state === "disconnected") {
      if (!this.dropTimer) this.dropTimer = setTimeout(() => this.fail(), DISCONNECT_GRACE_MS);
    } else if (state === "connected" && this.dropTimer) {
      clearTimeout(this.dropTimer);
      this.dropTimer = null;
    }
  }

  private handleSignalError(error: unknown): void {
    const state = stateForError(error);
    if (state !== "failed") this.stop(state);
    else this.fail();
  }

  private fail(): void {
    if (!this.stopped) this.stop("failed");
  }

  private startHeartbeat(seconds: number): void {
    if (this.heartbeat) clearInterval(this.heartbeat);
    this.heartbeat = setInterval(async () => {
      try {
        const answer = await this.deps.presence(this.token);
        if (answer.peer_token) this.token = answer.peer_token;
      } catch {
        // Offline or throttled: the next beat tries again.
      }
    }, Math.min(300, Math.max(5, seconds || 30)) * 1000);
  }

  private closePeer(): void {
    if (this.dropTimer) clearTimeout(this.dropTimer);
    this.dropTimer = null;
    this.peer?.close();
    this.peer = null;
    this.remoteSet = false;
    this.pending = [];
    this.deps.onStream(null);
  }

  private clearTimers(): void {
    if (this.readyTimer) clearInterval(this.readyTimer);
    if (this.heartbeat) clearInterval(this.heartbeat);
    this.readyTimer = null;
    this.heartbeat = null;
  }

  private set(state: ViewerState): void {
    this.state = state;
    this.deps.onState(state);
  }

  /** The current peer token (tests / leave beacons). */
  get peerToken(): string {
    return this.token;
  }
}
