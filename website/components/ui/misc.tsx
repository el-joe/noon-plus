import clsx from "clsx";
import { Package } from "lucide-react";
import type { ReactNode } from "react";

export function Container({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={clsx("mx-auto w-full max-w-(--container-page) px-4 md:px-8", className)}>{children}</div>;
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <section className={clsx("rounded-lg border border-line bg-surface p-4 md:p-5", className)}>{children}</section>;
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={clsx("animate-pulse rounded-md bg-subtle", className)} />;
}

export function Spinner({ className }: { className?: string }) {
  return <span aria-hidden className={clsx("inline-block size-4 animate-spin rounded-full border-2 border-current border-t-transparent", className)} />;
}

/** "Ad" / "Sponsored" label — required on every sponsored item (page-builder rule 1). */
export function AdLabel({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <span className={clsx("inline-flex items-center rounded-xs bg-subtle px-1.5 py-px text-caption font-medium text-secondary", className)}>
      {children}
    </span>
  );
}

export function Badge({ children, tone = "neutral", className }: { children: ReactNode; tone?: "neutral" | "success" | "warning" | "danger" | "info" | "brand" | "dark"; className?: string }) {
  const tones = {
    neutral: "bg-subtle text-secondary",
    success: "bg-success-subtle text-success-text",
    warning: "bg-warning-subtle text-warning-text",
    danger: "bg-warning-subtle text-danger-text",
    info: "bg-info-subtle text-link",
    brand: "bg-brand text-ink",
    dark: "bg-ink text-white",
  } as const;
  return <span className={clsx("inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-caption font-medium", tones[tone], className)}>{children}</span>;
}

/** Product image slot: the API image (G-W1), or the Figma placeholder when the product has none. */
export function ProductImage({ src, alt, className, label }: { src?: string | null; alt: string; className?: string; label?: string }) {
  if (src) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img src={src} alt={alt} loading="lazy" className={clsx("size-full object-contain", className)} />;
  }
  return (
    <div className={clsx("flex size-full flex-col items-center justify-center gap-2 bg-subtle text-muted", className)} role="img" aria-label={alt}>
      <Package className="size-10" strokeWidth={1.5} />
      {label ? <span className="text-caption">{label}</span> : null}
    </div>
  );
}

export function EmptyState({ icon, title, body, action }: { icon?: ReactNode; title: string; body?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-lg border border-line bg-surface px-6 py-12 text-center">
      {icon ? <div className="text-muted">{icon}</div> : null}
      <h2 className="text-h3 font-semibold">{title}</h2>
      {body ? <p className="max-w-md text-body text-secondary">{body}</p> : null}
      {action}
    </div>
  );
}

export function Alert({ children, tone = "info", className }: { children: ReactNode; tone?: "info" | "danger" | "success" | "warning"; className?: string }) {
  const tones = {
    info: "bg-info-subtle text-ink",
    danger: "bg-warning-subtle text-danger-text",
    success: "bg-success-subtle text-success-text",
    warning: "bg-brand-subtle text-brand-text",
  } as const;
  return (
    <div role={tone === "danger" ? "alert" : "status"} className={clsx("rounded-md px-4 py-3 text-body", tones[tone], className)}>
      {children}
    </div>
  );
}

export function SectionHeader({ title, action }: { title: string; action?: ReactNode }) {
  return (
    <div className="mb-3 flex items-center justify-between gap-4">
      <h2 className="text-h3 font-semibold">{title}</h2>
      {action}
    </div>
  );
}
