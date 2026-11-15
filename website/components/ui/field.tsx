import clsx from "clsx";
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from "react";

export const inputClass =
  "h-11 w-full rounded-md border border-line-strong bg-surface px-3 text-body text-ink placeholder:text-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:bg-subtle";

export function Field({ label, error, hint, children, className }: { label: ReactNode; error?: string | null; hint?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <label className={clsx("flex flex-col gap-1.5", className)}>
      <span className="text-label font-medium text-ink">{label}</span>
      {children}
      {hint ? <span className="text-body-sm text-secondary">{hint}</span> : null}
      {error ? <span className="text-body-sm text-danger-text">{error}</span> : null}
    </label>
  );
}

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={clsx(inputClass, className)} {...props} />;
}

export function Select({ className, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select className={clsx(inputClass, "pe-8", className)} {...props} />;
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea className={clsx(inputClass, "h-auto min-h-24 py-2", className)} {...props} />;
}
