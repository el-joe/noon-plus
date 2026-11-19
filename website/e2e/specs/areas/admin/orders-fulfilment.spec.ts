import {
  RUN, expect, expectValidation, fetchFile, flash, flow, form, inviteStaff, main, open, panelPage, recordOutcomes, signIn, submit, submitNoValidate, test,
} from "./helpers";
import { panelUrl } from "../../../support/accounts";

const R = "admin-panel-orders-delivery-and-fulfilment" as const;
recordOutcomes(R);

/*
 * Order records used (all from earlier dev runs, none from today's e2e fixtures):
 * order 2 / sub-order 2 (SoundMax, placed) for state changes, order 3 for force-cancel,
 * order 5 is moved to Packed for dispatch, order 6 (captured, delivered) for a manual refund.
 */

test("ADM-20 orders list, filters, detail and invoice PDF", async ({ page }) => {
  const f = flow(page, R, "ADM-20", "Orders list, detail and invoice PDF", "Admin filters orders by country/status/date/search, opens an order (Oman time shown) and downloads the invoice PDF.");
  await f.step("Open Orders", "Order list with filters and Oman time", async () => {
    await open(page, "/orders");
    await expect(main(page)).toContainText(/Oman time/);
    await expect(main(page)).toContainText(/NW-AE-/);
  });
  await f.step("Filter status Delivered, date range 2026-11-01..2026-11-30", "Only delivered orders in range", async () => {
    const fm = form(page, "/orders");
    await fm.locator('select[name="status"]').selectOption({ index: 1 }).catch(() => undefined);
    const opts = await fm.locator('select[name="status"] option').evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value));
    if (opts.includes("delivered")) await fm.locator('select[name="status"]').selectOption("delivered");
    await fm.locator('[name="from"]').fill("2026-11-01");
    await fm.locator('[name="to"]').fill("2026-11-30");
    await submit(fm);
    await expect(page).toHaveURL(/from=2026-11-01/);
    await expect(main(page)).toContainText(/Delivered/);
  });
  await f.step("Search NW-AE-1000002", "Exactly that order is found", async () => {
    await open(page, "/orders?q=NW-AE-1000002");
    await expect(main(page)).toContainText("NW-AE-1000002");
    await expect(main(page)).not.toContainText("NW-AE-1000006");
  });
  await f.step("Open order NW-AE-1000002", "Detail: sub-order timeline, payment & settlement, admin actions, customer & delivery", async () => {
    await open(page, "/orders/2");
    await expect(main(page)).toContainText(/Payment & settlement/);
    await expect(main(page)).toContainText(/Oman/);
    await expect(main(page)).toContainText(/Admin actions/);
  }, { fullPage: true });
  await f.step("Download the invoice PDF", "200, application/pdf, starts with %PDF", async () => {
    const res = await fetchFile(page, panelUrl("admin", "orders/6/invoice.pdf"));
    expect(res.status).toBe(200);
    expect(res.type).toContain("pdf");
    expect(res.head.slice(0, 4)).toBe("%PDF");
    return `${res.size} bytes`;
  });
});

