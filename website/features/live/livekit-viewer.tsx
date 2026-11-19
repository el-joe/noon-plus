"use client";

import { Volume2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import { Room, RoomEvent, Track, type RemoteTrack } from "livekit-client";

/**
 * D16: browser viewer for a LiveKit room (livekit-client). Joins with the
 * viewer token from `GET live-streams/{slug}` (`playback.url` / `.token`,
 * subscribe-only), shows the host's camera and plays the audio once the
 * viewer allows it. Loaded with `next/dynamic` (`ssr: false`) from the
 * watch page only. `onFailed` hands the page back to its HLS / poster fallback
 * when the room cannot be joined or the connection drops.
 */
export default function LiveKitViewer({ url, token, poster, onFailed }: { url: string; token: string; poster?: string | null; onFailed: () => void }) {
  const t = useTranslations("live.viewer");
  const video = useRef<HTMLVideoElement>(null);
  const audio = useRef<HTMLAudioElement>(null);
  const [state, setState] = useState<"connecting" | "waiting" | "playing">("connecting");
  const [needsSound, setNeedsSound] = useState(false);
  const roomRef = useRef<Room | null>(null);
  const failed = useRef(onFailed);
  failed.current = onFailed;

  useEffect(() => {
    let stopped = false;
    const room = new Room({ adaptiveStream: true, dynacast: false });
    roomRef.current = room;

    const attach = (track: RemoteTrack) => {
      if (track.kind === Track.Kind.Video && video.current) {
        track.attach(video.current);
        setState("playing");
      } else if (track.kind === Track.Kind.Audio && audio.current) {
        track.attach(audio.current);
        setNeedsSound(!room.canPlaybackAudio);
      }
    };

    room
      .on(RoomEvent.TrackSubscribed, (track) => attach(track))
      .on(RoomEvent.TrackUnsubscribed, (track) => {
        track.detach();
        if (track.kind === Track.Kind.Video) setState("waiting");
      })
      .on(RoomEvent.AudioPlaybackStatusChanged, () => setNeedsSound(!room.canPlaybackAudio))
      .on(RoomEvent.Disconnected, () => {
        if (!stopped) failed.current();
      });

    room
      .connect(url, token, { autoSubscribe: true })
      .then(() => {
        if (stopped) return;
        setState("waiting");
        room.remoteParticipants.forEach((participant) =>
          participant.trackPublications.forEach((publication) => {
            if (publication.track) attach(publication.track);
          }),
        );
      })
      .catch(() => {
        if (!stopped) failed.current();
      });

    return () => {
      stopped = true;
      roomRef.current = null;
      void room.disconnect();
    };
  }, [url, token]);

  return (
    <div className="relative size-full bg-black">
      <video ref={video} poster={poster ?? undefined} playsInline autoPlay muted className="size-full object-contain" data-testid="livekit-video" />
      <audio ref={audio} autoPlay />
      {state !== "playing" ? (
        <p className="absolute inset-x-0 bottom-4 text-center text-body-sm text-white/90" role="status">
          {state === "connecting" ? t("connecting") : t("waitingVideo")}
        </p>
      ) : null}
      {needsSound ? (
        <button
          type="button"
          onClick={() => void roomRef.current?.startAudio().then(() => setNeedsSound(false))}
          className="absolute end-3 bottom-3 inline-flex h-9 items-center gap-2 rounded-full bg-surface px-3 text-label font-semibold text-ink"
        >
          <Volume2 className="size-4" /> {t("unmute")}
        </button>
      ) : null}
    </div>
  );
}
