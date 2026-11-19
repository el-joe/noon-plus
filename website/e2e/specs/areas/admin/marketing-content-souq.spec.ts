import {
  RUN, expect, expectValidation, fetchFile, flash, flow, form, main, open, recordOutcomes, submit, submitNoValidate, test,
} from "./helpers";

const R = "admin-panel-marketing-content-and-souq" as const;
recordOutcomes(R);

const inOneHour = (h = 1) => {
  const d = new Date(Date.now() + h * 3600_000 + 4 * 3600_000); // Asia/Dubai wall clock
  return d.toISOString().slice(0, 16);
};

test("ADM-50 coupons: percentage with scope, BOGO, edit, toggle", async ({ page }) => {
  const f = flow(page, R, "ADM-50", "Coupons (incl. BOGO and scopes)", "Admin creates a category-scoped percentage coupon and a BOGO coupon (validation first), edits one, switches it off/on and filters the list.");
  const pct = `QAPCT${RUN}`.toUpperCase().slice(0, 16);
  const bogo = `QABOGO${RUN}`.toUpperCase().slice(0, 16);
  await f.step("Open New coupon and submit empty", "Refused: code (and value) required", async () => {
    await open(page, "/coupons/create");
    await submitNoValidate(form(page, "/coupons"));
    await expectValidation(page);
  });
  await f.step(`Create ${pct}: 15% off, max AED 50, platform-funded, scoped to one category, new customers, 100 uses`, "Coupon created and shown", async () => {
    await open(page, "/coupons/create");
    const fm = form(page, "/coupons");
    await fm.locator('[name="code"]').fill(pct);
    await fm.locator('[name="description"]').fill(`QA ${RUN} category coupon`);
    await fm.locator('select[name="scope"]').selectOption("platform");
    await fm.locator('select[name="funded_by"]').selectOption("platform");
    await fm.locator('select[name="type"]').selectOption("percentage");
    await fm.locator('[name="value"]').fill("15");
    await fm.locator('[name="max_discount_amount"]').fill("50");
    await fm.locator('select[name="target_type"]').selectOption("category");
    await fm.locator('select[name="target_category_ids[]"]').selectOption({ index: 0 });
    await fm.locator('select[name="customer_segment"]').selectOption("new_customers");
    await fm.locator('[name="usage_limit"]').fill("100");
    await fm.locator('[name="per_customer_limit"]').fill("1");
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required/);
    await open(page, `/coupons?q=${pct}`);
    await expect(main(page)).toContainText(pct);
  });
  await f.step(`Create BOGO ${bogo} with get quantity missing`, "Refused: buy/get quantities required for BOGO", async () => {
    await open(page, "/coupons/create");
    const fm = form(page, "/coupons");
    await fm.locator('[name="code"]').fill(bogo);
    await fm.locator('select[name="type"]').selectOption("bogo");
    await fm.locator('[name="buy_quantity"]').fill("2");
    await fm.locator('[name="get_quantity"]').fill("");
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step(`Create BOGO ${bogo}: buy 2 get 1, vendor-scoped (E2E Store), vendor-funded`, "Coupon created", async () => {
    await open(page, "/coupons/create");
    const fm = form(page, "/coupons");
    await fm.locator('[name="code"]').fill(bogo);
    await fm.locator('[name="description"]').fill(`QA ${RUN} BOGO`);
    await fm.locator('select[name="scope"]').selectOption("vendor");
    await fm.locator('select[name="vendor_id"]').selectOption("14");
    await fm.locator('select[name="funded_by"]').selectOption("vendor");
    await fm.locator('select[name="type"]').selectOption("bogo");
    await fm.locator('[name="buy_quantity"]').fill("2");
    await fm.locator('[name="get_quantity"]').fill("1");
    const active = fm.locator('input[type=checkbox][name="is_active"]');
    if (await active.isChecked()) await active.uncheck(); // created switched off: other suites' carts are unaffected
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required|must/);
    await open(page, `/coupons?q=${bogo}`);
    await expect(main(page)).toContainText(bogo);
  });
  await f.step(`Open ${pct}, edit the usage limit to 50`, "Saved", async () => {
    await open(page, `/coupons?q=${pct}`);
    await main(page).locator("tr", { hasText: pct }).getByRole("link").first().click();
    await expect(page).toHaveURL(/coupons\/\d+/);
    const id = page.url().match(/coupons\/(\d+)/)![1];
    await open(page, `/coupons/${id}/edit`);
    const fm = form(page, `/coupons/${id}`);
    await fm.locator('[name="usage_limit"]').fill("50");
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required/);
    await open(page, `/coupons/${id}`);
    await expect(main(page)).toContainText(/50/);
  }, { fullPage: true });
  await f.step("Switch the coupon off, then on again", "Toggle saved each time", async () => {
    for (let i = 0; i < 2; i++) await submit(main(page).locator('form[action$="/toggle"]'));
    await expect(main(page).locator('form[action$="/toggle"]')).toContainText(/Switch off/);
  });
  await f.step("Filter coupons by type BOGO", "Only BOGO coupons", async () => {
    await open(page, "/coupons?type=bogo");
    await expect(main(page)).toContainText(bogo);
    await expect(main(page)).not.toContainText(pct);
  });
  await f.step("Coupon participation: invite vendors to a platform coupon (validation, then save)", "Invitation saved", async () => {
    await open(page, "/marketing/coupon-participation");
    const fm = form(page, "/marketing/coupon-participation");
    await submitNoValidate(fm);
    await expectValidation(page);
    const fm2 = form(page, "/marketing/coupon-participation");
    await fm2.locator('[name="title"]').fill(`QA join ${RUN}`);
    await fm2.locator('[name="min_fee"]').fill("10");
    await fm2.locator('[name="capacity"]').fill("5");
    await fm2.locator('[name="closes_at"]').fill(inOneHour(48));
    await submit(fm2);
    await expect(main(page)).toContainText(`QA join ${RUN}`);
  });
});

