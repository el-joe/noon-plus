"use client";

import { SearchX } from "lucide-react";
import { useTranslations } from "next-intl";
import { ButtonLink } from "@/components/ui/button";
import { Container, EmptyState } from "@/components/ui/misc";
import { useHref } from "@/lib/market-context";

/** Error 404 (Figma 71:10890). */
export default function NotFound() {
  const t = useTranslations("common");
  const href = useHref();
  return (
    <Container className="py-16">
      <EmptyState
        icon={<SearchX className="size-12" />}
        title={t("notFoundTitle")}
        body={t("notFoundBody")}
        action={<ButtonLink href={href("/")}>{t("goHome")}</ButtonLink>}
      />
    </Container>
  );
}
