"use client";

import clsx from "clsx";
import { useTranslations } from "next-intl";
import { BOOKING_STATUSES } from "@/lib/api/travel";
import { humanize } from "@/lib/format";

type BookingStatus = (typeof BOOKING_STATUSES)[number];

/** Booking status chip set (Figma 145:6927: Held, Requested, Confirmed, Completed, Rejected, Cancelled, Hold expired). */
const TONES: Record<BookingStatus, string> = {
  held: "bg-brand-subtle text-brand-text",
  requested: "bg-info-subtle text-link",
  confirmed: "bg-success-subtle text-success-text",
  completed: "bg-ink text-white",
  rejected: "bg-warning-subtle text-danger-text",
  cancelled: "bg-subtle text-secondary",
  hold_expired: "bg-subtle text-secondary",
};

export function isBookingStatus(value: string): value is BookingStatus {
  return (BOOKING_STATUSES as readonly string[]).includes(value);
}

export function BookingStatusChip({ status, className }: { status: string; className?: string }) {
  const t = useTranslations("travel.status.chip");
  const known = isBookingStatus(status);
  return (
    <span className={clsx("inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-caption font-medium", known ? TONES[status] : "bg-subtle text-secondary", className)}>
      <span aria-hidden className="size-1.5 rounded-full bg-current" />
      {known ? t(status) : humanize(status)}
    </span>
  );
}
