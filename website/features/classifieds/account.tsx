"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { Bell, BellOff, CalendarCheck, Heart, MessageSquare, Pencil, Search, Send, Trash2 } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Input, Textarea } from "@/components/ui/field";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import type { AdAction, AdCard as AdCardData, SavedSearch } from "@/lib/api/classifieds";
import { formatDateTime, formatDay, formatMoney } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { AdGrid, AdImage, AdPrice, AdStatusChip, favouritesKey, useFavouriteNumbers } from "./ad-card";
import { ContractModal, requiredContracts } from "./contract-modal";
import { DuplicateButton } from "./post-wizard";

/**
 * `/account/inquiries` — classified chats as buyer and seller (Figma 50:2352,
 * chats 50:4032), and the D8 viewing / reserve requests (`?tab=requests`,
 * `GET me/viewing-requests`).
 */
export function InquiriesView() {
  const t = useTranslations("classifieds.inquiries");
  const params = useSearchParams();
  const href = useHref();
  const tab = params.get("tab") === "requests" ? "requests" : "chats";
  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("title")}</h1>
      <nav className="flex gap-4 border-b border-line" aria-label={t("title")}>
        {(["chats", "requests"] as const).map((key) => (
          <Link
            key={key}
            href={key === "chats" ? href("/account/inquiries") : `${href("/account/inquiries")}?tab=requests`}
            aria-current={tab === key ? "page" : undefined}
            className={clsx("-mb-px border-b-2 px-1 pb-2 text-body", tab === key ? "border-brand font-semibold" : "border-transparent text-secondary hover:text-ink")}
          >
            {t(`tabs.${key}`)}
          </Link>
        ))}
      </nav>
      {tab === "requests" ? <ViewingRequestsList /> : <ChatsView />}
    </div>
  );
}

const REQUEST_STATUSES = ["pending", "requested", "confirmed", "scheduled", "completed", "declined", "rejected", "cancelled", "no_show"] as const;

