"use client";

import { useEffect } from "react";
import { buildUrl } from "@/lib/api/client";
import { useMarket } from "@/lib/market-context";

/**
 * Portal funnel tracking (QC-042, FR-POR-2/8): each event is sent to
 * `POST /{market}/portal/funnel` (through the BFF, which adds the anonymous
 * visitor token; `keepalive` so a submit right before navigating still
 * arrives) and stored in `portal_funnel_events` for Admin → Portal
 * analytics. It is also pushed to `window.dataLayer` (for a tag manager)
 * and dispatched as a `nawy:portal-funnel` DOM event.
 */
export type PortalFunnelEvent = { event: "view" | "step" | "submitted"; page: string; step?: string; type?: string };

declare global {
  interface Window {
    dataLayer?: Record<string, unknown>[];
  }
}

export function trackPortalEvent(market: string, payload: PortalFunnelEvent) {
  if (typeof window === "undefined") return;
  const detail = { ...payload, market, at: new Date().toISOString() };
  try {
    (window.dataLayer ??= []).push({ ...detail, event: `portal_${payload.event}` });
    window.dispatchEvent(new CustomEvent("nawy:portal-funnel", { detail }));
    void fetch(buildUrl(market, "portal/funnel"), {
      method: "POST",
      keepalive: true,
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify({ event: payload.event, page: payload.page, type: payload.type, step: payload.step }),
    }).catch(() => undefined);
  } catch {
    // Tracking never breaks the page.
  }
}

/** Records one view of a portal page. */
export function FunnelView({ page }: { page: string }) {
  const market = useMarket();
  useEffect(() => trackPortalEvent(market.slug, { event: "view", page }), [market.slug, page]);
  return null;
}

/** Records each wizard step the applicant reaches (and the submit). */
export function useFunnelStep(type: string, step: string, submitted: boolean) {
  const market = useMarket();
  useEffect(() => trackPortalEvent(market.slug, { event: "step", page: `join/${type}`, type, step }), [market.slug, type, step]);
  useEffect(() => {
    if (submitted) trackPortalEvent(market.slug, { event: "submitted", page: `join/${type}`, type });
  }, [market.slug, type, submitted]);
}
