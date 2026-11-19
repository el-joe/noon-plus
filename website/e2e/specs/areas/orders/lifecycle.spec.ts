import type { Browser, BrowserContext, Page } from "@playwright/test";
import { panelUrl, PANELS } from "../../../support/accounts";
import { fixtures } from "../customer/data";
import { api, expect, flow, main, newCustomer, RUN, settle, setup, test, uaePhp, visit, type Flow } from "../customer/kit";
import { panel } from "../customer/kit";
import { addOffer, checkoutAddress, choosePayment, placeOrder, routeGateway, type Offer } from "../customer/shop";

/**
 * Order lifecycle across every actor: customer (storefront), seller (Seller
 * Center), Nawy admin (dispatch, pick & pack, refunds), shipping company
 * (carrier panel) and driver (delivery panel). Report order-lifecycle-and-delivery (ORD-xx).
 */
const R = "order-lifecycle-and-delivery" as const;
setup(R);
test.describe.configure({ timeout: 360_000 });

type Panels = Partial<Record<"partner" | "admin" | "carrier" | "delivery", { context: BrowserContext; page: Page }>>;
async function open(browser: Browser, ps: Panels, key: "partner" | "admin" | "carrier" | "delivery") {
  ps[key] ??= await panel(browser, key, PANELS[key].email);
  return ps[key]!.page;
}
const closeAll = async (ps: Panels) => { for (const p of Object.values(ps)) await p?.context.close().catch(() => undefined); };
const P = (key: "partner" | "admin" | "carrier" | "delivery", path: string) => panelUrl(key, path);
const healthy = async (page: Page) => expect(page.locator("body")).not.toContainText(/Server Error|Whoops|SQLSTATE|ErrorException/);
const flash = (page: Page) => page.locator('main [role="status"], main [role="alert"]').first();

/** Customer buys one unit of the e2e store's offer through checkout; returns the order number. */
async function buy(f: Flow, page: Page, offer: Offer, method: "card" | "cod") {
  let order = "";
  await f.step(`Customer: add the ${offer === "fbn" ? "Fulfilled by Nawy (AED 95)" : "seller-fulfilled (AED 120)"} offer and check out with ${method === "card" ? "card (fake gateway captures)" : "cash on delivery"}`, "'Thank you! Your order is placed.' with the order number", async () => {
    await addOffer(page, offer);
    await checkoutAddress(page);
    if (method === "card") {
      await choosePayment(page, /Credit \/ debit card/);
      await routeGateway(page, "captured");
    } else await choosePayment(page, /Cash on delivery/);
    await placeOrder(page);
    await expect(main(page)).toContainText(/Thank you! Your order is placed/, { timeout: 45_000 });
    order = /NW-AE-[A-Z0-9]+/.exec(await main(page).innerText())![0];
    return order;
  });
  return order;
}

/** Seller Center: opens the sub-order of `order` (one seller → `${order}-1`); returns its id. */
async function sellerOpens(f: Flow, sp: Page, order: string) {
  let id = 0;
  await f.step(`Seller Center → Orders: search ${order}-1 and open it`, "Order page with items, customer & delivery, totals, history and the next-step button", async () => {
    await sp.goto(P("partner", `orders?q=${order}`));
    await sp.getByRole("link", { name: `${order}-1` }).first().click();
    await expect(sp).toHaveURL(/\/orders\/\d+$/);
    id = Number(/\/orders\/(\d+)/.exec(sp.url())![1]);
    await expect(sp.locator("main")).toContainText(`${order}-1`);
    await healthy(sp);
  }, { page: sp });
  return id;
}

async function sellerStep(f: Flow, sp: Page, label: string, expected: string) {
  await f.step(`Seller: ${label}`, expected, async () => {
    sp.once("dialog", (d) => d.accept());
    await Promise.all([sp.waitForEvent("load"), sp.getByRole("button", { name: label, exact: true }).click()]);
    await healthy(sp);
    await expect(sp.getByRole("button", { name: label, exact: true })).toHaveCount(0);
  }, { page: sp });
}

