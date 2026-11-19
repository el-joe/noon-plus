import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { LiveJoin, ViewerSignal } from "@/lib/api/live";
import { LiveViewerSession, READY_RETRY_MS, stateForError, type HostMessage, type PeerLike, type ViewerDeps, type ViewerState } from "@/features/live/webrtc-session";

class FakeMediaStream {
  tracks: { kind: string }[] = [];
  getTracks() {
    return this.tracks;
  }
  addTrack(track: { kind: string }) {
    this.tracks.push(track);
  }
}

class FakePeer {
  listeners = new Map<string, ((event: unknown) => void)[]>();
  connectionState: RTCPeerConnectionState = "new";
  iceConnectionState: RTCIceConnectionState = "new";
  remote: RTCSessionDescriptionInit | null = null;
  local: RTCSessionDescriptionInit | null = null;
  candidates: RTCIceCandidateInit[] = [];
  closed = false;
  constructor(public config: RTCConfiguration) {}
  addEventListener(name: string, callback: (event: unknown) => void) {
    this.listeners.set(name, [...(this.listeners.get(name) ?? []), callback]);
  }
  emit(name: string, event: unknown = {}) {
    for (const callback of this.listeners.get(name) ?? []) callback(event);
  }
  async setRemoteDescription(description: RTCSessionDescriptionInit) {
    this.remote = description;
  }
  async createAnswer() {
    return { type: "answer" as const, sdp: "v=0 answer" };
  }
  async setLocalDescription(description: RTCSessionDescriptionInit) {
    this.local = description;
  }
  async addIceCandidate(candidate: RTCIceCandidateInit) {
    this.candidates.push(candidate);
  }
  addTransceiver() {
    return {} as RTCRtpTransceiver;
  }
  close() {
    this.closed = true;
  }
}

const join = (over: Partial<LiveJoin> = {}): LiveJoin => ({
  peer_id: "peer12345",
  peer_token: "v1.token.one",
  expires_at: null,
  channel: "private-live.eid.peer.peer12345",
  ice_servers: [{ urls: ["stun:stun.example:3478"] }],
  heartbeat_seconds: 30,
  max_viewers: 20,
  viewers: 1,
  host_online: true,
  socket: null,
  ...over,
});

function harness(joinAnswer: () => Promise<LiveJoin> = async () => join()) {
  const states: ViewerState[] = [];
  const signals: ViewerSignal[] = [];
  const tokens: string[] = [];
  const peers: FakePeer[] = [];
  let onMessage: (message: HostMessage) => void = () => {};
  let closedSubscriptions = 0;
  const deps: ViewerDeps = {
    join: vi.fn(joinAnswer),
    signal: vi.fn(async (token: string, message: ViewerSignal) => {
      tokens.push(token);
      signals.push(message);
    }),
    presence: vi.fn(async () => ({ peer_token: "v1.token.two" })),
    subscribe: vi.fn(async (_join, _token, handler) => {
      onMessage = handler;
      return { close: () => closedSubscriptions++ };
    }),
    createPeer: (config) => {
      const peer = new FakePeer(config);
      peers.push(peer);
      return peer as unknown as PeerLike;
    },
    onState: (state) => states.push(state),
    onStream: () => {},
  };
  const session = new LiveViewerSession(deps);
  return { session, deps, states, signals, tokens, peers, send: (message: HostMessage) => onMessage(message), closed: () => closedSubscriptions };
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal("MediaStream", FakeMediaStream);
});
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe("WebRTC viewer session (D18)", () => {
  it("joins, subscribes, says ready and answers the host's offer receive-only", async () => {
    const h = harness();
    await h.session.start();
    expect(h.signals).toEqual([{ type: "ready" }]);
    expect(h.states).toEqual(["connecting"]);

    // A host candidate overtaking the offer is buffered until the offer is set.
    await h.session.onMessage({ type: "candidate", candidate: { candidate: "candidate:early", sdpMid: "0", sdpMLineIndex: 0 } });
    await h.session.onMessage({ type: "offer", sdp: "v=0 offer" });
    const peer = h.peers[0];
    expect(peer.config.iceServers).toEqual([{ urls: ["stun:stun.example:3478"] }]);
    expect(peer.remote).toEqual({ type: "offer", sdp: "v=0 offer\r\n" });
    expect(peer.candidates).toEqual([{ candidate: "candidate:early", sdpMid: "0", sdpMLineIndex: 0 }]);
    expect(h.signals.at(-1)).toEqual({ type: "answer", sdp: "v=0 answer" });

    await h.session.onMessage({ type: "candidate", candidate: { candidate: "candidate:late", sdpMid: "0", sdpMLineIndex: 0 } });
    expect(peer.candidates).toHaveLength(2);

    peer.emit("icecandidate", { candidate: { candidate: "candidate:mine", sdpMid: "0", sdpMLineIndex: 0 } });
    expect(h.signals.at(-1)).toEqual({ type: "candidate", candidate: { candidate: "candidate:mine", sdpMid: "0", sdpMLineIndex: 0 } });

    peer.emit("track", { track: { kind: "video" } });
    expect(h.session.state).toBe("playing");
  });

  it("waits for the host and repeats ready until an offer comes", async () => {
    const h = harness(async () => join({ host_online: false }));
    await h.session.start();
    expect(h.session.state).toBe("waiting");
    await vi.advanceTimersByTimeAsync(READY_RETRY_MS * 2);
    expect(h.signals.filter((s) => s.type === "ready")).toHaveLength(3);
  });

  it("restarts when the studio comes back online and stops when the show ends", async () => {
    const h = harness();
    await h.session.start();
    await h.session.onMessage({ type: "offer", sdp: "v=0 offer" });
    await h.session.onMessage({ type: "online" });
    expect(h.peers[0].closed).toBe(true);
    expect(h.signals.at(-1)).toEqual({ type: "ready" });
    expect(h.session.state).toBe("connecting");

    await h.session.onMessage({ type: "ended" });
    expect(h.session.state).toBe("ended");
    expect(h.closed()).toBe(1);
  });

  it("renews the peer token with the heartbeat and uses the new one", async () => {
    const h = harness();
    await h.session.start();
    await vi.advanceTimersByTimeAsync(30_000);
    expect(h.session.peerToken).toBe("v1.token.two");
    await h.session.onMessage({ type: "offer", sdp: "v=0 offer" });
    expect(h.tokens.at(-1)).toBe("v1.token.two");
  });

  it("shows stream full / not live from the join answer", async () => {
    const full = harness(async () => Promise.reject(Object.assign(new Error("full"), { code: "stream_full" })));
    await full.session.start();
    expect(full.session.state).toBe("full");
    const off = harness(async () => Promise.reject(Object.assign(new Error("off"), { code: "stream_not_live" })));
    await off.session.start();
    expect(off.session.state).toBe("notLive");
    expect(stateForError(new Error("network"))).toBe("failed");
  });

  it("fails when ICE fails and retries in the same slot", async () => {
    const h = harness();
    await h.session.start();
    await h.session.onMessage({ type: "offer", sdp: "v=0 offer" });
    h.peers[0].connectionState = "failed";
    h.peers[0].emit("connectionstatechange");
    expect(h.session.state).toBe("failed");

    await h.session.start();
    expect(h.deps.join).toHaveBeenLastCalledWith({ peer_id: "peer12345", peer_token: "v1.token.one" });
  });

  it("gives the slot back on leave", async () => {
    const h = harness();
    await h.session.start();
    h.session.stop("ended", true);
    expect(h.signals.at(-1)).toEqual({ type: "leave" });
  });
});
