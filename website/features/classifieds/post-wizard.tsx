"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { Check, CheckCircle2, Copy, ImagePlus, Trash2, Video } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { Button, ButtonLink, buttonClass } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import type { AdDetail, ClassifiedAttribute, ClassifiedCategory, SubmitResult } from "@/lib/api/classifieds";
import * as core from "@/lib/api/endpoints";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { AdImage } from "./ad-card";
import { fieldError } from "./contract-modal";
import { checkVideo, formatLimit, photoLimits, planPhotoBatch, preparePhoto, readVideoDuration, VIDEO_TYPES, videoLimits, type PhotoLimits, type PhotoRejection, type VideoLimits } from "./photo-limits";

const STEPS = ["category", "details", "location", "photos", "review"] as const;
type Step = (typeof STEPS)[number];

type Draft = {
  categorySlug: string | null;
  categoryId: number | null;
  kind: "offer" | "wanted";
  title: string;
  description: string;
  price: string;
  negotiable: boolean;
  city: string;
  area: string;
  phone: string;
  specs: Record<string, unknown>;
};

const EMPTY: Draft = { categorySlug: null, categoryId: null, kind: "offer", title: "", description: "", price: "", negotiable: false, city: "", area: "", phone: "", specs: {} };

/**
 * `/classified/post` — post-ad wizard (Figma 55:2016; FR-CLS-1): category →
 * details with the category's dynamic attributes → location → photos →
 * review, then submit. When the category needs the seller contract the ad
 * waits in `pending_contract` until it is signed here; then it goes to
 * admin review. `?number=` resumes a draft / rejected ad.
 */
