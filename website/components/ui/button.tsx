import clsx from "clsx";
import Link from "next/link";
import type { ButtonHTMLAttributes, ComponentProps } from "react";

type Variant = "primary" | "dark" | "outline" | "ghost" | "link";
type Size = "sm" | "md" | "lg";

const variants: Record<Variant, string> = {
  primary: "bg-brand text-ink hover:brightness-95",
  dark: "bg-ink text-white hover:bg-ink/90",
  outline: "border border-line-strong bg-surface text-ink hover:bg-subtle",
  ghost: "text-ink hover:bg-subtle",
  link: "text-link hover:underline px-0",
};
const sizes: Record<Size, string> = {
  sm: "h-8 px-3 text-label",
  md: "h-10 px-4 text-body",
  lg: "h-12 px-6 text-body-lg",
};

export function buttonClass(variant: Variant = "primary", size: Size = "md", className?: string) {
  return clsx(
    "inline-flex items-center justify-center gap-2 rounded-md font-semibold transition disabled:cursor-not-allowed disabled:opacity-50",
    variants[variant],
    variant !== "link" && sizes[size],
    className,
  );
}

export function Button({ variant, size, className, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: Size }) {
  return <button type="button" className={buttonClass(variant, size, className)} {...props} />;
}

export function ButtonLink({ variant, size, className, ...props }: ComponentProps<typeof Link> & { variant?: Variant; size?: Size }) {
  return <Link className={buttonClass(variant, size, className)} {...props} />;
}
