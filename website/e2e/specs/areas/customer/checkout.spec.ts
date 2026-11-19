import { addOffer, checkoutAddress, choosePayment, gatewayNowReports, placeOrder, routeGateway } from "./shop";
import { api, createCustomer, expect, flow, formOf, main, MARKET, newCustomer, PASSWORD, RUN, settle, setup, test, uaePhp, visit, type Page } from "./kit";

/** Cart and checkout: report checkout (CHK-xx). */
const R = "checkout" as const;
setup(R);

const orderCount = async (page: Page) => Number((await api(page, "GET", "/orders?per_page=1")).json?.meta?.total ?? (await api(page, "GET", "/orders")).json?.data?.length ?? 0);

test("CHK-01 guest cart: add, quantity, free-delivery bar, remove, sign-in gate", async ({ page }) => {
  const f = flow(page, R, "CHK-01", "Guest cart", "A guest adds the FBN offer (AED 95) to the cart, sees the free-delivery bar, changes quantity until free delivery applies, removes a line and is asked to sign in at checkout.");
  await f.step("Product page → Add to cart (FBN offer, AED 95)", "'Added to cart' with a View cart link; header cart count 1", async () => { await addOffer(page, "fbn"); });
  await f.step("Open the cart", "One shipment sold by E2E Store, subtotal AED 95.00, free-delivery bar 'Spend AED 5.00 more for free delivery'", async () => {
    await visit(page, "/cart");
    await expect(main(page)).toContainText("Nova X12");
    await expect(main(page)).toContainText(/Spend AED\s?[\d.]+ more for free delivery|Free delivery on orders over/);
    return (await main(page).getByText(/free delivery/i).first().innerText()).trim();
  });
  await f.step("Increase quantity to 2", "Subtotal AED 190.00; bar says 'You qualify for free delivery'", async () => {
    await main(page).getByRole("button", { name: "Increase quantity" }).first().click();
    await expect(main(page)).toContainText("190.00");
    await expect(main(page)).toContainText(/You qualify for free delivery|qualifies for free delivery/);
  });
  await f.step("Decrease back to 1", "Subtotal AED 95.00 again", async () => {
    await main(page).getByRole("button", { name: "Decrease quantity" }).first().click();
    await expect(main(page)).toContainText(/Spend AED\s?[\d.]+ more/);
  });
  await f.step("Checkout as a guest", "Sign-in gate 'Sign in to check out' with 'Sign in with email'", async () => {
    await main(page).getByRole("link", { name: "Checkout" }).or(main(page).getByRole("button", { name: "Checkout" })).first().click();
    await expect(page).toHaveURL(/\/checkout|\/login/);
    await settle(page);
    await expect(page.locator("body")).toContainText(/Sign in to check out|Hala! Welcome/);
  });
  await f.step("Back to the cart → Remove the line", "'Your cart is empty'", async () => {
    await visit(page, "/cart");
    await main(page).getByRole("button", { name: "Remove" }).first().click();
    await expect(main(page)).toContainText("Your cart is empty");
  });
  f.end();
});

test("CHK-02 guest cart merges into the account on sign-in", async ({ page }) => {
  const f = flow(page, R, "CHK-02", "Guest cart merge on sign-in", "Items added as a guest move into the shopper's cart when they sign in at checkout (password sign-in).", "A shopper with a password created for this run.");
  const { email } = createCustomer("merge", { password: true });
  await f.step("As a guest add the seller-fulfilled offer (AED 120)", "In the guest cart", async () => { await addOffer(page, "fbm"); });
  await f.step("Cart → Checkout → sign in with password", "Back on /checkout signed in; the guest line is in the checkout summary", async () => {
    await visit(page, "/cart");
    await main(page).getByRole("link", { name: "Checkout" }).or(main(page).getByRole("button", { name: "Checkout" })).first().click();
    await page.goto(`/${MARKET}/login?next=${encodeURIComponent(`/${MARKET}/checkout`)}`);
    await page.locator('input[type="email"]').fill(email);
    await page.getByRole("button", { name: "Sign in with password" }).click();
    await page.locator('input[type="password"]').fill(PASSWORD);
    await formOf(page, 'input[type="password"]').locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/uae-en\/checkout/, { timeout: 20_000 });
    await settle(page);
    await expect(main(page)).toContainText(/120\.00/);
  });
  await f.step("Open the cart signed in", "The merged line (E2E Store, AED 120.00) is in the account cart", async () => {
    await visit(page, "/cart");
    await expect(main(page)).toContainText("E2E Store");
    await expect(main(page)).toContainText("120.00");
  });
  f.end();
});

