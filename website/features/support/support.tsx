"use client";

import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { ArrowLeft, FileText, LifeBuoy, Paperclip, Send, Star, X } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useRef, useState, type FormEvent } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Alert, Badge, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import { fieldErrors, formError } from "@/features/account-extra/shared";
import { getOrders } from "@/lib/api/endpoints";
import * as api from "@/lib/api/support";
import type { SupportOptions, Ticket } from "@/lib/api/support";
import { formatDateTime, formatDay } from "@/lib/format";
import { useErrorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

type Status = (typeof api.SUPPORT_STATUSES)[number];

const TONES: Record<Status, "success" | "warning" | "danger" | "info" | "neutral"> = {
  open: "warning",
  in_progress: "info",
  waiting_customer: "warning",
  resolved: "success",
  closed: "neutral",
};

export function TicketStatus({ ticket }: { ticket: Pick<Ticket, "status" | "status_label"> }) {
  const t = useTranslations("accountPlus.support.status");
  const status = ticket.status as Status;
  const label = api.SUPPORT_STATUSES.includes(status) ? t(status) : (ticket.status_label ?? ticket.status);
  return <Badge tone={TONES[status] ?? "neutral"}>{label}</Badge>;
}

const optionsKey = (market: string) => ["support-options", market] as const;

function useSupportOptions() {
  const market = useMarket();
  return useQuery({ queryKey: optionsKey(market.slug), staleTime: 60 * 60_000, queryFn: async () => (await api.getSupportOptions(market.slug)).data });
}

/** `/account/support` — the customer's tickets with a status filter (QC-007). */
export function SupportList() {
  const t = useTranslations("accountPlus.support");
  const market = useMarket();
  const href = useHref();
  const errorText = useErrorMessage();
  const [status, setStatus] = useState<Status | "">("");
  const list = useInfiniteQuery({
    queryKey: ["support-tickets", market.slug, status],
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => api.getTickets(market.slug, { status: status || undefined, page: pageParam, per_page: 20 }),
    getNextPageParam: (last) => (Number(last.meta.page ?? 1) < Number(last.meta.last_page ?? 1) ? Number(last.meta.page ?? 1) + 1 : undefined),
  });
  const tickets = list.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-h2 font-bold">{t("title")}</h1>
        <ButtonLink href={href("/account/support/new")}>{t("new")}</ButtonLink>
      </div>
      <div className="scrollbar-none flex gap-2 overflow-x-auto" role="group" aria-label={t("filter")}>
        {(["", ...api.SUPPORT_STATUSES] as const).map((value) => (
          <button
            key={value || "all"}
            type="button"
            aria-pressed={status === value}
            onClick={() => setStatus(value)}
            className={clsx("shrink-0 rounded-full border px-3 py-1 text-body-sm", status === value ? "border-ink bg-ink text-white" : "border-line bg-surface hover:bg-subtle")}
          >
            {value ? t(`status.${value}`) : t("all")}
          </button>
        ))}
      </div>
      {list.isLoading ? <Skeleton className="h-48 w-full" /> : null}
      {list.error ? <Alert tone="danger">{errorText(list.error)}</Alert> : null}
      {list.data && tickets.length === 0 ? (
        <EmptyState icon={<LifeBuoy className="size-10" />} title={status ? t("emptyFiltered") : t("empty")} body={t("emptyHint")} action={<ButtonLink href={href("/account/support/new")}>{t("new")}</ButtonLink>} />
      ) : null}
      <ul className="flex flex-col gap-3">
        {tickets.map((ticket) => (
          <li key={ticket.number}>
            <Link href={href(`/account/support/${encodeURIComponent(ticket.number)}`)} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-line bg-surface p-4 hover:shadow-sm">
              <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="flex flex-wrap items-center gap-2">
                  <TicketStatus ticket={ticket} />
                  <span className="text-caption text-secondary">{ticket.category_label ?? ticket.category}</span>
                </p>
                <p className="truncate text-body font-semibold">{ticket.subject}</p>
                <p className="text-body-sm text-secondary">
                  {ticket.number}
                  {ticket.order ? ` · ${ticket.order}` : ""} · {t("updated", { date: formatDateTime(ticket.last_message_at ?? ticket.created_at, market.locale, market.country.timeZone) })}
                </p>
              </div>
              {ticket.status === "waiting_customer" ? <Badge tone="warning">{t("needsReply")}</Badge> : null}
            </Link>
          </li>
        ))}
      </ul>
      {list.hasNextPage ? (
        <div className="flex justify-center">
          <Button variant="outline" onClick={() => list.fetchNextPage()} disabled={list.isFetchingNextPage}>
            {t("loadMore")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

/** File picker that respects the API limits (count, type, size). */
function AttachmentPicker({ files, onChange, options }: { files: File[]; onChange: (files: File[]) => void; options: SupportOptions | undefined }) {
  const t = useTranslations("accountPlus.support");
  const input = useRef<HTMLInputElement>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const max = options?.max_attachments ?? 5;
  const types = options?.attachment_types ?? ["jpg", "jpeg", "png", "webp", "pdf"];
  const maxKb = options?.attachment_max_kb ?? 5120;

  const add = (list: FileList | null) => {
    if (!list) return;
    const next = [...files];
    let issue: string | null = null;
    for (const file of Array.from(list)) {
      const ext = file.name.split(".").pop()?.toLowerCase() ?? "";
      if (!types.includes(ext)) issue = t("attachTypes", { types: types.join(", ") });
      else if (file.size > maxKb * 1024) issue = t("attachTooBig", { mb: Math.round(maxKb / 1024) });
      else if (next.length >= max) issue = t("attachMax", { max });
      else next.push(file);
    }
    setProblem(issue);
    onChange(next);
    if (input.current) input.current.value = "";
  };

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" variant="outline" onClick={() => input.current?.click()} disabled={files.length >= max}>
          <Paperclip className="size-4" />
          {t("attach")}
        </Button>
        <span className="text-caption text-secondary">{t("attachHint", { max, types: types.join(", "), mb: Math.round(maxKb / 1024) })}</span>
        <input ref={input} type="file" multiple hidden accept={types.map((x) => `.${x}`).join(",")} onChange={(e) => add(e.target.files)} />
      </div>
      {files.length > 0 ? (
        <ul className="flex flex-wrap gap-2">
          {files.map((file, i) => (
            <li key={`${file.name}-${i}`} className="flex items-center gap-1.5 rounded-full bg-subtle px-3 py-1 text-caption">
              <FileText className="size-3.5" />
              <span className="max-w-40 truncate">{file.name}</span>
              <button type="button" aria-label={t("removeFile", { name: file.name })} onClick={() => onChange(files.filter((_, j) => j !== i))}>
                <X className="size-3.5" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {problem ? <p className="text-body-sm text-danger-text">{problem}</p> : null}
    </div>
  );
}

/** `/account/support/new?order=&shipment=` — open a ticket. */
export function NewTicket() {
  const t = useTranslations("accountPlus.support");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const params = useSearchParams();
  const client = useQueryClient();
  const options = useSupportOptions();
  const orders = useQuery({ queryKey: ["orders", market.slug, "support-picker"], queryFn: async () => (await getOrders(market.slug, 1, { per_page: 20 })).data });
  const [category, setCategory] = useState(params.get("order") ? "order" : "");
  const [order, setOrder] = useState(params.get("order") ?? "");
  const [shipment, setShipment] = useState(params.get("shipment") ?? "");
  const [subject, setSubject] = useState("");
  const [message, setMessage] = useState("");
  const [priority, setPriority] = useState("normal");
  const [files, setFiles] = useState<File[]>([]);

  const create = useMutation({
    mutationFn: async () => {
      const form = new FormData();
      form.append("category", category);
      form.append("subject", subject.trim());
      form.append("message", message.trim());
      form.append("priority", priority);
      if (order) form.append("order", order);
      if (shipment) form.append("shipment", shipment);
      for (const file of files) form.append("attachments[]", file);
      return (await api.createTicket(market.slug, form)).data;
    },
    onSuccess: (ticket) => {
      client.invalidateQueries({ queryKey: ["support-tickets", market.slug] });
      router.push(href(`/account/support/${encodeURIComponent(ticket.number)}`));
    },
  });
  const errors = fieldErrors(create.error);
  const general = formError(create.error, ["category", "subject", "message", "priority", "order", "shipment"], tc("error"));
  const selected = orders.data?.find((o) => o.number === order);
  const priorities = (options.data?.priorities ?? []).map((p) => (typeof p === "string" ? { value: p, label: p } : p));

  const submit = (e: FormEvent) => {
    e.preventDefault();
    create.mutate();
  };

  return (
    <div className="flex flex-col gap-4">
      <Link href={href("/account/support")} className="flex items-center gap-1.5 text-body-sm text-secondary">
        <ArrowLeft className="size-4 rtl:rotate-180" /> {t("back")}
      </Link>
      <h1 className="text-h2 font-bold">{t("new")}</h1>
      {options.isLoading ? <Skeleton className="h-96 w-full" /> : null}
      {options.data ? (
        <Card>
          <form onSubmit={submit} className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t("category")} error={errors.category}>
                <Select value={category} onChange={(e) => setCategory(e.target.value)} required>
                  <option value="">{t("chooseCategory")}</option>
                  {options.data.categories.map((c) => (
                    <option key={c.value} value={c.value}>
                      {c.label}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label={t("priority")} error={errors.priority}>
                <Select value={priority} onChange={(e) => setPriority(e.target.value)}>
                  {priorities.map((p) => (
                    <option key={p.value} value={p.value}>
                      {p.label}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label={t("order")} hint={t("optional")} error={errors.order}>
                <Select
                  value={order}
                  onChange={(e) => {
                    setOrder(e.target.value);
                    setShipment("");
                  }}
                >
                  <option value="">{t("noOrder")}</option>
                  {order && !orders.data?.some((o) => o.number === order) ? <option value={order}>{order}</option> : null}
                  {orders.data?.map((o) => (
                    <option key={o.number} value={o.number}>
                      {o.number} · {formatDay(o.placed_at, market.locale)}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label={t("shipment")} hint={t("optional")} error={errors.shipment}>
                <Select value={shipment} onChange={(e) => setShipment(e.target.value)} disabled={!order}>
                  <option value="">{t("wholeOrder")}</option>
                  {shipment && !selected?.shipments.some((s) => s.number === shipment) ? <option value={shipment}>{shipment}</option> : null}
                  {selected?.shipments.map((s) => (
                    <option key={s.number} value={s.number}>
                      {s.number}
                      {s.seller ? ` · ${s.seller}` : ""}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>
            <Field label={t("subject")} error={errors.subject}>
              <Input value={subject} onChange={(e) => setSubject(e.target.value)} minLength={3} maxLength={160} required />
            </Field>
            <Field label={t("message")} error={errors.message} hint={t("messageHint")}>
              <Textarea value={message} onChange={(e) => setMessage(e.target.value)} minLength={10} maxLength={5000} rows={6} required />
            </Field>
            <AttachmentPicker files={files} onChange={setFiles} options={options.data} />
            {general ? <Alert tone="danger">{general}</Alert> : errors["attachments"] || Object.keys(errors).some((k) => k.startsWith("attachments")) ? (
              <Alert tone="danger">{Object.entries(errors).find(([k]) => k.startsWith("attachments"))?.[1]}</Alert>
            ) : null}
            <div className="flex justify-end">
              <Button type="submit" disabled={create.isPending || !category}>
                {create.isPending ? t("sending") : t("submit")}
              </Button>
            </div>
          </form>
        </Card>
      ) : null}
    </div>
  );
}

/** `/account/support/{number}` — thread, reply, reopen and rating. */
export function TicketDetail({ number }: { number: string }) {
  const t = useTranslations("accountPlus.support");
  const market = useMarket();
  const href = useHref();
  const errorText = useErrorMessage();
  const client = useQueryClient();
  const key = ["support-ticket", market.slug, number];
  const { data: ticket, isLoading, error } = useQuery({ queryKey: key, queryFn: async () => (await api.getTicket(market.slug, number)).data });
  const options = useSupportOptions();
  const refresh = () => {
    client.invalidateQueries({ queryKey: key });
    client.invalidateQueries({ queryKey: ["support-tickets", market.slug] });
  };

  if (isLoading) return <Skeleton className="h-96 w-full" />;
  if (error || !ticket) return <Alert tone="danger">{errorText(error)}</Alert>;

  return (
    <div className="flex flex-col gap-4">
      <Link href={href("/account/support")} className="flex items-center gap-1.5 text-body-sm text-secondary">
        <ArrowLeft className="size-4 rtl:rotate-180" /> {t("back")}
      </Link>
      <div className="flex flex-col gap-1">
        <p className="flex flex-wrap items-center gap-2">
          <TicketStatus ticket={ticket} />
          <span className="text-body-sm text-secondary">
            {ticket.number} · {ticket.category_label ?? ticket.category}
          </span>
        </p>
        <h1 className="text-h2 font-bold">{ticket.subject}</h1>
        <p className="text-body-sm text-secondary">
          {t("opened", { date: formatDateTime(ticket.created_at, market.locale, market.country.timeZone) })}
          {ticket.order ? (
            <>
              {" · "}
              <Link href={href(`/account/orders/${encodeURIComponent(ticket.order)}`)} className="text-link hover:underline">
                {ticket.shipment ?? ticket.order}
              </Link>
            </>
          ) : null}
        </p>
      </div>

      {ticket.reply_expected_by && !ticket.first_responded_at && (ticket.status === "open" || ticket.status === "in_progress") ? (
        <Alert tone="info">{t("replyBy", { date: formatDateTime(ticket.reply_expected_by, market.locale, market.country.timeZone) })}</Alert>
      ) : null}
      {ticket.status === "waiting_customer" ? <Alert tone="warning">{t("waitingYou")}</Alert> : null}

      <Card className="flex flex-col gap-4">
        <ol className="flex flex-col gap-3">
          {(ticket.messages ?? []).map((m) => (
            <li key={m.id} className={clsx("flex flex-col gap-1 rounded-lg p-3", m.mine ? "ms-6 bg-brand-subtle" : "me-6 bg-subtle")}>
              <p className="text-caption font-semibold text-secondary">
                {m.mine ? t("you") : m.from === "support" ? t("supportTeam") : m.from} · {formatDateTime(m.created_at, market.locale, market.country.timeZone)}
              </p>
              <p className="whitespace-pre-line text-body">{m.body}</p>
              {m.attachments.length > 0 ? (
                <ul className="mt-1 flex flex-wrap gap-2">
                  {m.attachments.map((a, i) => (
                    <li key={i}>
                      <a href={api.attachmentHref(a.url)} target="_blank" rel="noopener" className="flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1 text-caption hover:bg-subtle">
                        <Paperclip className="size-3.5" />
                        <span className="max-w-48 truncate">{a.name}</span>
                      </a>
                    </li>
                  ))}
                </ul>
              ) : null}
            </li>
          ))}
        </ol>
        {ticket.can_reply ? <ReplyBox number={ticket.number} options={options.data} onSent={refresh} /> : null}
        {!ticket.can_reply && ticket.status === "resolved" ? <p className="text-body-sm text-secondary">{t("resolvedHint")}</p> : null}
        {ticket.status === "closed" ? <p className="text-body-sm text-secondary">{t("closedHint")}</p> : null}
      </Card>

      {ticket.can_reopen ? <ReopenBox ticket={ticket} onDone={refresh} /> : null}
      {ticket.can_rate ? <RatingBox number={ticket.number} onDone={refresh} /> : null}
      {ticket.rating ? (
        <Card>
          <h2 className="mb-1 text-h4 font-semibold">{t("yourRating")}</h2>
          <Stars value={ticket.rating.score} />
          {ticket.rating.comment ? <p className="mt-1 text-body-sm text-secondary">{ticket.rating.comment}</p> : null}
        </Card>
      ) : null}
    </div>
  );
}

function ReplyBox({ number, options, onSent }: { number: string; options: SupportOptions | undefined; onSent: () => void }) {
  const t = useTranslations("accountPlus.support");
  const market = useMarket();
  const errorText = useErrorMessage();
  const [body, setBody] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const send = useMutation({
    mutationFn: async () => {
      const form = new FormData();
      form.append("body", body.trim());
      for (const file of files) form.append("attachments[]", file);
      return api.replyToTicket(market.slug, number, form);
    },
    onSuccess: () => {
      setBody("");
      setFiles([]);
      onSent();
    },
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (body.trim().length >= 2) send.mutate();
  };
  return (
    <form onSubmit={submit} className="flex flex-col gap-3 border-t border-line pt-4">
      <Field label={t("reply")}>
        <Textarea value={body} onChange={(e) => setBody(e.target.value)} minLength={2} maxLength={5000} rows={4} required />
      </Field>
      <AttachmentPicker files={files} onChange={setFiles} options={options} />
      {send.error ? <Alert tone="danger">{errorText(send.error)}</Alert> : null}
      <div className="flex justify-end">
        <Button type="submit" disabled={send.isPending || body.trim().length < 2}>
          <Send className="size-4 rtl:-scale-x-100" />
          {send.isPending ? t("sending") : t("send")}
        </Button>
      </div>
    </form>
  );
}

function ReopenBox({ ticket, onDone }: { ticket: Ticket; onDone: () => void }) {
  const t = useTranslations("accountPlus.support");
  const market = useMarket();
  const errorText = useErrorMessage();
  const [message, setMessage] = useState("");
  const reopen = useMutation({ mutationFn: () => api.reopenTicket(market.slug, ticket.number, message.trim()), onSuccess: onDone });
  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-h4 font-semibold">{t("reopenTitle")}</h2>
      <p className="text-body-sm text-secondary">{ticket.reopen_until ? t("reopenUntil", { date: formatDay(ticket.reopen_until, market.locale) }) : t("reopenHint")}</p>
      <Textarea value={message} onChange={(e) => setMessage(e.target.value)} maxLength={5000} rows={3} placeholder={t("reopenPlaceholder")} aria-label={t("reopenPlaceholder")} />
      {reopen.error ? <Alert tone="danger">{errorText(reopen.error)}</Alert> : null}
      <div>
        <Button variant="outline" onClick={() => reopen.mutate()} disabled={reopen.isPending}>
          {t("reopen")}
        </Button>
      </div>
    </Card>
  );
}

function Stars({ value, onPick }: { value: number; onPick?: (n: number) => void }) {
  const t = useTranslations("accountPlus.support");
  return (
    <div className="flex gap-1" role={onPick ? "radiogroup" : undefined} aria-label={t("ratingLabel")}>
      {[1, 2, 3, 4, 5].map((n) =>
        onPick ? (
          <button key={n} type="button" role="radio" aria-checked={value === n} aria-label={t("stars", { count: n })} onClick={() => onPick(n)}>
            <Star className={clsx("size-7", n <= value ? "fill-[var(--color-brand)] text-brand" : "text-line-strong")} />
          </button>
        ) : (
          <Star key={n} className={clsx("size-5", n <= value ? "fill-[var(--color-brand)] text-brand" : "text-line-strong")} aria-hidden />
        ),
      )}
    </div>
  );
}

function RatingBox({ number, onDone }: { number: string; onDone: () => void }) {
  const t = useTranslations("accountPlus.support");
  const market = useMarket();
  const errorText = useErrorMessage();
  const [score, setScore] = useState(0);
  const [comment, setComment] = useState("");
  const rate = useMutation({ mutationFn: () => api.rateTicket(market.slug, number, score, comment.trim()), onSuccess: onDone });
  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-h4 font-semibold">{t("rateTitle")}</h2>
      <Stars value={score} onPick={setScore} />
      <Textarea value={comment} onChange={(e) => setComment(e.target.value)} maxLength={1000} rows={2} placeholder={t("rateComment")} aria-label={t("rateComment")} />
      {rate.error ? <Alert tone="danger">{errorText(rate.error)}</Alert> : null}
      <div>
        <Button onClick={() => rate.mutate()} disabled={score < 1 || rate.isPending}>
          {t("rateSubmit")}
        </Button>
      </div>
    </Card>
  );
}