test("ADM-51 flash sales: create, transition, invite marketers", async ({ page }) => {
  const f = flow(page, R, "ADM-51", "Flash sales", "Admin creates a flash-sale event (validation, Oman-time hints), opens it, invites marketers and moves it through its states; existing live events are not touched.");
  await f.step("Open Flash sales; create empty", "Refused: name, start and end required; Oman time shown under each field", async () => {
    await open(page, "/flash-sales");
    await expect(main(page)).toContainText(/Oman time/);
    await submitNoValidate(form(page, "/flash-sales"));
    await expectValidation(page);
  });
  await f.step("Create with end before start", "Refused: end must be after start", async () => {
    const fm = form(page, "/flash-sales");
    await fm.locator('[name="name"]').fill(`QA Flash ${RUN}`);
    await fm.locator('[name="starts_at"]').fill(inOneHour(48));
    await fm.locator('[name="ends_at"]').fill(inOneHour(24));
    await submit(fm);
    await expectValidation(page, /after|before|must/i);
  });
  await f.step(`Create draft "QA Flash ${RUN}" (submissions now→+24h, sale +48h→+72h, max 2 per customer)`, "Draft event created", async () => {
    await open(page, "/flash-sales");
    const fm = form(page, "/flash-sales");
    await fm.locator('[name="name"]').fill(`QA Flash ${RUN}`);
    await fm.locator('[name="display_name"]').fill(`QA Flash ${RUN}`);
    await fm.locator('[name="submission_starts_at"]').fill(inOneHour(0));
    await fm.locator('[name="submission_ends_at"]').fill(inOneHour(24));
    await fm.locator('[name="starts_at"]').fill(inOneHour(48));
    await fm.locator('[name="ends_at"]').fill(inOneHour(72));
    await fm.locator('[name="max_qty_per_customer"]').fill("2");
    await submit(fm);
    await expect(main(page)).toContainText(`QA Flash ${RUN}`);
    await expect(main(page)).toContainText(/Draft/);
  });
  await f.step("Open the event", "Detail with status actions, submissions and marketer invites", async () => {
    if (!/flash-sales\/\d+/.test(page.url())) await main(page).locator("tr", { hasText: `QA Flash ${RUN}` }).getByRole("link").first().click();
    await expect(page).toHaveURL(/flash-sales\/\d+/);
    await expect(main(page)).toContainText(/Oman time/);
  }, { fullPage: true });
  await f.step("Invite all marketers", "Invitations sent", async () => {
    const fm = main(page).locator('form[action$="/marketer-invites"]');
    if (!(await fm.count())) return "invites not offered at this state";
    await fm.locator("select").selectOption("all");
    await submit(fm);
    await expect(main(page)).not.toContainText(/Server Error/);
  });
  await f.step("Move the event to its next state (e.g. open for submissions)", "State saved with reason", async () => {
    const fm = main(page).locator('form[action$="/status"]');
    const opts = await fm.locator('select[name="to"] option').evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value));
    const to = opts.find((v) => v !== "cancelled") ?? opts[0];
    await fm.locator('select[name="to"]').selectOption(to);
    await fm.locator('[name="reason"]').fill(`QA ${RUN}`);
    await submit(fm);
    return `options ${opts.join("/")}; moved to ${to}`;
  });
  await f.step("Cancel the event", "Event cancelled", async () => {
    const fm = main(page).locator('form[action$="/status"]');
    const opts = await fm.locator('select[name="to"] option').evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value));
    if (!opts.includes("cancelled")) return `cancel not offered (options ${opts.join("/")})`;
    await fm.locator('select[name="to"]').selectOption("cancelled");
    await fm.locator('[name="reason"]').fill(`QA ${RUN} test event`);
    await submit(fm);
    await expect(main(page)).toContainText(/Cancelled/);
  });
});