/** Admin → Delivery → Dispatch: hand the ready parcel to E2E Couriers. */
async function adminHandsToCarrier(f: Flow, ap: Page, sub: string) {
  await f.step(`Admin → Delivery → Dispatch: 'Ready to dispatch' row ${sub} → Hand over to E2E Couriers`, "Flash ':number handed to E2E Couriers'; the row now says 'With E2E Couriers'", async () => {
    await ap.goto(P("admin", `delivery/assignments?q=${encodeURIComponent(sub)}`));
    const row = ap.locator("main tr").filter({ hasText: sub }).first();
    await expect(row).toBeVisible();
    await row.locator('select[name="carrier_id"]').selectOption({ label: "E2E Couriers" });
    await Promise.all([ap.waitForEvent("load"), row.getByRole("button", { name: "Hand over" }).click()]);
    const msg = await flash(ap).innerText().catch(() => "");
    expect(msg, "flash after Hand over").toMatch(/handed to E2E Couriers/);
    return msg.trim();
  }, { page: ap });
}

/** Shipping company (carrier panel) → Pickups: assign the parcel to E2E Driver. */
async function carrierAssigns(f: Flow, cp: Page, sub: string) {
  await f.step(`Carrier panel → Pickup requests: SH-${sub} → Driver 'E2E Driver' → Assign`, "Flash 'SH-… assigned to E2E Driver.'; the parcel leaves the pickup list", async () => {
    await cp.goto(P("carrier", "pickups"));
    const row = cp.locator("main tr").filter({ hasText: `SH-${sub}` }).first();
    await expect(row).toBeVisible();
    await row.locator('select[name="agent_id"]').selectOption({ label: "E2E Driver" });
    await Promise.all([cp.waitForEvent("load"), row.getByRole("button", { name: "Assign", exact: true }).click()]);
    await expect(flash(cp)).toContainText(/assigned to E2E Driver/);
  }, { page: cp });
}

/** Delivery panel: open the driver's assignment for the sub-order. */
async function driverOpens(f: Flow, dp: Page, sub: string) {
  await f.step(`Driver (delivery panel) → Assignments: open DA-${sub}-…`, "Assignment with the delivery code card, customer, items and 'Accept' / 'Decline'", async () => {
    await dp.goto(P("delivery", "assignments"));
    await dp.locator("main a").filter({ hasText: `DA-${sub}-` }).first().click();
    await expect(dp).toHaveURL(/\/assignments\/DA-/);
    await healthy(dp);
  }, { page: dp });
}

async function driverPress(f: Flow, dp: Page, label: string, expected: string) {
  await f.step(`Driver: ${label}`, expected, async () => {
    await Promise.all([dp.waitForEvent("load"), dp.getByRole("button", { name: label }).click()]);
    await healthy(dp);
    await expect(dp.locator('main [role="alert"]')).toHaveCount(0);
  }, { page: dp });
}

/** The customer's view of the shipment: status text and the 4-digit delivery code (when shown). */
async function customerShipment(page: Page, order: string) {
  await visit(page, `/account/orders/${order}`);
  const text = await main(page).innerText();
  const code = /Delivery code\s*\n?\s*(\d{4})/i.exec(text)?.[1] ?? null;
  return { text, code };
}

async function driverConfirms(f: Flow, dp: Page, page: Page, order: string, opts: { proof?: boolean } = {}) {
  let customerCode: string | null = null;
  await f.step("Customer: order detail while out for delivery", "Status 'Out for delivery' and the 4-digit delivery code to read to the courier", async () => {
    const s = await customerShipment(page, order);
    expect(s.text).toMatch(/Out for delivery/i);
    customerCode = s.code;
    expect(customerCode, "delivery code shown to the customer").toMatch(/^\d{4}$/);
    return `customer code ${customerCode}`;
  });
  await f.step("Driver: the delivery code card shows the same 4 digits (customer reads it aloud — no typing)", "Driver's code equals the customer's code", async () => {
    await dp.reload();
    const driverCode = (await dp.locator("[data-delivery-code]").innerText()).replace(/\s/g, "");
    expect(driverCode).toBe(customerCode);
    return `driver code ${driverCode} = customer code ${customerCode}`;
  }, { page: dp });
  if (opts.proof) {
    await f.step("Driver: add the proof photo required for a COD parcel and collect the cash", "Photo attached; 'Collect cash: AED …' shown", async () => {
      await expect(dp.locator("main")).toContainText(/Collect cash/);
      await expect(dp.locator("main")).toContainText(/Proof photo|signature required/i);
      const PNG = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=", "base64");
      await dp.locator('form[data-confirm-form] input[type="file"][name="photo"]').setInputFiles({ name: "proof.png", mimeType: "image/png", buffer: PNG });
    }, { page: dp });
  }
  await driverPress(f, dp, "Codes match — delivered", "Assignment closed: 'This assignment is closed.' Delivered; customer details hidden");
  await f.step("Customer: order detail after delivery", "Shipment 'Delivered'; invoice links; Return item / Write review actions", async () => {
    const s = await customerShipment(page, order);
    expect(s.text).toMatch(/Delivered/);
  });
}

