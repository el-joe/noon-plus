"use client";

import clsx from "clsx";
import { ChevronLeft, ChevronRight, Video } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";
import type { AdDetail } from "@/lib/api/classifieds";
import { AdImage } from "./ad-card";

/** Ad gallery: main photo / video with thumbnails (Figma 55:1477). */
export function AdGallery({ media, title }: { media: AdDetail["media"]; title: string }) {
  const t = useTranslations("classifieds");
  const [index, setIndex] = useState(0);
  const items = media.length > 0 ? media : null;
  const current = items?.[index];
  const go = (delta: number) => items && setIndex((i) => (i + delta + items.length) % items.length);

  return (
    <section className="flex flex-col gap-3" aria-label={t("gallery")}>
      <div className="relative aspect-[16/10] w-full overflow-hidden rounded-lg bg-subtle">
        {current?.type === "video" ? (
          <video src={current.url} controls playsInline preload="metadata" className="size-full bg-ink object-contain" />
        ) : (
          <AdImage src={current?.url} alt={title} />
        )}
        {items && items.length > 1 ? (
          <>
            <button type="button" onClick={() => go(-1)} aria-label={t("previous")} className="absolute start-3 top-1/2 -translate-y-1/2 rounded-full bg-surface/90 p-2 shadow-sm">
              <ChevronLeft className="size-5 rtl:rotate-180" />
            </button>
            <button type="button" onClick={() => go(1)} aria-label={t("next")} className="absolute end-3 top-1/2 -translate-y-1/2 rounded-full bg-surface/90 p-2 shadow-sm">
              <ChevronRight className="size-5 rtl:rotate-180" />
            </button>
            <span className="absolute bottom-3 end-3 rounded-xs bg-ink/70 px-2 py-0.5 text-caption text-white">
              {index + 1} / {items.length}
            </span>
          </>
        ) : null}
      </div>
      {items && items.length > 1 ? (
        <div className="scrollbar-none flex gap-2 overflow-x-auto">
          {items.map((m, i) => (
            <button
              key={m.id}
              type="button"
              onClick={() => setIndex(i)}
              className={clsx("relative h-16 w-24 shrink-0 overflow-hidden rounded-md border-2", i === index ? "border-brand" : "border-transparent")}
              aria-label={`${i + 1}`}
            >
              {m.type === "video" ? (
                <span className="flex size-full items-center justify-center bg-ink text-white">
                  <Video className="size-5" />
                </span>
              ) : (
                <AdImage src={m.url} alt="" />
              )}
            </button>
          ))}
        </div>
      ) : null}
    </section>
  );
}