test("ADM-52 gift cards and vouchers", async ({ page }) => {
  const f = flow(page, R, "ADM-52", "Gift cards & vouchers", "Admin issues a gift card (validation first), opens and voids it; creates a voucher, opens and voids it.");
  await f.step("Issue gift card empty", "Refused: amount and recipient email required", async () => {
    await open(page, "/gift-cards");
    await submitNoValidate(form(page, "/gift-cards").filter({ has: page.locator('[name="amount"]') }));
    await expectValidation(page);
  });
  await f.step("Issue AED 50 gift card to a QA address", "Card issued, listed with code and balance", async () => {
    const fm = form(page, "/gift-cards").filter({ has: page.locator('[name="amount"]') });
    await fm.locator('[name="amount"]').fill("50");
    await fm.locator('[name="recipient_email"]').fill(`qa-gc-${RUN}@nawy.test`);
    await fm.locator('[name="recipient_name"]').fill("QA Recipient");
    await fm.locator('[name="message"]').fill("Happy testing");
    await submit(fm);
    await open(page, `/gift-cards?q=qa-gc-${RUN}`);
    await expect(main(page)).toContainText(`qa-gc-${RUN}`);
  });
  await f.step("Open the card and void it", "'Gift card voided.'; status Cancelled, balance AED 0.00, history row active → Cancelled with the reason; no Void form left", async () => {
    await main(page).locator('a[href*="/gift-cards/"]').filter({ hasNotText: /voucher/i }).first().click();
    // Wait for the card page before reading its form (the list page has no void form, so the reason was skipped).
    await expect(page).toHaveURL(/\/gift-cards\/\d+$/);
    const fm = form(page, "/void");
    await fm.locator("textarea, input:not([type=hidden])").first().fill(`QA ${RUN}`);
    await submit(fm);
    await expect(main(page)).toContainText("Gift card voided.");
    await expect(main(page)).toContainText(/Status\s*Cancelled/);
    await expect(main(page)).toContainText(/Balance\s*AED 0\.00/);
    await expect(main(page)).toContainText(`QA ${RUN}`);
    await expect(form(page, "/void")).toHaveCount(0);
  }, { fullPage: true });
  const code = `QAV${RUN}`.toUpperCase().slice(0, 14);
  await f.step("Vouchers: create empty, then a 20 AED voucher (10 redemptions, credit expiry 30 days)", "Validation, then voucher created", async () => {
    await open(page, "/gift-cards/vouchers");
    await submitNoValidate(form(page, "/gift-cards/vouchers").filter({ has: page.locator('[name="label"]') }));
    await expectValidation(page);
    const fm = form(page, "/gift-cards/vouchers").filter({ has: page.locator('[name="label"]') });
    await fm.locator('[name="code"]').fill(code);
    await fm.locator('[name="label"]').fill(`QA voucher ${RUN}`);
    await fm.locator('[name="value"]').fill("20");
    await fm.locator('[name="max_redemptions"]').fill("10");
    await fm.locator('[name="credit_expiry_days"]').fill("30");
    await fm.locator('[name="expires_at"]').fill(inOneHour(24 * 30));
    await submit(fm);
    await expect(main(page)).toContainText(`QA voucher ${RUN}`);
    await expect(main(page)).toContainText(/stored only as a hash/);
  });
  await f.step("Void the voucher with a reason", "Voucher voided (status Cancelled)", async () => {
    if (!/vouchers\/\d+/.test(page.url())) await main(page).locator("tr", { hasText: `QA voucher ${RUN}` }).getByRole("link").first().click();
    const fm = form(page, "/void");
    const r = fm.locator("textarea, input:not([type=hidden])");
    if (await r.count()) await r.first().fill(`QA ${RUN}`);
    await submit(fm);
    await expect(main(page)).toContainText(/Voucher voided|Cancelled/);
  });
});

