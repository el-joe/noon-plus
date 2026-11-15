import type { Money } from "./api/schemas";
import type { Locale } from "./markets";

/**
 * Money arrives already rounded to the currency's minor unit by the API
 * (MoneyResource: 2 decimals, 3 for OMR/KWD/BHD/JOD). We only add grouping and
 * keep Latin digits in both languages, as on the Figma frames.
 */
export function formatAmount(amount: string): string {
  const [whole, fraction] = amount.split(".");
  const negative = whole.startsWith("-");
  const digits = negative ? whole.slice(1) : whole;
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  return `${negative ? "-" : ""}${grouped}${fraction !== undefined ? `.${fraction}` : ""}`;
}

export function formatMoney(money: Money | null | undefined): string {
  if (!money) return "";
  return `${money.currency} ${formatAmount(money.amount)}`;
}

export function isZero(money: Money | null | undefined): boolean {
  return !money || Number(money.amount) === 0;
}

export function discountPercent(price: Money | null | undefined, was: Money | null | undefined): number | null {
  if (!price || !was) return null;
  const p = Number(price.amount);
  const w = Number(was.amount);
  if (!(w > p) || w <= 0) return null;
  return Math.round(((w - p) / w) * 100);
}

const intlLocale = (locale: Locale) => (locale === "ar" ? "ar-u-nu-latn" : "en-GB");

/** "15 Nov" / "Sat, 15 Nov" for delivery promises (date-only strings). */
export function formatDay(value: string | null | undefined, locale: Locale, withWeekday = false): string {
  if (!value) return "";
  const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00Z`) : new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(intlLocale(locale), {
    day: "numeric",
    month: "short",
    ...(withWeekday ? { weekday: "short" } : {}),
    timeZone: "UTC",
  }).format(date);
}

export function formatDateTime(value: string | null | undefined, locale: Locale, timeZone?: string): string {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: "medium", timeStyle: "short", timeZone }).format(date);
}

export function humanize(value: string): string {
  const text = value.replace(/[_-]+/g, " ").trim();
  return text.charAt(0).toUpperCase() + text.slice(1);
}
