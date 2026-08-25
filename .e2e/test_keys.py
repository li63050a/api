from playwright.sync_api import sync_playwright

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 900})
    logs = []
    page.on("console", lambda m: logs.append(f"{m.type}: {m.text}"))
    page.on("pageerror", lambda e: logs.append(f"PAGEERROR: {e}"))
    page.goto("http://127.0.0.1:8081/")
    page.wait_for_load_state("networkidle")
    # 登录
    page.fill("input[name=username]", "admin666")
    page.fill("input[name=password]", "admin666")
    page.click("button[type=submit]")
    page.wait_for_timeout(600)
    # 强制改密
    if page.is_visible("#changeOverlay"):
        page.fill("#changeForm input[name=username]", "boss")
        page.fill("#changeForm input[name=password]", "verysecret123")
        page.click("#changeForm button[type=submit]")
        page.wait_for_timeout(800)
    # 进入 API 密钥页
    page.click('.sidebar nav a[data-view=keys]')
    page.wait_for_timeout(800)
    page.screenshot(path="/workspace/.e2e/keys_page.png", full_page=True)
    # 打开生成密钥弹窗
    page.click("#keyNewBtn")
    page.wait_for_timeout(500)
    page.screenshot(path="/workspace/.e2e/key_modal.png", full_page=True)
    # 读取模型多选框选项
    opts = page.eval_on_selector_all("select[name=allowed_models] option", "els => els.map(e => e.textContent)")
    print("MODEL_OPTIONS:", opts)
    sel_count = page.eval_on_selector("select[name=allowed_models]", "el => el.options.length")
    print("OPTION_COUNT:", sel_count)
    print("CONSOLE:")
    for l in logs:
        print(" ", l)
    browser.close()
