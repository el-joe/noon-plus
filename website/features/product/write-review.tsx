"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { Camera, Check, Star, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useState, type FormEvent } from "react";
import { Modal } from "@/components/layout/modal";
import { Button } from "@/components/ui/button";
import { Field, Input, Textarea } from "@/components/ui/field";
import { Alert, ProductImage, Skeleton } from "@/components/ui/misc";
import { fieldErrors } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import type { ReviewableItem } from "@/lib/api/schemas";
import { errorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

export const reviewableKey = (market: string) => ["reviewable-items", market] as const;

/** Star picker (1–5) with the Figma word label. */
export function StarInput({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  const t = useTranslations("writeReview");
  const [hover, setHover] = useState(0);
  const shown = hover || value;
  return (
    <div className="flex items-center gap-3">
      <div className="flex gap-1" role="radiogroup" aria-label={t("overall")} onMouseLeave={() => setHover(0)}>
        {[1, 2, 3, 4, 5].map((n) => (
          <button
            key={n}
            type="button"
            role="radio"
            aria-checked={value === n}
            aria-label={t(`label${n}` as "label1")}
            onMouseEnter={() => setHover(n)}
            onClick={() => onChange(n)}
            className="p-0.5"
          >
            <Star className={clsx("size-8", n <= shown ? "text-brand" : "text-line-strong")} fill="currentColor" strokeWidth={0} />
          </button>
        ))}
      </div>
      {shown ? <span className="text-body text-secondary">{t(`label${shown}` as "label1")}</span> : null}
    </div>
  );
}

/**
 * Write-review modal (Figma 105:8417). Items come from `me/reviewable-items`
 * (delivered, not yet reviewed); with `productSlug` only that product's items
 * are offered. Submits multipart `POST reviews` (≤ 5 photos).
 */
export function WriteReviewModal({ productSlug, initialItemId, onClose }: { productSlug?: string; initialItemId?: number; onClose: () => void }) {
  const t = useTranslations();
  const market = useMarket();
  const client = useQueryClient();
  const items = useQuery({ queryKey: reviewableKey(market.slug), queryFn: async () => (await api.getReviewableItems(market.slug)).data });
  const options = (items.data ?? []).filter((i) => !productSlug || i.product?.slug === productSlug);
  const [itemId, setItemId] = useState<number | null>(initialItemId ?? null);
  const [rating, setRating] = useState(0);
  const [photos, setPhotos] = useState<File[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [done, setDone] = useState(false);

  useEffect(() => {
    if (itemId === null && options.length > 0) setItemId(options[0].order_item_id);
  }, [itemId, options]);

  const submit = useMutation({
    mutationFn: async (form: FormData) => (await api.createReview(market.slug, form)).data,
    onSuccess: () => {
      setDone(true);
      client.invalidateQueries({ queryKey: reviewableKey(market.slug) });
      client.invalidateQueries({ queryKey: ["my-reviews", market.slug] });
    },
    onError: (e) => setErrors(fieldErrors(e)),
  });

  const item = options.find((i) => i.order_item_id === itemId) ?? null;

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!item) return;
    if (!rating) {
      setErrors({ rating: t("writeReview.pickRating") });
      return;
    }
    const raw = new FormData(event.currentTarget);
    const form = new FormData();
    form.set("order_item_id", String(item.order_item_id));
    form.set("rating", String(rating));
    for (const key of ["title", "body"]) {
      const v = String(raw.get(key) ?? "").trim();
      if (v) form.set(key, v);
    }
    photos.forEach((file) => form.append("photos[]", file));
    setErrors({});
    submit.mutate(form);
  };

  const title = item ? t("writeReview.title", { product: item.product?.title ?? item.title ?? "" }) : t("reviews.write");

  return (
    <Modal title={title} onClose={onClose}>
      {done ? (
        <div className="flex flex-col items-center gap-3 py-6 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-success-subtle text-success-text">
            <Check className="size-6" />
          </span>
          <p className="text-body">{t("writeReview.submitted")}</p>
          <Button onClick={onClose}>{t("common.close")}</Button>
        </div>
      ) : items.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : options.length === 0 ? (
        <Alert tone="info">{productSlug ? t("writeReview.noItemsForProduct") : t("writeReview.noItems")}</Alert>
      ) : (
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <p className="text-body-sm text-secondary">{t("writeReview.subtitle")}</p>
          {options.length > 1 ? (
            <fieldset className="flex flex-col gap-2">
              <legend className="mb-1 text-label font-medium">{t("writeReview.chooseItem")}</legend>
              {options.map((o) => (
                <ItemOption key={o.order_item_id} item={o} checked={o.order_item_id === itemId} onSelect={() => setItemId(o.order_item_id)} />
              ))}
            </fieldset>
          ) : item ? (
            <ItemOption item={item} checked onSelect={() => undefined} />
          ) : null}
          <div>
            <p className="mb-1 text-label font-medium">{t("writeReview.overall")}</p>
            <StarInput value={rating} onChange={setRating} />
            {errors.rating ? <p className="mt-1 text-body-sm text-danger-text">{errors.rating}</p> : null}
          </div>
          <Field label={`${t("writeReview.reviewTitle")} ${t("common.optionalSuffix")}`} error={errors.title}>
            <Input name="title" maxLength={120} placeholder={t("writeReview.titlePlaceholder")} />
          </Field>
          <Field label={`${t("writeReview.body")} ${t("common.optionalSuffix")}`} error={errors.body}>
            <Textarea name="body" maxLength={2000} rows={4} placeholder={t("writeReview.bodyPlaceholder")} />
          </Field>
          <div>
            <p className="mb-2 text-label font-medium">{t("writeReview.photos")}</p>
            <div className="flex flex-wrap gap-2">
              {photos.map((file, i) => (
                <div key={i} className="relative size-16 overflow-hidden rounded-md border border-line">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={URL.createObjectURL(file)} alt="" className="size-full object-cover" />
                  <button type="button" onClick={() => setPhotos(photos.filter((_, j) => j !== i))} className="absolute end-0.5 top-0.5 rounded-full bg-surface p-0.5" aria-label={t("common.remove")}>
                    <X className="size-3" />
                  </button>
                </div>
              ))}
              {photos.length < 5 ? (
                <label className="flex size-16 cursor-pointer flex-col items-center justify-center gap-0.5 rounded-md border border-dashed border-line-strong text-caption text-secondary hover:bg-subtle">
                  <Camera className="size-4" />
                  {t("writeReview.addPhoto")}
                  <input
                    type="file"
                    accept="image/*"
                    multiple
                    className="sr-only"
                    onChange={(e) => {
                      setPhotos([...photos, ...Array.from(e.target.files ?? [])].slice(0, 5));
                      e.target.value = "";
                    }}
                  />
                </label>
              ) : null}
            </div>
            {errors.photos || errors["photos.0"] ? <p className="mt-1 text-body-sm text-danger-text">{errors.photos ?? errors["photos.0"]}</p> : null}
          </div>
          <details className="rounded-md bg-subtle p-3 text-body-sm">
            <summary className="cursor-pointer font-medium">{t("writeReview.guidelines")}</summary>
            <ul className="mt-2 flex list-disc flex-col gap-1 ps-5 text-secondary">
              <li>{t("writeReview.guideline1")}</li>
              <li>{t("writeReview.guideline2")}</li>
              <li>{t("writeReview.guideline3")}</li>
              <li>{t("writeReview.guideline4")}</li>
            </ul>
          </details>
          {submit.isError && Object.keys(errors).length === 0 ? <Alert tone="danger">{errorMessage(submit.error, t("common.error"))}</Alert> : null}
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={submit.isPending || !item}>
              {submit.isPending ? t("writeReview.submitting") : t("writeReview.submit")}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  );
}

function ItemOption({ item, checked, onSelect }: { item: ReviewableItem; checked: boolean; onSelect: () => void }) {
  const t = useTranslations("writeReview");
  return (
    <label className={clsx("flex cursor-pointer items-center gap-3 rounded-md border p-2", checked ? "border-brand bg-brand-subtle" : "border-line")}>
      <input type="radio" name="order_item" checked={checked} onChange={onSelect} className="sr-only" />
      <span className="size-12 shrink-0 overflow-hidden rounded-sm bg-surface">
        <ProductImage src={item.image?.url} alt="" />
      </span>
      <span className="min-w-0 text-body-sm">
        <span className="line-clamp-1 font-medium">{item.product?.title ?? item.title}</span>
        <span className="text-secondary">{t("order", { number: item.order_number })}</span>
      </span>
    </label>
  );
}