/** The customer's own viewing / reserve requests (`GET me/viewing-requests`). */
function ViewingRequestsList() {
  const t = useTranslations("classifieds.requests");
  const tr = useTranslations("classifieds.request.types");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const [type, setType] = useState<"" | "viewing" | "reserve">("");
  const [page, setPage] = useState(1);
  const list = useQuery({
    queryKey: ["viewing-requests", market.slug, type, page],
    queryFn: async () => api.getMyViewingRequests(market.slug, { type: type || undefined, page, per_page: 20 }),
  });
  const lastPage = Number(list.data?.meta.last_page ?? 1);

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-2">
        {(["", "viewing", "reserve"] as const).map((key) => (
          <Button key={key || "all"} size="sm" variant={type === key ? "dark" : "outline"} onClick={() => { setType(key); setPage(1); }}>
            {key ? tr(key) : t("allTypes")}
          </Button>
        ))}
      </div>
      {list.error ? <Alert tone="danger">{errorMessage(list.error, tc("error"))}</Alert> : null}
      {list.isLoading ? <Skeleton className="h-48 w-full" /> : null}
      {list.data && list.data.data.length === 0 ? <EmptyState icon={<CalendarCheck className="size-10" />} title={t("empty")} /> : null}
      <ul className="flex flex-col gap-3">
        {list.data?.data.map((r) => {
          const status = (REQUEST_STATUSES as readonly string[]).includes(r.status) ? t(`status.${r.status as (typeof REQUEST_STATUSES)[number]}`) : r.status;
          return (
            <li key={r.id}>
              <Card className="flex flex-wrap items-start gap-4">
                <div className="size-20 shrink-0 overflow-hidden rounded-md bg-subtle">
                  <AdImage src={r.ad?.image} alt={r.ad?.title ?? ""} />
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                  <p className="flex flex-wrap items-center gap-2 text-caption text-secondary">
                    <span className="font-semibold text-ink">{r.type === "reserve" || r.type === "viewing" ? tr(r.type) : r.type}</span>
                    <span className="rounded-full bg-subtle px-2 py-0.5">{status}</span>
                    <span>#{r.id}</span>
                  </p>
                  {r.ad ? (
                    <Link href={href(`/classified/find/${encodeURIComponent(r.ad.number)}`)} className="line-clamp-1 text-body font-semibold hover:underline">
                      {r.ad.title ?? r.ad.number}
                    </Link>
                  ) : null}
                  {r.ad?.price ? <p className="text-body-sm font-semibold" dir="ltr">{formatMoney(r.ad.price)}</p> : null}
                  <p className="text-body-sm text-secondary">
                    {r.scheduled_at ? t("scheduled", { date: formatDateTime(r.scheduled_at, market.locale, market.country.timeZone) }) : r.preferred_at ? t("preferred", { date: formatDateTime(r.preferred_at, market.locale, market.country.timeZone) }) : null}
                  </p>
                  {r.note ? <p className="text-body-sm">{t("note", { note: r.note })}</p> : null}
                  {r.created_at ? <p className="text-caption text-muted">{t("sent", { date: formatDay(r.created_at, market.locale) })}</p> : null}
                </div>
              </Card>
            </li>
          );
        })}
      </ul>
      {lastPage > 1 ? (
        <div className="flex items-center justify-center gap-2">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {tc("back")}
          </Button>
          <span className="text-body-sm text-secondary">
            {page} / {lastPage}
          </span>
          <Button size="sm" variant="outline" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
            {tc("continue")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

function ChatsView() {
  const t = useTranslations("classifieds.inquiries");
  const tc = useTranslations("common");
  const market = useMarket();
  const params = useSearchParams();
  const router = useRouter();
  const href = useHref();
  const [page, setPage] = useState(1);
  const selected = Number(params.get("thread")) || null;
  const threads = useQuery({ queryKey: ["classified-threads", market.slug, page], queryFn: async () => api.getThreads(market.slug, page) });
  const lastPage = Number(threads.data?.meta.last_page ?? 1);

  return (
    <div className="flex flex-col gap-4">
      {threads.error ? <Alert tone="danger">{errorMessage(threads.error, tc("error"))}</Alert> : null}
      <div className="grid items-start gap-4 lg:grid-cols-[320px_1fr]">
        <Card className="p-0 md:p-0">
          {threads.isLoading ? <Skeleton className="h-64 w-full" /> : null}
          {threads.data && threads.data.data.length === 0 ? (
            <div className="p-6 text-center text-body text-secondary">{t("empty")}</div>
          ) : (
            <ul className="divide-y divide-line">
              {threads.data?.data.map((thread) => (
                <li key={thread.id}>
                  <button
                    type="button"
                    onClick={() => router.replace(`${href("/account/inquiries")}?thread=${thread.id}`, { scroll: false })}
                    className={clsx("flex w-full flex-col gap-1 px-4 py-3 text-start hover:bg-subtle", selected === thread.id && "bg-brand-subtle")}
                  >
                    <span className="flex items-center justify-between gap-2">
                      <span className="line-clamp-1 text-body font-medium">{thread.ad.title}</span>
                      {thread.unread > 0 ? <span className="rounded-full bg-brand px-1.5 text-caption font-semibold">{thread.unread}</span> : null}
                    </span>
                    <span className="flex items-center gap-2 text-caption text-secondary">
                      <span>{t(`role.${thread.role === "seller" ? "seller" : "buyer"}`)}</span>
                      <AdStatusChip status={thread.status} />
                      <span className="ms-auto">{formatDay(thread.last_message_at, market.locale)}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          {lastPage > 1 ? (
            <div className="flex justify-between border-t border-line p-2">
              <Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                {tc("back")}
              </Button>
              <Button size="sm" variant="ghost" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
                {tc("continue")}
              </Button>
            </div>
          ) : null}
        </Card>
        {selected ? (
          <ThreadPanel id={selected} role={threads.data?.data.find((th) => th.id === selected)?.role ?? "buyer"} />
        ) : (
          <Card className="hidden flex-col items-center justify-center gap-2 py-16 text-center text-secondary lg:flex">
            <MessageSquare className="size-10" />
            {t("pick")}
          </Card>
        )}
      </div>
    </div>
  );
}

function ThreadPanel({ id, role }: { id: number; role: string }) {
  const t = useTranslations("classifieds.inquiries");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const [body, setBody] = useState("");
  const [contracts, setContracts] = useState<ReturnType<typeof requiredContracts>>(null);
  const thread = useQuery({ queryKey: ["classified-thread", market.slug, id], queryFn: async () => (await api.getThread(market.slug, id)).data, refetchInterval: 20_000 });
  const refresh = () => {
    client.invalidateQueries({ queryKey: ["classified-thread", market.slug, id] });
    client.invalidateQueries({ queryKey: ["classified-threads", market.slug] });
  };
  const reply = useMutation({
    mutationFn: () => api.replyThread(market.slug, id, body.trim()),
    onSuccess: () => {
      setBody("");
      refresh();
    },
    onError: (error) => setContracts(requiredContracts(error)),
  });
  const close = useMutation({ mutationFn: () => api.closeThread(market.slug, id), onSuccess: refresh });

  if (thread.isLoading) return <Skeleton className="h-96 w-full" />;
  if (thread.error || !thread.data) return <Alert tone="danger">{errorMessage(thread.error, tc("error"))}</Alert>;
  const data = thread.data;
  const closed = data.status === "closed";

  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3">
        <Link href={href(`/classified/find/${encodeURIComponent(data.ad.number)}`)} className="text-body font-semibold hover:underline">
          {data.ad.title}
        </Link>
        <div className="flex items-center gap-2">
          <AdStatusChip status={data.status} />
          {!closed ? (
            <Button size="sm" variant="outline" disabled={close.isPending} onClick={() => close.mutate()}>
              {t("close")}
            </Button>
          ) : null}
        </div>
      </div>
      <ul className="flex max-h-[480px] flex-col gap-2 overflow-y-auto">
        {data.messages.map((m) => {
          const mine = m.from === role;
          return (
            <li key={m.id} className={clsx("max-w-[80%] rounded-lg px-3 py-2 text-body", mine ? "self-end bg-brand-subtle" : "self-start bg-subtle")}>
              <p className="whitespace-pre-line">{m.body}</p>
              <p className="mt-1 text-caption text-muted">
                {m.from ? t(`from.${m.from === "seller" ? "seller" : m.from === "system" ? "system" : "buyer"}`) : ""} · {formatDateTime(m.at, market.locale, market.country.timeZone)}
              </p>
            </li>
          );
        })}
      </ul>
      {closed ? (
        <Alert tone="info">{t("closed")}</Alert>
      ) : (
        <form
          className="flex items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (body.trim()) reply.mutate();
          }}
        >
          <Textarea value={body} onChange={(e) => setBody(e.target.value)} maxLength={4000} placeholder={t("placeholder")} className="min-h-12 flex-1" />
          <Button type="submit" disabled={!body.trim() || reply.isPending} aria-label={t("send")}>
            <Send className="size-4 rtl:-scale-x-100" />
          </Button>
        </form>
      )}
      {reply.error && !requiredContracts(reply.error) ? <Alert tone="danger">{errorMessage(reply.error, tc("error"))}</Alert> : null}
      {contracts ? (
        <ContractModal
          contracts={contracts}
          onClose={() => setContracts(null)}
          onAccepted={() => {
            setContracts(null);
            reply.mutate();
          }}
        />
      ) : null}
    </Card>
  );
}

/** `/account/favourites` — saved classified & property ads (Figma Favourites 100:11857). */
export function FavouritesView() {
  const t = useTranslations("classifieds.favourites");
  const tc = useTranslations("common");
  const href = useHref();
  const tcl = useTranslations("classifieds");
  const favourites = useFavouriteNumbers();
  // Compare needs live ads of one category: offer the largest such group (max 4).
  const groups = new Map<string, string[]>();
  for (const ad of favourites.data ?? []) if (ad.status === "active" && ad.category) groups.set(ad.category, [...(groups.get(ad.category) ?? []), ad.number]);
  const compareSet = [...groups.values()].sort((a, b) => b.length - a.length)[0]?.slice(0, 4) ?? [];

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("title")}</h1>
      {favourites.error ? <Alert tone="danger">{errorMessage(favourites.error, tc("error"))}</Alert> : null}
      {favourites.isLoading ? <Skeleton className="h-64 w-full" /> : null}
      {favourites.data && favourites.data.length === 0 ? (
        <EmptyState icon={<Heart className="size-10" />} title={t("empty")} body={t("emptyHint")} action={<ButtonLink href={href("/classified")}>{t("browse")}</ButtonLink>} />
      ) : null}
      {compareSet.length >= 2 ? (
        <ButtonLink variant="outline" size="sm" className="self-start" href={`${href("/classified/compare")}?numbers=${compareSet.map(encodeURIComponent).join(",")}`}>
          {tcl("compare.cta")} ({compareSet.length})
        </ButtonLink>
      ) : null}
      {favourites.data && favourites.data.length > 0 ? <AdGrid ads={favourites.data} showStatus /> : null}
    </div>
  );
}

function searchHref(base: string, filters: Record<string, unknown>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (key === "attr" && value && typeof value === "object") {
      for (const [attr, v] of Object.entries(value as Record<string, unknown>)) {
        if (v && typeof v === "object") for (const [bound, n] of Object.entries(v as Record<string, unknown>)) params.set(`attr[${attr}][${bound}]`, String(n));
        else if (v !== null && v !== undefined) params.set(`attr[${attr}]`, String(v));
      }
    } else if (value !== null && value !== undefined && value !== "") {
      params.set(key, typeof value === "boolean" ? (value ? "1" : "0") : String(value));
    }
  }
  return `${base}?${params.toString()}`;
}

/** `/account/saved-searches` — alerts on/off, frequency, delete (Figma 100:11530). */
export function SavedSearchesView() {
  const t = useTranslations("classifieds.savedSearches");
  const ts = useTranslations("classifieds");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const key = ["classified-saved-searches", market.slug];
  const searches = useQuery({ queryKey: key, queryFn: async () => (await api.getSavedSearches(market.slug)).data });
  const update = useMutation({
    mutationFn: ({ id, body }: { id: number; body: Parameters<typeof api.updateSavedSearch>[2] }) => api.updateSavedSearch(market.slug, id, body),
    onSettled: () => client.invalidateQueries({ queryKey: key }),
  });
  const remove = useMutation({ mutationFn: (id: number) => api.deleteSavedSearch(market.slug, id), onSettled: () => client.invalidateQueries({ queryKey: key }) });

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("title")}</h1>
      <p className="text-body-sm text-secondary">{t("hint")}</p>
      {searches.error || update.error || remove.error ? <Alert tone="danger">{errorMessage(searches.error ?? update.error ?? remove.error, tc("error"))}</Alert> : null}
      {searches.isLoading ? <Skeleton className="h-48 w-full" /> : null}
      {searches.data && searches.data.length === 0 ? (
        <EmptyState icon={<Search className="size-10" />} title={t("empty")} action={<ButtonLink href={href("/classified/search")}>{t("start")}</ButtonLink>} />
      ) : null}
      <ul className="flex flex-col gap-3">
        {searches.data?.map((search: SavedSearch) => {
          const active = search.status === "active";
          return (
            <li key={search.id}>
              <Card className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1">
                  <Link href={searchHref(href("/classified/search"), search.filters)} className="text-body font-semibold hover:underline">
                    {search.name}
                  </Link>
                  <p className="line-clamp-1 text-body-sm text-secondary">
                    {Object.entries(search.filters)
                      .filter(([k]) => k !== "attr")
                      .map(([k, v]) => `${k}: ${api.specText(v)}`)
                      .join(" · ") || ts("allAds")}
                  </p>
                </div>
                <select
                  value={search.alert_frequency}
                  onChange={(e) => update.mutate({ id: search.id, body: { alert_frequency: e.target.value as "daily" | "instant" } })}
                  className="h-9 rounded-md border border-line-strong bg-surface px-2 text-body-sm"
                  aria-label={ts("alertFrequency")}
                >
                  <option value="daily">{ts("alerts.daily")}</option>
                  <option value="instant">{ts("alerts.instant")}</option>
                </select>
                <Button size="sm" variant="outline" onClick={() => update.mutate({ id: search.id, body: { status: active ? "paused" : "active" } })}>
                  {active ? <Bell className="size-4" /> : <BellOff className="size-4" />}
                  {active ? t("alertsOn") : t("alertsOff")}
                </Button>
                <Button size="sm" variant="ghost" aria-label={tc("remove")} onClick={() => remove.mutate(search.id)}>
                  <Trash2 className="size-4" />
                </Button>
              </Card>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

const ACTIONS: Record<string, AdAction[]> = {
  active: ["pause", "archive"],
  paused: ["resume", "archive"],
  expired: ["renew", "archive"],
  sold: ["unsold", "archive"],
  draft: ["archive"],
  pending_contract: ["archive"],
  rejected: ["archive"],
};

/** `/account/my-ads` — the customer's own classified ads with lifecycle actions. */
export function MyAdsView() {
  const t = useTranslations("classifieds.myAds");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const [soldFor, setSoldFor] = useState<{ number: string; price: string } | null>(null);
  const ads = useQuery({ queryKey: ["classified-mine", market.slug, page], queryFn: async () => api.getMyAds(market.slug, page) });
  const refresh = () => {
    client.invalidateQueries({ queryKey: ["classified-mine", market.slug] });
    client.invalidateQueries({ queryKey: favouritesKey(market.slug) });
  };
  const act = useMutation({ mutationFn: ({ number, action }: { number: string; action: AdAction }) => api.adAction(market.slug, number, action), onSuccess: refresh });
  const sold = useMutation({
    mutationFn: ({ number, price }: { number: string; price: string }) => api.markAdSold(market.slug, number, price),
    onSuccess: () => {
      setSoldFor(null);
      refresh();
    },
  });
  const total = Number(ads.data?.meta.total ?? 0);
  const pages = Math.max(1, Math.ceil(total / 24));

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-h2 font-bold">{t("title")}</h1>
        <ButtonLink href={href("/classified/post")}>{t("post")}</ButtonLink>
      </div>
      {ads.error || act.error || sold.error ? <Alert tone="danger">{errorMessage(ads.error ?? act.error ?? sold.error, tc("error"))}</Alert> : null}
      {ads.isLoading ? <Skeleton className="h-64 w-full" /> : null}
      {ads.data && ads.data.data.length === 0 ? <EmptyState title={t("empty")} action={<ButtonLink href={href("/classified/post")}>{t("post")}</ButtonLink>} /> : null}
      <ul className="flex flex-col gap-3">
        {ads.data?.data.map((ad: AdCardData) => {
          const editable = ["draft", "pending_contract"].includes(ad.status);
          const duplicable = (api.DUPLICABLE_STATUSES as readonly string[]).includes(ad.status);
          return (
            <li key={ad.number}>
              <Card className="flex flex-wrap items-center gap-4">
                <div className="size-20 shrink-0 overflow-hidden rounded-md bg-subtle">
                  <AdImage src={ad.image} alt={ad.title} />
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                  <Link href={href(`/classified/find/${encodeURIComponent(ad.number)}`)} className="line-clamp-1 text-body font-semibold hover:underline">
                    {ad.title}
                  </Link>
                  <AdPrice ad={ad} />
                  <p className="flex flex-wrap items-center gap-2 text-caption text-secondary">
                    <AdStatusChip status={ad.status} />
                    <span>#{ad.number}</span>
                    {typeof ad.views === "number" ? <span>{t("views", { count: ad.views })}</span> : null}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {editable ? (
                    <ButtonLink size="sm" variant="outline" href={`${href("/classified/post")}?number=${encodeURIComponent(ad.number)}`}>
                      <Pencil className="size-4" />
                      {ad.status === "pending_contract" ? t("signContract") : t("edit")}
                    </ButtonLink>
                  ) : null}
                  {duplicable ? <DuplicateButton number={ad.number} size="sm" variant="outline" /> : null}
                  {ad.status === "active" ? (
                    <Button size="sm" variant="outline" onClick={() => setSoldFor({ number: ad.number, price: ad.price?.amount ?? "" })}>
                      {t("markSold")}
                    </Button>
                  ) : null}
                  {(ACTIONS[ad.status] ?? []).map((action) => (
                    <Button key={action} size="sm" variant={action === "archive" ? "ghost" : "outline"} disabled={act.isPending} onClick={() => act.mutate({ number: ad.number, action })}>
                      {t(`actions.${action}`)}
                    </Button>
                  ))}
                </div>
                {soldFor?.number === ad.number ? (
                  <form
                    className="flex w-full flex-wrap items-center gap-2 border-t border-line pt-3"
                    onSubmit={(e) => {
                      e.preventDefault();
                      sold.mutate(soldFor);
                    }}
                  >
                    <label className="text-body-sm">{t("finalPrice", { currency: market.country.currency })}</label>
                    <Input type="number" min={0} step="any" value={soldFor.price} onChange={(e) => setSoldFor({ ...soldFor, price: e.target.value })} className="h-9 w-40" required dir="ltr" />
                    <Button size="sm" type="submit" disabled={sold.isPending}>
                      {t("confirmSold")}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setSoldFor(null)}>
                      {tc("cancel")}
                    </Button>
                  </form>
                ) : null}
              </Card>
            </li>
          );
        })}
      </ul>
      {pages > 1 ? (
        <div className="flex items-center justify-center gap-2">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {tc("back")}
          </Button>
          <span className="text-body-sm text-secondary">
            {page} / {pages}
          </span>
          <Button size="sm" variant="outline" disabled={page >= pages} onClick={() => setPage(page + 1)}>
            {tc("continue")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}