export function PostAdWizard({ number: initialNumber }: { number?: string }) {
  const t = useTranslations("classifieds.post");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const { user, isSignedIn, isLoading } = useSession();
  const [step, setStep] = useState<Step>("category");
  const [draft, setDraft] = useState<Draft>(EMPTY);
  const [number, setNumber] = useState<string | null>(initialNumber ?? null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [submitted, setSubmitted] = useState<SubmitResult | null>(null);

  const categories = useQuery({ queryKey: ["classified-categories", market.slug], queryFn: async () => (await api.getClassifiedCategories(market.slug)).data });
  const attributes = useQuery({
    queryKey: ["classified-attributes", market.slug, draft.categorySlug],
    enabled: !!draft.categorySlug,
    queryFn: async () => (await api.getCategoryAttributes(market.slug, draft.categorySlug!)).data,
  });
  const existing = useQuery({
    queryKey: ["classified-own-ad", market.slug, number],
    enabled: isSignedIn && !!number,
    queryFn: async () => (await api.getAd(market.slug, number!)).data,
  });

  // Reopened pending-contract ad: the contract version comes from `GET ads/{n}/contract`.
  const reopenedContract = useQuery({
    queryKey: ["classified-ad-contract", market.slug, number],
    enabled: isSignedIn && !!number && existing.data?.status === "pending_contract",
    queryFn: async () => (await api.getAdContract(market.slug, number!)).data,
  });

  // Resume: prefill once from the saved ad (category id from the tree).
  const [prefilled, setPrefilled] = useState(false);
  useEffect(() => {
    const ad = existing.data;
    if (!ad || prefilled || !categories.data) return;
    setPrefilled(true);
    const node = api.flattenCategories(categories.data).find((c) => c.slug === ad.category);
    setDraft({
      categorySlug: ad.category ?? null,
      categoryId: node?.id ?? null,
      kind: ad.kind === "wanted" ? "wanted" : "offer",
      title: ad.title,
      description: ad.description ?? "",
      price: ad.price?.amount ?? "",
      negotiable: !!ad.is_negotiable,
      city: ad.city ?? "",
      area: ad.area ?? "",
      phone: "",
      specs: ad.specs ?? {},
    });
    if (ad.status === "pending_contract") setStep("review");
    else setStep("details");
  }, [existing.data, prefilled, categories.data]);

  if (isLoading) return <Skeleton className="h-96 w-full" />;
  if (!isSignedIn) {
    return (
      <EmptyState
        title={t("signInTitle")}
        body={t("signInBody")}
        action={<ButtonLink href={`${href("/login")}?next=${encodeURIComponent(href("/classified/post"))}`}>{tc("continue")}</ButtonLink>}
      />
    );
  }
  if (number && existing.isLoading) return <Skeleton className="h-96 w-full" />;
  if (number && existing.error) return <Alert tone="danger">{errorMessage(existing.error, tc("error"))}</Alert>;
  if (existing.data && !["draft", "pending_contract"].includes(existing.data.status)) {
    // Rejected ads stay final (lifecycle §5.1): offer a copy as a new draft instead.
    const duplicable = (api.DUPLICABLE_STATUSES as readonly string[]).includes(existing.data.status);
    return (
      <EmptyState
        title={t("notEditable")}
        body={[existing.data.status === "rejected" && existing.data.rejection_reason ? t("rejected", { reason: existing.data.rejection_reason }) : null, duplicable ? t("duplicateHint") : null].filter(Boolean).join(" ") || undefined}
        action={
          <div className="flex flex-wrap justify-center gap-2">
            {duplicable ? <DuplicateButton number={existing.data.number} /> : null}
            <ButtonLink href={href("/account/my-ads")} variant={duplicable ? "outline" : "primary"}>
              {t("toMyAds")}
            </ButtonLink>
          </div>
        }
      />
    );
  }

  if (submitted && submitted.status !== "pending_contract") {
    return (
      <Card className="flex flex-col items-center gap-3 py-10 text-center">
        <CheckCircle2 className="size-14 text-success-text" />
        <h1 className="text-h2 font-bold">{t("submittedTitle")}</h1>
        <p className="max-w-md text-body text-secondary">{t("submittedBody", { number: submitted.number })}</p>
        <div className="flex gap-2">
          <ButtonLink href={href("/account/my-ads")}>{t("toMyAds")}</ButtonLink>
          <ButtonLink href={href("/classified")} variant="outline">
            {t("backToSouq")}
          </ButtonLink>
        </div>
      </Card>
    );
  }

  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));
  const schema = attributes.data?.attributes ?? [];
  // Photo limits of the chosen category (attributes response, then the tree node; defaults 20 / 8192 KB).
  const categoryNode = categories.data && draft.categorySlug ? api.flattenCategories(categories.data).find((c) => c.slug === draft.categorySlug) : undefined;
  const limits = photoLimits(attributes.data, categoryNode);
  const videoRules = videoLimits(attributes.data);
  const index = STEPS.indexOf(step);

  const payload = (): api.AdInput => ({
    ...(draft.categoryId ? { category_id: draft.categoryId } : {}),
    kind: draft.kind,
    title: draft.title.trim(),
    description: draft.description.trim() || null,
    price: draft.price === "" ? null : draft.price,
    is_negotiable: draft.negotiable,
    city: draft.city.trim() || null,
    area: draft.area.trim() || null,
    contact_phone: draft.phone.trim() || null,
    specs: Object.fromEntries(Object.entries(draft.specs).filter(([, v]) => v !== "" && v !== null && !(Array.isArray(v) && v.length === 0))),
  });

  /** Create the draft the first time, update it afterwards. */
  const save = async (): Promise<string | null> => {
    setBusy(true);
    setError(null);
    try {
      const ad: AdDetail = number ? (await api.updateAd(market.slug, number, payload())).data : (await api.createAd(market.slug, payload())).data;
      setNumber(ad.number);
      client.setQueryData(["classified-own-ad", market.slug, ad.number], ad);
      setPrefilled(true);
      return ad.number;
    } catch (e) {
      setError(e);
      return null;
    } finally {
      setBusy(false);
    }
  };

  const next = async () => {
    setError(null);
    if (step === "category" && !draft.categorySlug) return;
    if (step === "location") {
      const saved = await save();
      if (!saved) return;
    }
    if (step === "details" && number) {
      const saved = await save();
      if (!saved) return;
    }
    setStep(STEPS[Math.min(index + 1, STEPS.length - 1)]);
  };

  const submit = async () => {
    if (!number) return;
    setBusy(true);
    setError(null);
    try {
      const saved = await save();
      if (!saved) return;
      setBusy(true);
      setSubmitted((await api.submitAd(market.slug, saved)).data);
      client.invalidateQueries({ queryKey: ["classified-mine", market.slug] });
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const pendingContract = submitted?.status === "pending_contract" || (!submitted && existing.data?.status === "pending_contract");

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-5">
      <div>
        <h1 className="text-h2 font-bold">{number ? t("editTitle") : t("title")}</h1>
        <p className="text-body-sm text-secondary">{t("subtitle")}</p>
      </div>

      <ol className="flex gap-2 overflow-x-auto" aria-label={t("progress")}>
        {STEPS.map((s, i) => (
          <li key={s} className="flex shrink-0 items-center gap-2">
            <span className={clsx("flex size-7 items-center justify-center rounded-full text-caption font-semibold", i < index ? "bg-success-text text-white" : i === index ? "bg-brand text-ink" : "bg-subtle text-muted")}>
              {i < index ? <Check className="size-4" /> : i + 1}
            </span>
            <span className={clsx("text-body-sm", i === index ? "font-semibold" : "text-secondary")}>{t(`steps.${s}`)}</span>
            {i < STEPS.length - 1 ? <span className="h-px w-6 bg-line" aria-hidden /> : null}
          </li>
        ))}
      </ol>

      {existing.data?.status === "rejected" && existing.data.rejection_reason ? <Alert tone="danger">{t("rejected", { reason: existing.data.rejection_reason })}</Alert> : null}

      <Card className="flex flex-col gap-4">
        {step === "category" ? (
          <CategoryStep
            tree={categories.data}
            loading={categories.isLoading}
            selected={draft.categorySlug}
            onSelect={(c) => setDraft((d) => ({ ...d, categorySlug: c.slug, categoryId: c.id, specs: {} }))}
          />
        ) : null}

        {step === "details" ? (
          <div className="flex flex-col gap-4">
            <fieldset className="flex gap-4">
              <legend className="mb-1 text-label font-medium">{t("kind")}</legend>
              {(["offer", "wanted"] as const).map((kind) => (
                <label key={kind} className="flex items-center gap-2 text-body">
                  <input type="radio" checked={draft.kind === kind} onChange={() => set("kind", kind)} />
                  {t(`kinds.${kind}`)}
                </label>
              ))}
            </fieldset>
            <Field label={t("adTitle")} error={fieldError(error, "title")} hint={t("adTitleHint")}>
              <Input value={draft.title} onChange={(e) => set("title", e.target.value)} maxLength={160} required />
            </Field>
            {attributes.isLoading ? <Skeleton className="h-24 w-full" /> : null}
            {schema.length > 0 ? (
              <div className="grid gap-3 sm:grid-cols-2">
                {schema.map((a) => (
                  <AttributeInput key={a.key} attribute={a} value={draft.specs[a.key]} error={fieldError(error, `specs.${a.key}`)} onChange={(v) => set("specs", { ...draft.specs, [a.key]: v })} />
                ))}
              </div>
            ) : null}
            <Field label={t("description")} error={fieldError(error, "description")}>
              <Textarea value={draft.description} onChange={(e) => set("description", e.target.value)} maxLength={5000} rows={6} />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t("price", { currency: market.country.currency })} error={fieldError(error, "price")} hint={t("priceHint")}>
                <Input type="number" min={0} step="any" value={draft.price} onChange={(e) => set("price", e.target.value)} dir="ltr" />
              </Field>
              <label className="flex items-center gap-2 pt-7 text-body">
                <input type="checkbox" checked={draft.negotiable} onChange={(e) => set("negotiable", e.target.checked)} />
                {t("negotiable")}
              </label>
            </div>
          </div>
        ) : null}

        {step === "location" ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t("city")} error={fieldError(error, "city")}>
              <Input value={draft.city} onChange={(e) => set("city", e.target.value)} maxLength={80} required />
            </Field>
            <Field label={t("area")} error={fieldError(error, "area")}>
              <Input value={draft.area} onChange={(e) => set("area", e.target.value)} maxLength={80} />
            </Field>
            <Field label={t("contactPhone")} error={fieldError(error, "contact_phone")} hint={t("contactPhoneHint")} className="sm:col-span-2">
              <Input type="tel" value={draft.phone} onChange={(e) => set("phone", e.target.value)} maxLength={24} dir="ltr" />
            </Field>
          </div>
        ) : null}

        {step === "photos" && number ? <PhotosStep number={number} ad={existing.data ?? null} limits={limits} video={videoRules} /> : null}

        {step === "review" ? (
          <div className="flex flex-col gap-3">
            <h2 className="text-h4 font-semibold">{t("reviewTitle")}</h2>
            <dl className="grid gap-x-6 text-body-sm sm:grid-cols-2">
              {[
                [t("steps.category"), draft.categorySlug ?? "—"],
                [t("adTitle"), draft.title || "—"],
                [t("price", { currency: market.country.currency }), draft.price || "—"],
                [t("city"), [draft.city, draft.area].filter(Boolean).join(" · ") || "—"],
              ].map(([k, v]) => (
                <div key={k} className="flex justify-between gap-4 border-b border-line py-2">
                  <dt className="text-secondary">{k}</dt>
                  <dd className="text-end font-medium">{v}</dd>
                </div>
              ))}
            </dl>
            {attributes.data?.requires_contract && !pendingContract ? <Alert tone="info">{t("contractNotice")}</Alert> : null}
            {pendingContract && number ? (
              <SellerContract
                number={number}
                contract={submitted?.contract ?? reopenedContract.data?.contract ?? null}
                defaultName={user?.name ?? ""}
                onSigned={(status) => {
                  setSubmitted({ number, status, contract: null });
                  client.invalidateQueries({ queryKey: ["classified-mine", market.slug] });
                }}
              />
            ) : null}
          </div>
        ) : null}

        {error && !Object.keys((error as { fields?: object }).fields ?? {}).length ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
        {error && Object.keys((error as { fields?: object }).fields ?? {}).length ? <Alert tone="danger">{t("fixErrors")}</Alert> : null}
        {step === "category" && draft.categorySlug && !draft.categoryId && !number ? <Alert tone="warning">{t("categoryUnavailable")}</Alert> : null}

        <div className="flex items-center justify-between gap-2 border-t border-line pt-4">
          <Button variant="ghost" disabled={index === 0 || busy} onClick={() => setStep(STEPS[index - 1])}>
            {tc("back")}
          </Button>
          {step === "review" ? (
            pendingContract ? null : (
              <Button onClick={submit} disabled={busy || !number}>
                {t("submit")}
              </Button>
            )
          ) : (
            <Button
              onClick={next}
              disabled={busy || (step === "category" && (!draft.categorySlug || (!draft.categoryId && !number))) || (step === "details" && draft.title.trim().length === 0)}
            >
              {step === "location" ? t("saveContinue") : tc("continue")}
            </Button>
          )}
        </div>
      </Card>
    </div>
  );
}

