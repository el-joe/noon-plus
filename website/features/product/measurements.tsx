"use client";

import { useTranslations } from "next-intl";
import { Field, Input, Textarea } from "@/components/ui/field";
import type { MeasurementField } from "@/lib/api/schemas";

export type MeasurementValues = Record<string, string>;

function fieldLabel(field: MeasurementField): string {
  const label = field.label || field.key.replace(/_/g, " ");
  return field.unit ? `${label} (${field.unit})` : label;
}

/** Client-side check mirroring the backend (required + min/max); returns errors per key. */
export function validateMeasurements(
  fields: MeasurementField[],
  values: MeasurementValues,
  t: (key: "required" | "range", values: Record<string, string | number>) => string,
): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const field of fields) {
    const raw = (values[field.key] ?? "").trim();
    if (!raw) {
      if (field.required) errors[field.key] = t("required", { field: fieldLabel(field) });
      continue;
    }
    const n = Number(raw);
    const min = field.min != null ? Number(field.min) : null;
    const max = field.max != null ? Number(field.max) : null;
    if (Number.isNaN(n) || (min != null && n < min) || (max != null && n > max)) {
      errors[field.key] = t("range", { field: fieldLabel(field), min: min ?? "–", max: max ?? "–" });
    }
  }
  return errors;
}

/** Numbers only, empty values dropped — the `measurements` body of `PUT cart/items/{id}/measurements`. */
export function measurementPayload(values: MeasurementValues): Record<string, number | string> {
  return Object.fromEntries(
    Object.entries(values)
      .filter(([, v]) => v.trim() !== "")
      .map(([k, v]) => [k, Number.isNaN(Number(v)) ? v.trim() : Number(v)]),
  );
}

/** "Your measurements" (Figma 65:5397) — made-to-order garment measurements sent to the maker with the order. */
export function MeasurementsForm({
  fields,
  seller,
  values,
  note,
  errors,
  onChange,
  onNoteChange,
}: {
  fields: MeasurementField[];
  seller?: string | null;
  values: MeasurementValues;
  note: string;
  errors: Record<string, string>;
  onChange: (values: MeasurementValues) => void;
  onNoteChange: (note: string) => void;
}) {
  const t = useTranslations("measurements");
  return (
    <section className="flex flex-col gap-3 rounded-lg border border-brand/50 bg-surface p-4">
      <div>
        <h2 className="text-h4 font-semibold">{t("title")}</h2>
        <p className="text-body-sm text-secondary">{t("subtitle", { seller: seller ?? "—" })}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {fields.map((field) => (
          <Field key={field.key} label={`${fieldLabel(field)}${field.required ? " *" : ""}`} error={errors[field.key]}>
            <Input
              inputMode="decimal"
              dir="ltr"
              value={values[field.key] ?? ""}
              placeholder={field.min != null && field.max != null ? `${field.min}–${field.max}` : undefined}
              onChange={(e) => onChange({ ...values, [field.key]: e.target.value })}
            />
          </Field>
        ))}
      </div>
      <Field label={t("note")}>
        <Textarea value={note} maxLength={500} rows={2} placeholder={t("notePlaceholder")} onChange={(e) => onNoteChange(e.target.value)} />
      </Field>
      <p className="rounded-md bg-warning-subtle px-3 py-2 text-body-sm text-warning-text">{t("nonRefundable")}</p>
    </section>
  );
}