test("ADM-53 Nawy One plans and members", async ({ page }) => {
  const f = flow(page, R, "ADM-53", "Nawy One (membership)", "Admin adds a membership plan (validation first), edits the existing plan without changes (round-trip of a stored price) and filters members.");
  await f.step("Open Nawy One", "Plans list (Nawy One AED 25.00 monthly)", async () => {
    await open(page, "/membership");
    await expect(main(page)).toContainText(/Nawy One/);
  });
  await f.step("Add plan empty", "Refused: code, names, price, trial days required", async () => {
    await submitNoValidate(form(page, "/membership/plans"));
    await expectValidation(page);
  });
  await f.step(`Add annual plan QA-ONE-${RUN} (AED 199, 7 trial days, free delivery, closed to new members)`, "Plan listed", async () => {
    const fm = form(page, "/membership/plans");
    await fm.locator('[name="code"]').fill(`qa-one-${RUN}`);
    await fm.locator('[name="name_en"]').fill(`QA One ${RUN}`);
    await fm.locator('[name="name_ar"]').fill(`ون ${RUN}`);
    await fm.locator('select[name="interval"]').selectOption({ index: 1 });
    await fm.locator('[name="price"]').fill("199");
    await fm.locator('[name="trial_days"]').fill("7");
    const open_ = fm.locator('input[type=checkbox][name="is_active"]');
    if (await open_.isChecked()) await open_.uncheck();
    await submit(fm);
    await expect(main(page)).toContainText(`QA One ${RUN}`);
  });
  await f.step("Edit the Nawy One plan and save without changes", "Saved (price shown as 25, not 25.0000)", async () => {
    await open(page, "/membership/plans/1/edit");
    const fm = main(page).locator('form[action$="/membership/plans/1"]');
    await expect(fm.locator('[name="price"]')).toHaveValue("25");
    await submit(fm);
    await expect(main(page)).not.toContainText(/must have|field is required/);
  });
  await f.step("Members: filter by status and plan", "Subscriptions list renders", async () => {
    await open(page, "/membership/subscriptions?status=active");
  });
});