function CategoryStep({ tree, loading, selected, onSelect }: { tree?: ClassifiedCategory[]; loading: boolean; selected: string | null; onSelect: (c: ClassifiedCategory) => void }) {
  const t = useTranslations("classifieds.post");
  const [path, setPath] = useState<ClassifiedCategory[]>([]);
  if (loading) return <Skeleton className="h-48 w-full" />;
  if (!tree || tree.length === 0) return <EmptyState title={t("noCategories")} />;
  const level = path.length ? (path.at(-1)!.children ?? []) : tree;

  return (
    <div className="flex flex-col gap-3">
      <h2 className="text-h4 font-semibold">{t("chooseCategory")}</h2>
      {path.length ? (
        <nav className="flex flex-wrap items-center gap-1 text-body-sm">
          <button type="button" className="text-link" onClick={() => setPath([])}>
            {t("allCategories")}
          </button>
          {path.map((c, i) => (
            <span key={c.slug}>
              {" › "}
              <button type="button" className="text-link" onClick={() => setPath(path.slice(0, i + 1))}>
                {c.name}
              </button>
            </span>
          ))}
        </nav>
      ) : null}
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
        {level.map((c) => {
          const leaf = (c.children ?? []).length === 0;
          return (
            <button
              key={c.slug}
              type="button"
              onClick={() => (leaf ? onSelect(c) : setPath([...path, c]))}
              className={clsx("flex items-center justify-between rounded-md border p-3 text-start text-body", selected === c.slug ? "border-brand bg-brand-subtle font-semibold" : "border-line hover:bg-subtle")}
            >
              {c.name}
              {leaf ? (selected === c.slug ? <Check className="size-4" /> : null) : <span aria-hidden>›</span>}
            </button>
          );
        })}
      </div>
    </div>
  );
}

