import { NextResponse, type NextRequest } from "next/server";
import { DEFAULT_MARKET, parseMarket } from "@/lib/markets";
import { AUTH_HINT_COOKIE, TOKEN_COOKIE } from "@/lib/session";

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");

/**
 * Sign out: revoke this browser's Sanctum token with `POST auth/logout`
 * (G-W7, current token only), then drop the cookies.
 */
export async function DELETE(request: NextRequest) {
  const origin = request.headers.get("origin");
  if (origin && origin !== request.nextUrl.origin) {
    return NextResponse.json({ error: { code: "forbidden", message: "Cross-site request refused.", fields: {} } }, { status: 403 });
  }

  const market = parseMarket(request.nextUrl.searchParams.get("market"))?.slug ?? DEFAULT_MARKET;
  const token = request.cookies.get(TOKEN_COOKIE)?.value;

  if (token) {
    try {
      await fetch(`${API_ROOT}/${market}/auth/logout`, {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
        cache: "no-store",
      });
    } catch {
      // The cookie is removed below either way.
    }
  }

  const response = NextResponse.json({ data: { signed_out: true }, meta: {} });
  response.cookies.delete(TOKEN_COOKIE);
  response.cookies.delete(AUTH_HINT_COOKIE);
  return response;
}
