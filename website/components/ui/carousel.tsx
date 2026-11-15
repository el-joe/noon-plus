"use client";

import clsx from "clsx";
import useEmblaCarousel from "embla-carousel-react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { Children, useCallback, useEffect, useState, type ReactNode } from "react";
import { useMarket } from "@/lib/market-context";

/**
 * Horizontal rail on embla (page-builder responsive rule: carousels become
 * swipe rows; RTL mirrors). `itemClass` sets the slide width per breakpoint.
 */
export function Rail({
  children,
  itemClass = "basis-[43%] sm:basis-1/3 md:basis-1/4 lg:basis-1/6",
  gap = "gap-3",
  autoplayMs,
  dots = false,
  loop = false,
}: {
  children: ReactNode;
  itemClass?: string;
  gap?: string;
  autoplayMs?: number;
  dots?: boolean;
  loop?: boolean;
}) {
  const market = useMarket();
  const [ref, api] = useEmblaCarousel({ align: "start", direction: market.dir, loop, dragFree: !loop, containScroll: "trimSnaps" });
  const [canPrev, setCanPrev] = useState(false);
  const [canNext, setCanNext] = useState(false);
  const [selected, setSelected] = useState(0);
  const [snaps, setSnaps] = useState<number[]>([]);

  const sync = useCallback(() => {
    if (!api) return;
    setCanPrev(api.canScrollPrev());
    setCanNext(api.canScrollNext());
    setSelected(api.selectedScrollSnap());
    setSnaps(api.scrollSnapList());
  }, [api]);

  useEffect(() => {
    if (!api) return;
    sync();
    api.on("select", sync).on("reInit", sync);
    return () => {
      api.off("select", sync).off("reInit", sync);
    };
  }, [api, sync]);

  useEffect(() => {
    if (!api || !autoplayMs) return;
    const timer = window.setInterval(() => (api.canScrollNext() ? api.scrollNext() : api.scrollTo(0)), autoplayMs);
    return () => window.clearInterval(timer);
  }, [api, autoplayMs]);

  const Prev = market.dir === "rtl" ? ChevronRight : ChevronLeft;
  const Next = market.dir === "rtl" ? ChevronLeft : ChevronRight;

  return (
    <div className="relative">
      <div className="overflow-hidden" ref={ref}>
        <div className={clsx("flex", gap)}>
          {Children.map(children, (child) => (
            <div className={clsx("min-w-0 shrink-0 grow-0", itemClass)}>{child}</div>
          ))}
        </div>
      </div>
      {canPrev ? (
        <button type="button" onClick={() => api?.scrollPrev()} aria-label="Previous" className="absolute start-1 top-1/2 hidden size-9 -translate-y-1/2 items-center justify-center rounded-full bg-surface shadow-sm md:flex">
          <Prev className="size-5" />
        </button>
      ) : null}
      {canNext ? (
        <button type="button" onClick={() => api?.scrollNext()} aria-label="Next" className="absolute end-1 top-1/2 hidden size-9 -translate-y-1/2 items-center justify-center rounded-full bg-surface shadow-sm md:flex">
          <Next className="size-5" />
        </button>
      ) : null}
      {dots && snaps.length > 1 ? (
        <div className="mt-3 flex justify-center gap-1.5">
          {snaps.map((_, i) => (
            <button
              key={i}
              type="button"
              onClick={() => api?.scrollTo(i)}
              aria-label={`${i + 1}`}
              className={clsx("h-1.5 rounded-full transition-all", i === selected ? "w-5 bg-ink" : "w-1.5 bg-line-strong")}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}