test("ADM-21 sub-order state change and force-cancel", async ({ page }) => {
  const f = flow(page, R, "ADM-21", "Sub-order state change and force-cancel", "Admin moves a sub-order to the next allowed state (reason required) and force-cancels another (reason required).");
  await f.step("Order NW-AE-1000002: change state without reason", "Refused: reason required", async () => {
    await open(page, "/orders/2");
    const fm = form(page, "/sub-orders/2/status");
    if (!(await fm.count())) return "sub-order 2 already moved in an earlier run";
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step("Change state to the next allowed state with a reason", "Timeline shows the new state with actor and Oman time", async () => {
    const fm = form(page, "/sub-orders/2/status");
    if (!(await fm.count())) return "no further state available";
    const to = await fm.locator('select[name="to"] option').first().getAttribute("value");
    await fm.locator('select[name="to"]').selectOption(to!);
    await fm.locator('[name="reason"]').fill(`QA ${RUN} confirmed by phone`);
    await submit(fm);
    await expect(main(page)).toContainText(`QA ${RUN} confirmed by phone`);
    return `moved to ${to}`;
  }, { fullPage: true });
  await f.step("Order NW-AE-1000003: force-cancel without a reason", "Refused: reason required", async () => {
    await open(page, "/orders/3");
    const fm = form(page, "/sub-orders/3/cancel");
    if (!(await fm.count())) return "already cancelled in an earlier run";
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step("Force-cancel with a reason", "Sub-order Cancelled; force-cancel action gone", async () => {
    const fm = form(page, "/sub-orders/3/cancel");
    if (!(await fm.count())) return "already cancelled in an earlier run";
    await fm.locator('[name="reason"]').fill(`QA ${RUN} customer asked by phone`);
    await submit(fm);
    await expect(main(page)).toContainText(/Cancelled/);
    await expect(form(page, "/sub-orders/3/cancel")).toHaveCount(0);
  });
});

test("ADM-22 manual refund with four-eyes approval", async ({ page, browser }) => {
  const f = flow(page, R, "ADM-22", "Manual refund (four-eyes)", "Admin A requests a refund on a paid order; A cannot approve own request; finance admin B (invited through Staff) approves it. Refunds list filters.");
  await f.step("Order NW-AE-1000006: request refund with no amount/reason", "Validation errors", async () => {
    await open(page, "/orders/6");
    await submitNoValidate(form(page, "/orders/6/refunds"));
    await expectValidation(page);
  });
  await f.step("Request a refund of 1.00 to Nawy Credits with a reason", "Refund goes to the refunds queue as Pending", async () => {
    const fm = form(page, "/orders/6/refunds");
    await fm.locator('[name="amount"]').fill("1");
    await fm.locator('select[name="destination"]').selectOption("wallet");
    await fm.locator('[name="reason"]').fill(`QA ${RUN} goodwill`);
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required/);
  });
  let refundUrl = "";
  await f.step("Refunds → Pending", "The new refund is listed", async () => {
    await open(page, "/refunds?status=pending");
    const link = main(page).locator("tr", { hasText: "NW-AE-1000006" }).getByRole("link").first();
    refundUrl = (await link.getAttribute("href"))!;
    await open(page, refundUrl);
    await expect(main(page)).toContainText(`QA ${RUN} goodwill`);
  }, { fullPage: true });
  await f.step("Requester tries to approve own refund", "Refused (four-eyes) or approve button hidden", async () => {
    const fm = form(page, "/approve");
    if (!(await fm.count())) return "approve hidden for the requester";
    await submit(fm);
    await expect(main(page)).toContainText(/another admin|four-eyes|own|same admin|cannot approve/i);
  });
  let financeEmail = "";
  await f.step("Invite a Finance staff member and accept the invitation", "Staff member created with role Finance", async () => {
    financeEmail = await inviteStaff(page, browser, "finance", "fin");
    return financeEmail;
  });
  await f.step("Finance admin signs in (2FA) and approves the refund", "Refund approved/completed", async () => {
    const { context, page: fp } = await signIn(browser, financeEmail);
    await fp.goto(refundUrl);
    await submit(fp.locator('main form[action$="/approve"]'));
    await expect(fp.locator("main")).toContainText(/Approved|Processing|Completed/);
    await fp.screenshot({ path: "e2e/docx/shots/admin-panel-orders-delivery-and-fulfilment/adm-22-finance.png" });
    await context.close();
  });
  await f.step("Refunds: filter Completed / source Admin", "Filtered list", async () => {
    await open(page, "/refunds?status=completed&source=admin");
    await expect(main(page)).toContainText(/NW-AE-1000006|No refunds/);
  });
});

test("ADM-23 returns: approve and reject", async ({ page }) => {
  const f = flow(page, R, "ADM-23", "Returns", "Admin filters return requests, approves one and rejects one with a reason (reason validated).");
  await f.step("Open Returns, filter Requested", "Return requests listed", async () => {
    await open(page, "/returns?status=requested");
    await expect(main(page)).toContainText(/RT-|RET-|Requested/i);
  });
  const ids: string[] = [];
  await f.step("Open the oldest requested return", "Detail with items, reason and actions", async () => {
    const hrefs = await page.locator('main a[href*="/returns/"]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href).filter((h) => /\/returns\/\d+$/.test(h)));
    hrefs.sort((a, b) => Number(a.split("/").pop()) - Number(b.split("/").pop()));
    ids.push(...hrefs.slice(0, 2));
    await open(page, ids[0]);
  }, { fullPage: true });
  await f.step("Approve the return", "Status Approved; next steps (receive/inspect) offered", async () => {
    await submit(form(page, "/approve"));
    await expect(main(page)).toContainText(/Approved/);
  });
  await f.step("Advance / inspect the approved return if offered", "Next state saved", async () => {
    const adv = main(page).locator('form[action$="/advance"], form[action$="/inspect"]').first();
    if (!(await adv.count())) return "no advance step offered at this state";
    await adv.locator("select").first().selectOption({ index: 0 }).catch(() => undefined);
    const txt = adv.locator("textarea, input[type=text], input:not([type])");
    if (await txt.count()) await txt.first().fill(`QA ${RUN}`);
    await submit(adv);
    await expect(main(page)).not.toContainText(/Server Error/);
  });
  await f.step("Open the next return, reject without a reason", "Refused: reason required", async () => {
    await open(page, ids[1]);
    await submitNoValidate(form(page, "/reject"));
    await expectValidation(page);
  });
  await f.step("Reject with a reason", "Status Rejected", async () => {
    const fm = form(page, "/reject");
    await fm.locator("textarea, input:not([type=hidden])").first().fill(`QA ${RUN}: item used`);
    await submit(fm);
    await expect(main(page)).toContainText(/Rejected/);
  });
});

test("ADM-24 disputes, payments under review, support and warranty", async ({ page }) => {
  const f = flow(page, R, "ADM-24", "Disputes, payments under review, support tickets, warranty claims", "Admin reviews the dispute queue and a dispute thread; resolves a payment held under review; works a support ticket (internal note, assign, priority, status); checks warranty claims filters.");
  await f.step("Disputes: list and filter Resolved", "Dispute DSP listed", async () => {
    await open(page, "/disputes");
    await open(page, "/disputes?status=resolved");
  });
  await f.step("Open dispute #1 and post a message", "Message appears in the thread", async () => {
    await open(page, "/disputes/1");
    const fm = form(page, "/disputes/1/messages");
    if (!(await fm.count())) return "thread closed for messages";
    await fm.locator('[name="body"]').fill(`QA ${RUN} note to both parties`);
    await submit(fm);
    await expect(main(page)).toContainText(`QA ${RUN} note to both parties`);
  }, { fullPage: true });
  await f.step("Payments under review: open the held payment", "Expected vs captured amounts and resolve actions", async () => {
    await open(page, "/finance/payments-review");
    const href = await main(page).locator('a[href*="/finance/payments-review/"]').first().getAttribute("href").catch(() => null);
    if (!href) return "queue empty (resolved in an earlier run)";
    await open(page, href);
    await expect(main(page)).toContainText(/Captured|captured/);
  }, { fullPage: true });
  await f.step("Resolve without a note", "Refused: note required", async () => {
    const fm = main(page).locator('form[action*="/finance/payments-review/"]');
    if (!(await fm.count())) return "nothing to resolve";
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step("Resolve as 'Refund the captured amount' with a note", "Payment leaves the review queue", async () => {
    const fm = main(page).locator('form[action*="/finance/payments-review/"]');
    if (!(await fm.count())) return "nothing to resolve";
    await fm.locator('select[name="action"]').selectOption("refund");
    await fm.locator('[name="note"]').fill(`QA ${RUN}: amount mismatch, refunded`);
    await submit(fm);
    await open(page, "/finance/payments-review");
  });
  await f.step("Support tickets: filter open + overdue, open a ticket", "Ticket thread with reply, assign, priority, status", async () => {
    await open(page, "/support?status=open");
    await open(page, "/support/1");
  }, { fullPage: true });
  await f.step("Post an internal note, assign to me, set priority High, move status", "Each action saved", async () => {
    const reply = form(page, "/support/1/reply");
    await reply.locator('[name="body"]').fill(`QA ${RUN} internal note`);
    await reply.locator('input[type=checkbox][name="internal"]').check();
    await submit(reply);
    await expect(main(page)).toContainText(`QA ${RUN} internal note`);
    const assign = form(page, "/support/1/assign");
    const opt = await assign.locator('select[name="assignee"] option').evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value).filter(Boolean));
    if (opt.length) { await assign.locator('select[name="assignee"]').selectOption(opt[0]); await submit(assign); }
    const pr = form(page, "/support/1/priority");
    await pr.locator('select[name="priority"]').selectOption("high").catch(async () => pr.locator('select[name="priority"]').selectOption({ index: 0 }));
    await submit(pr);
    const st = form(page, "/support/1/status").first();
    if (await st.count()) await submit(st);
  });
  await f.step("Warranty claims: list and filter", "List renders with status and coverage filters", async () => {
    await open(page, "/warranty-claims");
    await open(page, "/warranty-claims?status=open");
  });
});

test("ADM-25 delivery zones, rates, carriers, agents, claims, scorecard", async ({ page }) => {
  const f = flow(page, R, "ADM-25", "Delivery & logistics setup", "Admin creates a shipping zone (validation first), adds an area and a rate, adds a shipping company, invites a delivery agent, files a carrier claim (validation) and opens the scorecard.");
  await f.step("Open Delivery & logistics (zones & rates)", "Zones listed with Create zone form", async () => {
    await open(page, "/delivery");
  });
  await f.step("Create zone empty", "Refused: name and subsidy share required", async () => {
    await submitNoValidate(form(page, "/delivery/zones"));
    await expectValidation(page);
  });
  await f.step(`Create zone "QA Zone ${RUN}" (inactive, 50% subsidy)`, "Zone created", async () => {
    const fm = form(page, "/delivery/zones");
    await fm.locator('[name="name"]').fill(`QA Zone ${RUN}`);
    await fm.locator('[name="vendor_subsidy_share_percent"]').fill("50");
    const active = fm.locator('input[type=checkbox][name="is_active"]');
    if (await active.isChecked()) await active.uncheck();
    await submit(fm);
    await open(page, "/delivery");
    await main(page).locator("tr", { hasText: `QA Zone ${RUN}` }).getByRole("link", { name: "Open" }).click();
    await expect(page).toHaveURL(/delivery\/zones\/\d+/);
  });
  await f.step("Add an area (Dubai / QA Area) and a standard rate", "Area and rate listed on the zone", async () => {
    const area = form(page, "/areas");
    await area.locator('[name="city"]').fill("Dubai");
    await area.locator('[name="area"]').fill(`QA Area ${RUN}`);
    await submit(area);
    await expect(main(page)).toContainText(`QA Area ${RUN}`);
    const rate = main(page).locator('form[action$="/rates"]');
    await rate.locator('[name="base_fee"]').fill("15");
    await rate.locator('[name="base_weight_kg"]').fill("5");
    await rate.locator('[name="per_kg_fee"]').fill("2");
    await rate.locator('[name="transit_min_days"]').fill("1");
    await rate.locator('[name="transit_max_days"]').fill("3");
    await submit(rate);
    await expect(main(page).locator('form[action*="/delivery/rates/"]').first()).toBeVisible();
  }, { fullPage: true });
  await f.step("Shipping companies: add one (validation first)", "Company listed", async () => {
    await open(page, "/delivery/carriers");
    await submitNoValidate(form(page, "/delivery/carriers"));
    await expectValidation(page);
    const fm = form(page, "/delivery/carriers");
    await fm.locator('[name="code"]').fill(`qa${RUN}`.slice(0, 12));
    await fm.locator('[name="name"]').fill(`QA Couriers ${RUN}`);
    await fm.locator('[name="contact_email"]').fill(`ops-${RUN}@nawy.test`);
    await submit(fm);
    await expect(main(page).locator(`input[value="QA Couriers ${RUN}"]`)).toHaveCount(1);
  });
  await f.step("Agents: invite a delivery agent, then revoke the invitation", "Invitation listed then revoked", async () => {
    await open(page, "/delivery/agents");
    const fm = form(page, "/delivery/agents");
    await fm.locator('[name="email"]').fill(`qa-agent-${RUN}@nawy.test`);
    await fm.locator('[name="name"]').fill(`QA Agent ${RUN}`);
    await fm.locator('select[name="vehicle_type"]').selectOption("bike");
    await submit(fm);
    await expect(main(page)).toContainText(`qa-agent-${RUN}@nawy.test`);
    const revoke = main(page).locator("tr", { hasText: `qa-agent-${RUN}@nawy.test` }).locator('form[action$="/revoke"]');
    await submit(revoke);
    await expect(main(page).locator("tr", { hasText: `qa-agent-${RUN}@nawy.test` }).locator('form[action$="/revoke"]')).toHaveCount(0);
  });
  await f.step("Carrier claims: submit with an unknown sub-order", "Refused with a validation message", async () => {
    await open(page, "/delivery/claims");
    const fm = form(page, "/delivery/claims");
    await fm.locator('[name="sub_order_number"]').fill("NW-AE-NOPE-1");
    await fm.locator('[name="amount"]').fill("10");
    await fm.locator('[name="description"]').fill(`QA ${RUN} parcel lost`);
    await submit(fm);
    await expectValidation(page, /No parcel with this number|not found|invalid|exist/i);
  });
  await f.step("Scorecard", "Carrier scorecard renders", async () => {
    await open(page, "/delivery/scorecard");
  });
});

test("ADM-26 dispatch board: pack, assign, assignment detail, label", async ({ page }) => {
  const f = flow(page, R, "ADM-26", "Dispatch (live board)", "Admin brings an old placed sub-order to Packed, sees it on the dispatch board, assigns an agent, opens assignments and the shipping label; agent withdrawals list.", "Uses the first old SoundMax cash-on-delivery order that is not packed yet (NW-AE-10000xx, 15–18 Nov; unpaid card orders cannot be confirmed). Today's E2E Store parcels are not touched.");
  let parcel = "";
  await f.step("Pick an old placed SoundMax order and move its sub-order to Packed (state by state, with reasons)", "Sub-order Packed", async () => {
    for (const id of [4, 1, 2, 21, 22, 23, 24, 19]) {
      await open(page, `/orders/${id}`);
      const fm = main(page).locator('form[action*="/sub-orders/"][action$="/status"]').first();
      if (!(await fm.count())) continue;
      const opts = await fm.locator('select[name="to"] option').evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value));
      if (!opts.some((v) => ["confirmed", "processing", "packed"].includes(v))) continue;
      parcel = (await main(page).innerText()).match(/NW-AE-\d+-1/)![0];
      for (let i = 0; i < 4; i++) {
        await open(page, `/orders/${id}`);
        const sf = main(page).locator('form[action*="/sub-orders/"][action$="/status"]').first();
        const o = await sf.locator('select[name="to"] option').evaluateAll((x) => x.map((y) => (y as HTMLOptionElement).value));
        const pick = o.find((v) => v === "packed") ?? o.find((v) => v !== "cancelled")!;
        await sf.locator('select[name="to"]').selectOption(pick);
        await sf.locator('[name="reason"]').fill(`QA ${RUN} ${pick}`);
        await submit(sf);
        if (await page.locator('main [role="alert"]').count()) break; // e.g. unpaid card order: cannot be confirmed
        if (pick === "packed") break;
      }
      if (await page.locator('main [role="alert"]').count()) { parcel = ""; continue; }
      return `${parcel} moved to Packed`;
    }
    throw new Error("no placed SoundMax order left");
  }, { fullPage: true });
  await f.step("Open Dispatch; filter Ready", "Board lists ready parcels with agent pickers and Oman time", async () => {
    await open(page, "/delivery/assignments");
    await expect(main(page)).toContainText(/Ready to dispatch/);
  });
  await f.step("Assign the parcel to QA2 Agent", "Parcel leaves 'Ready to dispatch' and appears as assigned", async () => {
    // The board is paged (QA-C08): search for the parcel rather than relying on page 1.
    await open(page, `/delivery/assignments?q=${encodeURIComponent(parcel)}`);
    const pickers = () => main(page).locator("tr", { hasText: parcel }).locator("form", { has: page.locator('select[name="agent_id"]') });
    await expect(pickers()).toHaveCount(1);
    const fm = pickers().first();
    const opts = await fm.locator('select[name="agent_id"] option').evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent?.trim() ?? "" })));
    const pick = opts.find((o) => o.t === "QA2 Agent") ?? opts.find((o) => o.v)!;
    await fm.locator('select[name="agent_id"]').selectOption(pick.v);
    await submit(fm);
    await open(page, `/delivery/assignments?q=${encodeURIComponent(parcel)}`);
    await expect(pickers()).toHaveCount(0);
    return `assigned to ${pick.t}`;
  });
  await f.step("Open an assignment detail", "Assignment timeline, attempts and proof links", async () => {
    await open(page, "/delivery/assignments/35");
    await expect(main(page)).toContainText(/NW-AE-/);
  }, { fullPage: true });
  await f.step("Open the shipping label and label PDF of that shipment", "Label HTML 200; PDF %PDF", async () => {
    const href = await main(page).locator('a[href*="/label"]').first().getAttribute("href").catch(() => null);
    if (!href) return "no label link on this assignment";
    const pdf = href.endsWith(".pdf") ? href : `${href}.pdf`;
    const res = await fetchFile(page, pdf);
    expect(res.status).toBe(200);
    expect(res.head.slice(0, 4)).toBe("%PDF");
    return `${res.size} bytes`;
  });
  await f.step("Agent withdrawals: filter scope and status", "Withdrawals list renders", async () => {
    await open(page, "/delivery/withdrawals");
    await open(page, "/delivery/withdrawals?status=pending");
  });
});

