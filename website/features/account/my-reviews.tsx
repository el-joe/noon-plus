"use client";

import { useQuery } from "@tanstack/react-query";
import { Star } from "lucide-react";
import Link from "next/link";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Alert, Badge, Card, EmptyState, ProductImage, Skeleton } from "@/components/ui/misc";
import { Stars } from "@/components/ui/rating";
import { reviewableKey, WriteReviewModal } from "@/features/product/write-review";
import * as api from "@/lib/api/endpoints";
import { formatDay } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

// QC-012: the author sees `pending`, `published` (was `approved`), `hidden` or `rejected`.
const TONES = { pending: "warning", published: "success", hidden: "neutral", rejected: "danger" } as const;

/** `/account/reviews` (Figma 105:8879): items waiting for a review + the customer's reviews with moderation status. */
export function MyReviewsView() {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const locale = useLocale() as "en" | "ar";
  const [writing, setWriting] = useState<number | null>(null);
  const reviewable = useQuery({ queryKey: reviewableKey(market.slug), queryFn: async () => (await api.getReviewableItems(market.slug)).data });
  const mine = useQuery({ queryKey: ["my-reviews", market.slug], queryFn: async () => (await api.getMyReviews(market.slug)).data });

  return (
    <div className="flex flex-col gap-5">
      <h1 className="text-h2 font-bold">{t("myReviews.title")}</h1>
      {reviewable.error || mine.error ? <Alert tone="danger">{errorMessage(reviewable.error ?? mine.error, t("common.error"))}</Alert> : null}

      {(reviewable.data?.length ?? 0) > 0 ? (
        <section className="flex flex-col gap-2">
          <h2 className="text-h4 font-semibold">{t("myReviews.toReview")}</h2>
          <div className="grid gap-3 md:grid-cols-2">
            {reviewable.data!.map((item) => (
              <Card key={item.order_item_id} className="flex items-center gap-3">
                <span className="size-14 shrink-0 overflow-hidden rounded-md border border-line">
                  <ProductImage src={item.image?.url} alt="" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="line-clamp-1 text-body font-medium">{item.product?.title ?? item.title}</p>
                  <p className="text-body-sm text-secondary">{t("writeReview.order", { number: item.order_number })}</p>
                </div>
                <Button size="sm" onClick={() => setWriting(item.order_item_id)}>
                  {t("myReviews.review")}
                </Button>
              </Card>
            ))}
          </div>
        </section>
      ) : null}

      <section className="flex flex-col gap-2">
        <h2 className="text-h4 font-semibold">{t("myReviews.written")}</h2>
        {mine.isLoading ? <Skeleton className="h-40 w-full" /> : null}
        {mine.data && mine.data.length === 0 ? <EmptyState icon={<Star className="size-10" />} title={t("myReviews.empty")} /> : null}
        <div className="flex flex-col gap-3">
          {(mine.data ?? []).map((review) => {
            const raw = review.status === "approved" ? "published" : (review.status ?? "pending");
            const status = (raw in TONES ? raw : "pending") as keyof typeof TONES;
            return (
              <Card key={review.id} className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  {review.product?.slug ? (
                    <Link href={href(`/products/${review.product.slug}`)} className="text-body font-semibold hover:underline">
                      {review.product.title}
                    </Link>
                  ) : (
                    <span className="text-body font-semibold">{review.product?.title}</span>
                  )}
                  <Badge tone={TONES[status] ?? "neutral"}>{t(`myReviews.status.${status}`)}</Badge>
                </div>
                <div className="flex items-center gap-2">
                  <Stars value={review.rating} />
                  <span className="text-body-sm text-secondary">{formatDay(review.created_at, locale)}</span>
                </div>
                {review.title ? <p className="text-body font-medium">{review.title}</p> : null}
                {review.body ? <p className="whitespace-pre-line text-body-sm text-secondary">{review.body}</p> : null}
                {review.seller_reply ? (
                  <div className="rounded-md border-s-2 border-brand bg-subtle p-3 text-body-sm">
                    <p className="font-semibold">{review.seller ? t("reviews.sellerReplyFrom", { seller: review.seller }) : t("reviews.sellerReply")}</p>
                    <p className="whitespace-pre-line">{review.seller_reply.body}</p>
                  </div>
                ) : null}
                {(status === "rejected" || status === "hidden") && review.rejection_reason ? <p className="text-body-sm text-danger-text">{t("myReviews.rejectedReason", { reason: review.rejection_reason })}</p> : null}
              </Card>
            );
          })}
        </div>
      </section>
      {writing !== null ? <WriteReviewModal initialItemId={writing} onClose={() => setWriting(null)} /> : null}
    </div>
  );
}
