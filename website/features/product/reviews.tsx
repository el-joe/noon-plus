"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { BadgeCheck, MessageSquare, ThumbsUp } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/field";
import { Alert, Skeleton } from "@/components/ui/misc";
import { Stars } from "@/components/ui/rating";
import * as api from "@/lib/api/endpoints";
import type { Review, ReviewPage, ReviewSummary } from "@/lib/api/schemas";
import { formatDay } from "@/lib/format";
import { useLoginRedirect, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { WriteReviewModal } from "./write-review";

type Sort = NonNullable<api.ReviewQuery["sort"]>;
const SORTS: Sort[] = ["recent", "helpful", "rating_desc", "rating_asc"];
const PER_PAGE = 10;

function histogramOf(summary: ReviewSummary): Record<number, number> {
  const h = summary.histogram;
  if (Array.isArray(h)) return Object.fromEntries(h.map((v, i) => [i, v]));
  return Object.fromEntries(Object.entries(h).map(([k, v]) => [Number(k), v]));
}

/** Rating summary + histogram (Figma 24:2 "Ratings & reviews" card). */
export function ReviewSummaryCard({ summary, onWrite, onFilter, active }: { summary: ReviewSummary; onWrite: () => void; onFilter?: (stars: number | null) => void; active?: number | null }) {
  const t = useTranslations("reviews");
  const histogram = histogramOf(summary);
  return (
    <div className="flex flex-col gap-4 rounded-lg border border-line bg-surface p-5">
      <h2 className="text-h4 font-semibold">{t("title")}</h2>
      <div className="flex items-center gap-3">
        <span className="text-[40px] font-bold leading-none">{summary.average != null ? Number(summary.average).toFixed(1) : "–"}</span>
        <div>
          <Stars value={summary.average} size="md" />
          <p className="text-body-sm text-secondary">{t("basedOn", { count: summary.count })}</p>
        </div>
      </div>
      <ul className="flex flex-col gap-1.5">
        {[5, 4, 3, 2, 1].map((stars) => {
          const count = histogram[stars] ?? 0;
          const pct = summary.count ? Math.round((count / summary.count) * 100) : 0;
          return (
            <li key={stars}>
              <button
                type="button"
                disabled={!onFilter || count === 0}
                onClick={() => onFilter?.(active === stars ? null : stars)}
                className={clsx("flex w-full items-center gap-2 rounded-sm px-1 text-body-sm disabled:cursor-default", active === stars && "bg-brand-subtle")}
                aria-pressed={active === stars}
              >
                <span className="w-7 shrink-0 tabular-nums">{stars} ★</span>
                <span className="h-2 flex-1 overflow-hidden rounded-full bg-subtle">
                  <span className={clsx("block h-full rounded-full", stars >= 4 ? "bg-success" : stars === 3 ? "bg-brand" : "bg-danger")} style={{ width: `${pct}%` }} />
                </span>
                <span className="w-9 shrink-0 text-end tabular-nums text-secondary">{pct}%</span>
              </button>
            </li>
          );
        })}
      </ul>
      <Button variant="outline" onClick={onWrite} className="self-start">
        {t("write")}
      </Button>
    </div>
  );
}

/**
 * PDP reviews (G-W3): summary + histogram, sort, star / photo filters, the
 * list with helpful votes, and the write-review modal for verified buyers.
 */
export function ProductReviews({ slug, initial }: { slug: string; initial?: ReviewPage | null }) {
  const t = useTranslations("reviews");
  const tc = useTranslations("common");
  const market = useMarket();
  const locale = useLocale() as "en" | "ar";
  const toLogin = useLoginRedirect();
  const { isSignedIn } = useSession();
  const [sort, setSort] = useState<Sort>("recent");
  const [stars, setStars] = useState<number | null>(null);
  const [withPhotos, setWithPhotos] = useState(false);
  const [perPage, setPerPage] = useState(PER_PAGE);
  const [writing, setWriting] = useState(false);
  const isDefault = sort === "recent" && stars === null && !withPhotos && perPage === PER_PAGE;

  const query = useQuery({
    queryKey: ["reviews", market.slug, slug, sort, stars, withPhotos, perPage],
    queryFn: async () => {
      const r = await api.getProductReviews(market.slug, slug, { sort, rating: stars ?? undefined, with_photos: withPhotos ? 1 : undefined, per_page: perPage });
      return { ...r.data, total: Number(r.meta.total ?? r.data.reviews.length) };
    },
    initialData: isDefault && initial ? { ...initial, total: initial.summary.count } : undefined,
    placeholderData: keepPreviousData,
  });

  const write = () => {
    if (!isSignedIn) toLogin();
    else setWriting(true);
  };

  const data = query.data;
  return (
    <section id="reviews" className="grid scroll-mt-28 items-start gap-6 lg:grid-cols-[320px_1fr]">
      {data ? <ReviewSummaryCard summary={data.summary} onWrite={write} onFilter={setStars} active={stars} /> : <Skeleton className="h-72 w-full" />}
      <div className="flex min-w-0 flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <Select aria-label={t("sortLabel")} value={sort} onChange={(e) => setSort(e.target.value as Sort)} className="h-9 w-auto text-body-sm">
            {SORTS.map((s) => (
              <option key={s} value={s}>
                {t(`sort.${s}`)}
              </option>
            ))}
          </Select>
          <Select aria-label={t("allStars")} value={stars ?? ""} onChange={(e) => setStars(e.target.value ? Number(e.target.value) : null)} className="h-9 w-auto text-body-sm">
            <option value="">{t("allStars")}</option>
            {[5, 4, 3, 2, 1].map((n) => (
              <option key={n} value={n}>
                {t("stars", { count: n })}
              </option>
            ))}
          </Select>
          <label className="flex h-9 items-center gap-2 rounded-md border border-line-strong bg-surface px-3 text-body-sm">
            <input type="checkbox" checked={withPhotos} onChange={(e) => setWithPhotos(e.target.checked)} />
            {t("withPhotos")}
          </label>
        </div>
        {query.isError ? <Alert tone="danger">{t("voteError")}</Alert> : null}
        {data && data.summary.count === 0 ? (
          <div className="flex flex-col items-center gap-2 rounded-lg border border-line bg-surface px-6 py-10 text-center">
            <MessageSquare className="size-8 text-muted" />
            <p className="text-body font-semibold">{t("empty")}</p>
            <p className="text-body-sm text-secondary">{t("emptyHint")}</p>
          </div>
        ) : data && data.reviews.length === 0 ? (
          <p className="rounded-lg border border-line bg-surface p-5 text-body-sm text-secondary">{t("noMatch")}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-line rounded-lg border border-line bg-surface px-5">
            {(data?.reviews ?? []).map((review) => (
              <ReviewItem key={review.id} review={review} locale={locale} slug={slug} />
            ))}
          </ul>
        )}
        {data && data.reviews.length < data.total ? (
          <Button variant="outline" className="self-center" disabled={query.isFetching} onClick={() => setPerPage(perPage + PER_PAGE)}>
            {tc("loadMore")}
          </Button>
        ) : null}
      </div>
      {writing ? <WriteReviewModal productSlug={slug} onClose={() => setWriting(false)} /> : null}
    </section>
  );
}

function ReviewItem({ review, locale, slug }: { review: Review; locale: "en" | "ar"; slug: string }) {
  const t = useTranslations("reviews");
  const market = useMarket();
  const client = useQueryClient();
  const toLogin = useLoginRedirect();
  const { isSignedIn } = useSession();
  const [state, setState] = useState({ voted: review.voted_helpful, count: review.helpful_count });
  const vote = useMutation({
    mutationFn: async (helpful: boolean) => (await api.voteHelpful(market.slug, review.id, helpful)).data,
    onSuccess: (r) => {
      setState({ voted: r.voted_helpful, count: r.helpful_count });
      client.invalidateQueries({ queryKey: ["reviews", market.slug, slug] });
    },
  });

  return (
    <li className="flex flex-col gap-2 py-4">
      <div className="flex flex-wrap items-center gap-2">
        <Stars value={review.rating} />
        {review.title ? <h3 className="text-body font-semibold">{review.title}</h3> : null}
      </div>
      <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-body-sm text-secondary">
        <span className="font-medium text-ink">{review.author}</span>
        <span>{formatDay(review.created_at, locale)}</span>
        {review.verified_purchase ? (
          <span className="inline-flex items-center gap-1 text-success-text">
            <BadgeCheck className="size-3.5" />
            {t("verified")}
          </span>
        ) : null}
        {review.seller ? <span>· {t("soldBy", { seller: review.seller })}</span> : null}
      </p>
      {review.body ? <p className="whitespace-pre-line text-body">{review.body}</p> : null}
      {review.photos.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {review.photos.map((photo) => (
            <a key={photo.id} href={photo.url} target="_blank" rel="noreferrer" className="size-16 overflow-hidden rounded-md border border-line">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={photo.url} alt="" loading="lazy" className="size-full object-cover" />
            </a>
          ))}
        </div>
      ) : null}
      <button
        type="button"
        disabled={vote.isPending}
        onClick={() => (isSignedIn ? vote.mutate(!state.voted) : toLogin())}
        aria-pressed={state.voted}
        className={clsx("inline-flex items-center gap-1.5 self-start rounded-full border px-3 py-1 text-body-sm", state.voted ? "border-brand bg-brand-subtle font-medium" : "border-line hover:bg-subtle")}
      >
        <ThumbsUp className="size-3.5" />
        {t("helpful", { count: state.count })}
      </button>
      {vote.isError ? <p className="text-body-sm text-danger-text">{t("voteError")}</p> : null}
    </li>
  );
}
