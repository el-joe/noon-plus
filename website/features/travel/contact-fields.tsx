"use client";

import { useTranslations } from "next-intl";
import { Field, Input } from "@/components/ui/field";
import { useMarket } from "@/lib/market-context";

export type Contact = { name: string; email: string; phone: string };

const DIAL: Record<string, string> = { uae: "+971", omn: "+968", ksa: "+966", egy: "+20", kwt: "+965", qat: "+974", bhr: "+973", jor: "+962" };

export function dialCode(countryCode: string): string {
  return DIAL[countryCode] ?? "+";
}

/** Name, email and mobile (D8 no-payment requests; the API validates the phone for the market's country). */
export function ContactFields({ value, onChange, errors = {} }: { value: Contact; onChange: (next: Contact) => void; errors?: Record<string, string> }) {
  const t = useTranslations("travel.contact");
  const market = useMarket();
  const code = dialCode(market.country.code);
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={`${t("name")} *`} error={errors.name}>
        <Input required minLength={2} maxLength={120} autoComplete="name" value={value.name} onChange={(e) => onChange({ ...value, name: e.target.value })} placeholder={t("namePlaceholder")} aria-invalid={!!errors.name} className={errors.name ? "border-danger" : undefined} />
      </Field>
      <Field label={`${t("email")} *`} error={errors.email}>
        <Input required type="email" maxLength={255} autoComplete="email" dir="ltr" value={value.email} onChange={(e) => onChange({ ...value, email: e.target.value })} placeholder={t("emailPlaceholder")} aria-invalid={!!errors.email} className={errors.email ? "border-danger" : undefined} />
      </Field>
      <Field label={`${t("phone")} *`} error={errors.phone} hint={errors.phone ? undefined : t("phoneHint", { code })} className="sm:col-span-2">
        <Input required type="tel" maxLength={24} autoComplete="tel" dir="ltr" inputMode="tel" value={value.phone} onChange={(e) => onChange({ ...value, phone: e.target.value })} placeholder={`${code} …`} aria-invalid={!!errors.phone} className={errors.phone ? "border-danger" : undefined} />
      </Field>
    </div>
  );
}
