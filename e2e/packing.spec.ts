import { expect, test } from "@playwright/test";
import { login, shot } from "./helpers";

const DIR = "docs/screenshots/c1-packing";

test.describe.configure({ mode: "serial" });

test("scan to pack: open by order code, count items, refuse wrong and extra scans, finish with Enter", async ({
    page,
}) => {
    await login(page, "owner@demo.test");

    // A fresh own-channel order waiting to be packed: 2 × ATC-TRANG-M and 1 × POL-NAVY-XL.
    await page.goto("/orders/new");
    await expect(page.getByTestId("order-form")).toBeVisible();
    await page.getByTestId("sku-picker").fill("ATC-TRANG-M");
    await page.getByTestId("sku-picker").press("Enter");
    await page.getByTestId("sku-picker").fill("POL-NAVY-XL");
    await page.getByTestId("sku-picker").press("Enter");
    await page.getByTestId("line-qty-0").fill("2");
    await page.getByTestId("new-customer").click();
    await page.getByTestId("nc-name").fill("Trần Gói Hàng");
    await page.getByTestId("nc-phone").fill(`09${Date.now().toString().slice(-8)}`);
    await page.locator("#nc-addr-quick").fill("20 Pasteur, P. Bến Nghé, Quận 1, TP.HCM");
    await page.locator("#nc-addr-quick").press("Enter");
    await expect(page.locator("#nc-addr-ward")).toHaveValue("Phường Sài Gòn");
    await page.getByTestId("nc-save").click();
    await expect(page.getByTestId("customer-delivery")).toContainText("20 Pasteur");
    await page.getByTestId("submit-order").click();
    await expect(page.getByTestId("order-status")).toHaveText("Chờ xác nhận");
    await page.getByTestId("action-confirm").click();
    await expect(page.getByTestId("order-status")).toHaveText("Chờ đóng gói");
    const orderId = page.url().split("/").pop()!;

    await page.getByRole("button", { name: "Đơn hàng", exact: true }).click();
    await page.getByRole("link", { name: "Đóng gói", exact: true }).click();
    await expect(page).toHaveURL(/\/orders\/pack$/);
    const scan = page.getByTestId("pack-scan");
    await expect(scan).toBeFocused();

    await scan.fill(orderId);
    await scan.press("Enter");
    await expect(page.getByTestId("pack-line")).toHaveCount(2);
    await expect(page.getByTestId("pack-progress")).toHaveText(
        "Đã quét 0/3 sản phẩm",
    );

    await scan.fill("atc-trang-m ");
    await scan.press("Enter");
    await expect(page.getByTestId("pack-flash")).toHaveAttribute(
        "data-tone",
        "ok",
    );
    await scan.fill("KHONG-CO-MA");
    await scan.press("Enter");
    await expect(page.getByTestId("pack-flash")).toHaveAttribute(
        "data-tone",
        "error",
    );
    await expect(page.getByTestId("pack-flash")).toContainText("Sai hàng");

    await scan.fill("ATC-TRANG-M");
    await scan.press("Enter");
    await scan.fill("ATC-TRANG-M");
    await scan.press("Enter");
    await expect(page.getByTestId("pack-flash")).toContainText("Thừa");
    await expect(page.getByTestId("pack-progress")).toHaveText(
        "Đã quét 2/3 sản phẩm",
    );

    await page.keyboard.press("Control+z");
    await expect(page.getByTestId("pack-progress")).toHaveText(
        "Đã quét 1/3 sản phẩm",
    );
    await scan.fill("ATC-TRANG-M");
    await scan.press("Enter");
    await scan.fill("POL-NAVY-XL");
    await scan.press("Enter");
    await expect(page.getByTestId("pack-progress")).toHaveText(
        "Đã quét 3/3 sản phẩm",
    );
    await expect(page.getByTestId("pack-line").first()).toHaveAttribute(
        "data-done",
        "true",
    );
    await shot(page, "01-packing-complete", DIR);

    const download = page.waitForEvent("download");
    await scan.press("Enter");
    expect((await download).suggestedFilename()).toMatch(
        /^phieu-dong-goi-.*\.pdf$/,
    );
    await expect(page.getByTestId("pack-line")).toHaveCount(0);
    await expect(page.getByTestId("pack-stats")).not.toContainText("—");

    await page.goto(`/orders/${orderId}`);
    await expect(page.getByTestId("order-status")).toHaveText("Chờ bàn giao");
});

test("pick list: tick orders and print one list summed per SKU", async ({
    page,
}) => {
    await login(page, "owner@demo.test");
    await page.goto("/orders?tab=to_handover");
    await page.getByLabel("Chọn tất cả").check();
    const download = page.waitForEvent("download");
    await page.getByTestId("print-picklist").click();
    expect((await download).suggestedFilename()).toMatch(
        /^phieu-nhat-hang-.*\.pdf$/,
    );
});

test("warehouse staff open the packing screen from the phone menu", async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, "staff@demo.test");
    await page.getByTestId("mobile-more").click();
    await page
        .getByTestId("mobile-more-sheet")
        .getByRole("link", { name: "Đóng gói" })
        .click();
    await expect(page.getByTestId("pack-scan")).toBeVisible();
    await shot(page, "02-packing-phone", DIR);
});