test("ORD-01 seller-fulfilled order: seller → admin dispatch → carrier → driver → delivered → invoice → review → return → refund", async ({ page, browser }) => {
  const f = flow(page, R, "ORD-01", "Full lifecycle of a seller-fulfilled order (card)", "A real order placed in the browser is driven through every actor: the seller confirms, processes, packs (packing slip, parcel label) and marks it ready; Nawy admin hands it to the shipping company; the carrier assigns its driver; the driver accepts, picks up and delivers with the code match; the customer sees tracking, Delivered, the invoice, reviews the item and returns it; the seller handles the return and the refund reaches the customer.",
    "A new shopper; Seller Center as the e2e seller, admin, carrier supervisor and driver sessions (e2e/.auth or password + 2FA).");
  const ps: Panels = {};
  try {
    await newCustomer(page, "ord1");
    const order = await buy(f, page, "fbm", "card");
    const sub = `${order}-1`;
    const sp = await open(browser, ps, "partner");
    const id = await sellerOpens(f, sp, order);
    await f.step("Customer: order detail right after paying", "Status Placed / Confirmed, sold by E2E Store, paid by card", async () => {
      const s = await customerShipment(page, order);
      expect(s.text).toContain("E2E Store");
    });
    await sellerStep(f, sp, "Confirm order", "Status Confirmed; history logs the seller");
    await sellerStep(f, sp, "Start processing", "Status Processing");
    await f.step("Seller: Print packing slip", "Packing slip page lists the order, items and address", async () => {
      await sp.goto(P("partner", `orders/${id}/packing-slip`));
      await expect(sp.locator("body")).toContainText(sub);
      await sp.goto(P("partner", `orders/${id}`));
    }, { page: sp });
    await sellerStep(f, sp, "Mark as packed", "Status Packed");
    await sellerStep(f, sp, "Ready for pickup", "Status Shipped (handed to carrier)");
    const ap = await open(browser, ps, "admin");
    await adminHandsToCarrier(f, ap, sub);
    await f.step("Seller: Print parcel label (HTML + PDF) — available once the delivery is booked", "Label with the shipment number/barcode and the destination; PDF downloads", async () => {
      await sp.goto(P("partner", `orders/${id}/label`));
      await expect(sp.locator("body")).toContainText(/SH-|NW-AE/);
      // The API request context does not get Chromium's *.nawy.test mapping: fetch the PDF from the page.
      const pdf = await sp.evaluate(async (url) => {
        const res = await fetch(url, { credentials: "include" });
        const bytes = new Uint8Array(await res.arrayBuffer());
        return { status: res.status, type: res.headers.get("content-type"), head: String.fromCharCode(...bytes.slice(0, 4)), size: bytes.length };
      }, P("partner", `orders/${id}/label.pdf`));
      expect(pdf.status).toBe(200);
      expect(pdf.head).toBe("%PDF");
      await sp.goto(P("partner", `orders/${id}`));
      return `label.pdf ${pdf.size} bytes (${pdf.type})`;
    }, { page: sp });
    const cp = await open(browser, ps, "carrier");
    await carrierAssigns(f, cp, sub);
    const dp = await open(browser, ps, "delivery");
    await driverOpens(f, dp, sub);
    await driverPress(f, dp, "Accept", "Status Accepted; next step 'Picked up — start delivery'");
    await driverPress(f, dp, "Picked up — start delivery", "Status Picked up; 'I have arrived' and the 'Codes match' form");
    await driverPress(f, dp, "I have arrived", "Arrival time recorded");
    await driverConfirms(f, dp, page, order);
    await f.step("Customer: open the invoice (HTML) and the PDF", "Tax invoice with the order number and VAT; PDF file", async () => {
      const res = await api(page, "GET", `/orders/${order}/invoice/pdf`);
      expect(res.status).toBe(200);
      expect(res.body.startsWith("%PDF")).toBe(true);
      const [popup] = await Promise.all([page.context().waitForEvent("page"), main(page).getByRole("link", { name: /Invoice/i }).first().click()]);
      await popup.waitForLoadState("load");
      await expect(popup.locator("body")).toContainText(order);
      await popup.screenshot({ path: `e2e/docx/shots/${R}/ord-01-invoice.png` });
      await popup.close();
    });
    await f.step("Customer: Account → My reviews → Write review for the delivered item", "'Thanks! Your review will appear once it has been checked.'", async () => {
      await visit(page, "/account/reviews");
      await main(page).getByRole("button", { name: "Write review" }).first().click();
      const dialog = page.getByRole("dialog");
      await dialog.getByRole("radio", { name: "Excellent" }).click();
      await dialog.locator('input[name="title"]').fill(`QA review ${RUN}`);
      await dialog.locator('textarea[name="body"]').fill("Arrived on time and the delivery code worked.");
      await dialog.getByRole("button", { name: "Submit review" }).click();
      await expect(dialog).toContainText("Thanks! Your review will appear once it has been checked.");
    }, { soft: true });
    let rt = "";
    await f.step("Customer: order → Return item → reason 'Changed my mind', refund to Nawy Credits → Submit return", "Returns list shows the new RT- return 'Requested'", async () => {
      await visit(page, `/account/orders/${order}`);
      await main(page).getByRole("link", { name: "Return item" }).first().click();
      await expect(page).toHaveURL(/\/account\/returns\/new/);
      await settle(page);
      await page.locator('select[aria-label="Quantity"]').first().selectOption("1");
      await page.locator('select[name="reason"]').selectOption("changed_mind");
      await page.locator('select[name="refund_destination"]').selectOption("wallet");
      await page.getByRole("button", { name: "Submit return" }).click();
      await expect(page).toHaveURL(/\/account\/returns$/);
      rt = /RT-[A-Z0-9-]+/.exec(await main(page).innerText())![0];
      return rt;
    });
    await f.step(`Seller Center → Returns → ${rt} → Approve return`, "Status Approved", async () => {
      await sp.goto(P("partner", "returns"));
      await sp.locator("main a").filter({ hasText: rt }).first().click();
      await Promise.all([sp.waitForEvent("load"), sp.getByRole("button", { name: "Approve return" }).click()]);
      await healthy(sp);
      await expect(sp.locator("main")).toContainText(/Approved/i);
    }, { page: sp });
    await f.step("Seller: move the return through pickup / transit / received (Mark … buttons)", "Status Received / Inspecting with the Inspection form", async () => {
      for (let i = 0; i < 4; i++) {
        if (await sp.getByRole("button", { name: "Save inspection and refund" }).isVisible().catch(() => false)) break;
        await Promise.all([sp.waitForEvent("load"), sp.getByRole("button", { name: /^Mark / }).first().click()]);
        await healthy(sp);
      }
      await expect(sp.getByRole("button", { name: "Save inspection and refund" })).toBeVisible();
    }, { page: sp });
    await f.step("Seller: inspection 'Good condition', put back in stock → Save inspection and refund", "Return Completed with the refund amount", async () => {
      await sp.locator('select[name$="[outcome]"]').first().selectOption("good");
      await sp.locator('input[type="checkbox"][name$="[restock]"]').first().check().catch(() => undefined);
      await Promise.all([sp.waitForEvent("load"), sp.getByRole("button", { name: "Save inspection and refund" }).click()]);
      await healthy(sp);
      await expect(sp.locator("main")).toContainText(/Completed|Refund/i);
    }, { page: sp });
    await f.step("Admin → Refunds: the refund for this order (approve and pay out if it waits for approval)", "Refund paid to Nawy Credits", async () => {
      await ap.goto(P("admin", "refunds?status=&source=return"));
      const link = ap.locator("main tr").filter({ hasText: order }).locator("a").filter({ hasText: /^RF-/ }).first();
      if (await link.count()) {
        await link.click();
        const approve = ap.getByRole("button", { name: "Approve and pay out" });
        if (await approve.isVisible().catch(() => false)) {
          await Promise.all([ap.waitForEvent("load"), approve.click()]);
        }
        await healthy(ap);
        return (await ap.locator("main").innerText()).replace(/\s+/g, " ").slice(0, 200);
      }
      throw new Error(`no return refund row for ${order} in Admin → Refunds`);
    }, { page: ap, soft: true });
    await f.step("Customer: Returns & refunds and Nawy Credits", "The return shows Completed / refund AED 120.00; credits balance AED 120.00", async () => {
      await visit(page, "/account/returns");
      await expect(main(page)).toContainText(rt);
      await visit(page, "/account/credits");
      await expect(main(page)).toContainText("120.00");
    });
    f.end();
  } finally {
    await closeAll(ps);
  }
});

