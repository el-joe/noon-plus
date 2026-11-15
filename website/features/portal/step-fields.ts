import type { Application, ApplicationType } from "@/lib/api/portal";

/**
 * Data-step fields of the registration wizards, mirroring
 * ApplicationWizard::rules() (backend). Labels come from `portal.fields.*`,
 * select / checkbox labels from `portal.options.{field}.{value}`.
 */

export type FieldKind = "text" | "email" | "tel" | "password" | "date" | "number" | "textarea" | "select" | "checks" | "list" | "socials" | "file" | "slug" | "category";
export type FieldDef = {
  name: string;
  kind: FieldKind;
  required?: boolean;
  options?: readonly string[];
  /** Hidden unless this returns true for the current values. */
  showIf?: (values: Values) => boolean;
  /** Field spans both grid columns. */
  wide?: boolean;
  ltr?: boolean;
  autoComplete?: string;
  max?: number;
};

export type Values = Record<string, string | string[] | File | null>;

export const SOCIAL_NETWORKS = ["instagram", "tiktok", "youtube", "snapchat", "x", "facebook"] as const;
const VENDOR_BUSINESS = ["llc", "sole_proprietorship", "partnership", "corporation"] as const;
const CLASSIFIED_BUSINESS = ["individual", "business"] as const;
const notIndividual = (v: Values) => v.business_type !== "individual";

export function stepFields(type: ApplicationType, step: string, isNew: boolean): FieldDef[] {
  switch (step) {
    case "account":
      return [
        { name: "name", kind: "text", required: true, autoComplete: "name", max: 120 },
        { name: "email", kind: "email", required: true, autoComplete: "email", ltr: true },
        { name: "phone", kind: "tel", required: true, autoComplete: "tel", ltr: true },
        { name: "gender", kind: "select", options: ["male", "female"] },
        { name: "password", kind: "password", required: isNew, autoComplete: "new-password", ltr: true },
        { name: "password_confirmation", kind: "password", required: isNew, autoComplete: "new-password", ltr: true },
      ];
    case "store":
      return [
        { name: "store_name", kind: "text", required: true, max: 120 },
        { name: "slug", kind: "slug", required: true, ltr: true },
        { name: "business_type", kind: "select", required: true, options: type === "classified" ? CLASSIFIED_BUSINESS : VENDOR_BUSINESS },
        { name: "legal_name", kind: "text", required: true, showIf: notIndividual, max: 160 },
        { name: "cr_number", kind: "text", required: true, showIf: notIndividual, ltr: true },
        { name: "licence_expiry", kind: "date", showIf: notIndividual },
        { name: "tax_id", kind: "text", ltr: true },
        ...(type === "vendor" ? [{ name: "fulfilment", kind: "select" as const, required: true, options: ["fbm", "fbn", "both"] }] : []),
        // Category ids from `categories/tree` (round 2).
        { name: "main_category_id", kind: "category" },
        { name: "description", kind: "textarea", wide: true, max: 1000 },
      ];
    case "address":
      return [
        { name: "city", kind: "text", required: true, autoComplete: "address-level2" },
        { name: "area", kind: "text", autoComplete: "address-level3" },
        { name: "street", kind: "text", required: true, autoComplete: "address-line1", wide: true },
        { name: "building", kind: "text", autoComplete: "address-line2" },
        { name: "postal_code", kind: "text", autoComplete: "postal-code", ltr: true },
        { name: "landmark", kind: "text", wide: true },
      ];
    case "agency":
      return [
        { name: "agency_name", kind: "text", required: true, max: 120 },
        { name: "licence_number", kind: "text", required: true, ltr: true },
        { name: "city", kind: "text" },
        { name: "logo", kind: "file", required: isNew },
        { name: "description", kind: "textarea", wide: true, max: 1000 },
      ];
    case "profile":
      return type === "broker"
        ? [
            { name: "display_name", kind: "text", required: true, max: 120 },
            { name: "licence_number", kind: "text", required: true, ltr: true },
            { name: "licence_expiry", kind: "date" },
            { name: "company_name", kind: "text" },
            { name: "whatsapp_phone", kind: "tel", required: true, ltr: true },
            { name: "areas", kind: "list" },
            { name: "bio", kind: "textarea", wide: true, max: 160 },
          ]
        : [
            { name: "job_types", kind: "checks", required: true, options: ["influencer", "affiliate"], wide: true },
            { name: "display_name", kind: "text", required: true, max: 120 },
            { name: "niche", kind: "text", max: 80 },
            { name: "whatsapp_phone", kind: "tel", required: true, ltr: true },
            { name: "followers", kind: "number" },
            { name: "languages", kind: "list" },
            { name: "socials", kind: "socials", wide: true },
            { name: "bio", kind: "textarea", wide: true, max: 160 },
          ];
    case "company":
      return [
        { name: "legal_name", kind: "text", required: true, max: 160 },
        { name: "licence_number", kind: "text", required: true, ltr: true },
        { name: "fleet_size", kind: "number" },
        { name: "coverage", kind: "list" },
        { name: "services", kind: "checks", options: ["same_day", "next_day", "standard", "cod", "returns", "bulk"], wide: true },
        { name: "api_integration", kind: "text", wide: true },
      ];
    default:
      return [];
  }
}

