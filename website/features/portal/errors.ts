import { ApiError } from "@/lib/api/client";

/** 422 `error.fields` → first message per field (dotted keys kept, e.g. `socials.0.handle`). */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError)) return {};
  return Object.fromEntries(Object.entries(error.fields).map(([key, messages]) => [key, messages[0] ?? ""]));
}

/** A banner message for errors that are not tied to one field. */
export function bannerError(error: unknown, fallback: string): string | null {
  if (!error) return null;
  if (error instanceof ApiError) {
    if (error.code === "network_error" || error.code === "api_unreachable" || error.status >= 500) return fallback;
    if (error.code === "validation_failed" && Object.keys(error.fields).length > 0) return null;
    return error.message || fallback;
  }
  return fallback;
}