test("ORD-02 FBN order: admin pick & pack → dispatch → driver delivers COD with proof", async ({ page, browser }) => {
  const f = flow(page, R, "ORD-02", "Fulfilled by Nawy order through pick & pack (COD)", "The FBN offer (listing 19, warehouse E2E-FC) is bought with cash on delivery; the seller cannot advance it; Nawy admin confirms, picks, packs and dispatches it in Inventory → Pick & pack; dispatch puts it with E2E Driver, who delivers with a proof photo and collects the cash.",
    "A new shopper; admin, seller and driver sessions.");
  const ps: Panels = {};
  try {
    await newCustomer(page, "ord2");
    const order = await buy(f, page, "fbn", "cod");
    const sub = `${order}-1`;
    const sp = await open(browser, ps, "partner");
    await sellerOpens(f, sp, order);
    await f.step("Seller: the FBN order has no fulfilment buttons", "No 'Confirm order' (the warehouse fulfils it)", async () => {
      await expect(sp.getByRole("button", { name: "Confirm order", exact: true })).toHaveCount(0);
    }, { page: sp, soft: true });
    const ap = await open(browser, ps, "admin");
    for (const [label, expected] of [["Confirm order", "Confirmed"], ["Start picking", "Processing"], ["Mark as packed", "Packed"], ["Dispatch", "Shipped"]] as const) {
      await f.step(`Admin → Inventory → Pick & pack: ${sub} → ${label}`, `Status ${expected}`, async () => {
        await ap.goto(P("admin", "inventory/pick-pack"));
        const row = ap.locator("main tr").filter({ hasText: sub }).first();
        await expect(row).toBeVisible();
        await Promise.all([ap.waitForEvent("load"), row.getByRole("button", { name: label }).click()]);
        await healthy(ap);
      }, { page: ap });
    }
    await f.step("Admin → Dispatch: put the parcel with E2E Driver (assign, or reassign the auto-assignment)", "An open assignment for E2E Driver", async () => {
      await ap.goto(P("admin", `delivery/assignments?q=${encodeURIComponent(sub)}`));
      const ready = ap.locator("main tr").filter({ hasText: sub }).filter({ has: ap.locator('select[name="agent_id"]') }).first();
      if (await ready.count()) {
        await ready.locator('select[name="agent_id"]').selectOption({ label: "E2E Driver" });
        await Promise.all([ap.waitForEvent("load"), ready.getByRole("button", { name: "Assign", exact: true }).click()]);
        return "assigned from Ready to dispatch";
      }
      const assignment = ap.locator("main a").filter({ hasText: `DA-${sub}-` }).first();
      await assignment.click();
      await healthy(ap);
      const agent = (await ap.locator("main dt", { hasText: /^Agent$/ }).locator("xpath=following-sibling::dd[1]").innerText()).trim();
      if (!agent.startsWith("E2E Driver")) {
        await ap.locator('select[name="agent_id"]').first().selectOption({ label: "E2E Driver" });
        await Promise.all([ap.waitForEvent("load"), ap.getByRole("button", { name: /Reassign/ }).first().click()]);
        await expect(flash(ap)).toContainText(/Reassigned/);
        return `auto-assigned to ${agent}; reassigned to E2E Driver`;
      }
      return "auto-assigned to E2E Driver on dispatch";
    }, { page: ap });
    const dp = await open(browser, ps, "delivery");
    await f.step("Driver → Assignments: open the newest open assignment of the order", "Assignment page", async () => {
      await dp.goto(P("delivery", "assignments"));
      const links = dp.locator("main tr").filter({ hasText: `DA-${sub}-` }).filter({ hasNotText: /Cancelled|Declined/ });
      await links.first().locator("a").first().click();
      await healthy(dp);
    }, { page: dp });
    if (await dp.getByRole("button", { name: "Accept" }).isVisible().catch(() => false)) await driverPress(f, dp, "Accept", "Accepted");
    await driverPress(f, dp, "Picked up — start delivery", "Picked up; COD amount to collect shown");
    await driverConfirms(f, dp, page, order, { proof: true });
    await f.step("Driver → COD: the collection is in hand", "COD page lists today's collection for this order", async () => {
      await dp.goto(P("delivery", "cod"));
      await healthy(dp);
      await expect(dp.locator("main")).toContainText(/AED/);
    }, { page: dp, soft: true });
    f.end();
  } finally {
    await closeAll(ps);
  }
});