/** Saved values of a step (application `data.{step}` / `account`) as form values. */
export function initialValues(fields: FieldDef[], app: Application | null, step: string, extra: Values = {}): Values {
  const saved: Record<string, unknown> = step === "account" ? { ...(app?.account ?? {}) } : { ...(app?.data?.[step] ?? {}) };
  const values: Values = {};
  for (const field of fields) {
    const raw = saved[field.name];
    if (field.kind === "checks") values[field.name] = Array.isArray(raw) ? raw.map(String) : [];
    else if (field.kind === "list") values[field.name] = Array.isArray(raw) ? raw.map(String).join(", ") : "";
    else if (field.kind === "socials") {
      for (const network of SOCIAL_NETWORKS) {
        const found = Array.isArray(raw) ? (raw as { network?: string; handle?: string }[]).find((s) => s?.network === network) : undefined;
        values[`social_${network}`] = found?.handle ?? "";
      }
    } else if (field.kind === "file" || field.kind === "password") values[field.name] = field.kind === "file" ? null : "";
    else values[field.name] = raw === null || raw === undefined ? "" : String(raw);
  }
  return { ...values, ...extra };
}

/** Form values → request body (JSON, or FormData when a file is attached). */
export function buildBody(fields: FieldDef[], values: Values): Record<string, unknown> | FormData {
  const body: Record<string, unknown> = {};
  let file: { name: string; file: File } | null = null;
  for (const field of fields) {
    if (field.showIf && !field.showIf(values)) continue;
    const value = values[field.name];
    switch (field.kind) {
      case "checks":
        body[field.name] = Array.isArray(value) ? value : [];
        break;
      case "list": {
        const items = String(value ?? "").split(/[,\n،]/).map((x) => x.trim()).filter(Boolean);
        if (items.length) body[field.name] = items;
        break;
      }
      case "socials": {
        const socials = SOCIAL_NETWORKS.map((network) => ({ network, handle: String(values[`social_${network}`] ?? "").trim() })).filter((s) => s.handle);
        if (socials.length) body.socials = socials;
        break;
      }
      case "number":
      case "category":
        if (value !== "" && value !== null) body[field.name] = Number(value);
        break;
      case "file":
        if (value instanceof File) file = { name: field.name, file: value };
        break;
      default:
        if (typeof value === "string" && value.trim() !== "") body[field.name] = value.trim();
    }
  }
  if (!file) return body;
  const form = new FormData();
  for (const [key, value] of Object.entries(body)) {
    if (Array.isArray(value)) value.forEach((v, i) => form.append(`${key}[${i}]`, typeof v === "object" ? JSON.stringify(v) : String(v)));
    else form.append(key, String(value));
  }
  form.append(file.name, file.file);
  return form;
}
