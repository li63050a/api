import os
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8081/"

EXECUTABLE = os.environ.get(
    "PW_EXECUTABLE",
    "/root/.cache/puppeteer/chrome/linux-151.0.7922.71/chrome-linux64/chrome",
)


def login(page):
    # 尝试两组凭据
    for uname, pwd in [("admin666", "admin666"), ("boss", "verysecret123")]:
        page.goto(BASE)
        page.wait_for_load_state("networkidle")
        if page.is_visible("#loginOverlay"):
            page.fill("#loginForm input[name=username]", uname)
            page.fill("#loginForm input[name=password]", pwd)
            page.click("#loginForm button[type=submit]")
            page.wait_for_timeout(600)
        if page.is_visible("#changeOverlay"):
            page.fill("#changeForm input[name=username]", "boss")
            page.fill("#changeForm input[name=password]", "verysecret123")
            page.click("#changeForm button[type=submit]")
            page.wait_for_timeout(800)
        if page.is_visible("#appShell"):
            return True
    return False


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=EXECUTABLE)
        page = browser.new_page(viewport={"width": 1440, "height": 900})
        logs = []
        page.on("console", lambda m: logs.append(f"{m.type}: {m.text}"))
        page.on("pageerror", lambda e: logs.append(f"PAGEERROR: {e}"))

        if not login(page):
            print("LOGIN_FAILED")
            browser.close()
            return 1

        print("=== NAV LINKS ===")
        links = page.eval_on_selector_all(".sidebar nav a", "els => els.map(e => e.getAttribute('data-view'))")
        print("NAV:", links)
        assert "billing" not in links, "billing 仍在导航中!"
        assert "speedtest" not in links, "speedtest 仍在导航中!"

        # ---------- API 密钥：模型多选 ----------
        print("\n=== KEYS / MODEL SELECT ===")
        page.click('.sidebar nav a[data-view=keys]')
        page.wait_for_timeout(800)
        page.click("#keyNewBtn")
        page.wait_for_timeout(500)
        opts = page.eval_on_selector_all("select[name=allowed_models] option", "els => els.map(e => e.textContent)")
        print("KEY_MODAL_MODEL_OPTIONS:", opts)
        page.screenshot(path="/workspace/.e2e/01_keys_modal.png")
        # 关闭弹窗
        page.click("[data-mcancel]")
        page.wait_for_timeout(300)

        # ---------- 供应商：模型管理弹窗 ----------
        print("\n=== PROVIDERS / MODEL MANAGER MODAL ===")
        page.click('.sidebar nav a[data-view=providers]')
        page.wait_for_timeout(800)
        page.screenshot(path="/workspace/.e2e/02_providers.png")
        assert page.is_visible("#modelManageBtn"), "模型管理按钮不存在"
        page.click("#modelManageBtn")
        page.wait_for_timeout(600)
        page.screenshot(path="/workspace/.e2e/03_model_manager.png")
        assert page.is_visible("#mmNewBtn"), "新增模型按钮不存在"
        assert page.is_visible("#mmSyncBtn"), "同步全部模型按钮不存在"
        assert page.is_visible("#mmSpeedBtn"), "一键测速按钮不存在"
        assert page.is_visible("#mmAutoDisable"), "自动禁用 checkbox 不存在"
        row_count = page.eval_on_selector_all("#mmTableWrap tbody tr", "els => els.length")
        print("MODEL_TABLE_ROWS:", row_count)
        speed_btns = page.eval_on_selector_all("#mmTableWrap [data-speedmodel]", "els => els.length")
        print("PER_ROW_SPEED_BUTTONS:", speed_btns)
        # 逐行测速按钮
        if speed_btns:
            page.click("#mmTableWrap [data-speedmodel]")
            page.wait_for_timeout(800)
            page.screenshot(path="/workspace/.e2e/04_model_speed.png")
            result_txt = page.eval_on_selector("#mmResult", "el => el.textContent")
            print("SPEED_RESULT_HAS_TEXT:", bool(result_txt.strip()))
        # 一键测速
        page.click("#mmSpeedBtn")
        page.wait_for_timeout(1500)
        page.screenshot(path="/workspace/.e2e/05_speed_all.png")
        result_txt = page.eval_on_selector("#mmResult", "el => el.textContent")
        print("SPEED_ALL_TEXT:", result_txt.strip()[:200])
        # 关闭
        page.click("[data-mclose]")
        page.wait_for_timeout(300)

        # ---------- 日志：删除/清空 ----------
        print("\n=== LOGS ===")
        page.click('.sidebar nav a[data-view=logs]')
        page.wait_for_timeout(800)
        page.screenshot(path="/workspace/.e2e/06_logs.png")
        has_clear = page.is_visible("#logsClearBtn")
        has_clear_all = page.is_visible("#logsClearAllBtn")
        del_btns = page.eval_on_selector_all("[data-dellog]", "els => els.length")
        print("LOGS_CLEAR_BTN:", has_clear, "CLEAR_ALL_BTN:", has_clear_all, "PER_ROW_DEL:", del_btns)

        print("\n=== CONSOLE ===")
        for l in logs:
            print(" ", l)
        browser.close()
        print("\nUI_TEST_DONE")
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
