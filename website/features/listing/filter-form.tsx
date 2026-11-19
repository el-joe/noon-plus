"use client";

import { useRouter } from "next/navigation";
import { useTransition, type ReactNode } from "react";

/**
 * Filter rail form: a checkbox or radio change applies at once (client
 * navigation, scroll kept); the price inputs apply with their button or
 * Enter. Repeated names (checkbox groups) are sent once, comma separated.
 * Without JavaScript it is a plain GET form.
 */
export function FilterForm({ action, children, className }: { action: string; children: ReactNode; className?: string }) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  const apply = (form: HTMLFormElement) => {
    const values = new Map<string, string[]>();
    for (const [key, value] of new FormData(form).entries()) {
      if (typeof value !== "string" || value === "") continue;
      values.set(key, [...(values.get(key) ?? []), value]);
    }
    const params = new URLSearchParams();
    for (const [key, list] of values) params.set(key, [...new Set(list)].join(","));
    const qs = params.toString();
    startTransition(() => router.push(qs ? `${action}?${qs}` : action, { scroll: false }));
  };

  return (
    <form
      method="get"
      action={action}
      aria-busy={pending}
      className={className}
      data-pending={pending ? "" : undefined}
      onChange={(event) => {
        const target = event.target as unknown as HTMLInputElement;
        if (target.type === "checkbox" || target.type === "radio") apply(event.currentTarget);
      }}
      onSubmit={(event) => {
        event.preventDefault();
        apply(event.currentTarget);
      }}
    >
      {children}
    </form>
  );
}