test("ORD-03 cancellations: by the customer (COD) and by the seller (card, refunded)", async ({ page, browser }) => {
  const f = flow(page, R, "ORD-03", "Cancellation paths", "The customer cancels a COD shipment from the order page with a reason; a card-paid order is cancelled by the seller with a reason and the customer sees it cancelled and refunded.", "A new shopper; Seller Center session.");
  const ps: Panels = {};
  try {
    await newCustomer(page, "ord3");
    const cod = await buy(f, page, "fbm", "cod");
    await f.step(`Customer: ${cod} → Cancel items → reason → Cancel shipment`, "Shipment Cancelled", async () => {
      await visit(page, `/account/orders/${cod}`);
      await main(page).getByRole("button", { name: "Cancel items" }).first().click();
      const dialog = page.getByRole("dialog");
      await dialog.locator('input[type="radio"][name="reason"]').first().check();
      await dialog.getByRole("button", { name: "Cancel shipment" }).click();
      await expect(dialog).toBeHidden();
      await expect(main(page)).toContainText(/Cancelled/);
    });
    const sp = await open(browser, ps, "partner");
    await f.step("Seller Center: the customer-cancelled order shows Cancelled", "Status Cancelled with the customer as actor", async () => {
      await sp.goto(P("partner", `orders?q=${cod}`));
      await expect(sp.locator("main")).toContainText(/Cancelled/);
    }, { page: sp });
    const paid = await buy(f, page, "fbm", "card");
    await sellerOpens(f, sp, paid);
    await f.step("Seller: Cancel this order with reason 'Out of stock' → Cancel order", "Status Cancelled; strike warning shown before", async () => {
      await sp.locator('textarea[name="reason"]').fill(`Out of stock (${RUN})`);
      sp.once("dialog", (d) => d.accept());
      await Promise.all([sp.waitForEvent("load"), sp.getByRole("button", { name: "Cancel order", exact: true }).click()]);
      await healthy(sp);
      await expect(sp.locator("main")).toContainText(/Cancelled/);
    }, { page: sp });
    await f.step(`Customer: ${paid} shows Cancelled and the refund`, "Shipment Cancelled; a refund line for AED 120.00 (to the card)", async () => {
      const s = await customerShipment(page, paid);
      expect(s.text).toMatch(/Cancelled/);
      expect(s.text).toMatch(/Refund|refunded/i);
    }, { soft: true });
    f.end();
  } finally {
    await closeAll(ps);
  }
});

