import { Globe, Headphones, Mail } from "lucide-react";
import Link from "next/link";
import { CookieSettingsLink } from "@/features/content/cookie-consent";
import { getFooter } from "@/lib/api/endpoints";
import type { Footer as FooterData } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import { COUNTRIES, type Market } from "@/lib/markets";
import { marketHref } from "@/lib/server-market";

const PAYMENT_BADGES = ["VISA", "Mastercard", "Apple Pay", "Thawani", "PayTabs", "PayPal", "Tabby", "Tamara"];

/** Footer (Figma 17:137) — columns, social links and copyright from `GET footer` (Admin → Content → Footer). */
export async function Footer({ market }: { market: Market }) {
  let data: FooterData | null = null;
  try {
    data = (await getFooter(market.slug)).data;
  } catch {
    data = null;
  }
  return <FooterView market={market} data={data} year={new Date().getUTCFullYear()} />;
}

/** Static fallback while the footer streams in (and when the API is unreachable). */
export function FooterFallback({ market }: { market: Market }) {
  return <FooterView market={market} data={null} year={null} />;
}

function FooterView({ market, data, year }: { market: Market; data: FooterData | null; year: number | null }) {
  const t = getT(market.locale);
  const columns =
    data && data.columns.length > 0
      ? data.columns
      : [
          {
            title: t("footer.shop"),
            links: [
              { label: t("common.home"), url: "/" },
              { label: t("search.title"), url: "/search" },
            ],
          },
          {
            title: t("footer.account"),
            links: [
              { label: t("footer.orders"), url: "/account/orders" },
              { label: t("footer.cart"), url: "/cart" },
              { label: t("account.nav.credits"), url: "/account/credits" },
            ],
          },
          {
            title: t("content.footer.marketplaces"),
            links: [
              { label: t("content.footer.classifieds"), url: "/classified" },
              { label: t("content.footer.realEstate"), url: "/property" },
              { label: t("content.footer.travel"), url: "/travel" },
              { label: t("content.footer.creators"), url: "/creators" },
              { label: t("content.footer.nawyTv"), url: "/live" },
            ],
          },
          {
            title: t("content.footer.nawy"),
            links: [
              { label: t("content.footer.about"), url: "/pages/about-us" },
              { label: t("content.footer.contact"), url: "/pages/contact-us" },
              { label: t("content.footer.blog"), url: "/blog" },
              { label: t("content.footer.sell"), url: "/join" },
              { label: t("content.footer.adsHub"), url: "/adsupport" },
            ],
          },
          {
            title: t("content.footer.help"),
            links: [
              { label: t("content.footer.helpCenter"), url: "/help" },
              { label: t("footer.orders"), url: "/account/orders" },
              { label: t("account.nav.returns"), url: "/account/returns" },
            ],
          },
          {
            title: t("content.footer.policies"),
            links: [
              { label: t("content.footer.terms"), url: "/pages/terms-and-conditions" },
              { label: t("content.footer.privacy"), url: "/pages/privacy-policy" },
              { label: t("content.footer.returns"), url: "/pages/return-policy" },
              { label: t("content.footer.shipping"), url: "/pages/shipping-policy" },
              { label: t("content.footer.refund"), url: "/pages/refund-policy" },
            ],
          },
        ];
  const social = data && !Array.isArray(data.social) ? Object.entries(data.social) : [];
  const resolve = (url: string) => (url.startsWith("/") ? marketHref(market, url) : url);

  return (
    <footer className="mt-12 border-t border-line">
      <div className="bg-page">
        <div className="mx-auto flex max-w-(--container-page) flex-wrap items-center gap-8 px-4 py-6 md:px-8">
          <div>
            <p className="text-h4 font-semibold">{t("footer.helpTitle")}</p>
            <p className="text-body-sm text-secondary">{t("footer.helpSubtitle")}</p>
          </div>
          <div className="flex items-center gap-3">
            <span className="flex size-10 items-center justify-center rounded-full border border-line bg-surface">
              <Headphones className="size-5" />
            </span>
            <span className="flex flex-col">
              <span className="text-caption uppercase text-secondary">{t("footer.helpCenter")}</span>
              <Link href={marketHref(market, "/help")} className="text-body font-medium hover:underline">
                {t("content.footer.helpCenter")}
              </Link>
            </span>
          </div>
          <div className="flex items-center gap-3">
            <span className="flex size-10 items-center justify-center rounded-full border border-line bg-surface">
              <Mail className="size-5" />
            </span>
            <span className="flex flex-col">
              <span className="text-caption uppercase text-secondary">{t("footer.emailSupport")}</span>
              <a href="mailto:care@nawy.com" className="text-body font-medium">
                care@nawy.com
              </a>
            </span>
          </div>
        </div>
      </div>
      <div className="bg-surface">
        <div className="mx-auto grid max-w-(--container-page) grid-cols-2 gap-8 px-4 py-8 sm:grid-cols-3 md:px-8 lg:grid-cols-7">
          {columns.map((column) => (
            <div key={column.title}>
              <p className="mb-3 text-label font-semibold uppercase">{column.title}</p>
              <ul className="flex flex-col gap-2">
                {column.links.map((link) => (
                  <li key={`${link.label}${link.url}`}>
                    <Link href={resolve(link.url)} className="text-body-sm text-secondary hover:text-ink">
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
          {social.length > 0 ? (
            <div>
              <p className="mb-3 text-label font-semibold uppercase">Social</p>
              <ul className="flex flex-col gap-2">
                {social.map(([network, url]) => (
                  <li key={network}>
                    <a href={url} rel="noopener noreferrer" target="_blank" className="text-body-sm capitalize text-secondary hover:text-ink">
                      {network}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      </div>
      <div className="border-t border-line bg-page">
        <div className="mx-auto flex max-w-(--container-page) flex-wrap items-center justify-between gap-4 px-4 py-4 md:px-8">
          <div className="flex flex-wrap items-center gap-4">
            <p className="text-body-sm text-secondary">{data?.copyright ?? (year ? t("footer.rights", { year }) : "© Nawy")}</p>
            <CookieSettingsLink label={t("content.cookies.settings")} />
          </div>
          <ul className="flex flex-wrap gap-2">
            {PAYMENT_BADGES.map((badge) => (
              <li key={badge} className="rounded-xs border border-line bg-surface px-2 py-0.5 text-caption text-secondary">
                {badge}
              </li>
            ))}
            <li className="rounded-xs border border-line bg-surface px-2 py-0.5 text-caption text-secondary">{t("footer.cashOnDelivery")}</li>
          </ul>
          <nav className="flex flex-wrap items-center gap-1 text-body-sm text-secondary" aria-label="Countries">
            <Globe className="size-4" />
            {COUNTRIES.map((c, i) => (
              <span key={c.code}>
                <Link href={`/${c.code}-${market.locale}`} className={c.code === market.country.code ? "font-semibold text-ink" : "hover:text-ink"}>
                  {c.iso2}
                </Link>
                {i < COUNTRIES.length - 1 ? " ·" : ""}
              </span>
            ))}
          </nav>
        </div>
      </div>
    </footer>
  );
}
