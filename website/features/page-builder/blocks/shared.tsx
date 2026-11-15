import Link from "next/link";
import type { ReactNode } from "react";
import { getT } from "@/lib/i18n";
import { background, blockTitle, resolveLink, str, type BlockContext, type Item, type Settings } from "../settings";

/** Common block chrome: title, subtitle, "View all" and background (page-builder §3 common settings). */
export function BlockSection({ s, ctx, children, title, className = "" }: { s: Settings; ctx: BlockContext; children: ReactNode; title?: string | null; className?: string }) {
  const t = getT(ctx.market.locale);
  const heading = title ?? blockTitle(s, ctx.market.locale);
  const subtitle = str(s, "subtitle", ctx.market.locale);
  const viewAll = resolveLink(str(s, "view_all") ?? str(s, "view_all_link"), ctx.market);
  const bg = background(s);
  return (
    <section className={`rounded-lg ${bg ? "p-4" : ""} ${className}`} style={bg ? { backgroundColor: bg } : undefined}>
      {heading || viewAll ? (
        <div className="mb-3 flex items-end justify-between gap-4">
          <div>
            {heading ? <h2 className="text-h3 font-semibold">{heading}</h2> : null}
            {subtitle ? <p className="text-body text-secondary">{subtitle}</p> : null}
          </div>
          {viewAll ? (
            <Link href={viewAll} className="shrink-0 rounded-sm border border-line bg-surface px-2.5 py-1 text-caption font-semibold uppercase">
              {t("common.viewAll")}
            </Link>
          ) : null}
        </div>
      ) : null}
      {children}
    </section>
  );
}

/** An image (with optional mobile crop) linking somewhere. */
export function ImageLink({ item, ctx, className = "", imgClass = "size-full object-cover", children }: { item: Item; ctx: BlockContext; className?: string; imgClass?: string; children?: ReactNode }) {
  const href = resolveLink(item.link, ctx.market);
  const body = (
    <>
      {item.image ? (
        <picture>
          {item.imageMobile ? <source media="(max-width: 767px)" srcSet={item.imageMobile} /> : null}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={item.image} alt={item.alt} loading="lazy" className={imgClass} />
        </picture>
      ) : (
        <div className="flex size-full items-center justify-center bg-brand-subtle p-4 text-center text-h4 font-semibold text-ink">{item.label}</div>
      )}
      {children}
    </>
  );
  return href ? (
    <Link href={href} className={`relative block overflow-hidden rounded-lg ${className}`}>
      {body}
    </Link>
  ) : (
    <div className={`relative overflow-hidden rounded-lg ${className}`}>{body}</div>
  );
}
