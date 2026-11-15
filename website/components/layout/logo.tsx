import Link from "next/link";

/** Nawy logo: navy tile with the logo mark + wordmark (Figma 17:56). */
export function Logo({ href, inverse = false }: { href: string; inverse?: boolean }) {
  return (
    <Link href={href} className="flex shrink-0 items-center gap-2" aria-label="Nawy">
      <span className="flex size-11 items-center justify-center rounded-[10px] bg-ink">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src="/brand/logo-mark.png" alt="" width={30} height={28} className="h-7 w-[30px] object-contain" />
      </span>
      <span className={`text-[26px] font-extrabold leading-none ${inverse ? "text-white" : "text-ink"}`} dir="ltr">
        nawy
      </span>
    </Link>
  );
}
