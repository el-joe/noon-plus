import { NextResponse, type NextRequest } from "next/server";
import { DEFAULT_MARKET, MARKET_COOKIE, parseMarket } from "@/lib/markets";

/**
 * URLs without a market prefix redirect to the visitor's last market (cookie)
 * or the default market (MASTER_PLAN §4a: "URLs without a prefix redirect to
 * the session country"). Visiting a market remembers it.
 */
export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  const first = pathname.split("/")[1] ?? "";
  const market = parseMarket(first);

  if (market) {
    const response = NextResponse.next();
    if (request.cookies.get(MARKET_COOKIE)?.value !== market.slug) {
      response.cookies.set(MARKET_COOKIE, market.slug, { path: "/", maxAge: 60 * 60 * 24 * 365, sameSite: "lax" });
    }
    return response;
  }

  const remembered = parseMarket(request.cookies.get(MARKET_COOKIE)?.value)?.slug ?? DEFAULT_MARKET;
  const url = request.nextUrl.clone();
  url.pathname = `/${remembered}${pathname === "/" ? "" : pathname}`;
  url.search = search;
  return NextResponse.redirect(url);
}

export const config = {
  // Skip Next internals, the BFF API and files with an extension (public assets).
  matcher: ["/((?!api/|_next/|.*\\.[a-zA-Z0-9]+$).*)"],
};
