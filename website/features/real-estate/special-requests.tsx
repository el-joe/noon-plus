"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Home, Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Alert, Badge, Card, EmptyState, Skeleton, Spinner } from "@/components/ui/misc";
import { cancelSpecialRequest, createSpecialRequest, getSpecialRequests, PROPERTY_TYPES, PURPOSES, type SpecialRequest, type SpecialRequestInput } from "@/lib/api/real-estate";
import { humanize } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { fieldErrorsOf } from "@/features/travel/shared";

const STATUSES = ["open", "in_progress", "fulfilled", "closed", "cancelled"] as const;
const TONES: Record<(typeof STATUSES)[number], "info" | "warning" | "success" | "neutral" | "danger"> = {
  open: "info",
  in_progress: "warning",
  fulfilled: "success",
  closed: "neutral",
  cancelled: "neutral",
};

/** `/account/special-requests` (Figma 50:2886): list, post a request, cancel. */
export function SpecialRequests() {
  const t = useTranslations("realEstate");
  const tc = useTranslations("common");
  const market = useMarket();
  const client = useQueryClient();
  const key = ["special-requests", market.slug];
  const [creating, setCreating] = useState(false);
  const [created, setCreated] = useState<string | null>(null);
  const { data, isLoading, error } = useQuery({ queryKey: key, queryFn: async () => (await getSpecialRequests(market.slug)).data });
  const cancel = useMutation({
    mutationFn: (number: string) => cancelSpecialRequest(market.slug, number),
    onSuccess: () => client.invalidateQueries({ queryKey: key }),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-h2 font-bold">{t("special.title")}</h1>
          <p className="text-body-sm text-secondary">{t("special.intro")}</p>
        </div>
        {!creating ? (
          <Button onClick={() => setCreating(true)}>
            <Plus className="size-4" />
            {t("special.new")}
          </Button>
        ) : null}
      </div>
      {created ? <Alert tone="success">{t("special.created", { number: created })}</Alert> : null}
      {creating ? (
        <NewRequest
          onCancel={() => setCreating(false)}
          onCreated={(number) => {
            setCreated(number);
            setCreating(false);
            client.invalidateQueries({ queryKey: key });
          }}
        />
      ) : null}
      {isLoading ? <Skeleton className="h-40 w-full" /> : null}
      {error ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
      {cancel.error ? <Alert tone="danger">{errorMessage(cancel.error, tc("error"))}</Alert> : null}
      {data && data.length === 0 && !creating ? <EmptyState icon={<Home className="size-10" />} title={t("special.empty")} /> : null}
      {data?.map((r) => (
        <RequestCard key={r.number} request={r} cancelling={cancel.isPending && cancel.variables === r.number} onCancel={() => window.confirm(t("special.cancelConfirm")) && cancel.mutate(r.number)} />
      ))}
    </div>
  );
}

function RequestCard({ request: r, onCancel, cancelling }: { request: SpecialRequest; onCancel: () => void; cancelling: boolean }) {
  const t = useTranslations("realEstate");
  const status = (STATUSES as readonly string[]).includes(r.status) ? (r.status as (typeof STATUSES)[number]) : null;
  const purpose = r.purpose && (PURPOSES as readonly string[]).includes(r.purpose) ? t(`purpose.${r.purpose as (typeof PURPOSES)[number]}`) : r.purpose;
  const type = r.property_type && (PROPERTY_TYPES as readonly string[]).includes(r.property_type) ? t(`types.${r.property_type as (typeof PROPERTY_TYPES)[number]}`) : r.property_type ? humanize(r.property_type) : null;
  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2 text-body font-semibold">
            {r.number}
            <Badge tone={status ? TONES[status] : "neutral"}>{status ? t(`special.status.${status}`) : humanize(r.status)}</Badge>
            {r.claimed && r.status === "in_progress" ? <span className="text-caption text-secondary">{t("special.claimed")}</span> : null}
          </p>
          <p className="text-body-sm text-secondary">{[purpose, type, r.city, r.bedrooms !== null ? `${t("bedrooms")}: ${r.bedrooms}` : null].filter(Boolean).join(" · ")}</p>
          {r.details ? <p className="mt-1 line-clamp-3 text-body-sm">{r.details}</p> : null}
        </div>
        {r.status === "open" || r.status === "in_progress" ? (
          <Button size="sm" variant="outline" onClick={onCancel} disabled={cancelling}>
            {cancelling ? <Spinner /> : null}
            {t("special.cancel")}
          </Button>
        ) : null}
      </div>
    </Card>
  );
}

function NewRequest({ onCancel, onCreated }: { onCancel: () => void; onCreated: (number: string) => void }) {
  const t = useTranslations("realEstate");
  const tc = useTranslations("common");
  const market = useMarket();
  const [form, setForm] = useState({ purpose: "", property_type: "", city: "", bedrooms: "", budget_min: "", budget_max: "", details: "" });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const create = useMutation({
    mutationFn: async () => {
      const body: SpecialRequestInput = { details: form.details.trim() };
      if (form.purpose) body.purpose = form.purpose;
      if (form.property_type) body.property_type = form.property_type;
      if (form.city.trim()) body.city = form.city.trim();
      if (form.bedrooms !== "") body.bedrooms = Number(form.bedrooms);
      if (form.budget_min !== "") body.budget_min = Number(form.budget_min);
      if (form.budget_max !== "") body.budget_max = Number(form.budget_max);
      return (await createSpecialRequest(market.slug, body)).data;
    },
    onSuccess: (r) => onCreated(r.number),
  });
  const errors = fieldErrorsOf(create.error);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    create.mutate();
  };
  return (
    <Card>
      <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
        {create.error && Object.keys(errors).length === 0 ? <Alert tone="danger" className="sm:col-span-2">{errorMessage(create.error, tc("error"))}</Alert> : null}
        <Field label={t("special.purpose")} error={errors.purpose}>
          <Select value={form.purpose} onChange={set("purpose")}>
            <option value="">{t("purpose.any")}</option>
            {PURPOSES.map((p) => (
              <option key={p} value={p}>
                {t(`purpose.${p}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field label={t("special.propertyType")} error={errors.property_type}>
          <Select value={form.property_type} onChange={set("property_type")}>
            <option value="">{t("anyType")}</option>
            {PROPERTY_TYPES.map((p) => (
              <option key={p} value={p}>
                {t(`types.${p}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field label={t("special.city")} error={errors.city}>
          <Input maxLength={80} value={form.city} onChange={set("city")} placeholder={t("cityPlaceholder")} />
        </Field>
        <Field label={t("special.bedrooms")} error={errors.bedrooms}>
          <Input type="number" min={0} max={50} inputMode="numeric" value={form.bedrooms} onChange={set("bedrooms")} />
        </Field>
        <Field label={`${t("special.budgetMin")} (${market.country.currency})`} error={errors.budget_min}>
          <Input type="number" min={0} inputMode="numeric" value={form.budget_min} onChange={set("budget_min")} />
        </Field>
        <Field label={`${t("special.budgetMax")} (${market.country.currency})`} error={errors.budget_max}>
          <Input type="number" min={0} inputMode="numeric" value={form.budget_max} onChange={set("budget_max")} />
        </Field>
        <Field label={`${t("special.details")} *`} error={errors.details} hint={`${form.details.length} / 2000`} className="sm:col-span-2">
          <Textarea required minLength={10} maxLength={2000} value={form.details} onChange={set("details")} placeholder={t("special.detailsPlaceholder")} />
        </Field>
        <div className="flex gap-2 sm:col-span-2">
          <Button type="submit" disabled={create.isPending || form.details.trim().length < 10}>
            {create.isPending ? <Spinner /> : null}
            {t("special.submit")}
          </Button>
          <Button variant="ghost" onClick={onCancel}>
            {tc("cancel")}
          </Button>
        </div>
      </form>
    </Card>
  );
}