test("ADM-54 marketers, campaigns, settings, celebrity reports, withdrawals", async ({ page }) => {
  const f = flow(page, R, "ADM-54", "Marketers & creators", "Admin filters marketers, suspends and reactivates one, creates a marketer campaign and invites influencers, saves marketer settings, regenerates and opens the celebrity monthly report PDF, sees cross-promotion and withdrawals.");
  await f.step("Marketers: filter Active and search 'Yasmin'", "Yasmin Style listed", async () => {
    await open(page, "/marketers?status=active&q=Yasmin");
    await expect(main(page)).toContainText("Yasmin Style");
  });
  await f.step("Suspend Yasmin Style with a reason, then reactivate", "Suspended, then Active", async () => {
    for (const to of ["suspended", "active"]) {
      await open(page, "/marketers?q=Yasmin");
      const fm = main(page).locator('form[action*="/marketers/"][action$="/status"]').first();
      await fm.locator('select[name="status"]').selectOption(to);
      await fm.locator('[name="reason"]').fill(`QA ${RUN} ${to}`);
      await submit(fm);
    }
    await open(page, "/marketers?q=Yasmin");
    await expect(main(page)).toContainText(/Active/);
  });
  await f.step("Campaigns: create empty, then a campaign open to affiliates with the influencer picker on 'None'", "Validation, then campaign created", async () => {
    await open(page, "/marketers/campaigns");
    await submitNoValidate(form(page, "/marketers/campaigns"));
    await expectValidation(page);
    const fm = form(page, "/marketers/campaigns");
    await fm.locator('[name="title"]').fill(`QA Campaign ${RUN}`);
    const listing = fm.locator('select[name="listing_id"]');
    const lo = await listing.locator("option").evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value).filter(Boolean));
    if (lo.length) await listing.selectOption(lo[0]);
    await fm.locator('[name="budget"]').fill("500");
    await fm.locator('[name="ends_at"]').fill(inOneHour(24 * 14));
    await fm.locator('input[type=checkbox][name="open_to_affiliates"]').check();
    await submit(fm); // influencer left on "None"
    await expect(main(page)).not.toContainText(/influencer_ids/);
    await expect(main(page)).toContainText(`QA Campaign ${RUN}`);
  });
  await f.step("Open the campaign and invite an influencer, then pause it", "Invitation listed; status Paused", async () => {
    if (!/campaigns\/\d+/.test(page.url())) await main(page).locator("tr", { hasText: `QA Campaign ${RUN}` }).getByRole("link").first().click();
    const inv = form(page, "/invite");
    if (await inv.count()) {
      await inv.locator("select").selectOption({ index: 0 });
      await submit(inv);
    }
    const st = main(page).locator('form[action$="/status"]').filter({ has: page.getByRole("button", { name: "Pause" }) });
    if (await st.count()) {
      const r = st.locator('[name="reason"]');
      if (await r.count()) await r.fill(`QA ${RUN}`);
      await submit(st, "Pause");
      await expect(main(page)).toContainText(/Paused/);
    }
  }, { fullPage: true });
  await f.step("Marketer settings: save general rules unchanged", "Saved", async () => {
    await open(page, "/marketers/settings");
    await submit(form(page, "/marketers/settings/general"));
    await expect(flash(page)).toBeVisible();
  });
  await f.step("Celebrity monthly report: regenerate 2026-10", "Report regenerated (or message: nothing to report)", async () => {
    await open(page, "/marketers/celebrity-reports");
    const fm = form(page, "/marketers/celebrity-reports/regenerate");
    await fm.locator('[name="period"]').fill("2026-10");
    await submit(fm);
    await expect(flash(page).or(page.locator('main [role="alert"]'))).toBeVisible();
    const href = await main(page).locator('a[href*="/celebrity-reports/"]').first().getAttribute("href").catch(() => null);
    if (!href) return `${await flash(page).innerText().catch(() => "")} — no influencer earned in 2026-10`;
    await open(page, href);
    const pdf = await fetchFile(page, `${href}/pdf`);
    expect(pdf.head.slice(0, 4)).toBe("%PDF");
    return `report + PDF ${pdf.size} bytes`;
  }, { fullPage: true });
  await f.step("Cross-promotion policies and marketer withdrawals", "Both pages render", async () => {
    await open(page, "/marketers/cross-promotion");
    await open(page, "/marketers/withdrawals");
  });
});

