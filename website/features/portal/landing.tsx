import clsx from "clsx";
import { Plus, type LucideIcon } from "lucide-react";
import type { ReactNode } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Container } from "@/components/ui/misc";

/**
 * Building blocks of the portal landing pages (Figma Marketer 93:1939,
 * Travel landing 93:1481): dark hero with two calls to action, a feature
 * grid, an FAQ and a closing call-to-action band.
 */

export type LandingAction = { label: string; href: string };

export function LandingHero({ eyebrow, title, body, primary, secondary, tone = "dark" }: { eyebrow: string; title: string; body: string; primary: LandingAction; secondary?: LandingAction; tone?: "dark" | "light" }) {
  const dark = tone === "dark";
  return (
    <section className={clsx("grid items-center gap-8 rounded-xl p-6 md:grid-cols-[1.3fr_1fr] md:p-10", dark ? "bg-ink text-white" : "bg-brand-subtle")}>
      <div>
        <p className={clsx("text-caption font-semibold uppercase tracking-wide", dark ? "text-brand" : "text-secondary")}>{eyebrow}</p>
        <h1 className="mt-2 text-h1 font-bold">{title}</h1>
        <p className={clsx("mt-3 text-body", dark ? "opacity-80" : "text-secondary")}>{body}</p>
        <div className="mt-6 flex flex-wrap gap-3">
          <ButtonLink href={primary.href} size="lg">
            {primary.label}
          </ButtonLink>
          {secondary ? (
            <ButtonLink href={secondary.href} size="lg" variant="outline">
              {secondary.label}
            </ButtonLink>
          ) : null}
        </div>
      </div>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src="/img/lifestyle-flatlay.jpg" alt="" className="hidden aspect-[16/10] w-full rounded-lg object-cover md:block" />
    </section>
  );
}

export function FeatureGrid({ title, items, columns = 3 }: { title: string; items: { icon: LucideIcon; title: string; body?: string }[]; columns?: 3 | 4 }) {
  return (
    <section>
      <h2 className="mb-4 text-h2 font-semibold">{title}</h2>
      <div className={clsx("grid gap-3 sm:grid-cols-2", columns === 4 ? "lg:grid-cols-4" : "lg:grid-cols-3")}>
        {items.map(({ icon: Icon, title: itemTitle, body }) => (
          <div key={itemTitle} className="rounded-lg border border-line bg-surface p-5">
            <span className="mb-3 flex size-9 items-center justify-center rounded-md bg-brand-subtle">
              <Icon className="size-5 text-brand-text" />
            </span>
            <p className="text-body font-semibold">{itemTitle}</p>
            {body ? <p className="mt-1 text-body-sm text-secondary">{body}</p> : null}
          </div>
        ))}
      </div>
    </section>
  );
}

export function Faq({ title, items }: { title: string; items: { q: string; a: string }[] }) {
  return (
    <section>
      <h2 className="mb-4 text-h2 font-semibold">{title}</h2>
      <div className="divide-y divide-line rounded-lg border border-line bg-surface">
        {items.map((item) => (
          <details key={item.q} className="group px-4 py-3">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-body font-medium">
              {item.q}
              <Plus className="size-4 shrink-0 transition-transform group-open:rotate-45" />
            </summary>
            <p className="mt-2 text-body-sm text-secondary">{item.a}</p>
          </details>
        ))}
      </div>
    </section>
  );
}

export function CtaBand({ title, action }: { title: string; action: LandingAction }) {
  return (
    <section className="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-brand p-6 md:px-8">
      <p className="text-h3 font-semibold text-ink">{title}</p>
      <ButtonLink href={action.href} size="lg" variant="dark">
        {action.label}
      </ButtonLink>
    </section>
  );
}

export function LandingPage({ children }: { children: ReactNode }) {
  return <Container className="flex flex-col gap-10 py-8">{children}</Container>;
}