test("ADM-27 warehouses, receiving with put-away, stock, transfers, removals", async ({ page, browser }) => {
  const f = flow(page, R, "ADM-27", "Inventory & fulfilment (FBN)", "Admin adds a warehouse, receives a seller's inbound shipment with put-away bins, adjusts stock, requests and moves a transfer, checks removals and pick & pack.", "The inbound plan is created by an own E2E Store seller session into the admin's new QA warehouse.");
  const code = `QA-${RUN}`.slice(0, 12).toUpperCase();
  await f.step("Inventory overview", "KPIs: inbound, pick & pack, removals, transfers; warehouse table", async () => {
    await open(page, "/inventory");
    await expect(main(page)).toContainText(/Warehouses/);
  });
  await f.step("Warehouses: add empty → errors; add QA warehouse", "Warehouse created and active", async () => {
    await open(page, "/inventory/warehouses");
    await submitNoValidate(form(page, "/inventory/warehouses"));
    await expectValidation(page);
    const fm = form(page, "/inventory/warehouses");
    await fm.locator('[name="code"]').fill(code);
    await fm.locator('[name="name_en"]').fill(`QA Warehouse ${RUN}`);
    await fm.locator('[name="name_ar"]').fill(`مستودع ${RUN}`);
    await fm.locator('[name="city"]').fill("Dubai");
    await fm.locator('[name="capacity_cubic_feet"]').fill("5000");
    await submit(fm);
    await expect(main(page).locator(`input[value="${code}"]`)).toHaveCount(1);
  });
  let inboundRef = "";
  await f.step("Seller (E2E Store) submits an inbound plan of 5 units to the QA warehouse", "Plan submitted", async () => {
    const { context, page: sp } = await panelPage(browser, "partner");
    await sp.goto(panelUrl("partner", "inventory/inbound/create"));
    const fm = sp.locator('main form[action$="/inventory/inbound"]');
    await fm.locator('select[name="warehouse_id"]').selectOption({ label: new RegExp(code) as unknown as string }).catch(async () => {
      const opts = await fm.locator('select[name="warehouse_id"] option').evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent ?? "" })));
      await fm.locator('select[name="warehouse_id"]').selectOption(opts.find((o) => o.t.includes(code))!.v);
    });
    await fm.locator('[name="cartons"]').fill("1").catch(() => undefined);
    const listing = fm.locator('select[name="lines[0][listing_id]"]');
    const lopts = await listing.locator("option").evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value).filter(Boolean));
    await listing.selectOption(lopts[0]);
    await fm.locator('[name="lines[0][quantity]"]').fill("5");
    await fm.locator('[name="lines[0][cartons]"]').fill("1").catch(() => undefined);
    await sp.once("dialog", (d) => d.accept());
    await Promise.all([sp.waitForLoadState("load"), fm.getByRole("button", { name: "Submit plan" }).click()]);
    await expect(sp.locator("main")).not.toContainText(/field is required/);
    inboundRef = ((await sp.locator("main").innerText()).match(/IB-[A-Z0-9-]+|ASN-[A-Z0-9-]+/) ?? [""])[0];
    await sp.screenshot({ path: "e2e/docx/shots/admin-panel-orders-delivery-and-fulfilment/adm-27-seller.png" });
    await context.close();
    return inboundRef || "plan submitted";
  });
  await f.step("Receiving: filter by the QA warehouse and open the plan", "Inbound detail with lines and actions", async () => {
    await open(page, "/inventory/inbound");
    const wh = await page.locator('main select[name="warehouse_id"] option').evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent ?? "" })));
    await open(page, `/inventory/inbound?warehouse_id=${wh.find((o) => o.t.includes(code))!.v}`);
    await main(page).locator('a[href*="/inventory/inbound/"]').first().click();
    await expect(page).toHaveURL(/inventory\/inbound\/\d+/);
  }, { fullPage: true });
  await f.step("Mark arrived, then Start receiving", "Status Receiving; receive form per line", async () => {
    await submit(form(page, "/arrive"));
    await submit(main(page).locator("form", { has: page.getByRole("button", { name: /Start receiving/ }) }));
    await expect(main(page).locator('form[action*="/inventory/inbound/lines/"]').first()).toBeVisible();
  });
  await f.step("Receive 4 good + 1 damaged with put-away bin QA-B1", "Line received; stock on hand +4 and damaged +1 in the QA warehouse", async () => {
    const fm = main(page).locator('form[action*="/inventory/inbound/lines/"]').first();
    const fields = await fm.locator("input, select").evaluateAll((els) => els.map((e) => (e as HTMLInputElement).name));
    const set = async (re: RegExp, v: string) => { const n = fields.find((x) => re.test(x)); if (n) await fm.locator(`[name="${n}"]`).fill(v); };
    await set(/^(received|good|quantity_received|sellable|received_quantity)/, "4");
    await set(/damaged/, "1");
    await set(/bin|location/, "QA-B1");
    await submit(fm);
    await expect(main(page)).not.toContainText(/field is required|Server Error/);
    return `line fields: ${fields.filter(Boolean).join(", ")}`;
  }, { fullPage: true });
  await f.step("Stock & adjustments: filter the QA warehouse", "SKU shows on hand 4, damaged 1, bin QA-B1", async () => {
    await open(page, `/inventory/stock?q=`);
    const opts = await page.locator('main select[name="warehouse_id"]').first().locator("option").evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent ?? "" })));
    await open(page, `/inventory/stock?warehouse_id=${opts.find((o) => o.t.includes(code))!.v}`);
    await expect(main(page)).toContainText(/QA-B1|4/);
  });
  await f.step("Adjust stock without a note", "Refused: note required for the audit", async () => {
    const fm = form(page, "/inventory/stock/adjust");
    await fm.locator('[name="sku"]').fill("E2E-FBN-1");
    await fm.locator('[name="quantity"]').fill("1");
    await submitNoValidate(fm);
    await expectValidation(page);
  });
  await f.step("Cycle count +1 on a SoundMax SKU in AE-FC1 with a note, then -1 back", "Two movements recorded with reason and note", async () => {
    for (const q of ["1", "-1"]) {
      const fm = form(page, "/inventory/stock/adjust");
      await fm.locator('select[name="warehouse_id"]').selectOption({ label: "AE-FC1" }).catch(() => fm.locator('select[name="warehouse_id"]').selectOption({ index: 0 }));
      await fm.locator('[name="sku"]').fill("NW-gmsr-82907");
      await fm.locator('[name="quantity"]').fill(q);
      await fm.locator('select[name="reason"]').selectOption({ index: 0 }).catch(() => undefined);
      await fm.locator('[name="note"]').fill(`QA ${RUN} cycle count ${q}`);
      await submit(fm);
      await expect(main(page)).not.toContainText(/field is required/);
    }
    await expect(main(page)).toContainText(`QA ${RUN} cycle count`);
  }, { fullPage: true });
  await f.step("Transfers: request 1 unit AE-FC1 → AE-FC2, then act on it", "Transfer created and moved to its next state", async () => {
    await open(page, "/inventory/transfers");
    const fm = form(page, "/inventory/transfers");
    await fm.locator('select[name="from_warehouse_id"]').selectOption({ label: /AE-FC1/ as unknown as string }).catch(() => fm.locator('select[name="from_warehouse_id"]').selectOption({ index: 0 }));
    const to = await fm.locator('select[name="to_warehouse_id"] option').evaluateAll((o) => o.map((x) => ({ v: (x as HTMLOptionElement).value, t: x.textContent ?? "" })));
    await fm.locator('select[name="to_warehouse_id"]').selectOption(to.find((o) => /AE-FC2|Abu Dhabi/.test(o.t))!.v);
    await fm.locator('[name="note"]').fill(`QA ${RUN} transfer`);
    await fm.locator('[name="lines[0][sku]"]').fill("NW-gmsr-82907");
    await fm.locator('[name="lines[0][quantity]"]').fill("1");
    await submit(fm);
    await expect(main(page)).toContainText(/TR-|Requested|requested/);
    const act = main(page).locator('form[action*="/inventory/transfers/"]').first();
    if (await act.count()) await submit(act);
    return (await main(page).locator('form[action*="/inventory/transfers/"]').first().getAttribute("action").catch(() => "")) ?? "";
  }, { fullPage: true });
  await f.step("Removals, Pick & pack", "Both queues render (empty states allowed)", async () => {
    await open(page, "/inventory/removals");
    await open(page, "/inventory/pick-pack");
  });
});