test("ADM-55 ads review, travel promotions, advertise requests, real estate", async ({ page }) => {
  const f = flow(page, R, "ADM-55", "Ads review, travel promotions, advertise requests, real estate", "Admin reviews the ads queues, configures travel-promotion slot and options (validation first), moves an advertise request, and checks the exclusive-contract form validation.");
  await f.step("Ads review", "Sponsored campaigns, slot bookings and bank-transfer packages queues", async () => {
    await open(page, "/marketing");
    await expect(main(page)).toContainText(/Sponsored product campaigns/);
  });
  await f.step("Approve the first waiting ad item (if any)", "Decision saved", async () => {
    const fm = main(page).locator('form[action*="/marketing/campaigns/"], form[action*="/marketing/bookings/"], form[action*="/marketing/subscriptions/"]').first();
    if (!(await fm.count())) return "nothing waiting in dev data";
    await submit(fm);
  });
  await f.step("Travel promotions: add option empty → errors; add 7-day option AED 99", "Option listed", async () => {
    await open(page, "/marketing/travel-promotions");
    await submitNoValidate(form(page, "/marketing/travel-promotions/products"));
    await expectValidation(page);
    const fm = form(page, "/marketing/travel-promotions/products");
    await fm.locator('[name="name_en"]').fill(`QA 7 days ${RUN}`);
    await fm.locator('[name="duration_days"]').fill("7");
    await fm.locator('[name="price"]').fill("99");
    await submit(fm);
    await expect(main(page).locator(`input[value="QA 7 days ${RUN}"]`)).toHaveCount(1);
  });
  await f.step("Travel promotions: save the slot settings unchanged", "Saved", async () => {
    await submit(form(page, "/marketing/travel-promotions/slot"));
    await expect(flash(page)).toBeVisible();
  });
  await f.step("Advertise requests: move the open request to its next status (Contacted / Converted)", "Status saved", async () => {
    await open(page, "/advertise-requests");
    const fm = main(page).locator('form[action*="/advertise-requests/"]').first();
    if (!(await fm.count())) return "no open request";
    const note = fm.locator('[name="note"]');
    if (await note.count()) await note.fill(`QA ${RUN} called`);
    const label = (await fm.getByRole("button").allInnerTexts()).map((t) => t.trim()).find((t) => /Contacted|Converted/.test(t)) ?? "Closed";
    await submit(fm, label);
    await expect(main(page)).not.toContainText(/Server Error/);
    return `moved with "${label}"`;
  });
  await f.step("Real estate: grant exclusivity with no marketer/ad", "Refused by validation", async () => {
    await open(page, "/real-estate");
    await submitNoValidate(form(page, "/real-estate/exclusive-contracts"));
    await expectValidation(page);
  });
});

test("ADM-56 pages and page builder, app contexts, live & videos", async ({ page }) => {
  const f = flow(page, R, "ADM-56", "Pages & page builder", "Admin creates a campaign page draft, saves a revision in the builder, schedules and archives it; saves an app context; creates a live broadcast.");
  const slug = `qa-page-${RUN}`;
  await f.step("Pages: create draft empty", "Refused: slug and title required", async () => {
    await open(page, "/pages");
    await submitNoValidate(form(page, "/pages"));
    await expectValidation(page);
  });
  await f.step(`Create campaign page draft /${slug}`, "Builder opens for the new page", async () => {
    const fm = form(page, "/pages");
    await fm.locator('select[name="template"]').selectOption("campaign");
    await fm.locator('[name="slug"]').fill(slug);
    await fm.locator('[name="title"]').fill(`QA Page ${RUN}`);
    await submit(fm);
    if (!/pages\/\d+/.test(page.url())) await main(page).locator("tr", { hasText: slug }).getByRole("link").first().click();
    await expect(page).toHaveURL(/pages\/\d+/);
  }, { fullPage: true });
  await f.step("Builder: set SEO title and save a draft revision", "Revision saved", async () => {
    const fm = main(page).locator('form[action$="/save"]');
    await fm.locator('[name="seo_title"]').fill(`QA SEO ${RUN}`);
    await submit(fm);
    await expect(main(page)).not.toContainText(/Server Error|field is required/);
  });
  await f.step("Schedule publishing in 2 days, then archive", "Scheduled (with Oman time), then Archived when offered", async () => {
    const sch = main(page).locator('form[action$="/schedule"]');
    if (await sch.count()) {
      await sch.locator('[name="publish_at"]').fill(inOneHour(48));
      await submit(sch);
    }
    await expect(main(page)).toContainText(/Scheduled/);
    const arc = main(page).locator('form[action$="/archive"]');
    if (!(await arc.count())) return "Scheduled; archive is offered only for drafts/published pages";
    await submit(arc);
    await expect(main(page)).toContainText(/Archived|archived/);
  });
  await f.step("App contexts: save the first context unchanged", "Saved", async () => {
    await open(page, "/app-contexts");
    await submit(main(page).locator('form[action*="/app-contexts/"]').first());
    await expect(flash(page)).toBeVisible();
  });
  await f.step("Live & videos: create broadcast empty, then a scheduled audio+video broadcast", "Validation, then broadcast listed", async () => {
    await open(page, "/media");
    await submitNoValidate(form(page, "/media").filter({ has: page.locator('[name="title_en"]') }).first());
    await expectValidation(page);
    const fm = form(page, "/media").filter({ has: page.locator('[name="title_en"]') }).first();
    await fm.locator('[name="title_en"]').fill(`QA Live ${RUN}`);
    await fm.locator('[name="title_ar"]').fill(`بث ${RUN}`);
    await fm.locator('[name="scheduled_at"]').fill(inOneHour(72));
    await submit(fm);
    await open(page, "/media");
    await expect(main(page)).toContainText(`QA Live ${RUN}`);
  });
});

