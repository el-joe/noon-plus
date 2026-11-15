import { str, type BlockContext, type Settings } from "@/features/page-builder/settings";
import { getT } from "@/lib/i18n";
import { NewsletterForm } from "./newsletter-form";

/** 44 newsletter_signup — email opt-in with double opt-in (G-W12). */
export function NewsletterSignup({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const t = getT(ctx.market.locale);
  return (
    <section className="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-brand-subtle p-5">
      <div>
        <p className="text-h4 font-semibold">{str(s, "title", ctx.market.locale) ?? t("newsletter.title")}</p>
        <p className="text-body-sm text-secondary">{str(s, "subtitle", ctx.market.locale) ?? t("newsletter.subtitle")}</p>
      </div>
      <NewsletterForm source="page_builder" />
    </section>
  );
}
