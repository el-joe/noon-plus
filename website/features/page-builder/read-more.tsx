"use client";

import { useState } from "react";

/** Clamped sanitised HTML with a "Read more" / "Show less" toggle (seo_text). */
export function ReadMore({ html, more, less }: { html: string; more: string; less: string }) {
  const [open, setOpen] = useState(false);
  return (
    <div>
      <div className={`prose-cms flex flex-col gap-2 text-body-sm text-secondary ${open ? "" : "max-h-20 overflow-hidden"}`} dangerouslySetInnerHTML={{ __html: html }} />
      <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open} className="mt-2 text-body-sm font-medium text-link">
        {open ? less : more}
      </button>
    </div>
  );
}
