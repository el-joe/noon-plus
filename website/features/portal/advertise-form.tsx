"use client";

import { CheckCircle2 } from "lucide-react";
import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Alert, Spinner } from "@/components/ui/misc";
import { AD_INTERESTS, ADVERTISER_TYPES, getAdvertiseOptions, sendAdvertiseRequest, type BudgetBand } from "@/lib/api/portal";
import { useMarket } from "@/lib/market-context";
import { bannerError, fieldErrors } from "./errors";

const group = (n: number) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ",");

/** Advertise request form (Figma Advertise Request form 96:8534) → POST advertise-requests (P9). */
export function AdvertiseForm() {
  const t = useTranslations("portal");
  const market = useMarket();
  const options = useQuery({ queryKey: ["advertise-options", market.slug], staleTime: 60 * 60_000, queryFn: async () => (await getAdvertiseOptions(market.slug)).data });
  const types = options.data?.advertiser_types.length ? options.data.advertiser_types : [...ADVERTISER_TYPES];
  const interests = options.data?.interests.length ? options.data.interests : [...AD_INTERESTS];
  const bands = options.data?.budget_bands ?? [];
  const loose = t as unknown as ((key: string) => string) & { has: (key: string) => boolean };
  const optionLabel = (prefix: string, key: string) => (loose.has(`${prefix}.${key}`) ? loose(`${prefix}.${key}`) : key.replace(/_/g, " "));
  /** Band label in the visitor's language from min / max (the API label is English). */
  const bandLabel = (band: BudgetBand) =>
    band.min === null && band.max === null
      ? t("advertise.bands.not_sure")
      : band.min === null
        ? t("advertise.bands.under", { currency: band.currency, max: group(band.max!) })
        : band.max === null
          ? t("advertise.bands.over", { currency: band.currency, min: group(band.min) })
          : t("advertise.bands.between", { currency: band.currency, min: group(band.min), max: group(band.max) });
  const [values, setValues] = useState({ name: "", email: "", phone: "", company: "", advertiser_type: "seller", interest: "product_ads", monthly_budget: "", message: "" });
  const [consent, setConsent] = useState(false);
  const [pending, setPending] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [sent, setSent] = useState<string | null>(null);
  const set = (key: keyof typeof values, value: string) => setValues((v) => ({ ...v, [key]: value }));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!consent) return setErrors({ consent: t("advertise.consentRequired") });
    setPending(true);
    setErrors({});
    setBanner(null);
    try {
      const { data } = await sendAdvertiseRequest(market.slug, {
        name: values.name.trim(),
        email: values.email.trim(),
        phone: values.phone.trim(),
        company: values.company.trim() || undefined,
        advertiser_type: values.advertiser_type,
        interest: values.interest,
        monthly_budget_band: values.monthly_budget || undefined,
        message: values.message.trim() || undefined,
        consent: true,
      });
      setSent(data.number);
    } catch (error) {
      setErrors(fieldErrors(error));
      setBanner(bannerError(error, t("common.error")));
    } finally {
      setPending(false);
    }
  };

  if (sent) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-lg border border-line bg-surface p-8 text-center">
        <CheckCircle2 className="size-12 text-success-text" />
        <h2 className="text-h2 font-semibold">{t("advertise.sentTitle")}</h2>
        <p className="text-body text-secondary">{t("advertise.sentBody")}</p>
        <p className="text-body-sm">
          {t("status.reference")} <span className="font-semibold" dir="ltr">{sent}</span>
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-6 rounded-lg border border-line bg-surface p-5 md:p-6">
      <section>
        <h2 className="mb-4 text-h3 font-semibold">{t("advertise.yourDetails")}</h2>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label={`${t("fields.name")} *`} error={errors.name}>
            <Input value={values.name} onChange={(e) => set("name", e.target.value)} autoComplete="name" required maxLength={120} />
          </Field>
          <Field label={`${t("advertise.workEmail")} *`} error={errors.email}>
            <Input type="email" dir="ltr" value={values.email} onChange={(e) => set("email", e.target.value)} autoComplete="email" required />
          </Field>
          <Field label={`${t("fields.phone")} *`} error={errors.phone} hint={t("hints.phone")}>
            <Input type="tel" dir="ltr" value={values.phone} onChange={(e) => set("phone", e.target.value)} autoComplete="tel" required />
          </Field>
          <Field label={t("advertise.company")} error={errors.company}>
            <Input value={values.company} onChange={(e) => set("company", e.target.value)} autoComplete="organization" maxLength={160} />
          </Field>
        </div>
      </section>
      <section>
        <h2 className="mb-4 text-h3 font-semibold">{t("advertise.yourCampaign")}</h2>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label={`${t("advertise.iAm")} *`} error={errors.advertiser_type}>
            <Select value={values.advertiser_type} onChange={(e) => set("advertiser_type", e.target.value)}>
              {types.map((type) => (
                <option key={type} value={type}>
                  {optionLabel("advertise.advertiserTypes", type)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={`${t("advertise.interest")} *`} error={errors.interest}>
            <Select value={values.interest} onChange={(e) => set("interest", e.target.value)}>
              {interests.map((interest) => (
                <option key={interest} value={interest}>
                  {optionLabel("advertise.interests", interest)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t("advertise.budget")} error={errors.monthly_budget_band ?? errors.monthly_budget}>
            <Select value={values.monthly_budget} onChange={(e) => set("monthly_budget", e.target.value)} disabled={options.isLoading}>
              <option value="">{t("common.choose")}</option>
              {bands.map((band) => (
                <option key={band.key} value={band.key}>
                  {bandLabel(band)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t("advertise.message")} error={errors.message} hint={t("advertise.messageHint")} className="md:col-span-2">
            <Textarea value={values.message} onChange={(e) => set("message", e.target.value)} maxLength={1000} placeholder={t("advertise.messagePlaceholder")} />
          </Field>
        </div>
      </section>
      <div>
        <label className="flex items-start gap-2 text-body">
          <input type="checkbox" className="mt-1 accent-brand" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
          <span>{t("advertise.consent")}</span>
        </label>
        {errors.consent ? <p className="mt-1 text-body-sm text-danger-text">{errors.consent}</p> : null}
      </div>
      {banner ? <Alert tone="danger">{banner}</Alert> : null}
      <div>
        <Button type="submit" size="lg" disabled={pending}>
          {pending ? <Spinner /> : null}
          {t("advertise.send")}
        </Button>
      </div>
    </form>
  );
}
