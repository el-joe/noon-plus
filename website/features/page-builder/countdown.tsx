"use client";

import { Fragment, useEffect, useState } from "react";

function parts(ms: number) {
  const total = Math.max(0, Math.floor(ms / 1000));
  return [Math.floor(total / 3600), Math.floor((total % 3600) / 60), total % 60];
}

/**
 * Live countdown to a server `ends_at` (renders after mount to avoid hydration drift):
 * hours : minutes : seconds boxes (red boxes for `danger`, home "Mega deals").
 * Always left-to-right, also on Arabic pages, like a clock.
 */
export function Countdown({ endsAt, tone = "dark" }: { endsAt: string; tone?: "dark" | "danger" }) {
  const target = new Date(endsAt).getTime();
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    setNow(Date.now());
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  if (Number.isNaN(target)) return null;
  const [h, m, sec] = now === null ? [0, 0, 0] : parts(target - now);
  const cell = tone === "danger" ? "bg-danger text-white" : "bg-surface text-ink";
  return (
    <span className="inline-flex items-center gap-1 font-semibold tabular-nums" dir="ltr" role="timer" suppressHydrationWarning>
      {[h, m, sec].map((v, i) => (
        <Fragment key={i}>
          {i > 0 ? <span className={tone === "danger" ? "text-danger" : undefined}>:</span> : null}
          <span className={`min-w-7 rounded-sm px-1 py-0.5 text-center text-body-sm ${cell}`}>{now === null ? "--" : String(v).padStart(2, "0")}</span>
        </Fragment>
      ))}
    </span>
  );
}
