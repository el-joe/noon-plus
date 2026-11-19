"use client";

import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from "react";

/**
 * Floating media state shared across pages (Figma mini player 100:13112 /
 * 100:13436 / 100:13734). The watch page registers what it plays; when the
 * visitor navigates away, the mini-player continues from the same position.
 */
export type NowPlaying = {
  slug: string;
  title: string;
  /** Playable file (recorded streams); null for live broadcasts (WebRTC plays on the watch page only, D18). */
  src: string | null;
  mime?: string | null;
  poster?: string | null;
  live: boolean;
};

export type MiniMode = "expanded" | "collapsed" | "dismissed";

type PlayerState = {
  nowPlaying: NowPlaying | null;
  mode: MiniMode;
  setMode: (mode: MiniMode) => void;
  play: (item: NowPlaying) => void;
  /** Last known playback position (seconds) and paused flag, written by whichever player is visible. */
  position: React.RefObject<{ time: number; paused: boolean }>;
};

const PlayerContext = createContext<PlayerState | null>(null);

export function LivePlayerProvider({ children }: { children: ReactNode }) {
  const [nowPlaying, setNowPlaying] = useState<NowPlaying | null>(null);
  const [mode, setMode] = useState<MiniMode>("expanded");
  const position = useRef({ time: 0, paused: false });

  const play = useCallback((item: NowPlaying) => {
    setNowPlaying((current) => {
      if (current?.slug !== item.slug) position.current = { time: 0, paused: false };
      return item;
    });
    setMode("expanded");
  }, []);

  const value = useMemo(() => ({ nowPlaying, mode, setMode, play, position }), [nowPlaying, mode, play]);
  return <PlayerContext.Provider value={value}>{children}</PlayerContext.Provider>;
}

export function useLivePlayer(): PlayerState {
  const ctx = useContext(PlayerContext);
  if (!ctx) throw new Error("useLivePlayer() must be used inside <LivePlayerProvider>.");
  return ctx;
}
