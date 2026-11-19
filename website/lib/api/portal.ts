import { z } from "zod";
import { ApiError, get, request, type Envelope } from "./client";

/**
 * Portal API (WP8, MASTER_PLAN §5a): join options, partner registration
 * wizards and advertise requests. Wizard calls go through the same-origin
 * proxy `/api/partner-application/{market}/…`, which keeps each
 * application's `X-Application-Token` in an httpOnly cookie.
 */

export const APPLICATION_TYPES = ["vendor", "classified", "travel_agency", "marketer", "broker", "carrier"] as const;
export type ApplicationType = (typeof APPLICATION_TYPES)[number];
export const isApplicationType = (value: string): value is ApplicationType => (APPLICATION_TYPES as readonly string[]).includes(value);

/** ApplicationType::steps() — used before a draft exists. */
export const TYPE_STEPS: Record<ApplicationType, string[]> = {
  vendor: ["account", "store", "address", "documents", "agreement"],
  classified: ["account", "store", "address", "documents", "agreement"],
  travel_agency: ["account", "agency"],
  marketer: ["account", "profile", "documents", "agreement"],
  broker: ["account", "profile", "documents", "agreement"],
  carrier: ["account", "company", "documents", "agreement"],
};

// JoinOptions::forCountry
export const joinOption = z.object({
  section: z.string(),
  label: z.string(),
  enabled: z.boolean(),
  types: z.array(z.object({ type: z.string(), label: z.string(), steps: z.array(z.string()) })),
  /** Roles a visitor picks (round 2): marketer → influencer / affiliate (type `marketer`) and broker (type `broker`). */
  roles: z.array(z.object({ role: z.string(), label: z.string(), type: z.string(), job_types: z.array(z.string()).default([]) })).default([]),
});
export type JoinOption = z.infer<typeof joinOption>;

// PartnerApplicationController::present
export const applicationDocument = z.object({
  document_type_id: z.number(),
  code: z.string(),
  name: z.string(),
  requirement: z.string(),
  has_expiry: z.boolean(),
  max_mb: z.number().nullable(),
  missing: z.boolean(),
  uploads: z.array(
    z.object({
      id: z.number(),
      status: z.string(),
      original_name: z.string().nullable(),
      rejection_reason: z.string().nullable(),
      valid_until: z.string().nullable(),
    }),
  ),
});
export type ApplicationDocument = z.infer<typeof applicationDocument>;

const record = z.record(z.string(), z.unknown()).or(z.array(z.unknown()).transform((): Record<string, unknown> => ({})));

export const application = z.object({
  number: z.string(),
  type: z.string(),
  status: z.string(),
  editable: z.boolean(),
  next_step: z.string().nullable(),
  /** QA-002: the 6-digit code mailed to the application email was confirmed. Submit is refused until then. */
  email_verified: z.boolean().default(false),
  steps: z.array(z.object({ key: z.string(), completed: z.boolean() })),
  account: z.looseObject({ name: z.string().nullable(), email: z.string().nullable(), phone: z.string().nullable() }),
  data: z.record(z.string(), record).or(z.array(z.unknown()).transform((): Record<string, Record<string, unknown>> => ({}))),
  logo_url: z.string().nullable(),
  documents: z.array(applicationDocument),
  agreement: z.object({ required: z.boolean(), kind: z.string(), version_id: z.number().nullable() }),
  requested_changes: z
    .object({ note: z.string().nullable().optional(), steps: z.array(z.string()).optional(), document_ids: z.array(z.number()).optional() })
    .nullable(),
  decision_reason: z.string().nullable(),
  submitted_at: z.string().nullable(),
  decided_at: z.string().nullable(),
});
export type Application = z.infer<typeof application>;

export const agreement = z.union([
  z.object({ required: z.literal(false) }),
  z.object({
    required: z.literal(true),
    kind: z.string(),
    title: z.string(),
    version_id: z.number(),
    version: z.union([z.number(), z.string()]),
    has_file: z.boolean(),
    text: z.string().nullable(),
  }),
]);
export type Agreement = z.infer<typeof agreement>;

// Public reads / writes through the regular client.
export const getJoinOptions = (market: string) => get(market, "join-options", z.array(joinOption));

export type AdvertiseInput = {
  name: string;
  email: string;
  phone: string;
  company?: string;
  advertiser_type: string;
  interest: string;
  monthly_budget?: string;
  monthly_budget_band?: string;
  message?: string;
  consent: boolean;
};
export const ADVERTISER_TYPES = ["seller", "brand", "agency", "other"] as const;
export const AD_INTERESTS = ["product_ads", "display_ads", "both", "not_sure"] as const;
// PortalController@advertiseOptions
export const advertiseOptions = z.object({
  advertiser_types: z.array(z.string()).default([]),
  interests: z.array(z.string()).default([]),
  budget_bands: z.array(z.object({ key: z.string(), label: z.string(), min: z.number().nullable(), max: z.number().nullable(), currency: z.string() })).default([]),
});
export type AdvertiseOptions = z.infer<typeof advertiseOptions>;
export type BudgetBand = AdvertiseOptions["budget_bands"][number];
export const getAdvertiseOptions = (market: string) => get(market, "advertise-requests/options", advertiseOptions);
export const sendAdvertiseRequest = (market: string, body: AdvertiseInput) =>
  request(market, "advertise-requests", z.object({ number: z.string(), status: z.string() }), { method: "POST", body });