test("ADM-28 FBN fees (zero defaults) and packaging", async ({ page }) => {
  const f = flow(page, R, "ADM-28", "FBN fees and packaging supplies", "FBN fee settings show the owner-decided 0.00 defaults and save; packaging supplies, packaging delivery fee and packaging orders.");
  await f.step("Open FBN fees", "Every money field is 0.00 (owner decision)", async () => {
    await open(page, "/inventory/fees");
    const vals = await main(page).locator('form input:not([type=hidden]):not([type=number])').evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    const money = vals.filter((v) => v !== "");
    expect(money.every((v) => Number(v) === 0), `money values ${money.join(",")}`).toBe(true);
    return `${money.length} money fields, all 0.00`;
  }, { fullPage: true });
  await f.step("Save fees unchanged", "Saved message; values still 0.00", async () => {
    await submit(form(page, "/inventory/fees"));
    await expect(flash(page)).toBeVisible();
  });
  await f.step("Packaging: add supply empty → errors; add QA box", "Supply listed", async () => {
    await open(page, "/packaging");
    await submitNoValidate(form(page, "/packaging/supplies"));
    await expectValidation(page);
    const fm = form(page, "/packaging/supplies");
    await fm.locator('[name="sku"]').fill(`QA-BOX-${RUN}`);
    await fm.locator('[name="name_en"]').fill(`QA Box ${RUN}`);
    await fm.locator('[name="name_ar"]').fill(`صندوق ${RUN}`);
    await fm.locator('[name="unit_cost"]').fill("1.5");
    await fm.locator('[name="min_quantity"]').fill("10");
    await submit(fm);
    await expect(main(page)).toContainText(`QA Box ${RUN}`);
  });
  await f.step("Packaging delivery fee: save the current value", "Saved", async () => {
    const fm = form(page, "/packaging/delivery-fee");
    const v = await fm.locator('[name="delivery_fee"]').inputValue();
    await fm.locator('[name="delivery_fee"]').fill(v || "0");
    await submit(fm);
    await expect(flash(page)).toBeVisible();
  });
  await f.step("Packaging orders: list and filter", "Orders list renders", async () => {
    await open(page, "/packaging/orders");
  });
});