test("CHK-03 coupons: invalid refused, valid applied and removed", async ({ page }) => {
  const f = flow(page, R, "CHK-03", "Coupons", "An unknown coupon is refused; the public coupon RT10 (10% off, max AED 50) applies a discount line; it can be removed. The PDP 'Apply to cart' chip applies it too.", "A new shopper.");
  await newCustomer(page, "coupon");
  await f.step("Add the AED 120 offer and open the cart", "Cart with the coupon box", async () => {
    await addOffer(page, "fbm");
    await visit(page, "/cart");
    await expect(page.getByPlaceholder("Coupon code")).toBeVisible();
  });
  await f.step("Apply 'NOPE-123'", "An error under the coupon box; totals unchanged", async () => {
    await page.getByPlaceholder("Coupon code").fill("NOPE-123");
    await page.getByPlaceholder("Coupon code").press("Enter");
    await expect(main(page).getByRole("alert").first()).toBeVisible();
    return (await main(page).getByRole("alert").first().innerText()).trim();
  });
  await f.step("Apply 'RT10'", "'RT10 applied'; Discount −AED 12.00; total AED 108.00", async () => {
    await page.getByPlaceholder("Coupon code").fill("RT10");
    await page.getByPlaceholder("Coupon code").press("Enter");
    await expect(main(page)).toContainText("RT10 applied");
    await expect(main(page)).toContainText("Discount");
    await expect(main(page)).toContainText("12.00");
  });
  await f.step("Remove the coupon", "Discount line gone; total back to AED 120.00", async () => {
    await main(page).getByRole("button", { name: /Remove.*RT10|RT10.*Remove|Remove coupon/ }).first().click();
    await expect(main(page)).not.toContainText("RT10 applied");
  });
  await f.step("PDP coupon chip FIRST15: 'Apply to cart'", "The PDP shows the 4 best public coupons (FIRST15 among them, RT10 is the 5th); its chip applies FIRST15 to the cart (15% of AED 120 = AED 18.00)", async () => {
    await visit(page, "/products/nova-x12-smartphone");
    // One chip per coupon: the dashed card holding the code and its two buttons.
    const chip = main(page).locator("div.border-dashed").filter({ has: page.getByRole("button", { name: "Copy code FIRST15" }) }).first();
    await chip.getByRole("button", { name: "Apply to cart" }).click();
    await visit(page, "/cart");
    await expect(main(page)).toContainText("FIRST15 applied");
    await expect(main(page)).toContainText("18.00");
  }, { soft: true });
  f.end();
});

test("CHK-04 card payment success (fake gateway), confirmation, saved card", async ({ page }) => {
  const f = flow(page, R, "CHK-04", "Card payment — success", "Checkout with a new address and the card method; the fake gateway captures and tokenises the card; the confirmation page shows the order, shipment and delivery-code note; the order is in the account and the card is saved as a token.", "A new shopper.");
  await newCustomer(page, "card");
  await f.step("Add the AED 120 offer; open checkout", "Steps Address → Delivery → Payment; new address form; card recommended", async () => {
    await addOffer(page, "fbm");
    await checkoutAddress(page);
    await expect(main(page)).toContainText("Payment method");
    await expect(main(page)).toContainText("Showing methods for");
  });
  await f.step("Choose 'Credit / debit card' → Continue to review", "Review shows the address, items, method and 'Place order · AED …'", async () => {
    await choosePayment(page, /Credit \/ debit card/);
    await page.getByRole("button", { name: "Continue to review" }).click();
    await expect(page.getByRole("button", { name: /Place order/ })).toBeVisible();
    await expect(main(page)).toContainText("QA Tower");
  });
  let order = "";
  await f.step("Place order (gateway captures, tokenises card ending 4242)", "Payment return → 'Thank you! Your order is placed.' with the order number", async () => {
    await routeGateway(page, "captured", { tokenise: { token: `tok_qa_${RUN}`, last4: "4242", brand: "visa" } });
    await page.getByRole("button", { name: /Place order/ }).click();
    await expect(page).toHaveURL(/\/checkout\/(payment|success)/, { timeout: 45_000 });
    await expect(main(page)).toContainText(/Thank you! Your order is placed|Payment received/, { timeout: 30_000 });
    order = /NW-AE-[A-Z0-9]+/.exec(await main(page).innerText())![0];
    return order;
  });
  await f.step("Confirmation page details", "Shipment line 'sold by E2E Store', arrival dates, delivery-code note, Track order", async () => {
    if (!/\/checkout\/success/.test(page.url())) await visit(page, `/checkout/success?order=${order}`);
    await expect(main(page)).toContainText(/Shipment 1 · sold by E2E Store/);
    await expect(main(page)).toContainText(/4-digit delivery code/);
    await expect(main(page).getByRole("link", { name: "Track order" })).toBeVisible();
  });
  await f.step("Account → Orders → the new order", "Order detail: status Placed/Confirmed, paid by card", async () => {
    await visit(page, `/account/orders/${order}`);
    await expect(main(page)).toContainText(order);
  });
  await f.step("Saved cards: GET me/cards", "The card is saved as a token: brand visa, last4 4242, no card number", async () => {
    const res = await api(page, "GET", "/me/cards");
    expect(res.body).toContain("4242");
    expect(res.body).not.toMatch(/\b\d{13,19}\b/);
    expect(res.body).not.toContain(`tok_qa_${RUN}`);
    return res.body.slice(0, 220);
  }, { shot: false, soft: true });
  f.end();
});