function AttributeInput({ attribute: a, value, error, onChange }: { attribute: ClassifiedAttribute; value: unknown; error: string | null; onChange: (v: unknown) => void }) {
  const market = useMarket();
  const t = useTranslations("classifieds.post");
  const label = `${a.label ?? a.key}${a.unit ? ` (${a.unit})` : ""}${a.required ? " *" : ""}`;

  if (a.type === "select") {
    return (
      <Field label={label} error={error}>
        <Select value={String(value ?? "")} onChange={(e) => onChange(e.target.value)} required={a.required}>
          <option value="">{t("choose")}</option>
          {a.options.map((o) => (
            <option key={api.optionValue(o)} value={api.optionValue(o)}>
              {api.optionLabel(o, market.locale)}
            </option>
          ))}
        </Select>
      </Field>
    );
  }
  if (a.type === "multiselect") {
    const current = Array.isArray(value) ? value.map(String) : [];
    return (
      <fieldset className="flex flex-col gap-1.5 sm:col-span-2">
        <legend className="text-label font-medium">{label}</legend>
        <div className="flex flex-wrap gap-2">
          {a.options.map((o) => {
            const v = api.optionValue(o);
            const on = current.includes(v);
            return (
              <label key={v} className={clsx("flex items-center gap-1.5 rounded-full border px-3 py-1 text-body-sm", on ? "border-brand bg-brand-subtle" : "border-line")}>
                <input type="checkbox" className="sr-only" checked={on} onChange={() => onChange(on ? current.filter((x) => x !== v) : [...current, v])} />
                {api.optionLabel(o, market.locale)}
              </label>
            );
          })}
        </div>
        {error ? <span className="text-body-sm text-danger-text">{error}</span> : null}
      </fieldset>
    );
  }
  if (a.type === "boolean") {
    return (
      <label className="flex items-center gap-2 pt-6 text-body">
        <input type="checkbox" checked={value === true || value === "1" || value === 1} onChange={(e) => onChange(e.target.checked)} />
        {label}
        {error ? <span className="text-body-sm text-danger-text">{error}</span> : null}
      </label>
    );
  }
  const numeric = a.type === "number" || a.type === "year";
  return (
    <Field label={label} error={error}>
      <Input
        type={numeric ? "number" : "text"}
        min={numeric && a.min !== null && a.min !== undefined ? Number(a.min) : undefined}
        max={numeric && a.max !== null && a.max !== undefined ? Number(a.max) : undefined}
        value={value === undefined || value === null ? "" : String(value)}
        onChange={(e) => onChange(numeric && e.target.value !== "" ? Number(e.target.value) : e.target.value)}
        required={a.required}
        maxLength={numeric ? undefined : 255}
      />
    </Field>
  );
}

