import { NextResponse, type NextRequest } from "next/server";
import { parseMarket } from "@/lib/markets";
import { TOKEN_COOKIE } from "@/lib/session";

/**
 * `GET /api/invoice/{market}/{number}` — the printable HTML tax invoice of an
 * order (wave 5a `GET orders/{n}/invoice`, G-A6). The browser opens it in a
 * new tab and prints or saves it as PDF. `?format=pdf` downloads the PDF the
 * API draws (`GET orders/{n}/invoice/pdf`, D16). The bearer token stays in the
 * httpOnly cookie; refusals (409 `invoice_not_ready`, 404) answer a small
 * HTML page with the API's localised message.
 */

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");
const NUMBER = /^[A-Za-z0-9-]{3,40}$/;

type Ctx = { params: Promise<{ market: string; number: string }> };

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]!);
}

function page(status: number, locale: "en" | "ar", message: string): Response {
  const dir = locale === "ar" ? "rtl" : "ltr";
  const title = locale === "ar" ? "الفاتورة غير متاحة" : "Invoice not available";
  const html = `<!doctype html><html lang="${locale}" dir="${dir}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>${title}</title><style>body{font-family:Inter,Cairo,Arial,sans-serif;color:#101828;margin:48px auto;max-width:560px;padding:0 16px}h1{font-size:20px}p{color:#475467}</style></head><body><h1>${title}</h1><p>${escapeHtml(message)}</p></body></html>`;
  return new NextResponse(html, { status, headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" } });
}

export async function GET(request: NextRequest, { params }: Ctx): Promise<Response> {
  const { market: slug, number } = await params;
  const market = parseMarket(slug);
  if (!market || !NUMBER.test(number)) return page(404, "en", "Unknown order.");
  const locale = market.locale;
  const pdf = request.nextUrl.searchParams.get("format") === "pdf";
  const token = request.cookies.get(TOKEN_COOKIE)?.value;
  if (!token) return page(401, locale, locale === "ar" ? "سجّل الدخول لعرض الفاتورة." : "Sign in to see this invoice.");

  let upstream: Response;
  try {
    upstream = await fetch(`${API_ROOT}/${market.slug}/orders/${encodeURIComponent(number)}/invoice${pdf ? "/pdf" : ""}`, {
      headers: { Accept: pdf ? "application/pdf, application/json" : "text/html, application/json", Authorization: `Bearer ${token}` },
      cache: "no-store",
    });
  } catch {
    return page(502, locale, locale === "ar" ? "تعذّر الوصول إلى المتجر الآن." : "The store is not reachable right now.");
  }

  if (!upstream.ok) {
    const json = (await upstream.json().catch(() => null)) as { error?: { message?: string }; message?: string } | null;
    return page(upstream.status, locale, json?.error?.message ?? json?.message ?? upstream.statusText);
  }

  const headers: Record<string, string> = {
    "Content-Type": upstream.headers.get("content-type") ?? (pdf ? "application/pdf" : "text/html; charset=utf-8"),
    "Cache-Control": "private, no-store",
    "X-Robots-Tag": "noindex",
  };
  const disposition = upstream.headers.get("content-disposition");
  if (disposition) headers["Content-Disposition"] = disposition;
  return new NextResponse(upstream.body, { status: 200, headers });
}
