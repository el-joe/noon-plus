import { Briefcase } from "lucide-react";
import Link from "next/link";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";

/** "Nawy for business" strip (Figma Portal header 72:2) shown on the portal pages. */
export function PortalNav({ market, active }: { market: Market; active?: "sell" | "join" | "advertise" | "status" }) {
  const t = getT(market.locale);
  const links = [
    { key: "sell", path: "/sell", label: t("portal.nav.sell") },
    { key: "join", path: "/join", label: t("portal.nav.join") },
    { key: "advertise", path: "/advertise", label: t("portal.nav.advertise") },
    { key: "status", path: "/join/status", label: t("portal.nav.status") },
  ] as const;
  return (
    <div className="border-b border-line bg-surface">
      <div className="mx-auto flex h-12 max-w-(--container-page) items-center gap-5 overflow-x-auto px-4 md:px-8">
        <span className="flex shrink-0 items-center gap-1.5 rounded-full bg-brand-subtle px-2.5 py-0.5 text-caption font-semibold text-brand-text">
          <Briefcase className="size-3.5" />
          {t("portal.forBusiness")}
        </span>
        {links.map((link) => (
          <Link
            key={link.key}
            href={`/${market.slug}${link.path}`}
            aria-current={active === link.key ? "page" : undefined}
            className={active === link.key ? "shrink-0 text-body font-semibold text-ink" : "shrink-0 text-body text-secondary hover:text-ink"}
          >
            {link.label}
          </Link>
        ))}
      </div>
    </div>
  );
}
