import type { ReactNode } from "react";
import { background, list, num, str, type BlockContext, type Settings } from "../settings";
import { BlockSection } from "./shared";

type ChildBlock = { type: string; settings: Settings; data?: Record<string, unknown> | null };
export type ChildRenderer = (block: ChildBlock, ctx: BlockContext, key: number) => ReactNode;

/** A container child (`columns[].block`, a column itself, or `children[]`) with the `data` BlockResolver resolved for it. */
function childBlock(entry: Settings): ChildBlock | null {
  const inner = entry.block && typeof entry.block === "object" ? (entry.block as Settings) : entry;
  const type = typeof inner.type === "string" ? inner.type : null;
  if (!type) return null;
  const settings = inner.settings && typeof inner.settings === "object" && !Array.isArray(inner.settings) ? (inner.settings as Settings) : {};
  const data = inner.data && typeof inner.data === "object" && !Array.isArray(inner.data) ? (inner.data as Record<string, unknown>) : null;
  return { type, settings, data };
}

/** 25 panel_row — 2–3 columns, each a child block (home: "More reasons to shop" · "Mega deals" · "In focus"); columns stretch to one height. */
export function PanelRow({ s, ctx, renderChild }: { s: Settings; ctx: BlockContext; renderChild: ChildRenderer }) {
  const columns = list(s, "columns");
  if (columns.length === 0 || (ctx.depth ?? 0) > 2) return null;
  const total = columns.reduce((sum, c) => sum + num(c, "width", 1), 0) || 1;
  return (
    <div className="flex flex-col gap-3 lg:flex-row">
      {columns.map((column, i) => {
        const child = childBlock(column);
        return (
          <div key={i} className="flex min-w-0 flex-col [&>*]:flex-1" style={{ flex: `${num(column, "width", 1) / total} 1 0%` }}>
            {child ? renderChild(child, { ...ctx, depth: (ctx.depth ?? 0) + 1 }, i) : null}
          </div>
        );
      })}
    </div>
  );
}

/** 26 section_band — coloured / image band wrapping child blocks with a title. */
export function SectionBand({ s, ctx, renderChild }: { s: Settings; ctx: BlockContext; renderChild: ChildRenderer }) {
  const children = list(s, "children")
    .map(childBlock)
    .filter((c): c is ChildBlock => c !== null)
    .map((c) => ({ ...c, settings: { surface: false, ...c.settings } }));
  if (children.length === 0 || (ctx.depth ?? 0) > 2) return null;
  const image = str(s, "background_image");
  return (
    <div
      className="rounded-lg bg-cover bg-center p-4"
      style={{ backgroundColor: background(s) ?? "var(--color-brand-subtle)", backgroundImage: image && /^https?:\/\//.test(image) ? `url("${image}")` : undefined }}
    >
      <BlockSection s={{ ...s, background: undefined, surface: false }} ctx={ctx}>
        <div className="flex flex-col gap-4">{children.map((child, i) => renderChild(child, { ...ctx, depth: (ctx.depth ?? 0) + 1 }, i))}</div>
      </BlockSection>
    </div>
  );
}
