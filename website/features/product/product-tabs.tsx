"use client";

import clsx from "clsx";
import { useCallback, useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import { useMarket } from "@/lib/market-context";
import { keyStep } from "./gallery-model";

export type PdpTab = { id: string; label: string; panel: ReactNode };

/**
 * PDP section tabs (Figma 24:2: Overview | Specifications | Ratings & reviews |
 * Questions & answers). WAI-ARIA tabs: one tabpanel visible, arrow keys /
 * Home / End move between tabs, the active tab is in the URL hash
 * (#specifications) so it is deep-linkable, and any in-page link to a tab's
 * hash (the rating link under the title) switches to it. Hidden panels stay
 * in the DOM so their server-rendered content is indexable.
 */
export function ProductTabs({ tabs, initial }: { tabs: PdpTab[]; initial?: string }) {
  const market = useMarket();
  const [active, setActive] = useState(initial ?? tabs[0]?.id);
  const list = useRef<HTMLDivElement>(null);
  const ids = tabs.map((t) => t.id);
  const idsKey = ids.join(",");

  const select = useCallback(
    (id: string, opts: { focus?: boolean; scroll?: boolean; push?: boolean } = {}) => {
      setActive(id);
      if (opts.push !== false && window.location.hash !== `#${id}`) {
        window.history.replaceState(window.history.state, "", `#${id}`);
      }
      if (opts.focus) document.getElementById(`tab-${id}`)?.focus();
      if (opts.scroll) list.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    },
    [],
  );

  // Deep link on load and hash links anywhere on the page.
  useEffect(() => {
    const known = idsKey.split(",");
    const fromHash = () => {
      const hash = decodeURIComponent(window.location.hash.slice(1));
      if (known.includes(hash)) select(hash, { scroll: true, push: false });
    };
    fromHash();
    window.addEventListener("hashchange", fromHash);
    return () => window.removeEventListener("hashchange", fromHash);
  }, [idsKey, select]);

  const onKey = (e: KeyboardEvent<HTMLButtonElement>) => {
    const i = ids.indexOf(active);
    let next: number | null = null;
    if (e.key === "Home") next = 0;
    else if (e.key === "End") next = ids.length - 1;
    else if (e.key === "ArrowLeft" || e.key === "ArrowRight") {
      const step = keyStep(e.key, market.dir)!;
      next = (i + step + ids.length) % ids.length;
    }
    if (next === null) return;
    e.preventDefault();
    select(ids[next], { focus: true });
  };

  return (
    <div className="flex flex-col gap-4">
      <div
        ref={list}
        role="tablist"
        aria-orientation="horizontal"
        className="scrollbar-none -mx-4 flex scroll-mt-28 gap-6 overflow-x-auto border-b border-line bg-page px-4 text-body md:sticky md:top-[117px] md:z-10 md:mx-0 md:px-0"
      >
        {tabs.map((tab) => {
          const selected = tab.id === active;
          return (
            <button
              key={tab.id}
              id={`tab-${tab.id}`}
              type="button"
              role="tab"
              aria-selected={selected}
              aria-controls={tab.id}
              tabIndex={selected ? 0 : -1}
              onClick={() => select(tab.id)}
              onKeyDown={onKey}
              className={clsx(
                "-mb-px shrink-0 border-b-2 py-3 outline-none transition-colors focus-visible:ring-2 focus-visible:ring-brand",
                selected ? "border-brand font-semibold text-ink" : "border-transparent text-secondary hover:text-ink",
              )}
            >
              {tab.label}
            </button>
          );
        })}
      </div>
      {tabs.map((tab) => (
        <div key={tab.id} id={tab.id} role="tabpanel" aria-labelledby={`tab-${tab.id}`} tabIndex={0} hidden={tab.id !== active} className="scroll-mt-40 outline-none">
          {tab.panel}
        </div>
      ))}
    </div>
  );
}

/** Button that switches the PDP tabs (e.g. "See all reviews" in the overview). */
export function TabLink({ to, className, children }: { to: string; className?: string; children: ReactNode }) {
  return (
    <a
      href={`#${to}`}
      className={className}
      onClick={(e) => {
        e.preventDefault();
        window.history.replaceState(window.history.state, "", `#${to}`);
        window.dispatchEvent(new HashChangeEvent("hashchange"));
      }}
    >
      {children}
    </a>
  );
}
