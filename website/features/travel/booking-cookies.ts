/**
 * Guest travel bookings on this device (MASTER_PLAN D8). The access token of
 * each booking lives in an httpOnly cookie set by /api/travel-booking; the
 * readable list cookie only holds booking numbers (no secrets).
 */
export const bookingTokenCookie = (number: string) => `nawy_booking_${number.replace(/[^A-Za-z0-9-]/g, "")}`;
export const GUEST_BOOKINGS_COOKIE = (countryCode: string) => `nawy_bookings_${countryCode}`;

export function readGuestBookingNumbers(countryCode: string): string[] {
  if (typeof document === "undefined") return [];
  const name = GUEST_BOOKINGS_COOKIE(countryCode);
  const match = document.cookie.split("; ").find((part) => part.startsWith(`${name}=`));
  if (!match) return [];
  return decodeURIComponent(match.slice(name.length + 1))
    .split(",")
    .filter((n) => /^[A-Za-z0-9-]{3,40}$/.test(n));
}
