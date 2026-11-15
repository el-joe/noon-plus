"use client";

import { useMutation } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useEffect, useState, type FormEvent } from "react";
import { Modal } from "@/components/layout/modal";
import { Button } from "@/components/ui/button";
import { Field, Textarea } from "@/components/ui/field";
import { Alert, Spinner } from "@/components/ui/misc";
import { sendTravelInquiry } from "@/lib/api/travel";
import { errorMessage, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { ContactFields, type Contact } from "./contact-fields";
import { fieldErrorsOf } from "./shared";

/** "Ask the agency" (FR-TRV-4) — POST travel/packages/{slug}/inquiries; guests allowed. */
export function AskAgency({ slug, agency }: { slug: string; agency: string }) {
  const t = useTranslations("travel");
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button variant="outline" className="w-full" onClick={() => setOpen(true)}>
        {t("detail.askAgency")}
      </Button>
      {open ? (
        <Modal title={t("inquiry.title")} onClose={() => setOpen(false)}>
          <InquiryForm slug={slug} agency={agency} />
        </Modal>
      ) : null}
    </>
  );
}

function InquiryForm({ slug, agency }: { slug: string; agency: string }) {
  const t = useTranslations();
  const market = useMarket();
  const { user } = useSession();
  const [contact, setContact] = useState<Contact>({ name: "", email: "", phone: "" });
  const [message, setMessage] = useState("");
  useEffect(() => {
    if (user) setContact((c) => ({ ...c, name: c.name || user.name || "", email: c.email || user.email }));
  }, [user]);
  const send = useMutation({ mutationFn: () => sendTravelInquiry(market.slug, slug, { ...contact, message }) });
  const errors = fieldErrorsOf(send.error);

  if (send.isSuccess) return <Alert tone="success">{t("travel.inquiry.sent", { agency })}</Alert>;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    send.mutate();
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-3">
      {send.error && Object.keys(errors).length === 0 ? <Alert tone="danger">{errorMessage(send.error, t("common.error"))}</Alert> : null}
      <ContactFields value={contact} onChange={setContact} errors={errors} />
      <Field label={`${t("travel.inquiry.message")} *`} error={errors.message}>
        <Textarea required maxLength={2000} value={message} onChange={(e) => setMessage(e.target.value)} />
      </Field>
      <Button type="submit" disabled={send.isPending}>
        {send.isPending ? <Spinner /> : null}
        {t("travel.inquiry.send")}
      </Button>
    </form>
  );
}