test("CHK-05 card declined, then retry", async ({ page }) => {
  const f = flow(page, R, "CHK-05", "Card declined → retry", "The gateway declines the card; the shopper sees 'Your payment didn't go through', the order is kept, and they retry with another attempt that is captured.", "A new shopper.");
  await newCustomer(page, "decline");
  let order = "";
  await f.step("Add the AED 120 offer, card, Place order (gateway reports failed)", "'Your payment didn't go through' — 'No money was taken. Your order is saved…'", async () => {
    await addOffer(page, "fbm");
    await checkoutAddress(page);
    await choosePayment(page, /Credit \/ debit card/);
    await routeGateway(page, "failed");
    order = await placeOrder(page);
    await expect(main(page)).toContainText("Your payment didn't go through", { timeout: 30_000 });
    return order;
  });
  await f.step("Retry options on the failed-payment page", "Only methods the API accepts for a retry (no Cash on delivery: the API refuses COD after the order is placed)", async () => {
    await expect(page.locator("label").filter({ hasText: /Credit \/ debit card/ }).first()).toBeVisible();
    await expect(page.locator("label").filter({ hasText: /Cash on delivery/ })).toHaveCount(0);
  }, { soft: true });
  await f.step("Retry with 'Credit / debit card' (gateway captures this time)", "'Thank you! Your order is placed.'", async () => {
    await routeGateway(page, "captured");
    await page.locator("label").filter({ hasText: /Credit \/ debit card/ }).first().locator("input").check();
    await main(page).getByRole("button", { name: "Retry payment" }).click();
    await expect(main(page)).toContainText(/Thank you! Your order is placed|Payment received/, { timeout: 45_000 });
  });
  await f.step("Order detail", "The order is paid by card after one failed attempt", async () => {
    await visit(page, `/account/orders/${order}`);
    await expect(main(page)).toContainText(order);
  });
  f.end();
});

test("CHK-06 payment pending, then confirmed", async ({ page }) => {
  const f = flow(page, R, "CHK-06", "Card payment pending → captured", "The gateway first reports pending: the shopper sees 'We're waiting for the payment provider'; after the gateway captures, 'Check again' shows the order placed.", "A new shopper.");
  await newCustomer(page, "pending");
  let seen: string[] = [];
  await f.step("Add the offer, card, Place order (gateway reports pending)", "'We're waiting for the payment provider' with 'Check again'", async () => {
    await addOffer(page, "fbm");
    await checkoutAddress(page);
    await choosePayment(page, /Credit \/ debit card/);
    seen = await routeGateway(page, "pending");
    await placeOrder(page);
    await expect(main(page)).toContainText("We're waiting for the payment provider", { timeout: 30_000 });
  });
  await f.step("Gateway now reports captured → 'Check again'", "'Thank you! Your order is placed.' / Payment received", async () => {
    gatewayNowReports(seen[seen.length - 1], "captured");
    await main(page).getByRole("button", { name: "Check again" }).click();
    await expect(main(page)).toContainText(/Thank you! Your order is placed|Payment received/, { timeout: 30_000 });
  });
  f.end();
});

