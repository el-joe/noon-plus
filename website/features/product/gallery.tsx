"use client";

import clsx from "clsx";
import useEmblaCarousel from "embla-carousel-react";
import { ChevronLeft, ChevronRight, X, ZoomIn } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent } from "react";
import { WishlistButton } from "@/components/product/product-card";
import { ProductImage } from "@/components/ui/misc";
import type { ProductImageData } from "@/lib/api/schemas";
import { useMarket } from "@/lib/market-context";
import { gallerySlides, keyStep, slideForSku } from "./gallery-model";
import { useSelectedOffer } from "./offer-context";

/**
 * PDP gallery (Figma 24:2 desktop: vertical thumbnail rail + main image with
 * the wishlist heart; 85:445 mobile: full-width swipe slider with dots).
 * Main image is an embla slider (touch swipe, RTL-aware), thumbnails switch
 * on click/hover, prev/next arrows and keyboard arrows step, hovering zooms
 * (desktop), clicking opens a lightbox. Picking a variant in the buy box
 * jumps to that variant's picture.
 */
export function ProductGallery({ images, title, slug }: { images: ProductImageData[]; title: string; slug: string }) {
  const t = useTranslations("product");
  const tc = useTranslations("common");
  const market = useMarket();
  const locale = useLocale() as "en" | "ar";
  const slides = useMemo(() => gallerySlides(images, locale), [images, locale]);
  const many = slides.length > 1;
  const [ref, api] = useEmblaCarousel({
    direction: market.dir,
    loop: false,
    // Mouse drags would fight the hover zoom; touch and pen still swipe.
    watchDrag: (_api, event) => (event as PointerEvent).pointerType !== "mouse",
  });
  const [index, setIndex] = useState(0);
  const [zoom, setZoom] = useState<{ x: number; y: number } | null>(null);
  const [lightbox, setLightbox] = useState(false);
  const thumbs = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!api) return;
    const sync = () => setIndex(api.selectedScrollSnap());
    sync();
    api.on("select", sync).on("reInit", sync);
    return () => {
      api.off("select", sync).off("reInit", sync);
    };
  }, [api]);

  const go = useCallback(
    (i: number, jump = false) => {
      const next = Math.max(0, Math.min(slides.length - 1, i));
      setIndex(next);
      api?.scrollTo(next, jump);
    },
    [api, slides.length],
  );

  // Keep the active thumbnail in view inside the rail.
  useEffect(() => {
    const el = thumbs.current?.querySelector<HTMLElement>(`[data-thumb="${index}"]`);
    el?.scrollIntoView({ block: "nearest", inline: "nearest" });
  }, [index]);

  // Variant change in the buy box → that variant's picture.
  const sku = useSelectedOffer()?.offer?.sku ?? null;
  useEffect(() => {
    const target = slideForSku(slides, sku);
    if (target !== null) go(target);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sku]);

  const onKey = (e: KeyboardEvent) => {
    const step = keyStep(e.key, market.dir);
    if (step === null || !many) return;
    e.preventDefault();
    go(index + step);
  };

  const onMove = (e: MouseEvent<HTMLDivElement>) => {
    const box = e.currentTarget.getBoundingClientRect();
    setZoom({ x: ((e.clientX - box.left) / box.width) * 100, y: ((e.clientY - box.top) / box.height) * 100 });
  };

  const Prev = market.dir === "rtl" ? ChevronRight : ChevronLeft;
  const Next = market.dir === "rtl" ? ChevronLeft : ChevronRight;

  return (
    <section className="flex min-w-0 flex-col-reverse gap-3 md:flex-row md:items-start" aria-label={t("gallery")} aria-roledescription="carousel" data-testid="pdp-gallery">
      {many ? (
        <div ref={thumbs} className="scrollbar-none hidden shrink-0 gap-2 md:flex md:max-h-[520px] md:flex-col md:overflow-y-auto" role="group" aria-label={t("gallery")}>
          {slides.map((slide, i) => (
            <button
              key={slide.key}
              data-thumb={i}
              type="button"
              onClick={() => go(i)}
              onMouseEnter={() => go(i, true)}
              aria-label={t("imageN", { n: i + 1 })}
              aria-current={i === index ? "true" : undefined}
              className={clsx("size-16 shrink-0 overflow-hidden rounded-md border-2 bg-surface p-1 transition-colors", i === index ? "border-brand" : "border-line hover:border-line-strong")}
            >
              <ProductImage src={slide.url} alt="" />
            </button>
          ))}
        </div>
      ) : null}

      <div className="relative min-w-0 flex-1 self-start">
        <div
          className="overflow-hidden rounded-lg border border-line bg-surface outline-none focus-visible:ring-2 focus-visible:ring-brand"
          ref={ref}
          tabIndex={0}
          onKeyDown={onKey}
          aria-label={t("gallery")}
        >
          <div className="flex touch-pan-y">
            {slides.length > 0 ? (
              slides.map((slide, i) => (
                <div
                  key={slide.key}
                  className="relative aspect-square min-w-0 shrink-0 grow-0 basis-full cursor-zoom-in overflow-hidden"
                  role="group"
                  aria-roledescription="slide"
                  aria-label={t("imageN", { n: i + 1 })}
                  aria-hidden={i !== index}
                  onMouseMove={i === index ? onMove : undefined}
                  onMouseLeave={() => setZoom(null)}
                  onClick={() => setLightbox(true)}
                >
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={slide.url}
                    alt={slide.alt || title}
                    loading={i === 0 ? "eager" : "lazy"}
                    draggable={false}
                    className="size-full select-none object-contain p-6 transition-transform duration-150 ease-out"
                    style={i === index && zoom ? { transform: "scale(2)", transformOrigin: `${zoom.x}% ${zoom.y}%` } : undefined}
                  />
                </div>
              ))
            ) : (
              <div className="aspect-square min-w-0 shrink-0 grow-0 basis-full">
                <ProductImage src={null} alt={title} label={t("noImages")} />
              </div>
            )}
          </div>
        </div>

        <WishlistButton slug={slug} className="absolute end-3 top-3 z-[1] size-9 rounded-full border border-line bg-surface shadow-xs hover:bg-subtle" />
        {slides.length > 0 ? (
          <button
            type="button"
            onClick={() => setLightbox(true)}
            aria-label={t("zoom")}
            className="absolute bottom-3 end-3 z-[1] hidden size-9 items-center justify-center rounded-full border border-line bg-surface shadow-xs hover:bg-subtle md:flex"
          >
            <ZoomIn className="size-4" />
          </button>
        ) : null}

        {many ? (
          <>
            <button
              type="button"
              onClick={() => go(index - 1)}
              disabled={index === 0}
              aria-label={tc("previous")}
              className="absolute start-3 top-1/2 z-[1] hidden size-9 -translate-y-1/2 items-center justify-center rounded-full border border-line bg-surface shadow-sm hover:bg-subtle disabled:opacity-0 md:flex"
            >
              <Prev className="size-5" />
            </button>
            <button
              type="button"
              onClick={() => go(index + 1)}
              disabled={index === slides.length - 1}
              aria-label={tc("next")}
              className="absolute end-3 top-1/2 z-[1] hidden size-9 -translate-y-1/2 items-center justify-center rounded-full border border-line bg-surface shadow-sm hover:bg-subtle disabled:opacity-0 md:flex"
            >
              <Next className="size-5" />
            </button>
            <div className="mt-3 flex justify-center gap-1.5 md:hidden">
              {slides.map((slide, i) => (
                <button
                  key={slide.key}
                  type="button"
                  onClick={() => go(i)}
                  aria-label={t("imageN", { n: i + 1 })}
                  aria-current={i === index ? "true" : undefined}
                  className={clsx("h-1.5 rounded-full transition-all", i === index ? "w-5 bg-ink" : "w-1.5 bg-line-strong")}
                />
              ))}
            </div>
            <p className="sr-only" aria-live="polite">
              {t("imageOf", { n: index + 1, total: slides.length })}
            </p>
          </>
        ) : null}
      </div>

      {lightbox && slides.length > 0 ? (
        <Lightbox
          slides={slides}
          index={index}
          title={title}
          dir={market.dir}
          onIndex={(i) => go(i, true)}
          onClose={() => setLightbox(false)}
          labels={{ close: tc("close"), prev: tc("previous"), next: tc("next"), image: (n) => t("imageN", { n }), of: (n, total) => t("imageOf", { n, total }) }}
        />
      ) : null}
    </section>
  );
}

