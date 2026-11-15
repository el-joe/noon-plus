"use client";

import { useTranslations } from "next-intl";
import { humanize } from "@/lib/format";
import { Badge } from "./misc";

const TONES: Record<string, "success" | "warning" | "danger" | "info" | "neutral"> = {
  delivered: "success",
  completed: "success",
  paid: "success",
  captured: "success",
  authorized: "success",
  approved: "success",
  refunded: "success",
  cancelled: "danger",
  failed: "danger",
  rejected: "danger",
  expired: "danger",
  out_for_delivery: "info",
  shipped: "info",
  partially_shipped: "info",
  processing: "warning",
  pending: "warning",
  placed: "warning",
};

export function StatusBadge({ status }: { status: string }) {
  const t = useTranslations("status");
  const key = status as "pending";
  return <Badge tone={TONES[status] ?? "neutral"}>{t.has(key) ? t(key) : humanize(status)}</Badge>;
}