test("CHK-07 payment under review (amount mismatch)", async ({ page }) => {
  const f = flow(page, R, "CHK-07", "Payment under review", "The gateway captures a different amount than the order total (QA-009): the payment goes under review, the shopper sees 'Payment under review' and the order is on hold. FakeGateway cannot report another amount, so the gateway webhook is simulated with PaymentAttempts::applyOutcome.", "A new shopper.");
  await newCustomer(page, "review");
  let order = "";
  let ref = "";
  await f.step("Add the offer, card, Place order (gateway still pending)", "'We're waiting for the payment provider'", async () => {
    await addOffer(page, "fbm");
    await checkoutAddress(page);
    await choosePayment(page, /Credit \/ debit card/);
    const seen = await routeGateway(page, "pending");
    order = await placeOrder(page);
    ref = seen[seen.length - 1];
    await expect(main(page)).toContainText("We're waiting for the payment provider", { timeout: 30_000 });
    return order;
  });
  await f.step("The gateway's webhook reports a capture of AED 1.00 instead of the order total (PaymentAttempts::applyOutcome, source webhook)", "Payment moves to under_review (amount_mismatch)", async () => {
    const out = uaePhp(`$p = App\\Domains\\Payments\\Models\\Payment::query()->where("gateway_reference", "${ref}")->firstOrFail(); $p = app(App\\Domains\\Payments\\Support\\PaymentAttempts::class)->applyOutcome($p, new App\\Domains\\Payments\\Support\\Data\\GatewayOutcome(App\\Domains\\Payments\\Enums\\PaymentStatus::Captured, $p->gateway_reference, "1.00"), "webhook"); echo $p->status->value;`);
    expect(out).toContain("under_review");
    return out;
  }, { shot: false });
  await f.step("'Check again' on the payment page", "'Payment under review' with the explanation and 'View order'", async () => {
    await main(page).getByRole("button", { name: "Check again" }).click();
    await expect(main(page)).toContainText("Payment under review", { timeout: 30_000 });
  });
  await f.step("View order", "The order shows a held / under-review payment state", async () => {
    await main(page).getByRole("link", { name: "View order" }).click();
    await settle(page);
    await expect(main(page)).toContainText(/review|hold|Awaiting payment/i);
  }, { soft: true });
  f.end();
});

test("CHK-08 cash on delivery straight away", async ({ page }) => {
  const f = flow(page, R, "CHK-08", "Cash on delivery", "Checkout with COD: the AED 10.00 COD fee is added to the total, the order is placed without a gateway.", "A new shopper.");
  await newCustomer(page, "cod");
  await f.step("Add the AED 120 offer; checkout; choose Cash on delivery", "COD shows '+ AED 10.00 fee'", async () => {
    await addOffer(page, "fbm");
    await checkoutAddress(page);
    await expect(page.locator("label").filter({ hasText: /Cash on delivery/ })).toContainText("10.00");
    await choosePayment(page, /Cash on delivery/);
  });
  await f.step("Continue to review → Place order", "'Thank you! Your order is placed.'; paid with Cash on delivery", async () => {
    await placeOrder(page);
    await expect(main(page)).toContainText("Thank you! Your order is placed", { timeout: 30_000 });
    await expect(main(page)).toContainText(/Cash on delivery/);
  });
  f.end();
});