test("ORD-04 failed delivery → reschedule → delivered on the second attempt", async ({ page, browser }) => {
  const f = flow(page, R, "ORD-04", "Failed delivery and retry", "Admin assigns a COD parcel straight to E2E Driver; the driver reports 'not home'; the parcel shows under Needs attention / Failed deliveries; admin reschedules it, assigns again, and the driver delivers on attempt 2.", "A new shopper; seller, admin, carrier and driver sessions.");
  const ps: Panels = {};
  try {
    await newCustomer(page, "ord4");
    const order = await buy(f, page, "fbm", "cod");
    const sub = `${order}-1`;
    const sp = await open(browser, ps, "partner");
    await sellerOpens(f, sp, order);
    for (const label of ["Confirm order", "Start processing", "Mark as packed"]) await sellerStep(f, sp, label, "Next status");
    const ap = await open(browser, ps, "admin");
    await f.step(`Admin → Dispatch: assign ${sub} to E2E Driver (no shipping company)`, "Flash ':number assigned to E2E Driver.'", async () => {
      await ap.goto(P("admin", `delivery/assignments?q=${encodeURIComponent(sub)}`));
      const row = ap.locator("main tr").filter({ hasText: sub }).first();
      await row.locator('select[name="agent_id"]').selectOption({ label: "E2E Driver" });
      await Promise.all([ap.waitForEvent("load"), row.getByRole("button", { name: "Assign", exact: true }).click()]);
      await expect(flash(ap)).toContainText(/assigned to E2E Driver/);
    }, { page: ap });
    const dp = await open(browser, ps, "delivery");
    await driverOpens(f, dp, sub);
    await driverPress(f, dp, "Accept", "Accepted");
    await driverPress(f, dp, "Picked up — start delivery", "Picked up");
    await f.step("Driver: Can't deliver → 'Not home', note → Report failed attempt", "Assignment Failed; shipment Failed (attempt 1 of 3)", async () => {
      await dp.locator('select[name="reason"]').selectOption("not_home");
      await dp.locator('input[name="note"]').fill("Nobody at the door");
      await Promise.all([dp.waitForEvent("load"), dp.getByRole("button", { name: "Report failed attempt" }).click()]);
      await healthy(dp);
      await expect(dp.locator("main")).toContainText(/closed|Failed/i);
    }, { page: dp });
    await f.step("Customer: order detail after the failed attempt", "The shipment says the delivery attempt failed / will be retried", async () => {
      const s = await customerShipment(page, order);
      return s.text.replace(/\s+/g, " ").slice(0, 200);
    });
    const cp = await open(browser, ps, "carrier");
    await f.step("Carrier panel → Failed deliveries", "Exceptions board renders (the parcel was assigned by Nawy, not the carrier)", async () => {
      await cp.goto(P("carrier", "exceptions"));
      await healthy(cp);
    }, { page: cp });
    await f.step("Admin → Dispatch → Needs attention: reschedule for tomorrow", "Shipment Rescheduled and back in 'Ready to dispatch'", async () => {
      await ap.goto(P("admin", `delivery/assignments?q=${encodeURIComponent(sub)}`));
      const row = ap.locator("main tr").filter({ hasText: sub }).filter({ has: ap.getByRole("button", { name: "Reschedule" }) }).first();
      await expect(row).toBeVisible();
      const date = row.locator('input[type="date"]');
      if (await date.count()) await date.fill(new Date(Date.now() + 86_400_000).toISOString().slice(0, 10));
      await Promise.all([ap.waitForEvent("load"), row.getByRole("button", { name: "Reschedule" }).click()]);
      await healthy(ap);
      await expect(ap.locator('main [role="alert"]')).toHaveCount(0);
    }, { page: ap });
    await f.step("Admin → Dispatch: assign the rescheduled parcel to E2E Driver again", "Assignment attempt 2", async () => {
      await ap.goto(P("admin", `delivery/assignments?q=${encodeURIComponent(sub)}`));
      const row = ap.locator("main tr").filter({ hasText: sub }).filter({ has: ap.locator('select[name="agent_id"]') }).first();
      await row.locator('select[name="agent_id"]').selectOption({ label: "E2E Driver" });
      await Promise.all([ap.waitForEvent("load"), row.getByRole("button", { name: "Assign", exact: true }).click()]);
      await expect(flash(ap)).toContainText(/assigned to E2E Driver/);
    }, { page: ap });
    await f.step("Driver: open the attempt-2 assignment", "Attempt 2", async () => {
      await dp.goto(P("delivery", "assignments"));
      await dp.locator("main tr").filter({ hasText: `DA-${sub}-` }).filter({ hasText: /Assigned/ }).first().locator("a").first().click();
      await expect(dp.locator("body")).toContainText(/Attempt 2/);
    }, { page: dp });
    await driverPress(f, dp, "Accept", "Accepted");
    await driverPress(f, dp, "Picked up — start delivery", "Picked up");
    await driverConfirms(f, dp, page, order, { proof: true });
    f.end();
  } finally {
    await closeAll(ps);
  }
});
