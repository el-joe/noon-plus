"use client";

import clsx from "clsx";
import { Globe, Heart, ShoppingCart, Store, User } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { Suspense, useState } from "react";
import { cartItemCount, useCart, useSession, useWishlist } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { syncLocale } from "@/lib/locale-sync";
import { switchMarketPath } from "@/lib/markets";
import { NotificationBell } from "@/features/account-extra/notifications";
import { SearchBox } from "@/features/search/search-box";
import { CountrySwitcher } from "./country-switcher";
import { DeliverTo } from "./deliver-to";
import { Logo } from "./logo";
import { CategoryNav } from "./mega-menu";

/** Vertical shortcuts in the category bar (routes built in WP9 part B). */
const VERTICAL_LINKS = [
  { key: "deals", path: "/deals" },
  { key: "travel", path: "/travel" },
  { key: "openSouq", path: "/classified" },
  { key: "property", path: "/property" },
  { key: "nawyTv", path: "/live" },
  { key: "creators", path: "/creators" },
] as const;

/** Storefront header (Figma "Web header" 17:55 + "Category nav" 17:94). */
export function Header() {
  const t = useTranslations("header");
  const market = useMarket();
  const href = useHref();
  const { user } = useSession();
  const { data: cart } = useCart();
  const count = cartItemCount(cart);
  const { data: wishlist } = useWishlist();
  const saved = user ? (wishlist?.length ?? 0) : 0;
  const [switcherOpen, setSwitcherOpen] = useState(false);

  return (
    <header className="sticky top-0 z-40">
      <div className="bg-brand text-ink">
        <div className="mx-auto flex max-w-(--container-page) flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 md:h-[72px] md:flex-nowrap md:px-8 md:py-0">
          <Logo href={href("/")} />
          <div className="hidden lg:block">
            <DeliverTo />
          </div>
          <SearchBox className="order-last w-full md:order-none md:flex-1" />
          <nav className="ms-auto flex items-center gap-4 md:ms-0 md:gap-5" aria-label={t("account")}>
            <Suspense fallback={<LanguageLink pathname={null} />}>
              <CurrentLanguageLink />
            </Suspense>
            <button type="button" onClick={() => setSwitcherOpen(true)} className="hidden items-center gap-1 text-body font-medium sm:flex" aria-label={t("countryLanguage")}>
              <Globe className="size-5" />
              <span>{market.country.iso2}</span>
            </button>
            <span className="hidden h-6 w-px bg-ink/25 sm:block" aria-hidden />
            <Link href={user ? href("/account") : href("/login")} className="flex items-center gap-1.5 text-body font-medium">
              <User className="size-[22px]" />
              <span className="hidden max-w-28 truncate sm:inline">{user ? (user.name || user.email.split("@")[0]) : t("signIn")}</span>
            </Link>
            <NotificationBell />
            <Link href={user ? href("/account/wishlist") : `${href("/login")}?next=${encodeURIComponent(href("/account/wishlist"))}`} className="relative flex items-center text-body font-medium" aria-label={t("wishlist")}>
              <Heart className="size-[22px]" />
              {saved > 0 ? (
                <span className="absolute -end-2 -top-1.5 rounded-full bg-ink px-1 text-caption leading-4 text-white" aria-hidden>
                  {saved}
                </span>
              ) : null}
            </Link>
            <Link href={href("/cart")} className="flex items-center gap-1.5 text-body font-medium">
              <ShoppingCart className="size-[22px]" />
              <span className="hidden sm:inline">{t("cart")}</span>
              {count > 0 ? (
                <span className="rounded-full bg-ink px-1.5 text-caption text-white" aria-label={String(count)}>
                  {count}
                </span>
              ) : null}
            </Link>
          </nav>
        </div>
      </div>
      <div className="border-b border-line bg-surface">
        <div className="mx-auto flex h-11 max-w-(--container-page) items-center gap-6 px-4 md:px-8">
          <CategoryNav />
          <nav className="hidden shrink-0 items-center gap-5 border-s border-line ps-5 text-body whitespace-nowrap xl:flex" aria-label={t("verticals")}>
            {VERTICAL_LINKS.map(({ key, path }) => (
              <Link key={key} href={href(path)} className={clsx("shrink-0 hover:text-link", key === "deals" ? "font-semibold text-danger-text" : "text-ink")}>
                {t(key)}
              </Link>
            ))}
          </nav>
          {process.env.NEXT_PUBLIC_PORTAL_URL ? (
            <a href={process.env.NEXT_PUBLIC_PORTAL_URL} className="ms-auto hidden shrink-0 items-center gap-1.5 text-body text-link md:flex">
              <Store className="size-4" />
              {t("sellOnNawy")}
            </a>
          ) : (
            <Link href={href("/join")} className="ms-auto hidden shrink-0 items-center gap-1.5 text-body text-link md:flex">
              <Store className="size-4" />
              {t("sellOnNawy")}
            </Link>
          )}
        </div>
      </div>
      {switcherOpen ? <CountrySwitcher onClose={() => setSwitcherOpen(false)} /> : null}
    </header>
  );
}

/** Same page in the other language (pathname suspends on unknown dynamic params, hence the Suspense above). */
function CurrentLanguageLink() {
  return <LanguageLink pathname={usePathname()} />;
}

function LanguageLink({ pathname }: { pathname: string | null }) {
  const t = useTranslations("header");
  const market = useMarket();
  const otherLocale = market.locale === "en" ? "ar" : "en";
  const target = `${market.country.code}-${otherLocale}`;
  return (
    <Link
      href={pathname ? switchMarketPath(pathname, target) : `/${target}`}
      hrefLang={otherLocale}
      lang={otherLocale}
      // E2E-001: the other language is a different root layout (full load); prefetching it only
      // costs a request, and on a 404 page the prefetched 404 is a console error.
      prefetch={false}
      onClick={() => void syncLocale(market.slug, otherLocale)}
      className="text-body font-medium"
    >
      {t("otherLanguage")}
    </Link>
  );
}