function Lightbox({
  slides,
  index,
  title,
  dir,
  onIndex,
  onClose,
  labels,
}: {
  slides: ReturnType<typeof gallerySlides>;
  index: number;
  title: string;
  dir: "ltr" | "rtl";
  onIndex: (i: number) => void;
  onClose: () => void;
  labels: { close: string; prev: string; next: string; image: (n: number) => string; of: (n: number, total: number) => string };
}) {
  const close = useRef<HTMLButtonElement>(null);
  const touch = useRef<number | null>(null);
  const slide = slides[index] ?? slides[0];
  const step = useCallback((d: number) => onIndex(Math.max(0, Math.min(slides.length - 1, index + d))), [index, onIndex, slides.length]);

  useEffect(() => {
    close.current?.focus();
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previous;
    };
  }, []);

  useEffect(() => {
    const onKey = (e: globalThis.KeyboardEvent) => {
      if (e.key === "Escape") onClose();
      const d = keyStep(e.key, dir);
      if (d !== null) {
        e.preventDefault();
        step(d);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [dir, onClose, step]);

  const Prev = dir === "rtl" ? ChevronRight : ChevronLeft;
  const Next = dir === "rtl" ? ChevronLeft : ChevronRight;

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-ink/90" role="dialog" aria-modal="true" aria-label={title} onClick={onClose}>
      <div className="flex items-center justify-between p-4 text-white">
        <span className="text-body-sm tabular-nums">{labels.of(index + 1, slides.length)}</span>
        <button ref={close} type="button" onClick={onClose} aria-label={labels.close} className="flex size-10 items-center justify-center rounded-full bg-white/10 hover:bg-white/20">
          <X className="size-5" />
        </button>
      </div>
      <div
        className="relative flex min-h-0 flex-1 items-center justify-center px-4"
        onClick={(e) => e.stopPropagation()}
        onTouchStart={(e) => (touch.current = e.touches[0]?.clientX ?? null)}
        onTouchEnd={(e) => {
          const start = touch.current;
          const end = e.changedTouches[0]?.clientX;
          touch.current = null;
          if (start == null || end == null || Math.abs(end - start) < 40) return;
          const forward = end < start ? 1 : -1;
          step(dir === "rtl" ? -forward : forward);
        }}
      >
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={slide.url} alt={slide.alt || title} className="max-h-full max-w-full rounded-lg bg-white object-contain p-6" />
        {slides.length > 1 ? (
          <>
            <button type="button" onClick={() => step(-1)} disabled={index === 0} aria-label={labels.prev} className="absolute start-4 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-white text-ink shadow disabled:opacity-30">
              <Prev className="size-6" />
            </button>
            <button type="button" onClick={() => step(1)} disabled={index === slides.length - 1} aria-label={labels.next} className="absolute end-4 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-white text-ink shadow disabled:opacity-30">
              <Next className="size-6" />
            </button>
          </>
        ) : null}
      </div>
      {slides.length > 1 ? (
        <div className="flex justify-center gap-2 p-4" onClick={(e) => e.stopPropagation()}>
          {slides.map((s, i) => (
            <button
              key={s.key}
              type="button"
              onClick={() => onIndex(i)}
              aria-label={labels.image(i + 1)}
              aria-current={i === index ? "true" : undefined}
              className={clsx("size-14 overflow-hidden rounded-md border-2 bg-white p-1", i === index ? "border-brand" : "border-transparent opacity-70 hover:opacity-100")}
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={s.url} alt="" className="size-full object-contain" />
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}
