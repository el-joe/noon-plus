import type { Metadata } from "next";
import "./globals.css";
import { DEFAULT_MARKET } from "@/lib/markets";

export const metadata: Metadata = { title: "404 — Nawy" };

/** URLs that match no route at all (e.g. an unknown market such as /xyz-fr). */
export default function GlobalNotFound() {
  return (
    <html lang="en" dir="ltr">
      <body className="flex min-h-screen items-center justify-center bg-page p-6 font-sans text-ink">
        <div className="max-w-md text-center">
          <p className="text-h1 font-bold">404</p>
          <h1 className="mt-2 text-h3 font-semibold">We couldn&apos;t find that page · لم نتمكن من العثور على هذه الصفحة</h1>
          <a href={`/${DEFAULT_MARKET}`} className="mt-6 inline-flex h-11 items-center rounded-md bg-brand px-5 font-semibold">
            Nawy
          </a>
        </div>
      </body>
    </html>
  );
}
