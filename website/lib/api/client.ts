import { z } from "zod";

/**
 * Typed client for the Laravel API envelope:
 *   success → {data, meta}; failure → {error: {code, message, fields}}.
 *
 * On the server it calls NEXT_PUBLIC_API_URL directly (public reads only).
 * In the browser it calls the same-origin BFF proxy `/api/nawy/*`, which adds
 * the Sanctum bearer token and the guest cart token from httpOnly cookies
 * (see app/api/nawy/[...path]/route.ts), so tokens never reach page JS.
 */

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public fields: Record<string, string[]> = {},
    public details: Record<string, unknown> = {},
    /** Seconds from the `Retry-After` header of a 429 (throttled), or null. */
    public retryAfter: number | null = null,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

export type Query = Record<string, string | number | boolean | null | undefined>;

export type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  query?: Query;
  body?: unknown;
  headers?: Record<string, string>;
  signal?: AbortSignal;
};

/** Codes that mean the stored session can no longer be used (sign in again). */
export const SESSION_ENDED_CODES = ["unauthenticated", "account_suspended", "wrong_token"] as const;

export function isSessionEnded(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || (error.status === 403 && (SESSION_ENDED_CODES as readonly string[]).includes(error.code)));
}

function parseRetryAfter(value: string | null): number | null {
  if (!value) return null;
  const seconds = Number(value);
  if (Number.isFinite(seconds)) return Math.max(0, Math.ceil(seconds));
  const date = Date.parse(value);
  return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000));
}

/** Language for calls outside a market (QC-028): the page's `<html lang>`. */
function currentLanguage(): string {
  if (typeof document !== "undefined") return document.documentElement.lang?.startsWith("ar") ? "ar" : "en";
  return "en";
}

export type Envelope<T> = { data: T; meta: Record<string, unknown> };

export function apiRoot(): string {
  if (typeof window !== "undefined") return "/api/nawy";
  return (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");
}

export function buildUrl(market: string | null, path: string, query?: Query): string {
  const segments = [apiRoot(), market, path.replace(/^\//, "")].filter(Boolean).join("/");
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== "") params.set(key, String(value));
  }
  const qs = params.toString();
  return qs ? `${segments}?${qs}` : segments;
}

const errorEnvelope = z.object({
  error: z.looseObject({
    code: z.string(),
    message: z.string(),
    fields: z.record(z.string(), z.array(z.string())).or(z.array(z.unknown())).optional(),
  }),
});

export async function request<S extends z.ZodType>(
  market: string | null,
  path: string,
  schema: S,
  options: RequestOptions = {},
): Promise<Envelope<z.infer<S>>> {
  const { method = "GET", query, body, headers = {}, signal } = options;
  const isForm = typeof FormData !== "undefined" && body instanceof FormData;
  const init: RequestInit = {
    method,
    signal,
    cache: "no-store",
    headers: {
      Accept: "application/json",
      ...(market === null ? { "Accept-Language": currentLanguage() } : {}),
      ...(body !== undefined && !isForm ? { "Content-Type": "application/json" } : {}),
      ...headers,
    },
    body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
  };

  let response: Response;
  try {
    response = await fetch(buildUrl(market, path, query), init);
  } catch (cause) {
    throw new ApiError(0, "network_error", cause instanceof Error ? cause.message : "Network error");
  }

  const json: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    const retryAfter = response.status === 429 ? parseRetryAfter(response.headers.get("retry-after")) : null;
    const parsed = errorEnvelope.safeParse(json);
    if (parsed.success) {
      const { code, message, fields, ...details } = parsed.data.error;
      throw new ApiError(response.status, code, message, Array.isArray(fields) ? {} : (fields ?? {}), details, retryAfter);
    }
    // Laravel's own responses (404 page, 401 Unauthenticated, 422 validation).
    const raw = (json ?? {}) as { message?: string; errors?: Record<string, string[]> };
    const code = response.status === 401 ? "unauthenticated" : response.status === 404 ? "not_found" : response.status === 422 ? "validation_failed" : response.status === 429 ? "too_many_requests" : "http_error";
    throw new ApiError(response.status, code, raw.message || response.statusText || code, raw.errors ?? {}, {}, retryAfter);
  }

  const envelope = z.object({ data: schema, meta: z.record(z.string(), z.unknown()).or(z.array(z.unknown())).optional() });
  const parsed = envelope.safeParse(json);
  if (!parsed.success) {
    if (process.env.NODE_ENV !== "production") {
      console.error(`[api] unexpected response shape for ${path}`, parsed.error.issues.slice(0, 5));
    }
    throw new ApiError(response.status, "invalid_response", "The server returned an unexpected response.");
  }

  const result = parsed.data as { data: z.infer<S>; meta?: Record<string, unknown> | unknown[] };
  const meta = result.meta && !Array.isArray(result.meta) ? result.meta : {};
  return { data: result.data, meta };
}

export async function get<S extends z.ZodType>(market: string | null, path: string, schema: S, query?: Query, init?: Omit<RequestOptions, "query" | "method">) {
  return request(market, path, schema, { ...init, query, method: "GET" });
}

/** Read a 404 as null (missing page / product) and rethrow anything else. */
export async function orNull<T>(promise: Promise<T>): Promise<T | null> {
  try {
    return await promise;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** 422 `error.fields` → first message per field (for inline form errors). */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError)) return {};
  const out: Record<string, string> = {};
  for (const [key, messages] of Object.entries(error.fields)) {
    if (messages?.[0]) out[key] = messages[0];
  }
  return out;
}