/**
 * Photos for the saved draft (`POST classifieds/ads/{number}/photos`, one file
 * per call). The category's `max_photos` / `photo_max_kb` are enforced before
 * upload; large photos are resized / re-encoded in the browser first.
 */
function PhotosStep({ number, ad, limits, video }: { number: string; ad: AdDetail | null; limits: PhotoLimits; video: VideoLimits }) {
  const t = useTranslations("classifieds.post");
  const tc = useTranslations("common");
  const market = useMarket();
  const client = useQueryClient();
  const [progress, setProgress] = useState<{ phase: "preparing" | "uploading"; current: number; total: number } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notes, setNotes] = useState<string[]>([]);
  const [videoBusy, setVideoBusy] = useState(false);
  const [videoError, setVideoError] = useState<string | null>(null);
  const media = ad?.media ?? [];
  const photoCount = media.filter((m) => m.type === "photo").length;
  const full = photoCount >= limits.maxPhotos;
  const size = formatLimit(limits.maxKb);
  const refresh = () => client.invalidateQueries({ queryKey: ["classified-own-ad", market.slug, number] });

  const describe = (rejected: PhotoRejection[]): string[] => {
    const out: string[] = [];
    const tooMany = rejected.filter((r) => r.reason === "count").length;
    if (tooMany > 0) out.push(t("photoLimits.tooMany", { max: limits.maxPhotos, count: tooMany }));
    for (const r of rejected) {
      if (r.reason === "type") out.push(t("photoLimits.notPhoto", { name: r.name }));
      if (r.reason === "size") out.push(t("photoLimits.tooLarge", { name: r.name, size }));
    }
    return out;
  };

  const upload = async (files: FileList | null) => {
    if (!files?.length) return;
    setError(null);
    const { accepted, rejected } = planPhotoBatch(Array.from(files), photoCount, limits);
    const messages = describe(rejected);
    setNotes(messages);
    let done = 0;
    for (const file of accepted) {
      done++;
      setProgress({ phase: "preparing", current: done, total: accepted.length });
      const prepared = await preparePhoto(file, limits.maxBytes);
      if (!prepared.ok) {
        messages.push(prepared.reason === "size" ? t("photoLimits.tooLarge", { name: file.name, size }) : t("photoLimits.unreadable", { name: file.name }));
        setNotes([...messages]);
        continue;
      }
      setProgress({ phase: "uploading", current: done, total: accepted.length });
      try {
        await api.uploadAdPhoto(market.slug, number, prepared.file);
      } catch (e) {
        setError(errorMessage(e, tc("error")));
      }
    }
    setProgress(null);
    refresh();
  };

  const hasVideo = media.some((m) => m.type === "video");
  const uploadVideo = async (file: File | undefined) => {
    if (!file) return;
    setVideoError(null);
    setVideoBusy(true);
    try {
      const duration = (VIDEO_TYPES as readonly string[]).includes(file.type) ? await readVideoDuration(file) : null;
      const problem = checkVideo(file, duration, video);
      if (problem) {
        setVideoError(t(`video.${problem}`, { seconds: video.maxSeconds, size: formatLimit(video.maxKb) }));
        return;
      }
      await api.uploadAdVideo(market.slug, number, file, Math.max(1, Math.ceil(duration!)));
      refresh();
    } catch (e) {
      setVideoError(errorMessage(e, tc("error")));
    } finally {
      setVideoBusy(false);
    }
  };

  const remove = async (id: number) => {
    try {
      await api.removeAdMedia(market.slug, number, id);
    } catch (e) {
      setError(errorMessage(e, tc("error")));
    }
    refresh();
  };

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-h4 font-semibold">{t("photosTitle")}</h2>
        <span className="text-body-sm text-secondary">{t("photoLimits.count", { count: photoCount, max: limits.maxPhotos })}</span>
      </div>
      <p className="text-body-sm text-secondary">
        {t("photosHint")} {t("photoLimits.hint", { max: limits.maxPhotos, size })}
      </p>
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">
        {media.map((m) => (
          <div key={m.id} className="relative aspect-square overflow-hidden rounded-md border border-line">
            {m.type === "video" ? (
              <div className="flex size-full flex-col items-center justify-center gap-1 bg-ink text-caption text-white">
                <Video className="size-6" aria-hidden />
                {t("video.label")}
                {m.duration_seconds ? <span dir="ltr">{`${Math.floor(m.duration_seconds / 60)}:${String(m.duration_seconds % 60).padStart(2, "0")}`}</span> : null}
              </div>
            ) : (
              <AdImage src={m.type === "photo" ? m.url : null} alt="" />
            )}
            <button type="button" onClick={() => remove(m.id)} aria-label={tc("remove")} className="absolute end-1 top-1 rounded-full bg-surface/90 p-1">
              <Trash2 className="size-4" />
            </button>
          </div>
        ))}
        {full ? null : (
          <label
            className={clsx(
              "flex aspect-square flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed border-line-strong p-2 text-center text-body-sm text-secondary focus-within:ring-2 focus-within:ring-brand",
              progress ? "cursor-wait opacity-70" : "cursor-pointer hover:bg-subtle",
            )}
          >
            <ImagePlus className="size-6" />
            {progress ? t(`photoLimits.${progress.phase}`, { current: progress.current, total: progress.total }) : t("addPhotos")}
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              multiple
              disabled={!!progress}
              className="sr-only"
              onChange={(e) => {
                const input = e.currentTarget;
                // Clear afterwards so picking the same photo again fires onChange.
                void upload(input.files).finally(() => {
                  input.value = "";
                });
              }}
            />
          </label>
        )}
      </div>
      {full ? <Alert tone="info">{t("photoLimits.full", { max: limits.maxPhotos })}</Alert> : null}
      {video.allowed && !hasVideo ? (
        <div className="flex flex-col gap-2 rounded-md border border-line p-3">
          <h3 className="text-body font-semibold">{t("video.title")}</h3>
          <p className="text-body-sm text-secondary">{t("video.hint", { seconds: video.maxSeconds, size: formatLimit(video.maxKb) })}</p>
          <label className={buttonClass("outline", "sm", clsx("self-start focus-within:ring-2 focus-within:ring-brand", videoBusy ? "cursor-wait opacity-70" : "cursor-pointer"))}>
            <Video className="size-4" aria-hidden />
            {videoBusy ? t("video.uploading") : t("video.add")}
            <input
              type="file"
              accept="video/mp4,video/webm,video/quicktime"
              disabled={videoBusy}
              className="sr-only"
              onChange={(e) => {
                const input = e.currentTarget;
                void uploadVideo(input.files?.[0]).finally(() => {
                  input.value = "";
                });
              }}
            />
          </label>
          {videoError ? <Alert tone="danger">{videoError}</Alert> : null}
        </div>
      ) : null}
      {notes.length > 0 ? (
        <Alert tone="warning">
          <ul className="flex flex-col gap-1">
            {notes.map((note, i) => (
              <li key={i}>{note}</li>
            ))}
          </ul>
        </Alert>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
    </div>
  );
}