test("ADM-57 blog, help centre, static pages, footer, notification templates", async ({ page }) => {
  const f = flow(page, R, "ADM-57", "Blog, help centre, static pages, footer, templates", "Admin adds a blog category and a draft post (validation first) and archives it; adds a help collection and article; saves a static page, the footer and a notification template.");
  await f.step("Blog: add post empty", "Refused: titles required", async () => {
    await open(page, "/cms");
    await submitNoValidate(form(page, "/cms/blog/posts"));
    await expectValidation(page);
  });
  await f.step("Add blog category and a draft post in it", "Draft post listed", async () => {
    const cat = form(page, "/cms/blog/categories");
    await cat.locator('[name="name_en"]').fill(`QA Cat ${RUN}`);
    await cat.locator('[name="name_ar"]').fill(`تصنيف ${RUN}`);
    await submit(cat);
    const fm = form(page, "/cms/blog/posts");
    await fm.locator('[name="title_en"]').fill(`QA Post ${RUN}`);
    await fm.locator('[name="title_ar"]').fill(`مقال ${RUN}`);
    await fm.locator('[name="body_en"]').fill("Body text for the QA post.");
    await fm.locator('[name="body_ar"]').fill("نص المقال التجريبي.");
    const opts = await fm.locator('select[name="category_id"] option').evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent ?? "" })));
    const c = opts.find((o) => o.t.includes(`QA Cat ${RUN}`));
    if (c) await fm.locator('select[name="category_id"]').selectOption(c.v);
    await fm.locator('[name="tags"]').fill("qa, test");
    await submit(fm);
    await expect(main(page)).toContainText(`QA Post ${RUN}`);
  });
  await f.step("Archive the QA post", "Post archived", async () => {
    const row = main(page).locator("tr, li, div.border", { hasText: `QA Post ${RUN}` }).last();
    const arc = row.locator('form[action$="/archive"]');
    if (!(await arc.count())) return "archive action not shown for drafts";
    await submit(arc);
  });
  await f.step("Help centre: add collection and article", "Article listed under the collection", async () => {
    await open(page, "/cms/help");
    const col = form(page, "/cms/help/collections");
    await col.locator('[name="title_en"]').fill(`QA Help ${RUN}`);
    await col.locator('[name="title_ar"]').fill(`مساعدة ${RUN}`);
    await submit(col);
    const art = form(page, "/cms/help/articles");
    const v = await page.locator('main select[name="collection_id"] option', { hasText: `QA Help ${RUN}` }).first().getAttribute("value");
    await page.locator('main select[name="collection_id"]').selectOption(v!);
    await art.locator('[name="title_en"]').fill(`QA Article ${RUN}`);
    await art.locator('[name="title_ar"]').fill(`مقالة ${RUN}`);
    await art.locator('[name="body_en"]').fill("How to test.");
    await art.locator('[name="body_ar"]').fill("كيفية الاختبار.");
    await submit(art);
    await expect(main(page)).toContainText(`QA Article ${RUN}`);
  }, { fullPage: true });
  await f.step("Static pages: save the first page unchanged", "Saved", async () => {
    await open(page, "/cms/static-pages");
    await submit(form(page, "/cms/static-pages").first());
    await expect(flash(page)).toBeVisible();
  });
  await f.step("Footer: save unchanged", "Saved", async () => {
    await open(page, "/cms/footer");
    await submit(form(page, "/cms/footer"));
    await expect(flash(page)).toBeVisible();
  });
  await f.step("Notification templates: open order.placed / mail / en and save", "Template saved", async () => {
    await open(page, "/notification-templates?event=order.placed&channel=mail&locale=en");
    const fm = form(page, "/notification-templates");
    const subject = fm.locator('[name="subject"]');
    if (!(await subject.inputValue())) await subject.fill("Your order {order_number} is placed");
    const body = fm.locator('[name="body"]');
    if (!(await body.inputValue())) await body.fill("Thank you for your order {order_number}.");
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required/);
  });
});