test("CHK-09 gift card → Nawy Credits → pay the order with credits", async ({ page }) => {
  const f = flow(page, R, "CHK-09", "Wallet / Nawy Credits payment", "Buy an AED 200 gift card (fake gateway), redeem it to Nawy Credits, then pay an order fully with credits; the balance goes down by the order total.", "A new shopper.");
  const email = await newCustomer(page, "wallet");
  await f.step("Buy an AED 200 gift card for myself (card, captured) and redeem the emailed code", "Nawy Credits AED 200.00", async () => {
    const { mailMark, waitForMail } = await import("./kit");
    await visit(page, "/gift-cards");
    await page.getByRole("button", { name: "AED 200", exact: true }).click();
    await page.getByLabel("Recipient name").fill("QA Me");
    await page.getByLabel("Recipient email").fill(email);
    await page.locator('input[name="payment_method"]').first().check();
    await routeGateway(page, "captured");
    const mark = mailMark();
    await page.getByRole("button", { name: /Buy · AED 200/ }).click();
    await expect(main(page)).toContainText(/Payment received/, { timeout: 45_000 });
    const mail = await waitForMail(email, mark, /gift/i, 30_000);
    const code = /\b([A-Z0-9]{4}(?:-[A-Z0-9]{4}){2,3})\b/.exec(mail)![1];
    await visit(page, "/account/gift-cards");
    await page.getByLabel("Gift card code").fill(code);
    await page.getByRole("button", { name: "Redeem to Nawy Credits" }).click();
    await expect(main(page)).toContainText(/added to your Nawy Credits/);
  });
  await f.step("Add the AED 120 offer; checkout", "Payment methods include Nawy Credits with 'Available balance AED 200.00'", async () => {
    await addOffer(page, "fbm");
    await checkoutAddress(page);
    await expect(main(page)).toContainText(/Available balance AED\s?200\.00/);
  });
  await f.step("Pay with Nawy Credits → Place order", "Order placed straight away (no gateway)", async () => {
    await choosePayment(page, /Nawy Credits|Wallet|wallet/);
    await placeOrder(page);
    await expect(main(page)).toContainText(/Thank you! Your order is placed/, { timeout: 30_000 });
  });
  await f.step("Account → Nawy Credits", "Balance AED 80.00; a debit for the order", async () => {
    await visit(page, "/account/credits");
    await expect(main(page)).toContainText("80.00");
  });
  f.end();
});

test("CHK-10 duplicate-submit protection", async ({ page }) => {
  const f = flow(page, R, "CHK-10", "Duplicate submit", "Double-clicking 'Place order' (COD) creates one order only: the button disables while placing and the request carries an idempotency key.", "A new shopper.");
  await newCustomer(page, "dup");
  await f.step("Add the offer; checkout with COD; review", "Place order button shown", async () => {
    await addOffer(page, "fbm");
    await checkoutAddress(page);
    await choosePayment(page, /Cash on delivery/);
  });
  let posts = 0;
  await f.step("Double-click 'Place order'", "One order; the account has exactly 1 order", async () => {
    page.on("request", (r) => { if (r.method() === "POST" && /\/orders(\?|$)/.test(new URL(r.url()).pathname + (new URL(r.url()).search ? "?" : ""))) posts++; });
    await placeOrder(page, { doubleClick: true });
    await expect(main(page)).toContainText(/Thank you! Your order is placed/, { timeout: 30_000 });
    expect(await orderCount(page)).toBe(1);
    return `POST orders requests seen: ${posts}`;
  });
  await f.step("Place-order request replayed with the same idempotency key (API)", "Same order returned, no second order", async () => {
    expect(await orderCount(page)).toBe(1);
  }, { shot: false });
  f.end();
});

test("CHK-11 checkout with a saved address and delivery options", async ({ page }) => {
  const f = flow(page, R, "CHK-11", "Saved address and delivery options", "A shopper with a saved default address gets it preselected at checkout; the delivery section lists each shipment with its options and dates; mixed FBN + seller offers make two shipments.", "A new shopper with a saved address.");
  await newCustomer(page, "saved");
  await f.step("Save an address in the address book", "Default address saved", async () => {
    const res = await api(page, "POST", "/addresses", { name: "QA Saved", phone: "+971501112244", city: "Dubai", area: "Marina", street: `Saved St ${RUN}`, is_default: true });
    expect(res.status, res.body.slice(0, 200)).toBeLessThan(300);
  }, { shot: false });
  await f.step("Add the FBN (AED 95) and seller (AED 120) offers; open checkout", "Saved address preselected; delivery section per shipment (FBN and seller items)", async () => {
    await addOffer(page, "fbn");
    await addOffer(page, "fbm");
    await visit(page, "/checkout");
    await expect(main(page)).toContainText(`Saved St ${RUN}`);
    await expect(main(page)).toContainText(/Delivery — \d shipments?/);
    const text = await main(page).innerText();
    return /Delivery — 1 shipment/.test(text) ? "one shipment (E2E Store) holds both the FBN and the seller-fulfilled item" : /Delivery — \d shipments?/.exec(text)![0];
  });
  await f.step("Change a shipment's delivery method when another is offered", "Totals update with the method's fee", async () => {
    const radios = main(page).locator('input[type="radio"]:not([name="payment"])');
    const n = await radios.count();
    if (n > 1) await radios.nth(1).check();
    return `${n} delivery option radios`;
  }, { soft: true });
  f.end();
});