/** Seller contract before the ad goes to review (FR-CLS-1, `POST classifieds/ads/{n}/contract`). */
type SellerContractData = NonNullable<SubmitResult["contract"]> & { text?: string | null };

function SellerContract({ number, contract, defaultName, onSigned }: { number: string; contract: SellerContractData | null; defaultName: string; onSigned: (status: string) => void }) {
  const t = useTranslations("classifieds.post");
  const tc = useTranslations("common");
  const market = useMarket();
  const [name, setName] = useState(defaultName);
  const [agree, setAgree] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const text = useQuery({
    queryKey: ["contract-text", market.slug, contract?.version_id],
    enabled: !!contract && contract.text === undefined,
    queryFn: async () => (await core.getContract(market.slug, contract!.version_id)).data,
  });

  const sign = async () => {
    setBusy(true);
    setError(null);
    try {
      onSigned((await api.signAdContract(market.slug, number, name.trim())).data.status);
    } catch (e) {
      setError(e);
      setBusy(false);
    }
  };

  return (
    <section className="flex flex-col gap-3 rounded-md border border-brand bg-brand-subtle/40 p-4">
      <h3 className="text-h4 font-semibold">{contract ? `${contract.title} · v${contract.version}` : t("contractTitle")}</h3>
      <p className="text-body-sm text-secondary">{t("contractHint")}</p>
      {contract ? (
        <div className="max-h-64 overflow-y-auto whitespace-pre-line rounded-sm bg-surface p-3 text-body-sm">{contract.text ? contract.text : text.isLoading ? tc("loading") : (text.data?.text ?? t("contractUnavailable"))}</div>
      ) : null}
      <Field label={t("fullName")} error={fieldError(error, "full_name")}>
        <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={160} />
      </Field>
      <label className="flex items-center gap-2 text-body">
        <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} />
        {t("contractAccept")}
      </label>
      {error && !fieldError(error, "full_name") ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
      <Button onClick={sign} disabled={!agree || name.trim().length < 3 || busy}>
        {t("signAndSubmit")}
      </Button>
    </section>
  );
}

/** "Duplicate" a rejected / expired / archived / sold ad into a new draft, then open it in the wizard. */
export function DuplicateButton({ number, size = "md", variant = "primary" }: { number: string; size?: "sm" | "md"; variant?: "primary" | "outline" }) {
  const t = useTranslations("classifieds.post");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      const copy = (await api.duplicateAd(market.slug, number)).data;
      client.invalidateQueries({ queryKey: ["classified-mine", market.slug] });
      window.location.assign(`${href("/classified/post")}?number=${encodeURIComponent(copy.number)}`);
    } catch (e) {
      setError(errorMessage(e, tc("error")));
      setBusy(false);
    }
  };
  return (
    <span className="inline-flex flex-col items-start gap-1">
      <Button size={size} variant={variant} onClick={run} disabled={busy}>
        <Copy className="size-4" />
        {t("duplicate")}
      </Button>
      {error ? <span className="text-caption text-danger-text">{error}</span> : null}
    </span>
  );
}
