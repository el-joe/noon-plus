"use client";

import { useMutation } from "@tanstack/react-query";
import { CalendarCheck } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input, Textarea } from "@/components/ui/field";
import { Alert, Spinner } from "@/components/ui/misc";
import { requestViewing } from "@/lib/api/real-estate";
import { errorMessage, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { ContactFields, type Contact } from "@/features/travel/contact-fields";
import { fieldErrorsOf } from "@/features/travel/shared";

/** Local "YYYY-MM-DDTHH:mm" for a datetime-local input. */
function localInput(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * Viewing / reserve request (Figma Property detail 70:6770, phone added in
 * WPD1; D8): type (from the ad's `request_types`), name, email, mobile and
 * preferred time — no payment. POST classifieds/ads/{number}/viewings; guests allowed.
 */
export function ViewingRequestForm({ number, types: allowed = ["viewing"] }: { number: string; types?: string[] }) {
  const t = useTranslations();
  const market = useMarket();
  const { user } = useSession();
  const types = (["viewing", "reserve"] as const).filter((type) => allowed.includes(type));
  const [type, setType] = useState<"viewing" | "reserve">(types[0] ?? "viewing");
  const [contact, setContact] = useState<Contact>({ name: "", email: "", phone: "" });
  const [when, setWhen] = useState("");
  const [message, setMessage] = useState("");
  const [min, setMin] = useState("");
  useEffect(() => {
    const next = new Date(Date.now() + 2 * 60 * 60 * 1000);
    next.setMinutes(0, 0, 0);
    setMin(localInput(new Date()));
    setWhen((w) => w || localInput(next));
  }, []);
  useEffect(() => {
    if (user) setContact((c) => ({ ...c, name: c.name || user.name || "", email: c.email || user.email }));
  }, [user]);

  const send = useMutation({
    mutationFn: () => requestViewing(market.slug, number, { ...contact, type, preferred_at: new Date(when).toISOString(), message: message.trim() || undefined }),
  });
  const errors = fieldErrorsOf(send.error);

  if (send.isSuccess) {
    return (
      <div className="flex flex-col items-start gap-2">
        <Alert tone="success" className="w-full">
          <p className="flex items-center gap-2 font-semibold">
            <CalendarCheck className="size-4" />
            {t("realEstate.viewing.sent")}
          </p>
          <p className="text-body-sm">{t("realEstate.viewing.sentBody")}</p>
        </Alert>
        <Button variant="link" onClick={() => send.reset()}>
          {t("realEstate.viewing.another")}
        </Button>
      </div>
    );
  }

  const submit = (e: FormEvent) => {
    e.preventDefault();
    send.mutate();
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-3">
      {send.error && Object.keys(errors).length === 0 ? <Alert tone="danger">{errorMessage(send.error, t("common.error"))}</Alert> : null}
      {types.length > 1 ? (
        <fieldset className="flex flex-wrap gap-4">
          <legend className="mb-1 text-label font-medium">{t("realEstate.viewing.what")}</legend>
          {types.map((option) => (
            <label key={option} className="flex items-center gap-2 text-body">
              <input type="radio" name="request-type" checked={type === option} onChange={() => setType(option)} />
              {t(`realEstate.viewing.types.${option}`)}
            </label>
          ))}
        </fieldset>
      ) : null}
      {types.length === 0 ? <Alert tone="warning">{t("realEstate.viewing.unavailable")}</Alert> : null}
      <ContactFields value={contact} onChange={setContact} errors={errors} />
      <Field label={`${t("realEstate.viewing.preferred")} *`} error={errors.preferred_at}>
        <Input type="datetime-local" required min={min} value={when} onChange={(e) => setWhen(e.target.value)} />
      </Field>
      <Field label={t("realEstate.viewing.message")} error={errors.message}>
        <Textarea maxLength={1000} value={message} onChange={(e) => setMessage(e.target.value)} />
      </Field>
      <Button type="submit" disabled={send.isPending || !when || types.length === 0}>
        {send.isPending ? <Spinner /> : null}
        {type === "reserve" ? t("realEstate.viewing.sendReserve") : t("realEstate.viewing.send")}
      </Button>
      <p className="text-caption text-secondary">{t("realEstate.viewing.note")}</p>
    </form>
  );
}