test("ADM-58 Open Souq admin: classified review, reports, categories; travel packages", async ({ page }) => {
  const f = flow(page, R, "ADM-58", "Classifieds (Open Souq) and travel review", "Admin approves one pending classified ad and rejects another (reason required), checks abuse reports, changes a category's photo limits and contract requirement (and restores them), and opens the travel review queue.", "Uses only the two older pending QA ads (CL-AE-0000005/7; E2E ads of the Open Souq suite are left alone) and the parent category 'Mobiles & electronics'; settings are restored at the end.");
  await f.step("Classifieds review queue", "Pending ads with media and details, never public before approval", async () => {
    await open(page, "/classifieds");
    await expect(main(page)).toContainText(/never public before approval/);
  });
  const row = (n: string) => main(page).locator("tr", { hasText: n });
  await f.step("Reject CL-AE-0000005 with an empty reason (browser check bypassed)", "Refused; the reason error is shown", async () => {
    const fm = row("CL-AE-0000005").locator('form[action$="/reject"]');
    if (!(await fm.count())) return "already decided in an earlier run";
    await submitNoValidate(fm);
    await expect(page.locator('main [role="alert"]')).toContainText(/reason/i);
  });
  await f.step("Reject CL-AE-0000005 with a reason", "Ad leaves the queue", async () => {
    const fm = row("CL-AE-0000005").locator('form[action$="/reject"]');
    if (!(await fm.count())) return "already decided in an earlier run";
    const action = await fm.getAttribute("action");
    await fm.locator('[name="reason"]').fill(`QA ${RUN}: photos do not match`);
    await submit(fm);
    await expect(main(page).locator(`form[action="${action}"]`)).toHaveCount(0);
  });
  await f.step("Approve CL-AE-0000007", "Ad leaves the queue (now public)", async () => {
    const fm = row("CL-AE-0000007").locator('form[action$="/approve"]');
    if (!(await fm.count())) return "already decided in an earlier run";
    const action = await fm.getAttribute("action");
    await submit(fm);
    await expect(main(page).locator(`form[action="${action}"]`)).toHaveCount(0);
  });
  await f.step("Abuse reports", "Open reports list", async () => {
    await open(page, "/classifieds/reports");
  });
  await f.step("Classified categories: set Mobiles & electronics to 12 photos / 4096 KB, then back to 20 / 8192", "Limits saved and restored", async () => {
    await open(page, "/classifieds/categories");
    for (const [n, kb] of [["12", "4096"], ["20", "8192"]]) {
      const fm = main(page).locator("tr", { hasText: "electronics-souq" }).locator('form[action$="/limits"]');
      await fm.locator('[name="max_photos"]').fill(n);
      await fm.locator('[name="photo_max_kb"]').fill(kb);
      await submit(fm);
      await expect(main(page).locator("tr", { hasText: "electronics-souq" })).toContainText(`${n} photos`);
    }
  }, { fullPage: true });
  await f.step("Photo limits out of range (0 photos)", "Refused by validation", async () => {
    const fm = main(page).locator("tr", { hasText: "electronics-souq" }).locator('form[action$="/limits"]');
    await fm.locator('[name="max_photos"]').fill("0");
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step("Require a contract for Mobiles & electronics, then remove the requirement", "Contract required = Yes, then No", async () => {
    await open(page, "/classifieds/categories");
    const row = () => main(page).locator("tr", { hasText: "electronics-souq" });
    await submit(row().locator('form[action$="/contract"]'));
    await expect(row()).toContainText(/Yes/);
    await submit(row().locator('form[action$="/contract"]'));
    await expect(row()).toContainText(/No/);
  });
  await f.step("Travel packages review queue", "Queue renders (approve/reject when a package is waiting)", async () => {
    await open(page, "/travel");
    const n = await main(page).locator('form[action$="/approve"]').count();
    return n ? `${n} waiting (not decided: reserved for the travel suite)` : "nothing waiting for review";
  });
});
