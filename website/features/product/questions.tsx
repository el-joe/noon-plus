"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { MessageCircleQuestion } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/field";
import { Alert, Badge, Skeleton } from "@/components/ui/misc";
import { fieldErrors } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { formatDay } from "@/lib/format";
import { errorMessage, useLoginRedirect, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/** Product Q&A (G-W3; app frame 97:4183 pattern): approved questions with seller / Nawy answers, and "Ask a question". */
export function ProductQuestions({ slug }: { slug: string }) {
  const t = useTranslations("questions");
  const tc = useTranslations("common");
  const market = useMarket();
  const locale = useLocale() as "en" | "ar";
  const toLogin = useLoginRedirect();
  const { isSignedIn } = useSession();
  const [page, setPage] = useState(1);
  const [asking, setAsking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const list = useQuery({
    queryKey: ["questions", market.slug, slug, page],
    queryFn: async () => {
      const pages = await Promise.all(Array.from({ length: page }, (_, i) => api.getQuestions(market.slug, slug, i + 1)));
      return { items: pages.flatMap((p) => p.data), last: Number(pages.at(-1)?.meta.last_page ?? 1) };
    },
  });

  const ask = useMutation({
    mutationFn: async (body: string) => (await api.askQuestion(market.slug, slug, body)).data,
    onSuccess: () => setAsking(false),
    onError: (e) => setError(fieldErrors(e).body ?? errorMessage(e, tc("error"))),
  });

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    const body = String(new FormData(event.currentTarget).get("body") ?? "").trim();
    ask.mutate(body);
  };

  return (
    <section id="questions" className="flex scroll-mt-28 flex-col gap-3 rounded-lg border border-line bg-surface p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-h4 font-semibold">{t("title")}</h2>
        {isSignedIn ? (
          !asking ? (
            <Button variant="outline" size="sm" onClick={() => setAsking(true)}>
              {t("ask")}
            </Button>
          ) : null
        ) : (
          <button type="button" onClick={toLogin} className="text-body-sm font-medium text-link">
            {t("signIn")}
          </button>
        )}
      </div>
      {ask.isSuccess && !asking ? <Alert tone="success">{t("submitted")}</Alert> : null}
      {asking ? (
        <form onSubmit={submit} className="flex flex-col gap-2">
          <Textarea name="body" required minLength={5} maxLength={500} rows={3} placeholder={t("placeholder")} aria-label={t("ask")} />
          {error ? <p className="text-body-sm text-danger-text">{error}</p> : null}
          <div className="flex gap-2">
            <Button type="submit" size="sm" disabled={ask.isPending}>
              {t("submit")}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setAsking(false)}>
              {tc("cancel")}
            </Button>
          </div>
        </form>
      ) : null}
      {list.isLoading ? (
        <Skeleton className="h-24 w-full" />
      ) : (list.data?.items.length ?? 0) === 0 ? (
        <p className="flex items-center gap-2 text-body-sm text-secondary">
          <MessageCircleQuestion className="size-4" />
          {t("empty")}
        </p>
      ) : (
        <ul className="flex flex-col divide-y divide-line">
          {list.data!.items.map((q) => (
            <li key={q.id} className="flex flex-col gap-2 py-3">
              <p className="text-body font-semibold">Q: {q.body}</p>
              <p className="text-caption text-muted">{t("askedBy", { author: q.author ?? "—", date: formatDay(q.created_at, locale) })}</p>
              {q.answers.length === 0 ? <p className="text-body-sm text-secondary">{t("noAnswer")}</p> : null}
              {q.answers.map((a) => (
                <div key={a.id} className="rounded-md bg-subtle p-3 text-body-sm">
                  <p className="mb-1 flex items-center gap-2 font-medium">
                    {t("answerFrom", { author: a.by === "admin" ? t("nawy") : (a.author ?? "—") })}
                    {a.by === "admin" ? <Badge tone="brand">{t("nawy")}</Badge> : null}
                  </p>
                  <p className="whitespace-pre-line">{a.body}</p>
                </div>
              ))}
            </li>
          ))}
        </ul>
      )}
      {list.data && page < list.data.last ? (
        <Button variant="outline" size="sm" className="self-center" onClick={() => setPage(page + 1)} disabled={list.isFetching}>
          {tc("loadMore")}
        </Button>
      ) : null}
    </section>
  );
}