// Wizard calls (browser only) through the application proxy.
const errorEnvelope = z.object({
  error: z.looseObject({ code: z.string(), message: z.string(), fields: z.record(z.string(), z.array(z.string())).or(z.array(z.unknown())).optional() }),
});

function retryAfterSeconds(response: Response): number | null {
  if (response.status !== 429) return null;
  const seconds = Number(response.headers.get("retry-after"));
  return Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds) : null;
}

async function wizardCall<S extends z.ZodType>(market: string, path: string, schema: S, init: { method?: "GET" | "POST" | "DELETE"; body?: unknown; query?: Record<string, string> } = {}): Promise<Envelope<z.infer<S>>> {
  const isForm = typeof FormData !== "undefined" && init.body instanceof FormData;
  const qs = init.query ? `?${new URLSearchParams(init.query).toString()}` : "";
  let response: Response;
  try {
    response = await fetch(`/api/partner-application/${market}${path ? `/${path}` : ""}${qs}`, {
      method: init.method ?? "GET",
      cache: "no-store",
      headers: { Accept: "application/json", ...(init.body !== undefined && !isForm ? { "Content-Type": "application/json" } : {}) },
      body: init.body === undefined ? undefined : isForm ? (init.body as FormData) : JSON.stringify(init.body),
    });
  } catch (cause) {
    throw new ApiError(0, "network_error", cause instanceof Error ? cause.message : "Network error");
  }
  const json: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const parsed = errorEnvelope.safeParse(json);
    if (parsed.success) {
      const { code, message, fields, ...details } = parsed.data.error;
      throw new ApiError(response.status, code, message, Array.isArray(fields) ? {} : (fields ?? {}), details, retryAfterSeconds(response));
    }
    throw new ApiError(response.status, response.status === 429 ? "too_many_requests" : "http_error", response.statusText || "Request failed", {}, {}, retryAfterSeconds(response));
  }
  const parsed = z.object({ data: schema, meta: z.unknown().optional() }).safeParse(json);
  if (!parsed.success) throw new ApiError(response.status, "invalid_response", "The server returned an unexpected response.");
  return { data: (parsed.data as { data: z.infer<S> }).data, meta: {} };
}

const enc = encodeURIComponent;

export const myApplicationNumbers = (market: string) => wizardCall(market, "mine", z.array(z.string()));
export const forgetApplication = (market: string, number: string) => wizardCall(market, `forget/${enc(number)}`, z.unknown(), { method: "DELETE" });
export const resumeApplication = (market: string, number: string, token: string) =>
  wizardCall(market, "resume", application, { method: "POST", body: { number, token } });

export const startApplication = (market: string, type: ApplicationType, account: Record<string, unknown>) =>
  wizardCall(market, "", application, { method: "POST", body: { type, ...account } });
export const getApplication = (market: string, number: string) => wizardCall(market, enc(number), application);
export const saveApplicationStep = (market: string, number: string, step: string, body: Record<string, unknown> | FormData) =>
  wizardCall(market, `${enc(number)}/steps/${enc(step)}`, application, { method: "POST", body });
export const uploadApplicationDocument = (market: string, number: string, form: FormData) =>
  wizardCall(market, `${enc(number)}/documents`, z.object({ id: z.number(), status: z.string(), application }), { method: "POST", body: form });
export const removeApplicationDocument = (market: string, number: string, documentId: number) =>
  wizardCall(market, `${enc(number)}/documents/${documentId}`, application, { method: "DELETE" });
export const getApplicationAgreement = (market: string, number: string) => wizardCall(market, `${enc(number)}/agreement`, agreement);
export const submitApplication = (market: string, number: string, fullName: string) =>
  wizardCall(market, `${enc(number)}/submit`, application, { method: "POST", body: { accept: true, full_name: fullName } });
// QA-002: confirm the application email with the mailed 6-digit code.
export const verifyApplicationEmail = (market: string, number: string, code: string) =>
  wizardCall(market, `${enc(number)}/email/verify`, application, { method: "POST", body: { code } });
export const resendApplicationEmailCode = (market: string, number: string) =>
  wizardCall(market, `${enc(number)}/email/resend`, z.object({ sent: z.boolean() }), { method: "POST" });
export const withdrawApplication = (market: string, number: string) => wizardCall(market, `${enc(number)}/withdraw`, application, { method: "POST" });
export const checkStoreSlug = (market: string, slug: string) =>
  wizardCall(market, "check-slug", z.object({ slug: z.string(), available: z.boolean() }), { query: { slug } });
export const requestResumeLink = (market: string, email: string, type: ApplicationType) =>
  wizardCall(market, "resume-link", z.unknown(), { method: "POST", body: { email, type } });

/** QC-042: partner agreements with a public page (`GET contracts/current/{kind}`). */
export const PUBLIC_AGREEMENTS = ["seller_platform", "travel_agency", "broker", "marketer_onboarding", "shipping_company"] as const;
export type PublicAgreement = (typeof PUBLIC_AGREEMENTS)[number];
export const isPublicAgreement = (value: string | undefined): value is PublicAgreement => (PUBLIC_AGREEMENTS as readonly string[]).includes(value ?? "");
export const currentAgreement = z.object({
  id: z.number(),
  kind: z.string(),
  title: z.string(),
  version: z.union([z.number(), z.string()]),
  has_file: z.boolean(),
  text: z.string().nullable(),
  published_at: z.string().nullable(),
});
export type CurrentAgreement = z.infer<typeof currentAgreement>;
export const getCurrentAgreement = (market: string, kind: PublicAgreement) => get(market, `contracts/current/${kind}`, currentAgreement);
