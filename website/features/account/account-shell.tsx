"use client";

import clsx from "clsx";
import { BadgeCheck, Bell, CreditCard, LifeBuoy, Store, CalendarCheck, Crown, FileSignature, Gift, Heart, LayoutGrid, LogOut, MapPin, Megaphone, MessageSquare, Package, RotateCcw, Scale, Search, Shield, ShieldCheck, Star, Ticket, User, Wallet, Home as HomeIcon } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import type { ReactNode } from "react";
import { ButtonLink } from "@/components/ui/button";
import { EmptyState, Skeleton } from "@/components/ui/misc";
import { useSession, useSignOut } from "@/lib/hooks";
import { useHref } from "@/lib/market-context";

const NAV = [
  { key: "overview", path: "/account", icon: LayoutGrid, group: "myAccount" },
  { key: "notifications", path: "/account/notifications", icon: Bell, group: "myAccount" },
  { key: "orders", path: "/account/orders", icon: Package, group: "myAccount" },
  { key: "returns", path: "/account/returns", icon: RotateCcw, group: "myAccount" },
  { key: "disputes", path: "/account/disputes", icon: Scale, group: "myAccount" },
  { key: "warranty", path: "/account/warranty", icon: ShieldCheck, group: "myAccount" },
  { key: "reviews", path: "/account/reviews", icon: Star, group: "myAccount" },
  { key: "support", path: "/account/support", icon: LifeBuoy, group: "myAccount" },
  { key: "bookings", path: "/account/bookings", icon: CalendarCheck, group: "marketplace" },
  { key: "inquiries", path: "/account/inquiries", icon: MessageSquare, group: "marketplace" },
  { key: "myAds", path: "/account/my-ads", icon: Megaphone, group: "marketplace" },
  { key: "favourites", path: "/account/favourites", icon: BadgeCheck, group: "marketplace" },
  { key: "followedStores", path: "/account/followed-stores", icon: Store, group: "marketplace" },
  { key: "savedSearches", path: "/account/saved-searches", icon: Search, group: "marketplace" },
  { key: "specialRequests", path: "/account/special-requests", icon: HomeIcon, group: "marketplace" },
  { key: "wishlist", path: "/account/wishlist", icon: Heart, group: "walletOffers" },
  { key: "credits", path: "/account/credits", icon: Wallet, group: "walletOffers" },
  { key: "giftCards", path: "/account/gift-cards", icon: Gift, group: "walletOffers" },
  { key: "vouchers", path: "/account/vouchers", icon: Ticket, group: "walletOffers" },
  { key: "membership", path: "/account/membership", icon: Crown, group: "walletOffers" },
  { key: "cards", path: "/account/cards", icon: CreditCard, group: "walletOffers" },
  { key: "profile", path: "/account/profile", icon: User, group: "profile" },
  { key: "addresses", path: "/account/addresses", icon: MapPin, group: "profile" },
  { key: "contracts", path: "/account/contracts", icon: FileSignature, group: "profile" },
  { key: "security", path: "/account/security", icon: Shield, group: "profile" },
] as const;

/**
 * Account shell (Figma Account overview 33:134 sidebar). NAV lists every
 * account page; each route lives under app/[market]/account/.
 */
export function AccountShell({ children }: { children: ReactNode }) {
  const t = useTranslations("account");
  const href = useHref();
  const pathname = usePathname();
  const { user, isLoading } = useSession();
  const signOut = useSignOut();

  if (isLoading) return <Skeleton className="h-[480px] w-full" />;
  if (!user) {
    return (
      <EmptyState
        title={t("signInRequired")}
        action={<ButtonLink href={`${href("/login")}?next=${encodeURIComponent(pathname)}`}>{t("signInRequired")}</ButtonLink>}
      />
    );
  }

  // An old phone-only Nawy account must add its email first (the API answers `email_required` until then).
  if (user.requires_email) {
    return (
      <EmptyState
        title={t("addEmailRequired")}
        action={<ButtonLink href={`${href("/auth/add-email")}?next=${encodeURIComponent(pathname)}`}>{t("addEmailAction")}</ButtonLink>}
      />
    );
  }

  const initials = (user.name || user.email).split(/[\s@.]+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("");
  const groups = ["myAccount", "marketplace", "walletOffers", "profile"] as const;

  return (
    <div className="grid items-start gap-6 lg:grid-cols-[260px_1fr]">
      <aside className="rounded-lg border border-line bg-surface p-3">
        <div className="mb-3 flex items-center gap-3 p-2">
          <span className="flex size-11 items-center justify-center rounded-full bg-brand-subtle text-body font-semibold text-brand-text">{initials}</span>
          <div className="min-w-0">
            <p className="truncate text-body font-semibold">{t("hello", { name: user.name || user.email.split("@")[0] })}</p>
            <p className="truncate text-body-sm text-secondary" dir="ltr">
              {user.email}
            </p>
          </div>
        </div>
        <nav className="scrollbar-none flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
          {groups.map((group) => (
            <div key={group} className="contents lg:block">
              <p className="hidden px-2 pb-1 pt-3 text-caption font-medium uppercase text-muted lg:block">{t(group)}</p>
              {NAV.filter((n) => n.group === group).map(({ key, path, icon: Icon }) => {
                const target = href(path);
                const active = key === "overview" ? pathname === target : pathname.startsWith(target);
                return (
                  <Link key={key} href={target} className={clsx("flex shrink-0 items-center gap-3 rounded-md px-3 py-2 text-body", active ? "bg-brand-subtle font-semibold" : "hover:bg-subtle")}>
                    <Icon className="size-4" />
                    {t(`nav.${key}`)}
                  </Link>
                );
              })}
            </div>
          ))}
          <button type="button" onClick={() => signOut.mutate()} disabled={signOut.isPending} className="mt-2 flex shrink-0 items-center gap-3 rounded-md border-t border-line px-3 py-2 text-body hover:bg-subtle lg:w-full">
            <LogOut className="size-4" />
            {t("nav.signOut")}
          </button>
        </nav>
      </aside>
      <div className="min-w-0">{children}</div>
    </div>
  );
}
